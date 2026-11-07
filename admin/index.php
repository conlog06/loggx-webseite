<?php
/* ==========================================================================
   LoggX Launch-System – Admin-Bereich  (…/admin/)
   ========================================================================== */
declare(strict_types=1);
require dirname(__DIR__) . '/_launch/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header("Referrer-Policy: same-origin");

lx_session();
$c = lx_config();
$self = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
$siteUrl = (lx_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . lx_base() . '/';

function mb_like_substr(string $v, int $max): string { return function_exists('mb_substr') ? mb_substr($v, 0, $max) : substr($v, 0, $max); }
function flash(string $text, string $type = 'ok'): void { $_SESSION['lx_flash'] = [$text, $type]; }
function back(string $q = ''): void {
    global $self, $tab;
    $t = (string)($_POST['tab'] ?? '');
    if ($t === '') $t = tab_for_action((string)($_POST['action'] ?? ''));
    header('Location: ' . $self . '?tab=' . urlencode($t) . $q, true, 303); exit;
}
/** Zu welchem Bereich eine Aktion gehört (für die Rückleitung nach dem Speichern) */
function tab_for_action(string $a): string {
    if (in_array($a, ['login', 'login2', 'setup'], true)) return 'start';
    if (str_starts_with($a, 'lead_')) return 'anfragen';
    if (str_starts_with($a, 'booking') || $a === 'feed_new') return 'termine';
    if (str_starts_with($a, 'stats')) return 'statistik';
    if (in_array($a, ['site', 'texts', 'image', 'image_delete'], true)) return 'inhalte';
    if (str_starts_with($a, 'totp') || str_starts_with($a, 'backup') || str_starts_with($a, 'weekly') || in_array($a, ['admin_pw', 'mail_settings', 'test_mail', 'log_clear', 'log_clear_ch'], true)) return 'einstellungen';
    return 'veroeffentlichung';
}
/** Anmeldeprotokoll (letzte 40 Einträge, IP gekürzt) */
function admin_log(string $what, bool $ok = true): void {
    try {
        $ip = lx_remote_ip();
        $ip = str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 2)) . ':…' : (string)preg_replace('/^(\d+\.\d+)\..*$/', '$1.x.x', $ip);
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        $dev = trim(lx_stats_browser($ua) . ' auf ' . lx_stats_os($ua));
        lx_locked('adminlog', function () use ($what, $ok, $ip, $dev) {
            $l = lx_read('adminlog', []); $l[] = ['ts' => time(), 't' => $what, 'ok' => $ok, 'ip' => $ip, 'dev' => $dev];
            lx_write('adminlog', array_slice($l, -40));
        });
    } catch (Throwable $e) {}
}
function setup_code(): string {
    $file = LX_DATA . '/einrichtungscode.php';
    if (!is_file($file)) {
        if (!is_dir(LX_DATA)) mkdir(LX_DATA, 0750, true);
        $code = lx_new_code() . '-' . lx_new_code();
        file_put_contents($file, LX_GUARD . "Einrichtungscode für den LoggX-Admin-Bereich:\n" . $code . "\n", LOCK_EX);
    }
    $raw = (string)file_get_contents($file);
    preg_match('/[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}/', $raw, $m);
    return $m[0] ?? '';
}

$isAdmin = !empty($_SESSION['lx_admin']) && ($_SESSION['lx_admin_until'] ?? 0) > time();
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$action = $_POST['action'] ?? '';
$error = null;
// Bereiche, geordnet in Gruppen (Kunden · Webseite · System)
$tabGroups = [
    'Kunden' => ['start' => 'Übersicht', 'anfragen' => 'Anfragen', 'termine' => 'Termine'],
    'Webseite' => ['inhalte' => 'Inhalte', 'veroeffentlichung' => 'Veröffentlichung'],
    'System' => ['statistik' => 'Statistik', 'einstellungen' => 'Einstellungen'],
];
$tabs = array_merge(...array_values($tabGroups)) + ['suche' => 'Suche'];
$tabAlias = ['webseite' => 'veroeffentlichung', 'sicherheit' => 'einstellungen'];  // alte Links
$tab = (string)($_GET['tab'] ?? '');
$tab = $tabAlias[$tab] ?? $tab;
if (!isset($tabs[$tab])) $tab = 'start';

/* ---------- Ersteinrichtung ---------- */
if ($c['admin_hash'] === '') {
    $expected = setup_code();
    if ($post && $action === 'setup') {
        $pw = (string)($_POST['password'] ?? '');
        if (lx_throttled('setup', 5)) $error = 'Zu viele Versuche. Bitte 15 Minuten warten.';
        elseif (!hash_equals(lx_norm_code($expected), lx_norm_code((string)($_POST['setup_code'] ?? '')))) { lx_fail('setup'); $error = 'Der Einrichtungscode stimmt nicht.'; }
        elseif (strlen($pw) < 10) $error = 'Das Passwort muss mindestens 10 Zeichen lang sein.';
        elseif ($pw !== ($_POST['password2'] ?? '')) $error = 'Die Passwörter stimmen nicht überein.';
        else {
            $c['admin_hash'] = password_hash($pw, PASSWORD_DEFAULT);
            if ($c['secret'] === '') $c['secret'] = bin2hex(random_bytes(32));
            lx_save_config($c);
            @unlink(LX_DATA . '/einrichtungscode.php');
            session_regenerate_id(true);
            $_SESSION['lx_admin'] = true; $_SESSION['lx_admin_until'] = time() + 8 * 3600;
            flash('Admin-Bereich eingerichtet. Willkommen!');
            back();
        }
    }
    render_auth('setup', $error);
    exit;
}

/* ---------- Login / Logout ---------- */
if (isset($_GET['logout'])) { $_SESSION = []; session_destroy(); header('Location: ' . $self, true, 303); exit; }
if (!$isAdmin) {
    $need2fa = !empty($c['totp_on']) && $c['totp_secret'] !== '';
    $grant = function (string $how = 'Passwort') { session_regenerate_id(true); unset($_SESSION['lx_pw_ok']); $_SESSION['lx_admin'] = true; $_SESSION['lx_admin_until'] = time() + 8 * 3600; admin_log('Angemeldet (' . $how . ')'); };
    if (isset($_GET['abbrechen'])) unset($_SESSION['lx_pw_ok']);
    $pwOk = !empty($_SESSION['lx_pw_ok']) && $_SESSION['lx_pw_ok'] > time() - 300; // 5 Minuten für den Code
    if ($post && $action === 'login') {
        if (lx_throttled('login', 6)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        elseif (password_verify((string)($_POST['password'] ?? ''), $c['admin_hash'])) {
            if ($need2fa && !lx_trust_ok($c)) { session_regenerate_id(true); $_SESSION['lx_pw_ok'] = time(); header('Location: ' . $self, true, 303); exit; }
            $grant($need2fa ? 'Passwort, vertrautes Gerät' : 'Passwort'); back();
        } else { lx_fail('login'); admin_log('Falsches Passwort', false); $error = 'Passwort falsch.'; }
    }
    if ($post && $action === 'login2') {
        if (!$need2fa || !$pwOk) $error = 'Die Zeit ist abgelaufen – bitte noch einmal mit dem Passwort anmelden.';
        elseif (lx_throttled('login2', 6)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        else {
            $code = (string)($_POST['code'] ?? '');
            $step = lx_totp_check($c['totp_secret'], $code, (int)$c['totp_last']);
            $usedRecovery = false;
            if ($step === null && ($n = lx_recovery_norm($code)) !== '' && strlen($n) === 10) {
                foreach ($c['totp_recovery'] as $i => $h) if (password_verify($n, (string)$h)) { unset($c['totp_recovery'][$i]); $usedRecovery = true; break; }
            }
            if ($step !== null || $usedRecovery) {
                if ($step !== null) $c['totp_last'] = $step;
                $c['totp_recovery'] = array_values($c['totp_recovery']);
                lx_save_config($c);
                $grant($usedRecovery ? 'Wiederherstellungscode' : 'Passwort + Code');
                if (!empty($_POST['trust'])) lx_trust_set($c);
                if ($usedRecovery) flash('Wiederherstellungscode verwendet – noch ' . count($c['totp_recovery']) . ' übrig. Erstelle unter Einstellungen → Zwei-Faktor neue Codes.', 'warn');
                back();
            }
            lx_fail('login2'); admin_log('Falscher Zwei-Faktor-Code', false); $error = 'Der Code stimmt nicht. Bitte den aktuellen Code aus der App eingeben.';
            $pwOk = true;
        }
    }
    if ($need2fa && $pwOk) { render_auth('totp', $error); exit; }
    render_auth('login', $error);
    exit;
}
$_SESSION['lx_admin_until'] = time() + 8 * 3600; // Sitzung verlängern

/* ---------- Aktionen ---------- */
if ($post) {
    if (!lx_check_csrf()) { flash('Sitzung abgelaufen – bitte erneut versuchen.', 'error'); back(); }
    $codes = lx_codes();
    switch ($action) {
        case 'status':
            $mode = $_POST['mode'] ?? 'live';
            $c['mode'] = in_array($mode, ['live', 'prelaunch', 'maintenance'], true) ? $mode : 'live';
            $at = trim((string)($_POST['launch_at'] ?? ''));
            $c['launch_at'] = lx_ts($at) ? $at : '';
            $c['auto_launch'] = !empty($_POST['auto_launch']);
            $until = trim((string)($_POST['until'] ?? ''));
            $c['maintenance']['until'] = lx_ts($until) ? $until : '';
            if ($c['mode'] === 'prelaunch' && $c['launch_at'] === '') flash('Gespeichert. Tipp: Ohne Launch-Zeitpunkt zeigt die Landingpage keinen Countdown.', 'warn');
            else flash('Status gespeichert.');
            lx_save_config($c); lx_indexnow_later();
            break;
        case 'launch_now':
            $c['mode'] = 'live'; lx_save_config($c); lx_indexnow_later(); flash('Die Seite ist jetzt öffentlich.'); break;
        case 'indexnow':
            if (PHP_SAPI === 'cli-server') { flash('Lokal (Testserver) wird nichts gemeldet – das geht nur auf loggx.de.', 'warn'); break; }
            if (lx_effective_mode($c) !== 'live') { flash('Die Seite ist noch nicht öffentlich – erst nach dem Launch melden.', 'warn'); break; }
            [$ok, $msg] = lx_indexnow_submit(); flash($msg, $ok ? 'ok' : 'error');
            back('#indexnow');
        case 'site':
            $S = $c['site'];
            $str = function (string $k, int $max = 120) { return trim(mb_like_substr((string)($_POST[$k] ?? ''), $max)); };
            $num = function (string $k, int $min, int $max, int $def) { $v = filter_var($_POST[$k] ?? '', FILTER_VALIDATE_INT); return ($v === false || $v < $min || $v > $max) ? $def : $v; };
            $mail = trim((string)($_POST['email'] ?? ''));
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) { flash('Bitte eine gültige E-Mail-Adresse eintragen.', 'error'); break; }
            $S['email'] = $mail;
            foreach (['tax_number' => 40, 'tax_office' => 60, 'hoster' => 160] as $k => $max) $S[$k] = $str($k, $max);
            $S['stats_text'] = $str('stats_text', 800);
            $S['thumio'] = !empty($_POST['thumio']);
            $S['phone_show'] = !empty($_POST['phone_show']);
            foreach (['phone' => 40, 'street' => 80, 'city' => 80, 'time_visit' => 30, 'time_firm' => 30, 'kicker_home' => 90, 'kicker_angebot' => 90, 'bar_text' => 160, 'bar_link_text' => 40, 'bar_badge' => 20] as $k => $max) $S[$k] = $str($k, $max);
            $S['price_visit'] = $num('price_visit', 0, 99999, (int)$S['price_visit']);
            $S['price_firm'] = $num('price_firm', 0, 99999, (int)$S['price_firm']);
            $S['price_hosting'] = $num('price_hosting', 0, 9999, (int)$S['price_hosting']);
            $S['price_hourly'] = $num('price_hourly', 0, 999, (int)($S['price_hourly'] ?? 50));
            $S['lp_price_visit'] = $num('lp_price_visit', 0, 99999, 0);
            $S['lp_price_firm'] = $num('lp_price_firm', 0, 99999, 0);
            $S['lp_strike'] = !empty($_POST['lp_strike']);
            $S['response_hours'] = $num('response_hours', 1, 168, (int)$S['response_hours']);
            $link = trim((string)($_POST['bar_link'] ?? ''));
            $S['bar_link'] = preg_match('~^(#|/|https?://)~i', $link) ? substr($link, 0, 300) : '';
            $wasOn = !empty($c['site']['bar_on']);
            $S['bar_on'] = !empty($_POST['bar_on']);
            if (!empty($_POST['bar_remove'])) { $S['bar_on'] = false; $S['bar_text'] = $S['bar_link_text'] = $S['bar_link'] = ''; }
            // Neue Ankündigung → wird auch Besuchern wieder angezeigt, die die alte geschlossen haben
            if ($S['bar_text'] !== ($c['site']['bar_text'] ?? '') || ($S['bar_on'] && !$wasOn)) $S['bar_id'] = bin2hex(random_bytes(4));
            $c['site'] = $S; $c['contact_email'] = $mail;
            lx_save_config($c); lx_indexnow_later(); flash('Webseite aktualisiert – die Änderungen sind sofort sichtbar.');
            break;
        case 'texts':
            foreach (['kicker', 'title', 'text'] as $k) $c['landing'][$k] = trim((string)($_POST['l_' . $k] ?? ''));
            $c['landing']['signup'] = !empty($_POST['l_signup']);
            foreach (['title', 'text'] as $k) $c['maintenance'][$k] = trim((string)($_POST['m_' . $k] ?? ''));
            lx_save_config($c); flash('Texte gespeichert.');
            break;
        case 'add_code':
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') { flash('Bitte einen Namen für den Zugang angeben.', 'error'); break; }
            $exp = trim((string)($_POST['expires'] ?? ''));
            $code = ['id' => bin2hex(random_bytes(6)), 'name' => (function_exists('mb_substr') ? mb_substr($name, 0, 80) : substr($name, 0, 80)), 'code' => lx_new_code(),
                     'created' => date('Y-m-d H:i'), 'expires' => $exp !== '' ? $exp . 'T23:59' : '', 'active' => true, 'uses' => 0, 'last_used' => ''];
            $codes[] = $code; lx_save_codes($codes);
            $_SESSION['lx_new_code'] = $code['id'];
            flash('Zugang für „' . $code['name'] . '“ angelegt. Link kopieren und weitergeben.');
            break;
        case 'toggle_code':
            foreach ($codes as &$cd) if ($cd['id'] === ($_POST['id'] ?? '')) $cd['active'] = empty($cd['active']);
            unset($cd); lx_save_codes($codes); flash('Zugang aktualisiert.');
            break;
        case 'delete_code':
            $codes = array_filter($codes, fn($cd) => $cd['id'] !== ($_POST['id'] ?? ''));
            lx_save_codes($codes); flash('Zugang gelöscht – die Person sieht ab sofort wieder die Landingpage.');
            break;
        case 'shared':
            $pw = (string)($_POST['shared_password'] ?? '');
            if (!empty($_POST['clear'])) { $c['shared_password'] = ''; flash('Gemeinsames Vorschau-Passwort ausgeschaltet.'); }
            elseif (strlen($pw) < 6) { flash('Das Vorschau-Passwort braucht mindestens 6 Zeichen.', 'error'); break; }
            else { $c['shared_password'] = password_hash($pw, PASSWORD_DEFAULT); flash('Vorschau-Passwort gesetzt.'); }
            lx_save_config($c);
            break;
        /* ----- Zwei-Faktor-Anmeldung ----- */
        case 'totp_start':
            $_SESSION['lx_totp_pending'] = lx_totp_new_secret();
            back('#sicherheit');
        case 'totp_cancel':
            unset($_SESSION['lx_totp_pending']); back('#sicherheit');
        case 'totp_confirm':
            $sec = (string)($_SESSION['lx_totp_pending'] ?? '');
            $step = $sec !== '' ? lx_totp_check($sec, (string)($_POST['code'] ?? '')) : null;
            if ($step === null) { flash('Der Code stimmt nicht. Prüfe, ob die Uhrzeit am Handy stimmt, und gib den aktuellen Code ein.', 'error'); back('#sicherheit'); }
            $codes = lx_recovery_new();
            $c['totp_on'] = true; $c['totp_secret'] = $sec; $c['totp_last'] = $step;
            $c['totp_recovery'] = array_map(fn($x) => password_hash(lx_recovery_norm($x), PASSWORD_DEFAULT), $codes);
            lx_save_config($c);
            unset($_SESSION['lx_totp_pending']); $_SESSION['lx_recovery_show'] = $codes;
            flash('Zwei-Faktor-Anmeldung ist aktiv. Speichere jetzt deine Wiederherstellungscodes!');
            back('#sicherheit');
        case 'totp_recovery_new':
        case 'totp_off':
            $code = (string)($_POST['code'] ?? '');
            $okCode = lx_totp_check($c['totp_secret'], $code, (int)$c['totp_last']) !== null;
            if ($action === 'totp_off' && !password_verify((string)($_POST['password'] ?? ''), $c['admin_hash'])) { flash('Passwort falsch.', 'error'); back('#sicherheit'); }
            if (!$okCode) { flash('Der Code aus der Authenticator-App stimmt nicht.', 'error'); back('#sicherheit'); }
            if ($action === 'totp_off') {
                $c['totp_on'] = false; $c['totp_secret'] = ''; $c['totp_last'] = 0; $c['totp_recovery'] = [];
                lx_save_config($c); lx_trust_clear();
                flash('Zwei-Faktor-Anmeldung ist ausgeschaltet.', 'warn');
            } else {
                $codes = lx_recovery_new();
                $c['totp_recovery'] = array_map(fn($x) => password_hash(lx_recovery_norm($x), PASSWORD_DEFAULT), $codes);
                lx_save_config($c); $_SESSION['lx_recovery_show'] = $codes;
                flash('Neue Wiederherstellungscodes erstellt – die alten gelten nicht mehr.');
            }
            back('#sicherheit');
        case 'totp_untrust':
            lx_trust_clear(); flash('Dieses Gerät fragt beim nächsten Login wieder nach dem Code.'); back('#sicherheit');
        case 'admin_pw':
            $old = (string)($_POST['old'] ?? ''); $new = (string)($_POST['new'] ?? '');
            if (!password_verify($old, $c['admin_hash'])) flash('Aktuelles Passwort falsch.', 'error');
            elseif (strlen($new) < 10) flash('Das neue Passwort braucht mindestens 10 Zeichen.', 'error');
            else { $c['admin_hash'] = password_hash($new, PASSWORD_DEFAULT); lx_save_config($c); admin_log('Admin-Passwort geändert'); flash('Admin-Passwort geändert.'); }
            back('#passwort');
            break;
        case 'export':
            $list = lx_read('signups', []);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="loggx-anmeldungen-' . date('Y-m-d') . '.csv"');
            echo "\xEF\xBB\xBF" . "E-Mail;Datum\n";
            foreach ($list as $s) echo '"' . str_replace('"', '""', $s['email']) . '";' . $s['date'] . "\n";
            exit;
        case 'image':
        case 'image_delete':
            $slots = lx_image_slots(); $slot = (string)($_POST['slot'] ?? '');
            if (!isset($slots[$slot])) break;
            $old = (string)($c['site']['img_' . $slot] ?? '');
            $delOld = function () use ($old) { if ($old !== '') { $f = lx_root() . '/' . strtok($old, '?'); if (is_file($f)) @unlink($f); } };
            if ($action === 'image_delete') { $delOld(); $c['site']['img_' . $slot] = ''; lx_save_config($c); flash('Bild entfernt.'); break; }
            $up = $_FILES['file'] ?? null;
            if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) { flash('Upload fehlgeschlagen – bitte eine Bilddatei wählen (max. 8 MB).', 'error'); break; }
            if ($up['size'] > 8 * 1024 * 1024) { flash('Das Bild ist größer als 8 MB.', 'error'); break; }
            $info = @getimagesize($up['tmp_name']);
            $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
            if (!$ext) { flash('Nur JPG, PNG oder WebP möglich.', 'error'); break; }
            $delOld();
            $rel = 'img/' . $slot . '-upload.' . $ext;
            if (!@move_uploaded_file($up['tmp_name'], lx_root() . '/' . $rel)) { flash('Konnte das Bild nicht speichern – hat der Ordner img/ Schreibrechte?', 'error'); break; }
            @chmod(lx_root() . '/' . $rel, 0644);
            $c['site']['img_' . $slot] = $rel . '?v=' . time(); lx_save_config($c);
            flash($slots[$slot][0] . ' aktualisiert.');
            break;
        case 'stats':
            $c['site']['stats_on'] = !empty($_POST['stats_on']); lx_save_config($c);
            flash($c['site']['stats_on'] ? 'Statistik ist eingeschaltet.' : 'Statistik ist ausgeschaltet – es wird nichts mehr gezählt.');
            break;
        case 'stats_reset':
            lx_stats_reset(); flash('Statistik wurde zurückgesetzt.');
            break;
        /* ----- Termine ----- */
        case 'booking_new':
            if (!empty($_POST['save_tpl'])) {
                $c['booking']['mail_subject'] = lx_cut((string)($_POST['mail_subject'] ?? ''), 200) ?: lx_bk_defaults()['mail_subject'];
                $c['booking']['mail_text'] = lx_cut(str_replace("\r", '', (string)($_POST['mail_text'] ?? '')), 5000) ?: lx_bk_defaults()['mail_text'];
                lx_save_config($c);
            }
            [$ok, $res, $clash] = lx_booking_create($_POST);
            if (!$ok) { flash($res, 'error'); back('#neu'); }
            $mode = (string)($_POST['mode'] ?? 'vorschlag');
            $msg = $mode === 'still' ? 'Termin eingetragen.' : ($res['sent'] ? ($mode === 'fest' ? 'Termin eingetragen – Bestätigung mit Kalendereintrag an ' : 'Vorschlag mit Bestätigungslink an ') . $res['email'] . ' gesendet.' : 'Termin gespeichert, aber die E-Mail ging nicht raus – prüfe unter Einstellungen → E-Mail den Absender.');
            if ($clash) $msg .= ' Achtung: Überschneidung mit „' . $clash['type_name'] . ' – ' . $clash['name'] . '“ (' . date('d.m. H:i', (int)$clash['start']) . ').';
            if ($res['status'] !== 'offen' || count(lx_bk_opts($res)) === 1) { if (lx_bk_outside((int)$res['start'], lx_bk_min($res))) $msg .= ' (Außerhalb deiner Arbeitszeit.)'; }
            flash($msg, $clash || !$res['sent'] && $mode !== 'still' ? 'warn' : 'ok');
            back('&id=' . $res['id']);
        case 'booking_move':
            $b = lx_booking_move((string)($_POST['id'] ?? ''), (array)($_POST['date'] ?? []), (array)($_POST['time'] ?? []), (string)($_POST['note'] ?? ''));
            flash($b ? 'Neuer Vorschlag an ' . ($b['email'] ?: '–') . ' gesendet.' : 'Bitte gültige Zeiten in der Zukunft wählen.', $b ? 'ok' : 'error');
            back('&id=' . urlencode((string)($_POST['id'] ?? '')));
        case 'booking_cancel':
            $b = lx_booking_cancel((string)($_POST['id'] ?? ''), (string)($_POST['msg'] ?? ''), !empty($_POST['mail']));
            flash($b ? 'Termin abgesagt' . (!empty($_POST['mail']) && $b['email'] !== '' ? ' – ' . $b['name'] . ' wurde per E-Mail informiert.' : '.') : 'Termin nicht gefunden.', $b ? 'ok' : 'error');
            back('&id=' . urlencode((string)($_POST['id'] ?? '')));
        case 'booking_resend':
            $b = lx_booking_get((string)($_POST['id'] ?? ''));
            $ok = $b && lx_booking_remind($b);
            flash($ok ? 'Erinnerung an ' . $b['email'] . ' gesendet.' : 'Erinnerung konnte nicht gesendet werden.', $ok ? 'ok' : 'error');
            back(!empty($_POST['from_detail']) ? '&id=' . urlencode((string)($_POST['id'] ?? '')) : '#kommend');
        case 'booking_memo':
            lx_booking_update((string)($_POST['id'] ?? ''), function (&$x) { $x['memo'] = lx_cut(str_replace("\r", '', (string)($_POST['memo'] ?? '')), 1500); });
            flash('Notiz gespeichert.'); back('&id=' . urlencode((string)($_POST['id'] ?? '')));
        case 'booking_delete':
            lx_booking_delete((string)($_POST['id'] ?? '')); flash('Eintrag gelöscht.'); back('#kommend');
        case 'booking_settings':
            $B = lx_bk_cfg();
            $t = [];
            foreach ((array)($_POST['bk_types'] ?? []) as $row) {
                $nm = lx_cut((string)($row['name'] ?? ''), 60); if ($nm === '') continue;
                $id = preg_replace('/[^a-z0-9]/', '', (string)($row['id'] ?? '')) ?: substr(preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower($nm), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss'])), 0, 20) . bin2hex(random_bytes(2));
                $t[] = ['id' => $id, 'name' => $nm, 'min' => max(10, min(480, (int)($row['min'] ?? 30))), 'where' => lx_cut((string)($row['where'] ?? ''), 200)];
            }
            if (!$t) { flash('Bitte mindestens eine Terminart behalten.', 'error'); back('#einstellungen'); }
            $hours = [];
            for ($wd = 0; $wd < 7; $wd++) {
                $f = (string)($_POST['h_from'][$wd] ?? ''); $to = (string)($_POST['h_to'][$wd] ?? '');
                $hours[$wd] = !empty($_POST['h_on'][$wd]) && preg_match('/^\d{2}:\d{2}$/', $f) && preg_match('/^\d{2}:\d{2}$/', $to) && $to > $f ? "$f-$to" : '';
            }
            $c['booking'] = array_merge($B, ['types' => array_slice($t, 0, 10), 'hours' => $hours, 'buffer' => max(0, min(120, (int)($_POST['buffer'] ?? 15))), 'lead_h' => max(1, min(336, (int)($_POST['lead_h'] ?? 12))),
                'mail_subject' => lx_cut((string)($_POST['bk_subject'] ?? ''), 200) ?: lx_bk_defaults()['mail_subject'],
                'mail_text' => lx_cut(str_replace("\r", '', (string)($_POST['bk_text'] ?? '')), 5000) ?: lx_bk_defaults()['mail_text'],
                'notify' => !empty($_POST['bk_notify']), 'remind' => !empty($_POST['bk_remind'])]);
            lx_save_config($c); flash('Einstellungen für Termine gespeichert.'); back('#einstellungen');
        case 'feed_new':
            lx_booking_feed_key(true); flash('Neuer Abo-Link erzeugt – der alte funktioniert nicht mehr.', 'warn'); back('#abo');
        /* ----- Anfragen: Wiedervorlage ----- */
        case 'lead_follow':
            $id = (string)($_POST['id'] ?? ''); $q = (string)($_POST['in'] ?? '');
            $date = $q === 'clear' ? '' : (ctype_digit($q) ? date('Y-m-d', strtotime('+' . (int)$q . ' days')) : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['date'] ?? '')) ? (string)$_POST['date'] : ''));
            lx_locked('leads', function () use ($id, $date) {
                $l = lx_leads();
                foreach ($l as &$x) if ($x['id'] === $id) { $x['follow'] = $date; $x['notes'][] = ['ts' => time(), 't' => $date === '' ? 'Wiedervorlage entfernt' : 'Wiedervorlage: ' . date('d.m.Y', (int)strtotime($date)), 'sys' => true]; }
                unset($x); lx_save_leads($l);
            });
            flash($date === '' ? 'Wiedervorlage entfernt.' : 'Wiedervorlage am ' . date('d.m.Y', (int)strtotime($date)) . ' – erscheint dann in der Übersicht unter „Zu erledigen“.');
            back('&id=' . urlencode($id));
        /* ----- Einstellungen ----- */
        case 'mail_settings':
            $from = trim((string)($_POST['mail_from'] ?? ''));
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { flash('Die Absender-Adresse ist keine gültige E-Mail-Adresse.', 'error'); back('#email'); }
            $c['site']['mail_from'] = $from;
            $cc = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string)($_POST['mail_cc'] ?? '')) ?: []));
            foreach ($cc as $m) if (!filter_var($m, FILTER_VALIDATE_EMAIL)) { flash('„' . $m . '“ ist keine gültige E-Mail-Adresse.', 'error'); back('#email'); }
            $c['site']['mail_cc'] = implode(', ', $cc);
            lx_save_config($c); flash('E-Mail-Einstellungen gespeichert.'); back('#email');
        case 'backup':
            $cfg = $c;
            foreach (['admin_hash', 'secret', 'totp_secret', 'totp_recovery', 'totp_last', 'fin_totp_secret', 'fin_totp_recovery', 'fin_totp_last', 'fin_pw_hash', 'shared_password', 'indexnow_key'] as $k) unset($cfg[$k]);
            unset($cfg['booking']['feed_key']);
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="loggx-sicherung-' . date('Y-m-d') . '.json"');
            echo json_encode(['erstellt' => date('c'), 'hinweis' => 'Datensicherung aus dem LoggX-Admin (ohne Passwörter und geheime Schlüssel). Enthält personenbezogene Daten – sicher aufbewahren.',
                'anfragen' => lx_leads(), 'termine' => lx_bookings(), 'benachrichtigungen' => lx_read('signups', []), 'zugaenge' => lx_codes(), 'einstellungen' => $cfg], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            admin_log('Datensicherung heruntergeladen');
            exit;
        case 'backup_now':
            $nm = lx_backup_create('manuell'); admin_log('Sicherung erstellt'); flash('Sicherung erstellt (' . $nm . ').'); back('#sicherungen');
        case 'backup_restore':
            if (!password_verify((string)($_POST['password'] ?? ''), $c['admin_hash'])) { flash('Passwort falsch – nichts wurde geändert.', 'error'); back('#sicherungen'); }
            $ok = lx_backup_restore((string)($_POST['name'] ?? ''));
            if ($ok) admin_log('Sicherung wiederhergestellt: ' . (string)$_POST['name']);
            flash($ok ? 'Stand wiederhergestellt. Der vorherige Stand wurde vorher automatisch gesichert („vor Wiederherstellung“).' : 'Diese Sicherung konnte nicht gelesen werden.', $ok ? 'ok' : 'error');
            back('#sicherungen');
        case 'backup_download':
            $d = lx_backup_load((string)($_POST['name'] ?? ''));
            if (!$d) { flash('Sicherung nicht gefunden.', 'error'); back('#sicherungen'); }
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="loggx-server-sicherung-' . date('Y-m-d', (int)$d['meta']['ts']) . '.json"');
            echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); admin_log('Server-Sicherung heruntergeladen'); exit;
        case 'log_clear_ch':
            $ch = (string)($_POST['ch'] ?? '');
            if (in_array($ch, ['fehler', 'mail', '404'], true)) lx_log_clear($ch);
            flash('Protokoll geleert.'); back('&log=' . urlencode($ch) . '#protokolle');
        case 'weekly':
            $c['site']['weekly_on'] = !empty($_POST['weekly_on']); lx_save_config($c);
            flash($c['site']['weekly_on'] ? 'Wochenbericht ist an – jeden Montag ab 7 Uhr.' : 'Wochenbericht ausgeschaltet.'); back('#bericht');
        case 'weekly_send':
            $ok = lx_weekly_report(true); flash($ok ? 'Wochenbericht an ' . implode(', ', lx_admin_rcpts($c)) . ' gesendet.' : 'Senden fehlgeschlagen.', $ok ? 'ok' : 'error'); back('#bericht');
        case 'lead_reply':
            [$ok, $msg] = lx_lead_reply((string)($_POST['id'] ?? ''), (string)($_POST['subject'] ?? ''), (string)($_POST['text'] ?? ''));
            flash($msg, $ok ? 'ok' : 'error'); back('&id=' . urlencode((string)($_POST['id'] ?? '')));
        case 'lead_templates':
            if (!empty($_POST['reset'])) { $c['leads']['templates'] = []; lx_save_config($c); flash('Vorlagen zurückgesetzt.'); back('#vorlagen'); }
            $t = [];
            foreach ((array)($_POST['tpl'] ?? []) as $row) {
                $nm = lx_cut((string)($row['name'] ?? ''), 60); if ($nm === '') continue;
                $t[] = ['id' => preg_replace('/[^a-z0-9]/', '', (string)($row['id'] ?? '')) ?: bin2hex(random_bytes(3)), 'name' => $nm, 'subject' => lx_cut((string)($row['subject'] ?? ''), 200), 'text' => lx_cut(str_replace("\r", '', (string)($row['text'] ?? '')), 6000)];
            }
            $c['leads']['templates'] = array_slice($t, 0, 12); lx_save_config($c); flash('Antwortvorlagen gespeichert.'); back('#vorlagen');
        case 'log_clear':
            lx_write('adminlog', []); flash('Anmeldeprotokoll geleert.'); back('#protokoll');
        /* ----- Anfragen ----- */
        case 'lead_status':
        case 'lead_note':
        case 'lead_delete':
            $id = (string)($_POST['id'] ?? '');
            lx_locked('leads', function () use ($action, $id) {
                $l = lx_leads();
                foreach ($l as $k => &$x) {
                    if ($x['id'] !== $id) continue;
                    if ($action === 'lead_status' && isset(lx_lead_status()[$_POST['status'] ?? ''])) {
                        $x['status'] = $_POST['status']; $x['notes'][] = ['ts' => time(), 't' => 'Status: ' . lx_lead_status()[$x['status']], 'sys' => true];
                    }
                    if ($action === 'lead_note' && ($t = lx_cut(str_replace("\r", '', (string)($_POST['note'] ?? '')), 2000)) !== '') $x['notes'][] = ['ts' => time(), 't' => $t];
                    if ($action === 'lead_delete') unset($l[$k]);
                    else $x['seen'] = true;
                }
                unset($x);
                lx_save_leads($l);
            });
            flash($action === 'lead_delete' ? 'Anfrage gelöscht.' : 'Gespeichert.');
            back($action === 'lead_delete' ? '' : '&id=' . urlencode($id));
        case 'lead_settings':
            $c['leads']['autoreply'] = !empty($_POST['autoreply']);
            $c['leads']['keep_months'] = max(0, min(60, (int)($_POST['keep_months'] ?? 12)));
            lx_save_config($c); flash('Einstellungen für Anfragen gespeichert.');
            back();
        case 'test_mail':
            $ok = lx_mail_admin('Test-E-Mail von loggx.de', "Hallo Constantin,\n\nwenn du diese E-Mail liest, kommen Anfragen von deiner Webseite richtig bei dir an.\n\nEmpfänger: " . implode(', ', lx_admin_rcpts($c)) . "\nAbsender: " . lx_mail_from() . "\n\nLoggX Webseite\n");
            flash($ok ? 'Test-E-Mail an ' . implode(' und ', lx_admin_rcpts($c)) . ' verschickt – bitte auch im Spam-Ordner nachsehen.' : 'Versand fehlgeschlagen. Trag als Absender eine Adresse deiner Domain ein (z. B. kontakt@loggx.de) und versuch es noch einmal.', $ok ? 'ok' : 'error');
            back('#email');
        case 'lead_export':
            $o = lx_lead_options();
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="loggx-anfragen-' . date('Y-m-d') . '.csv"');
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Datum', 'Status', 'Priorität', 'Empfehlung', 'Name', 'Firma', 'Branche', 'Ort', 'E-Mail', 'Telefon', 'Lieber per', 'Jetzige Seite', 'Vorhaben', 'Umfang', 'Dazu gewünscht', 'Start', 'Budget', 'Paket', 'Nachricht', 'Herkunft', 'Gerät'], ';');
            foreach (array_reverse(lx_leads()) as $x) {
                $d = $x['data'];
                fputcsv($f, [date('d.m.Y H:i', $x['created']), lx_lead_status()[$x['status']] ?? $x['status'], $x['score'], $x['rec'], $d['name'], $d['firma'],
                    lx_lead_branche($d), $d['ort'], $d['email'], $d['telefon'], $o['kontakt'][$d['kontakt']] ?? '', $d['webseite'], $o['projekt'][$d['projekt']] ?? '',
                    $o['seiten'][$d['seiten']] ?? '', implode(', ', array_map(fn($z) => $o['extras'][$z] ?? $z, $d['extras'])), $o['start'][$d['start']] ?? '',
                    $o['budget'][$d['budget']] ?? '', $d['paket'], $d['nachricht'], $x['src'], $x['device']], ';');
            }
            exit;
        case 'delete_signup':
            $list = lx_read('signups', []);
            $list = array_values(array_filter($list, fn($s) => $s['email'] !== ($_POST['email'] ?? '')));
            lx_write('signups', $list); flash('Anmeldung gelöscht.');
            break;
    }
    back();
}

/* ---------- Ansicht ---------- */
$flash = $_SESSION['lx_flash'] ?? null; unset($_SESSION['lx_flash']);
$newCode = $_SESSION['lx_new_code'] ?? null; unset($_SESSION['lx_new_code']);
$codes = lx_codes();
$signups = lx_read('signups', []);
$eff = lx_effective_mode($c);
$launchTs = lx_ts($c['launch_at']);
$csrf = lx_csrf();
$modeNames = ['live' => 'Öffentlich', 'prelaunch' => 'Vor dem Launch', 'maintenance' => 'Wartungsarbeiten'];

$leads = array_reverse(lx_leads());
$newLeads = count(array_filter($leads, fn($x) => $x['status'] === 'neu'));
$bkBadge = count(array_filter(lx_bookings(), fn($b) => $b['status'] === 'abgelehnt' && (int)$b['end'] > time() - 14 * 86400)); // „passt nicht“ → neuer Vorschlag nötig
try { lx_maintenance_tick(); } catch (Throwable $e) {}
$range = in_array((int)($_GET['tage'] ?? 30), [7, 30, 90, 365], true) ? (int)($_GET['tage'] ?? 30) : 30;
$st = in_array($tab, ['start', 'statistik'], true) ? lx_stats_summary($range) : null;
$hidden = fn(string $a) => '<input type="hidden" name="csrf" value="' . e($csrf) . '"><input type="hidden" name="action" value="' . e($a) . '"><input type="hidden" name="tab" value="' . e($GLOBALS['tab']) . '">';
$n = fn($v, int $dec = 0) => number_format((float)$v, $dec, ',', '.');
$pct = fn($a, $b) => $b > 0 ? (int)round($a / $b * 100) : 0;
$trend = function (int $now, int $prev): string {
    if ($prev <= 0) return $now > 0 ? '<em class="trend up">neu</em>' : '';
    $p = (int)round(($now - $prev) / $prev * 100);
    return '<em class="trend ' . ($p >= 0 ? 'up' : 'down') . '">' . ($p >= 0 ? '↑ ' : '↓ ') . abs($p) . ' %</em>';
};
$dur = fn(int $s) => $s >= 60 ? floor($s / 60) . ' Min. ' . ($s % 60) . ' Sek.' : $s . ' Sek.';
$srcName = fn(string $k) => $k === 'direkt' ? 'Direkt / Lesezeichen' : $k;

head('Admin – LoggX', true);
?>
<header class="bar">
  <a class="brand" href="?tab=start"><svg viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#0F2540"/><path d="M70 375V137H129V375ZM80 375V325H219V375Z" fill="#fff"/><path d="M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z" fill="#FFD166"/></svg>LoggX <span>Admin</span></a>
  <form class="gsearch" method="get" role="search"><input type="hidden" name="tab" value="suche"><input type="search" name="q" value="<?= e($tab === 'suche' ? (string)($_GET['q'] ?? '') : '') ?>" placeholder="Suchen …" aria-label="Anfragen und Termine durchsuchen"><kbd>/</kbd></form>
  <nav class="bar-r"><a href="<?= e(lx_url('')) ?>" target="_blank" rel="noopener">Seite ansehen ↗</a><a href="?logout">Abmelden</a></nav>
</header>
<nav class="tabs" aria-label="Bereiche">
  <?php $primary = ['start', 'anfragen', 'termine', 'statistik']; foreach ($tabGroups as $g => $items): ?><div class="tg"><span class="tg-l"><?= e($g) ?></span><?php foreach ($items as $k => $t): ?><a href="?tab=<?= $k ?>" class="<?= in_array($k, $primary, true) ? 'pri' : 'sec' ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($k) ?><span><?= $t ?></span><?= $k === 'anfragen' && $newLeads ? '<i class="badge-n">' . $newLeads . '</i>' : '' ?><?= $k === 'termine' && $bkBadge ? '<i class="badge-n">' . $bkBadge . '</i>' : '' ?></a><?php endforeach; ?></div><?php endforeach; ?>
  <div class="tg tg-fin"><a href="finanzen.php" class="sec" title="FinanzKlar – Buchhaltung (nur für dich)"><?= icon('finanzen') ?><span>Finanzen</span></a></div>
  <details class="tabs-more"<?= !in_array($tab, $primary, true) ? ' data-cur' : '' ?>><summary><?= icon('mehr') ?><span>Mehr</span></summary>
    <div class="more-sheet"><?php foreach ($tabGroups as $g => $items): foreach ($items as $k => $t): if (in_array($k, $primary, true)) continue; ?><a href="?tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($k) ?><span><?= $t ?><small><?= e($g) ?></small></span></a><?php endforeach; endforeach; ?>
      <a href="finanzen.php"><?= icon('finanzen') ?><span>Finanzen<small>FinanzKlar</small></span></a>
      <a href="?tab=suche"><?= icon('suche') ?><span>Suche<small>Anfragen &amp; Termine</small></span></a>
      <a href="<?= e(lx_url('')) ?>" target="_blank" rel="noopener"><?= icon('webseite') ?><span>Seite ansehen<small>loggx.de</small></span></a></div></details>
</nav>
<main class="admin">
  <?php if ($flash): ?><p class="flash <?= e($flash[1]) ?>" role="status"><?= e($flash[0]) ?></p><?php endif; ?>
  <?php if ($eff !== 'live' && $tab !== 'veroeffentlichung'): ?><p class="flash warn">Besucher sehen gerade: <strong><?= e($modeNames[$eff]) ?></strong>. <a href="?tab=veroeffentlichung">Ändern</a></p><?php endif; ?>

<?php /* ======================= ÜBERSICHT ======================= */ if ($tab === 'start'):
  $maxDay = max(1, ...array_map(fn($d) => $d['u'], $st['days']));
  $conv = $st['u'] ? $st['a'] / $st['u'] * 100 : 0;
  $hello = (int)date('G') < 11 ? 'Guten Morgen' : ((int)date('G') < 18 ? 'Hallo' : 'Guten Abend');
?>
  <?php if (empty($c['totp_on'])): ?><p class="flash warn">Tipp: Schütze den Admin-Bereich mit einer Zwei-Faktor-Anmeldung. <a href="?tab=einstellungen#sicherheit">Jetzt einrichten</a></p><?php endif; ?>
  <div class="page-head">
    <div><h1><?= $hello ?>, Constantin.</h1><p class="sub">So läuft loggx.de in den letzten <?= $range === 365 ? '12 Monaten' : $range . ' Tagen' ?>.</p></div>
    <?= range_nav($range, 'start') ?>
  </div>
  <?php
    $bkAll = lx_bookings(); $today = date('Y-m-d');
    $nextBk = array_slice(array_values(array_filter($bkAll, fn($b) => $b['status'] === 'bestaetigt' && (int)$b['end'] >= time())), 0, 5);
    $todo = [];
    $errs7 = count(array_filter(lx_read('log-fehler', []), fn($x) => (int)$x['ts'] > time() - 7 * 86400));
    $mailF = count(array_filter(lx_read('log-mail', []), fn($x) => empty($x['x']['ok']) && (int)$x['ts'] > time() - 7 * 86400));
    if ($mailF) $todo[] = ['hot', '<a href="?tab=einstellungen&amp;log=mail#protokolle"><b>' . $mailF . ' E-Mail' . ($mailF > 1 ? 's' : '') . '</b> konnte' . ($mailF > 1 ? 'n' : '') . ' nicht gesendet werden – Absender prüfen</a>'];
    if ($errs7) $todo[] = ['warn', '<a href="?tab=einstellungen&amp;log=fehler#protokolle"><b>' . $errs7 . ' technische Meldung' . ($errs7 > 1 ? 'en' : '') . '</b> in den letzten 7 Tagen ansehen</a>'];
    if ($newLeads) $todo[] = ['hot', '<a href="?tab=anfragen&amp;status=neu"><b>' . $newLeads . ' neue Anfrage' . ($newLeads > 1 ? 'n' : '') . '</b> beantworten</a>'];
    foreach ($leads as $x) if (($x['follow'] ?? '') !== '' && $x['follow'] <= $today && !in_array($x['status'], ['gewonnen', 'verloren'], true))
        $todo[] = ['follow', '<a href="?tab=anfragen&amp;id=' . e($x['id']) . '">Wiedervorlage: <b>' . e($x['data']['firma'] ?: $x['data']['name']) . '</b></a>' . ($x['follow'] < $today ? ' <span class="tag bad">seit ' . e(date('d.m.', (int)strtotime($x['follow']))) . '</span>' : ' <span class="tag warn">heute</span>')];
    foreach ($bkAll as $b) {
        if ($b['status'] === 'abgelehnt' && (int)$b['end'] > time() - 14 * 86400) $todo[] = ['cal', '<a href="?tab=termine&amp;id=' . e($b['id']) . '"><b>' . e($b['name']) . '</b> passt der Termin nicht – neue Zeit vorschlagen</a>'];
        elseif ($b['status'] === 'offen' && !lx_bk_expired($b) && (int)$b['created'] < time() - 2 * 86400) $todo[] = ['wait', '<a href="?tab=termine&amp;id=' . e($b['id']) . '"><b>' . e($b['name']) . '</b> hat seit ' . e(lx_ago((int)$b['created'])) . ' nicht auf den Terminvorschlag geantwortet</a>'];
    }
    foreach ($leads as $x) if ($x['status'] === 'angebot' && ($x['follow'] ?? '') === '') {
        $last = max(array_merge([(int)$x['created']], array_map(fn($nt) => (int)$nt['ts'], (array)$x['notes'])));
        if ($last < time() - 7 * 86400) $todo[] = ['offer', '<a href="?tab=anfragen&amp;id=' . e($x['id']) . '">Angebot an <b>' . e($x['data']['firma'] ?: $x['data']['name']) . '</b> seit ' . e(lx_ago($last)) . ' offen – nachfassen?</a>'];
    }
  ?>
  <div class="cols-1-1">
    <section class="card">
      <div class="card-h"><h2>Nächste Termine</h2><a href="?tab=termine" class="more">Kalender →</a></div>
      <?php if (!$nextBk): ?><p class="empty">Keine bestätigten Termine. <a href="?tab=termine#neu">Termin vorschlagen</a></p><?php else: ?>
      <ul class="agenda"><?php foreach ($nextBk as $b): $isToday = date('Y-m-d', (int)$b['start']) === $today; ?>
        <li<?= $isToday ? ' class="today"' : '' ?>><a href="?tab=termine&amp;id=<?= e($b['id']) ?>"><span class="ag-d"><b><?= $isToday ? 'Heute' : (date('Y-m-d', (int)$b['start']) === date('Y-m-d', strtotime('+1 day')) ? 'Morgen' : ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', (int)$b['start'])] . ' ' . date('j.n.', (int)$b['start'])) ?></b><small><?= date('H:i', (int)$b['start']) ?> Uhr</small></span>
          <span class="lm"><b><?= e($b['name']) ?></b><small><?= e($b['type_name']) ?><?= $b['where'] !== '' ? ' · ' . e(lx_cut($b['where'], 40)) : '' ?></small></span></a></li>
      <?php endforeach; ?></ul><?php endif; ?>
    </section>
    <section class="card">
      <div class="card-h"><h2>Zu erledigen <?= $todo ? '<span class="count">' . count($todo) . '</span>' : '' ?></h2></div>
      <?php if (!$todo): ?><p class="empty">Alles erledigt. 🎉</p><?php else: ?>
      <ul class="insights todo"><?php foreach (array_slice($todo, 0, 8) as [$k, $t]): ?><li class="in-<?= e($k) ?>"><?= $t ?></li><?php endforeach; ?></ul><?php endif; ?>
    </section>
  </div>
  <section class="kpis">
    <div><span>Besucher</span><b><?= $n($st['u']) ?></b><?= $trend($st['u'], $st['prev']['u']) ?></div>
    <div><span>Anfragen</span><b><?= $n($st['a']) ?></b><?= $trend($st['a'], $st['prev']['a']) ?></div>
    <div><span>Anfrage-Quote</span><b><?= $n($conv, 1) ?> %</b><small>Besucher → Anfrage</small></div>
    <div><span>Ø Verweildauer</span><b><?= $st['v'] ? $dur((int)round($st['t'] / $st['v'])) : '–' ?></b><small><?= $pct($st['e'], $st['v']) ?> % bleiben über 15 Sek.</small></div>
  </section>
  <div class="cols-2-1">
    <section class="card">
      <div class="card-h"><h2>Besucher pro Tag</h2><span class="legend"><i></i> Tag mit Anfrage</span></div>
      <div class="chart" role="img" aria-label="Besucher pro Tag">
        <?php foreach ($st['days'] as $d): $h = $d['u'] ? max(3, round($d['u'] / $maxDay * 100)) : 0; ?><span class="cbar<?= $d['a'] ? ' has-a' : '' ?>" style="--h:<?= $h ?>%" data-tip="<?= e(date('d.m.', strtotime($d['day']))) ?> · <?= $d['u'] ?> Besucher<?= $d['a'] ? ' · ' . $d['a'] . ' Anfrage(n)' : '' ?>"><i></i></span><?php endforeach; ?>
      </div>
      <div class="chart-axis"><span><?= e(date('d.m.', strtotime($st['days'][0]['day']))) ?></span><span>heute</span></div>
    </section>
    <section class="card">
      <h2>Auf einen Blick</h2>
      <?php $ins = insights($st, $leads, $range); if (!$ins): ?><p class="empty">Sobald Besucher kommen, erscheinen hier Hinweise.</p><?php endif; ?>
      <ul class="insights"><?php foreach ($ins as [$k, $t]): ?><li class="in-<?= e($k) ?>"><?= $t ?></li><?php endforeach; ?></ul>
    </section>
  </div>
  <div class="cols-1-1">
    <section class="card">
      <div class="card-h"><h2>Neueste Anfragen</h2><a href="?tab=anfragen" class="more">Alle ansehen →</a></div>
      <?php if (!$leads): ?><p class="empty">Noch keine Anfragen. Sobald jemand das Formular abschickt, steht sie hier und du bekommst eine E-Mail.</p><?php endif; ?>
      <ul class="leads compact"><?php foreach (array_slice($leads, 0, 5) as $x) echo lead_row($x); ?></ul>
    </section>
    <section class="card">
      <div class="card-h"><h2>Woher kommen die Besucher?</h2><a href="?tab=statistik" class="more">Details →</a></div>
      <div class="bars"><?= bars($st['r'], 0, 6, $srcName) ?></div>
    </section>
  </div>

<?php /* ======================= ANFRAGEN ======================= */ elseif ($tab === 'anfragen'):
  $o = lx_lead_options(); $stat = lx_lead_status();
  $id = (string)($_GET['id'] ?? ''); $one = null;
  foreach ($leads as $x) if ($x['id'] === $id) $one = $x;
  if ($one && empty($one['seen'])) lx_locked('leads', function () use ($id) { $l = lx_leads(); foreach ($l as &$x) if ($x['id'] === $id) $x['seen'] = true; unset($x); lx_save_leads($l); });
  if ($one): $d = $one['data']; [$tk, $tl] = lx_lead_temp((int)$one['score']);
    $mailBody = "Guten Tag {$d['name']},\n\nvielen Dank für Ihre Anfrage bei LoggX!\n\n";
    $mailto = 'mailto:' . rawurlencode($d['email']) . '?subject=' . rawurlencode('Ihre Anfrage bei LoggX') . '&body=' . rawurlencode($mailBody);
?>
  <a href="?tab=anfragen" class="backlink">← Alle Anfragen</a>
  <div class="page-head">
    <div><h1><?= e($d['firma'] ?: $d['name']) ?></h1><p class="sub"><?= e(lx_date_de((int)$one['created'])) ?> · über <?= e($one['src']) ?><?= e(lx_lead_pages()[$one['page'] ?? ''] ?? '') ?> · <?= e($one['device']) ?></p></div>
    <div class="head-tags"><span class="temp t-<?= $tk ?>"><?= $tl ?> · <?= (int)$one['score'] ?></span><span class="pill-s"><i class="dot st-<?= e($one['status']) ?>"></i><?= e($stat[$one['status']] ?? '') ?></span></div>
  </div>
  <div class="cols-2-1">
    <section class="card">
      <div class="quick">
        <a class="btn" href="<?= e($mailto) ?>">✉️ Per E-Mail antworten</a>
        <a class="btn btn-ghost" href="?tab=termine&amp;lead=<?= e($one['id']) ?>#neu">📅 Termin vorschlagen</a>
        <?php if ($d['telefon']): ?><a class="btn btn-ghost" href="tel:<?= e(lx_tel($d['telefon'])) ?>">📞 Anrufen</a>
        <a class="btn btn-ghost" href="https://wa.me/<?= e(lead_wa($d['telefon'])) ?>?text=<?= rawurlencode('Guten Tag ' . $d['name'] . ', hier ist Constantin von LoggX – danke für Ihre Anfrage!') ?>" target="_blank" rel="noopener">💬 WhatsApp</a><?php endif; ?>
        <?php if ($d['webseite']): $u = preg_match('~^https?://~i', $d['webseite']) ? $d['webseite'] : 'https://' . $d['webseite']; ?><a class="btn btn-ghost" href="<?= e($u) ?>" target="_blank" rel="noopener noreferrer">🌐 Jetzige Seite</a><?php endif; ?>
      </div>
      <div class="rec-box"><span>Empfehlung der Webseite</span><b><?= e($one['rec']) ?></b><small><?= e($one['why']) ?></small></div>
      <dl class="facts">
        <dt>Name</dt><dd><?= e($d['name']) ?></dd>
        <dt>E-Mail</dt><dd><a href="mailto:<?= e($d['email']) ?>"><?= e($d['email']) ?></a></dd>
        <dt>Telefon</dt><dd><?= e($d['telefon'] ?: '–') ?></dd>
        <dt>Am liebsten per</dt><dd><strong><?= e($o['kontakt'][$d['kontakt']] ?? '–') ?></strong></dd>
        <dt>Firma</dt><dd><?= e($d['firma'] ?: '–') ?></dd>
        <dt>Branche</dt><dd><?= e(lx_lead_branche($d) ?: '–') ?></dd>
        <dt>Ort</dt><dd><?= e($d['ort'] ?: '–') ?></dd>
        <?php if ($d['webseite']): ?><dt>Jetzige Seite</dt><dd><?= e($d['webseite']) ?></dd><?php endif; ?>
        <dt>Vorhaben</dt><dd><?= e($o['projekt'][$d['projekt']] ?? '–') ?></dd>
        <dt>Umfang</dt><dd><?= e($o['seiten'][$d['seiten']] ?? '–') ?></dd>
        <dt>Dazu gewünscht</dt><dd><?= $d['extras'] ? implode('', array_map(fn($z) => '<span class="chip-s">' . e($o['extras'][$z] ?? $z) . '</span>', $d['extras'])) : '–' ?></dd>
        <dt>Start</dt><dd><?= e($o['start'][$d['start']] ?? '–') ?></dd>
        <dt>Budget</dt><dd><?= e($o['budget'][$d['budget']] ?? '–') ?></dd>
        <?php if ($d['paket']): ?><dt>Paket geklickt</dt><dd><?= e($d['paket']) ?></dd><?php endif; ?>
      </dl>
      <?php if ($d['nachricht']): ?><h3>Nachricht</h3><p class="msg"><?= nl2br(e($d['nachricht'])) ?></p><?php endif; ?>
      <?php $tpls = array_map(fn($t) => ['n' => $t['name'], 's' => lx_reply_fill($t['subject'], $one), 't' => lx_reply_fill($t['text'], $one)], lx_reply_templates($c)); $mails = count(array_filter((array)$one['notes'], fn($nt) => !empty($nt['mail']))); ?>
      <div class="reply" id="antworten">
        <h3>Antworten <small><?= $mails ? $mails . ' E-Mail' . ($mails > 1 ? 's' : '') . ' gesendet' : 'direkt von hier – wird in den Notizen gespeichert' ?></small></h3>
        <div class="tpl-pick" data-tpls="<?= e(json_encode($tpls, JSON_UNESCAPED_UNICODE)) ?>"><?php foreach ($tpls as $i => $t): ?><button type="button" class="chip" data-tpl="<?= $i ?>"><?= e($t['n']) ?></button><?php endforeach; ?><a class="more" href="?tab=anfragen#vorlagen">Vorlagen bearbeiten</a></div>
        <form method="post" class="stack" onsubmit="return confirm('E-Mail jetzt an <?= e($d['email']) ?> senden?')"><?= $hidden('lead_reply') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>">
          <label>An<input type="email" value="<?= e($d['email']) ?>" disabled></label>
          <label>Betreff<input type="text" name="subject" value="<?= e($tpls[0]['s'] ?? 'Ihre Anfrage bei LoggX') ?>" maxlength="200" required></label>
          <label>Text<textarea name="text" rows="9" maxlength="8000" required><?= e($tpls[0]['t'] ?? '') ?></textarea></label>
          <div class="btnrow"><button class="btn">✉️ E-Mail senden</button><a class="btn btn-ghost" href="<?= e($mailto) ?>">Lieber im Mailprogramm</a></div>
        </form>
      </div>
    </section>
    <aside class="card">
      <h2>Status</h2>
      <form method="post" class="status-pick"><?= $hidden('lead_status') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>">
        <?php foreach ($stat as $k => $t): ?><button name="status" value="<?= $k ?>" class="st<?= $one['status'] === $k ? ' on' : '' ?>"><i class="dot st-<?= $k ?>"></i><?= e($t) ?></button><?php endforeach; ?>
      </form>
      <?php $lb = array_values(array_filter(lx_bookings(), fn($b) => ($b['lead'] ?? '') === $one['id'])); if ($lb): ?>
      <h2 class="mt">Termine</h2>
      <ul class="agenda"><?php foreach (array_reverse($lb) as $b): ?><li><a href="?tab=termine&amp;id=<?= e($b['id']) ?>"><span class="ag-d"><b><?= e(date('d.m.', (int)$b['start'])) ?></b><small><?= date('H:i', (int)$b['start']) ?></small></span><span class="lm"><b><?= e($b['type_name']) ?></b><small><?= e(LX_BK_STATUS[$b['status']] ?? '') ?></small></span></a></li><?php endforeach; ?></ul>
      <?php endif; ?>
      <h2 class="mt">Wiedervorlage</h2>
      <?php $fw = (string)($one['follow'] ?? ''); ?>
      <?php if ($fw !== ''): ?><p class="follow-now<?= $fw <= date('Y-m-d') ? ' due' : '' ?>">⏰ <?= e(date('d.m.Y', (int)strtotime($fw))) ?><?= $fw <= date('Y-m-d') ? ' – fällig' : '' ?></p><?php endif; ?>
      <form method="post" class="follow"><?= $hidden('lead_follow') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>">
        <div class="btnrow"><button class="btn btn-ghost btn-small" name="in" value="1">Morgen</button><button class="btn btn-ghost btn-small" name="in" value="3">In 3 Tagen</button><button class="btn btn-ghost btn-small" name="in" value="7">In 1 Woche</button><?php if ($fw !== ''): ?><button class="btn btn-ghost btn-small" name="in" value="clear">Entfernen</button><?php endif; ?></div>
        <div class="row" style="margin:8px 0 0"><input type="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= e($fw) ?>" aria-label="Datum für Wiedervorlage"><button class="btn btn-small" name="in" value="date">Setzen</button></div>
      </form>
      <h2 class="mt">Notizen</h2>
      <form method="post" class="stack"><?= $hidden('lead_note') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>">
        <textarea name="note" rows="3" placeholder="z. B. Angerufen, Termin am 12.11. um 10 Uhr"></textarea><button class="btn btn-small">Notiz speichern</button></form>
      <ul class="notes"><?php foreach (array_reverse($one['notes']) as $nt): ?><li class="<?= !empty($nt['sys']) ? 'sys' : '' ?>"><small><?= e(date('d.m.Y H:i', (int)$nt['ts'])) ?></small><?= nl2br(e($nt['t'])) ?></li><?php endforeach; ?>
        <li class="sys"><small><?= e(date('d.m.Y H:i', (int)$one['created'])) ?></small>Anfrage eingegangen</li></ul>
      <form method="post" class="mt" onsubmit="return confirm('Anfrage endgültig löschen?')"><?= $hidden('lead_delete') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>"><button class="btn btn-small btn-danger">Anfrage löschen</button></form>
    </aside>
  </div>
<?php else:
  $f = (string)($_GET['status'] ?? ''); $q = trim((string)($_GET['q'] ?? ''));
  $counts = array_count_values(array_column($leads, 'status'));
  $shown = array_filter($leads, fn($x) => ($f === '' || $x['status'] === $f) && ($q === '' || stripos(implode(' ', [$x['data']['name'], $x['data']['firma'], $x['data']['email'], $x['data']['ort']]), $q) !== false));
  $won = $counts['gewonnen'] ?? 0; $closed = $won + ($counts['verloren'] ?? 0);
?>
  <div class="page-head"><div><h1>Anfragen</h1><p class="sub"><?= count($leads) ?> insgesamt · <?= $newLeads ?> neu<?= $closed ? ' · ' . round($won / $closed * 100) . ' % gewonnen' : '' ?></p></div>
    <form method="post"><?= $hidden('lead_export') ?><button class="btn btn-ghost btn-small"<?= $leads ? '' : ' disabled' ?>>Als Excel/CSV laden</button></form></div>
  <div class="pipeline">
    <a href="?tab=anfragen" class="<?= $f === '' ? 'on' : '' ?>"><b><?= count($leads) ?></b>Alle</a>
    <?php foreach (lx_lead_status() as $k => $t): ?><a href="?tab=anfragen&amp;status=<?= $k ?>" class="<?= $f === $k ? 'on' : '' ?>"><b><i class="dot st-<?= $k ?>"></i><?= $counts[$k] ?? 0 ?></b><?= e($t) ?></a><?php endforeach; ?>
  </div>
  <?php if ($leads): ?><form class="search" method="get"><input type="hidden" name="tab" value="anfragen"><?php if ($f): ?><input type="hidden" name="status" value="<?= e($f) ?>"><?php endif; ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Suchen nach Name, Firma, Ort …"></form><?php endif; ?>
  <section class="card flush">
    <?php if (!$shown): ?><p class="empty pad"><?= $leads ? 'Keine Anfragen für diese Auswahl.' : 'Noch keine Anfragen. Sobald jemand das Formular auf der Webseite abschickt, steht sie hier – mit Paket-Empfehlung und Priorität – und du bekommst eine E-Mail.' ?></p><?php endif; ?>
    <ul class="leads"><?php foreach ($shown as $x) echo lead_row($x); ?></ul>
  </section>
  <section class="card" id="vorlagen">
    <h2>Antwortvorlagen</h2>
    <p class="hint">Erscheinen in jeder Anfrage unter „Antworten“. Platzhalter: <code>{name}</code> <code>{firma}</code> <code>{firma_bei}</code> („ für Firma“) <code>{paket}</code> <code>{ort}</code> <code>{webseite}</code></p>
    <form method="post" class="stack"><?= $hidden('lead_templates') ?>
      <div id="tplList" class="tpl-ed"><?php foreach (lx_reply_templates($c) as $i => $t): ?>
        <details class="tech"><summary><?= e($t['name']) ?></summary><input type="hidden" name="tpl[<?= $i ?>][id]" value="<?= e($t['id']) ?>">
          <div class="stack"><div class="cols"><label>Name<input type="text" name="tpl[<?= $i ?>][name]" value="<?= e($t['name']) ?>" maxlength="60"></label><label>Betreff<input type="text" name="tpl[<?= $i ?>][subject]" value="<?= e($t['subject']) ?>" maxlength="200"></label></div>
          <label>Text<textarea name="tpl[<?= $i ?>][text]" rows="8"><?= e($t['text']) ?></textarea></label><small class="hint" style="margin:0">Name leeren = Vorlage löschen</small></div></details>
      <?php endforeach; ?>
        <details class="tech"><summary>+ Neue Vorlage</summary><div class="stack"><div class="cols"><label>Name<input type="text" name="tpl[neu][name]" maxlength="60" placeholder="z. B. Termin-Erinnerung"></label><label>Betreff<input type="text" name="tpl[neu][subject]" maxlength="200"></label></div><label>Text<textarea name="tpl[neu][text]" rows="6">Guten Tag {name},

Viele Grüße
Constantin Moritz Loggen
LoggX – Webseiten für Unternehmen</textarea></label></div></details>
      </div>
      <div class="btnrow"><button class="btn btn-small">Vorlagen speichern</button><button class="btn btn-small btn-ghost" name="reset" value="1" onclick="return confirm('Alle Vorlagen auf Standard zurücksetzen?')">Standard wiederherstellen</button></div>
    </form>
  </section>
  <section class="card">
    <h2>Einstellungen für Anfragen</h2>
    <form method="post" class="stack"><?= $hidden('lead_settings') ?>
      <label class="check"><input type="checkbox" name="autoreply" value="1"<?= !empty($c['leads']['autoreply']) ? ' checked' : '' ?>> Automatische Bestätigung an den Kunden senden (mit Paket-Empfehlung)</label>
      <p class="hint" style="margin:0">Anfragen gehen an <strong><?= e(implode(', ', lx_admin_rcpts($c))) ?></strong> – Empfänger und Absender änderst du unter <a href="?tab=einstellungen#email">Einstellungen → E-Mail</a>.</p>
      <div class="cols">
        <label>Anfragen automatisch löschen nach <span class="unit-in"><input type="number" name="keep_months" min="0" max="60" value="<?= (int)$c['leads']['keep_months'] ?>"><i>Monaten</i></span><small>0 = nie · „Auftrag erhalten“ bleibt immer</small></label>
      </div>
      <div class="btnrow"><button class="btn btn-small">Speichern</button></div>
    </form>
  </section>
<?php endif; ?>

<?php /* ======================= TERMINE ======================= */ elseif ($tab === 'termine'):
  $B = lx_bk_cfg(); $all = lx_bookings(); $now = time(); $types = lx_booking_types($B);
  $isUp = fn($b) => $b['status'] !== 'abgesagt' && (($b['status'] === 'offen' && !lx_bk_expired($b)) || ($b['status'] !== 'offen' && (int)$b['end'] >= $now));
  $up = array_values(array_filter($all, $isUp));
  $past = array_reverse(array_values(array_filter($all, fn($b) => !$isUp($b))));
  $cnt = fn($st) => count(array_filter($up, fn($b) => $b['status'] === $st));
  $one = isset($_GET['id']) ? lx_booking_get((string)$_GET['id']) : null;
  // Vorbelegung (z. B. aus einer Anfrage)
  $pre = ['name' => (string)($_GET['name'] ?? ''), 'email' => (string)($_GET['email'] ?? ''), 'phone' => '', 'lead' => '', 'art' => (string)($_GET['art'] ?? ''), 'date' => (string)($_GET['date'] ?? ''), 'time' => (string)($_GET['time'] ?? '')];
  if (($lid = (string)($_GET['lead'] ?? '')) !== '') foreach ($leads as $x) if ($x['id'] === $lid) {
      $pre = array_merge($pre, ['name' => $x['data']['name'], 'email' => $x['data']['email'], 'phone' => (string)$x['data']['telefon'], 'lead' => $x['id'], 'firma' => $x['data']['firma']]);
      if ($pre['art'] === '') $pre['art'] = ($x['data']['kontakt'] ?? '') === 'video' ? 'video' : 'erstgespraech';
  }
  $selType = lx_booking_type($pre['art'], $B) ?? ($types[0] ?? ['id' => '', 'name' => 'Termin', 'min' => 30, 'where' => '']);
  $free = [];
  foreach (array_unique(array_merge([(int)$selType['min']], array_map(fn($t) => (int)$t['min'], $types))) as $m) $free[$m] = array_map(fn($t) => [date('Y-m-d', $t), date('H:i', $t), ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', $t)] . ' ' . date('j.n.', $t) . ' · ' . date('H:i', $t)], lx_booking_free($m, 8));
  // Wochenansicht
  $wk = max(-26, min(52, (int)($_GET['woche'] ?? 0)));
  $mon = strtotime('monday this week', strtotime(($wk >= 0 ? '+' : '') . $wk . ' weeks'));
  $wkItems = [];
  foreach ($all as $b) { if ($b['status'] === 'abgesagt') continue; foreach (lx_bk_opts($b) as $i => $t) if ($t >= $mon && $t < strtotime('+7 days', $mon)) $wkItems[date('Y-m-d', $t)][] = [$t, $b, $b['status'] === 'offen' && count(lx_bk_opts($b)) > 1 ? $i + 1 : 0]; }
  foreach ($wkItems as &$list) usort($list, fn($a, $z) => $a[0] <=> $z[0]); unset($list);
  $typeRow = fn(string $k, array $t) => '<div class="trow"><input type="hidden" name="bk_types[' . $k . '][id]" value="' . e($t['id'] ?? '') . '"><input name="bk_types[' . $k . '][name]" value="' . e($t['name'] ?? '') . '" placeholder="Name, z. B. Erstgespräch" maxlength="60" aria-label="Name der Terminart">'
      . '<select name="bk_types[' . $k . '][min]" aria-label="Dauer">' . implode('', array_map(fn($m) => '<option value="' . $m . '"' . ((int)($t['min'] ?? 30) === $m ? ' selected' : '') . '>' . ($m < 60 ? $m . ' Min.' : str_replace('.', ',', (string)($m / 60)) . ' Std.') . '</option>', [15, 20, 30, 45, 60, 90, 120, 180, 240])) . '</select>'
      . '<input name="bk_types[' . $k . '][where]" value="' . e($t['where'] ?? '') . '" placeholder="Ort, z. B. Telefon oder Zoom-Link" maxlength="200" aria-label="Ort"><button type="button" class="icon" data-remove aria-label="Terminart löschen" title="Löschen">✕</button></div>';
  $stTag = ['offen' => 'warn', 'bestaetigt' => 'on', 'abgelehnt' => 'bad', 'abgesagt' => 'no'];
  $wdn = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
  $timeRow = fn(int $i, string $d = '', string $t = '') => '<div class="trow t-time" data-i="' . $i . '"><span class="t-n">' . ($i + 1) . '</span><input type="date" name="date[]" value="' . e($d) . '" min="' . date('Y-m-d') . '"' . ($i === 0 ? ' required' : '') . ' aria-label="Datum ' . ($i + 1) . '"><input type="time" name="time[]" value="' . e($t) . '" step="300"' . ($i === 0 ? ' required' : '') . ' aria-label="Uhrzeit ' . ($i + 1) . '">' . ($i ? '<button type="button" class="icon" data-remove-time aria-label="Zeit entfernen" title="Entfernen">✕</button>' : '<span></span>') . '</div>';
  $feed = lx_booking_feed_key(); $feedUrl = lx_abs_url('_launch/api.php?a=kalender&k=' . $feed); $webcal = preg_replace('~^https?://~', 'webcal://', $feedUrl);
?>
<?php if ($one): $b = $one; $opts = lx_bk_opts($b); $min = lx_bk_min($b); $lead = null; foreach ($leads as $x) if ($x['id'] === ($b['lead'] ?? '')) $lead = $x; ?>
  <a href="?tab=termine" class="backlink">← Alle Termine</a>
  <div class="page-head">
    <div><h1><?= e($b['name']) ?></h1><p class="sub"><?= e($b['type_name']) ?> · <?= $min ?> Min.<?= $b['where'] !== '' ? ' · ' . e($b['where']) : '' ?></p></div>
    <div class="head-tags"><span class="tag <?= $stTag[$b['status']] ?? '' ?>"><?= e(lx_bk_expired($b) && $b['status'] === 'offen' ? 'Abgelaufen' : (LX_BK_STATUS[$b['status']] ?? $b['status'])) ?></span></div>
  </div>
  <div class="cols-2-1">
    <section class="card">
      <?php if (count($opts) > 1): ?><h2>Vorgeschlagene Zeiten</h2><ol class="bk-when-list"><?php foreach ($opts as $t): ?><li><b><?= e(lx_bk_day($t)) ?></b> <?= date('H:i', $t) ?>–<?= date('H:i', $t + $min * 60) ?> Uhr<?= lx_bk_clash($t, $min, $b['id']) ? ' <span class="tag warn">Überschneidung</span>' : '' ?></li><?php endforeach; ?></ol>
      <?php else: ?><div class="bk-big"><span><?= e(lx_bk_day((int)$b['start'])) ?></span><b><?= date('H:i', (int)$b['start']) ?>–<?= date('H:i', (int)$b['end']) ?> Uhr</b></div><?php endif; ?>
      <dl class="facts">
        <dt>E-Mail</dt><dd><?= $b['email'] !== '' ? '<a href="mailto:' . e($b['email']) . '">' . e($b['email']) . '</a>' : '–' ?></dd>
        <?php if (($b['phone'] ?? '') !== ''): ?><dt>Telefon</dt><dd><a href="tel:<?= e(lx_tel($b['phone'])) ?>"><?= e($b['phone']) ?></a></dd><?php endif; ?>
        <dt>Anfrage</dt><dd><?= $lead ? '<a href="?tab=anfragen&amp;id=' . e($lead['id']) . '">' . e($lead['data']['firma'] ?: $lead['data']['name']) . ' →</a>' : '–' ?></dd>
        <dt>E-Mail gesendet</dt><dd><?= !empty($b['sent']) ? e(date('d.m.Y H:i', (int)$b['sent'])) : 'nein' ?><?= !empty($b['reminded']) ? ' · Erinnerung ' . e(date('d.m. H:i', (int)$b['reminded'])) : '' ?></dd>
        <?php if ($b['note'] !== ''): ?><dt>Nachricht an Kunde</dt><dd><?= nl2br(e($b['note'])) ?></dd><?php endif; ?>
        <?php if ($b['answer'] !== ''): ?><dt>Antwort</dt><dd class="bk-answer"><?= nl2br(e($b['answer'])) ?></dd><?php endif; ?>
      </dl>
      <div class="btnrow">
        <a class="btn btn-ghost btn-small" href="<?= e(lx_url('_launch/api.php?a=ics&t=' . $b['token'])) ?>">📅 In meinen Kalender</a>
        <a class="btn btn-ghost btn-small" href="<?= e(lx_url('termin?t=' . $b['token'])) ?>" target="_blank" rel="noopener">Kundenansicht ↗</a>
        <button type="button" class="btn btn-ghost btn-small" data-copy="<?= e(lx_booking_link($b)) ?>">Link kopieren</button>
        <?php if ($b['status'] !== 'abgesagt' && $b['email'] !== '' && (int)$b['end'] > $now): ?><form method="post"><?= $hidden('booking_resend') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>"><input type="hidden" name="from_detail" value="1"><button class="btn btn-ghost btn-small"><?= $b['status'] === 'offen' ? '🔔 Erinnern' : '🔔 Erinnerung senden' ?></button></form><?php endif; ?>
      </div>
      <?php if ($b['status'] !== 'abgesagt'): ?>
      <div class="bk-tools">
        <details class="tech"><summary>Neue Zeit vorschlagen</summary>
          <form method="post" class="stack"><?= $hidden('booking_move') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
            <div class="times" data-times><?= $timeRow(0, date('Y-m-d', (int)$b['start']), date('H:i', (int)$b['start'])) ?></div>
            <button type="button" class="btn btn-ghost btn-small" data-add-time>+ weitere Zeit</button>
            <label>Nachricht <small>(optional)</small><textarea name="note" rows="2" maxlength="1500" placeholder="z. B. Am Dienstag klappt es leider nicht – wie wäre es stattdessen …"></textarea></label>
            <button class="btn btn-small">Neuen Vorschlag senden</button></form></details>
        <details class="tech"><summary>Absagen</summary>
          <form method="post" class="stack" onsubmit="return confirm('Termin absagen?')"><?= $hidden('booking_cancel') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
            <label>Grund <small>(optional, steht in der E-Mail)</small><input type="text" name="msg" maxlength="600"></label>
            <label class="check"><input type="checkbox" name="mail" value="1"<?= $b['email'] !== '' ? ' checked' : ' disabled' ?>> Absage per E-Mail schicken (mit Kalender-Löschung)</label>
            <button class="btn btn-small btn-danger">Termin absagen</button></form></details>
      </div>
      <?php endif; ?>
    </section>
    <aside class="card">
      <h2>Interne Notiz</h2>
      <form method="post" class="stack"><?= $hidden('booking_memo') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>">
        <textarea name="memo" rows="4" maxlength="1500" placeholder="Nur für dich, z. B. Themen fürs Gespräch"><?= e($b['memo'] ?? '') ?></textarea><button class="btn btn-small">Speichern</button></form>
      <h2 class="mt">Verlauf</h2>
      <ul class="notes"><?php foreach (array_reverse((array)($b['log'] ?? [])) as $lg): ?><li class="sys"><small><?= e(date('d.m.Y H:i', (int)$lg['ts'])) ?></small><?= e($lg['t']) ?></li><?php endforeach; ?></ul>
      <form method="post" class="mt" onsubmit="return confirm('Termin endgültig löschen? (Es geht keine E-Mail raus.)')"><?= $hidden('booking_delete') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>"><button class="btn btn-small btn-danger">Eintrag löschen</button></form>
    </aside>
  </div>
<?php else: ?>
  <div class="page-head"><div><h1>Termine</h1><p class="sub">Du schlägst bis zu drei Zeiten vor – die Person wählt mit einem Klick, beide bekommen einen Kalendereintrag.</p></div>
    <a class="btn" href="#neu">+ Termin vorschlagen</a></div>
  <nav class="subnav" aria-label="Abschnitte"><a href="#woche">Woche</a><a href="#neu">Neuer Termin</a><a href="#kommend">Kommende</a><a href="#einstellungen">Arbeitszeiten &amp; Vorlagen</a><a href="#abo">Kalender-Abo</a></nav>
  <section class="kpis">
    <div><span>Wartet auf Antwort</span><b><?= $n($cnt('offen')) ?></b><small>Vorschläge</small></div>
    <div><span>Bestätigt</span><b><?= $n($cnt('bestaetigt')) ?></b><small>kommende Termine</small></div>
    <div><span>Passt nicht</span><b><?= $n($cnt('abgelehnt')) ?></b><small>neuen Vorschlag schicken</small></div>
    <div><span>Diese Woche</span><b><?= $n(count(array_filter($up, fn($b) => $b['status'] === 'bestaetigt' && (int)$b['start'] < strtotime('monday next week')))) ?></b><small>bestätigte Termine</small></div>
  </section>

  <section class="card" id="woche">
    <div class="card-h"><h2><?= $wk === 0 ? 'Diese Woche' : ($wk === 1 ? 'Nächste Woche' : 'Woche ab ' . date('j.n.', $mon)) ?> <small class="muted-s"><?= date('j.n.', $mon) ?>–<?= date('j.n.Y', strtotime('+6 days', $mon)) ?></small></h2>
      <nav class="range"><a href="?tab=termine&amp;woche=<?= $wk - 1 ?>#woche" aria-label="Vorherige Woche">←</a><a href="?tab=termine#woche"<?= $wk === 0 ? ' aria-current="true"' : '' ?>>Heute</a><a href="?tab=termine&amp;woche=<?= $wk + 1 ?>#woche" aria-label="Nächste Woche">→</a></nav></div>
    <div class="week">
      <?php for ($i = 0; $i < 7; $i++): $d = strtotime("+$i days", $mon); $k = date('Y-m-d', $d); $h = lx_bk_hours($d, $B); ?>
      <div class="wday<?= $k === date('Y-m-d') ? ' today' : '' ?><?= $h ? '' : ' off' ?>">
        <div class="wd-h"><b><?= substr($wdn[(int)date('w', $d)], 0, 2) ?></b><span><?= date('j.n.', $d) ?></span><?php if ($h): ?><small><?= date('G', $h[0]) ?>–<?= date('G', $h[1]) ?> Uhr</small><?php else: ?><small>frei</small><?php endif; ?></div>
        <?php foreach ($wkItems[$k] ?? [] as [$t, $b, $optN]): ?>
          <a class="wev st-<?= e($b['status']) ?>" href="?tab=termine&amp;id=<?= e($b['id']) ?>" title="<?= e($b['type_name'] . ' – ' . $b['name']) ?>"><b><?= date('H:i', $t) ?></b><span><?= e($b['name']) ?></span><small><?= $optN ? 'Vorschlag ' . $optN : e($b['type_name']) ?></small></a>
        <?php endforeach; ?>
        <?php if ($h && $d + 86400 > $now && empty($wkItems[$k])): ?><a class="wadd" href="?tab=termine&amp;date=<?= $k ?>#neu" aria-label="Termin am <?= date('j.n.', $d) ?> anlegen">+</a><?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
    <p class="legend-row"><span><i class="lg st-bestaetigt"></i>bestätigt</span><span><i class="lg st-offen"></i>Vorschlag, wartet auf Antwort</span><span><i class="lg st-abgelehnt"></i>passt nicht</span></p>
  </section>

  <section class="card" id="neu">
    <h2>Termin vorschlagen</h2>
    <p class="hint">Tipp: In jeder Anfrage gibt es „📅 Termin vorschlagen“ – dann sind Name und E-Mail schon ausgefüllt.<?= !empty($pre['firma']) ? ' Vorbelegt aus der Anfrage von <strong>' . e($pre['firma']) . '</strong>.' : '' ?></p>
    <form method="post" class="stack" id="bkNew"><?= $hidden('booking_new') ?><input type="hidden" name="lead" value="<?= e($pre['lead']) ?>">
      <div class="cols">
        <label>Name<input type="text" name="name" value="<?= e($pre['name']) ?>" required maxlength="80" placeholder="Vor- und Nachname"></label>
        <label>E-Mail<input type="email" name="email" value="<?= e($pre['email']) ?>" maxlength="200" placeholder="name@firma.de" id="bkEmail" required></label>
        <label>Telefon <small>(optional)</small><input type="text" name="phone" value="<?= e($pre['phone']) ?>" maxlength="40"></label>
      </div>
      <div class="cols">
        <label>Terminart<select name="art" id="bkArt"><?php foreach ($types as $t): ?><option value="<?= e($t['id']) ?>" data-name="<?= e($t['name']) ?>" data-min="<?= (int)$t['min'] ?>" data-where="<?= e($t['where'] ?? '') ?>"<?= $t['id'] === $selType['id'] ? ' selected' : '' ?>><?= e($t['name']) ?> (<?= (int)$t['min'] ?> Min.)</option><?php endforeach; ?></select></label>
        <label>Dauer <span class="unit-in"><input type="number" name="min" id="bkMin" value="<?= (int)$selType['min'] ?>" min="10" max="480" step="5"><i>Min.</i></span></label>
        <label class="span2">Wo? <small>(Telefon, Adresse oder Link zum Video-Call)</small><input type="text" name="where" id="bkWhere" value="<?= e($selType['where'] ?? '') ?>" maxlength="200"></label>
      </div>
      <input type="hidden" name="type_name" id="bkTName" value="<?= e($selType['name']) ?>">
      <fieldset class="fs"><legend>Wann?</legend>
        <div class="mode-row">
          <label class="mode-s"><input type="radio" name="mode" value="vorschlag" checked><span><b>Vorschlag senden</b><small>bis zu 3 Zeiten, die Person wählt</small></span></label>
          <label class="mode-s"><input type="radio" name="mode" value="fest"><span><b>Fest eintragen</b><small>schon abgesprochen – Bestätigung schicken</small></span></label>
          <label class="mode-s"><input type="radio" name="mode" value="still"><span><b>Nur eintragen</b><small>ohne E-Mail</small></span></label>
        </div>
        <div class="times" data-times><?= $timeRow(0, $pre['date'], $pre['time']) ?></div>
        <button type="button" class="btn btn-ghost btn-small" data-add-time>+ weitere Zeit</button>
        <div class="free" id="bkFree" data-free="<?= e(json_encode($free, JSON_UNESCAPED_UNICODE)) ?>"><span class="free-l">Freie Zeiten:</span><span class="free-c"></span></div>
        <p class="hint" style="margin:0">Berücksichtigt deine Arbeitszeiten, bestehende Termine und <?= (int)$B['buffer'] ?> Min. Puffer. <a href="#einstellungen">Ändern</a></p>
      </fieldset>
      <label>Persönliche Nachricht <small>(optional – steht in der E-Mail)</small><textarea name="note" rows="2" maxlength="1500" placeholder="z. B. Schauen Sie sich gern vorab meine Beispielseiten an."></textarea></label>
      <details class="tech" id="bkMail"><summary>E-Mail ansehen &amp; anpassen</summary>
        <p class="hint">Platzhalter: <code>{name}</code> <code>{terminart}</code> <code>{zeiten}</code> <code>{dauer}</code> <code>{ort}</code> <code>{nachricht}</code> <code>{link}</code> (Link zum Bestätigen). Bei nur einer Zeit hängt die Kalender-Datei an.</p>
        <label>Betreff<input type="text" name="mail_subject" value="<?= e($B['mail_subject']) ?>" maxlength="200"></label>
        <label>Text<textarea name="mail_text" rows="12" maxlength="5000"><?= e($B['mail_text']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="save_tpl" value="1"> Als neue Standard-Vorlage speichern</label>
      </details>
      <label>Interne Notiz <small>(nur für dich)</small><input type="text" name="memo" maxlength="1500" placeholder="z. B. Möchte Shop – Preise vorbereiten"></label>
      <button class="btn" id="bkSubmit">Vorschlag per E-Mail senden</button>
    </form>
  </section>

  <section class="card" id="kommend">
    <h2>Kommende Termine <span class="count"><?= count($up) ?></span></h2>
    <?php if (!$up): ?><p class="empty">Noch keine Termine. Schlag oben einen vor – oder direkt aus einer Anfrage.</p>
    <?php else: $lastDay = ''; ?><div class="bk-list"><?php foreach ($up as $b): $day = lx_bk_day((int)$b['start'], false); $opts = lx_bk_opts($b); ?>
      <?php if ($day !== $lastDay): $lastDay = $day; ?><h3 class="bk-dayh"><?= e($day) ?><?= date('Y-m-d', (int)$b['start']) === date('Y-m-d') ? ' <span class="tag on">heute</span>' : (date('Y-m-d', (int)$b['start']) === date('Y-m-d', strtotime('+1 day')) ? ' <span class="tag">morgen</span>' : '') ?></h3><?php endif; ?>
      <div class="bk-row">
        <div class="bk-time"><b><?= date('H:i', (int)$b['start']) ?></b><small>bis <?= date('H:i', (int)$b['end']) ?></small></div>
        <div class="bk-who"><a href="?tab=termine&amp;id=<?= e($b['id']) ?>"><b><?= e($b['name']) ?></b></a> <span class="tag"><?= e($b['type_name']) ?></span> <span class="tag <?= $stTag[$b['status']] ?? '' ?>"><?= e(LX_BK_STATUS[$b['status']] ?? $b['status']) ?></span>
          <small><?= $b['email'] !== '' ? e($b['email']) : 'ohne E-Mail' ?><?= $b['where'] !== '' ? ' · ' . e(lx_cut($b['where'], 60)) : '' ?> · <?= $b['status'] === 'offen' ? 'vorgeschlagen ' . e(lx_ago((int)$b['created'])) : ($b['answered'] ? 'Antwort ' . e(lx_ago((int)$b['answered'])) : 'eingetragen ' . e(lx_ago((int)$b['created']))) ?></small>
          <?php if (count($opts) > 1): ?><small class="bk-optl">Zur Wahl: <?= e(implode(' · ', array_map(fn($t) => ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', $t)] . ' ' . date('j.n. H:i', $t), $opts))) ?></small><?php endif; ?>
          <?php if ($b['answer'] !== ''): ?><p class="bk-answer">„<?= e(lx_cut($b['answer'], 220)) ?>“</p><?php endif; ?>
          <?php if ($b['status'] === 'offen' && (int)$b['created'] < $now - 2 * 86400): ?><p class="bk-warn">Seit <?= e(lx_ago((int)$b['created'])) ?> keine Antwort – kurz erinnern?</p><?php endif; ?></div>
        <div class="btnrow">
          <?php if ($b['status'] === 'abgelehnt'): ?><a class="btn btn-small" href="?tab=termine&amp;id=<?= e($b['id']) ?>">Neue Zeit vorschlagen</a><?php endif; ?>
          <?php if ($b['status'] === 'offen' && $b['email'] !== ''): ?><form method="post"><?= $hidden('booking_resend') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>"><button class="btn btn-ghost btn-small">🔔 Erinnern</button></form><?php endif; ?>
          <a class="btn btn-ghost btn-small" href="<?= e(lx_url('_launch/api.php?a=ics&t=' . $b['token'])) ?>" title="In meinen Kalender">📅</a>
          <a class="btn btn-ghost btn-small" href="?tab=termine&amp;id=<?= e($b['id']) ?>">Details</a></div>
      </div><?php endforeach; ?></div><?php endif; ?>
    <?php if ($past): ?><details class="tech mt"><summary>Vergangene &amp; abgesagte Termine <small>(<?= count($past) ?>)</small></summary>
      <div class="table-scroll"><table class="tbl"><thead><tr><th>Termin</th><th>Art</th><th>Name</th><th>Status</th><th></th></tr></thead><tbody>
      <?php foreach (array_slice($past, 0, 80) as $b): ?><tr><td><a href="?tab=termine&amp;id=<?= e($b['id']) ?>"><?= e(date('d.m.Y H:i', (int)$b['start'])) ?></a></td><td><?= e($b['type_name']) ?></td><td><?= e($b['name']) ?> <small><?= e($b['email']) ?></small></td>
        <td><span class="tag <?= $b['status'] === 'bestaetigt' ? 'on' : '' ?>"><?= e($b['status'] === 'abgesagt' ? 'abgesagt' : ($b['status'] === 'bestaetigt' ? 'stattgefunden' : ($b['status'] === 'offen' ? 'nicht beantwortet' : (LX_BK_STATUS[$b['status']] ?? '')))) ?></span></td>
        <td><form method="post" onsubmit="return confirm('Eintrag löschen?')"><?= $hidden('booking_delete') ?><input type="hidden" name="id" value="<?= e($b['id']) ?>"><button class="icon" aria-label="Löschen" title="Löschen">✕</button></form></td></tr><?php endforeach; ?>
      </tbody></table></div></details><?php endif; ?>
  </section>

  <div class="cols-2-1">
  <section class="card" id="einstellungen">
    <h2>Arbeitszeiten, Terminarten &amp; Vorlage</h2>
    <form method="post" class="site-form"><?= $hidden('booking_settings') ?>
      <fieldset><legend>Arbeitszeiten</legend>
        <p class="hint">Nur für die Vorschläge „Freie Zeiten“ – du kannst trotzdem jede Zeit eintragen.</p>
        <div class="hours-ed"><?php foreach ([1, 2, 3, 4, 5, 6, 0] as $wd): $hv = (string)(((array)$B['hours'])[$wd] ?? ''); $on = preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $hv, $hm); ?>
          <div class="hrow"><label class="check"><input type="checkbox" name="h_on[<?= $wd ?>]" value="1"<?= $on ? ' checked' : '' ?>> <?= $wdn[$wd] ?></label><input type="time" name="h_from[<?= $wd ?>]" value="<?= e($on ? $hm[1] : '09:00') ?>" step="900" aria-label="<?= $wdn[$wd] ?> von"><span>bis</span><input type="time" name="h_to[<?= $wd ?>]" value="<?= e($on ? $hm[2] : '17:00') ?>" step="900" aria-label="<?= $wdn[$wd] ?> bis"></div>
        <?php endforeach; ?></div>
        <div class="cols">
          <label>Puffer zwischen Terminen <span class="unit-in"><input type="number" name="buffer" min="0" max="120" step="5" value="<?= (int)$B['buffer'] ?>"><i>Min.</i></span></label>
          <label>Frühestens vorschlagen ab <span class="unit-in"><input type="number" name="lead_h" min="1" max="336" value="<?= (int)$B['lead_h'] ?>"><i>Std. ab jetzt</i></span></label>
        </div>
      </fieldset>
      <fieldset><legend>Terminarten</legend>
        <p class="hint">Damit Dauer und Ort beim Vorschlagen schon ausgefüllt sind.</p>
        <div id="bkTypes" class="types-ed"><?php foreach ($types as $i => $t) echo $typeRow((string)$i, $t); ?></div>
        <template id="tpl-bktype"><?= $typeRow('__', ['min' => 30]) ?></template>
        <button type="button" class="btn btn-ghost btn-small" data-add="tpl-bktype" data-to="bkTypes">+ Terminart</button>
      </fieldset>
      <fieldset><legend>Standard-E-Mail für Vorschläge</legend>
        <p class="hint">Platzhalter: <code>{name}</code> <code>{terminart}</code> <code>{zeiten}</code> <code>{dauer}</code> <code>{ort}</code> <code>{nachricht}</code> <code>{link}</code>. Fehlt <code>{link}</code>, wird der Bestätigungslink unten angehängt.</p>
        <label>Betreff<input type="text" name="bk_subject" value="<?= e($B['mail_subject']) ?>" maxlength="200"></label>
        <label>Text<textarea name="bk_text" rows="11" maxlength="5000"><?= e($B['mail_text']) ?></textarea></label>
      </fieldset>
      <label class="check"><input type="checkbox" name="bk_notify" value="1"<?= !empty($B['notify']) ? ' checked' : '' ?>> Mir eine E-Mail schicken, wenn jemand antwortet (mit Kalendereintrag)</label>
      <label class="check"><input type="checkbox" name="bk_remind" value="1"<?= !empty($B['remind']) ? ' checked' : '' ?>> Kunden einen Tag vorher automatisch erinnern</label>
      <button class="btn">Speichern</button>
    </form>
  </section>
  <section class="card" id="abo">
    <h2>Kalender-Abo</h2>
    <p class="hint">Alle Termine automatisch in deinem Kalender (iPhone, Google, Outlook) – bestätigte fest, offene Vorschläge als „(Vorschlag)“. Aktualisiert sich von selbst.</p>
    <ol class="steps-s">
      <li><b>iPhone/Mac:</b> Link unten antippen → „Abonnieren“.</li>
      <li><b>Google Kalender:</b> Weitere Kalender → „Per URL“ → Link einfügen.</li>
      <li><b>Outlook:</b> Kalender hinzufügen → „Aus dem Internet abonnieren“.</li>
    </ol>
    <div class="feed"><code><?= e($feedUrl) ?></code></div>
    <div class="btnrow"><a class="btn btn-small" href="<?= e($webcal) ?>">Auf diesem Gerät abonnieren</a><button type="button" class="btn btn-ghost btn-small" data-copy="<?= e($feedUrl) ?>">Link kopieren</button></div>
    <p class="hint mt">Der Link ist geheim – wer ihn kennt, sieht deine Termine. Weitergegeben? Neuen erzeugen, der alte funktioniert dann nicht mehr.</p>
    <form method="post" onsubmit="return confirm('Neuen Link erzeugen? Bestehende Abos müssen neu eingerichtet werden.')"><?= $hidden('feed_new') ?><button class="btn btn-ghost btn-small btn-danger">Neuen Link erzeugen</button></form>
  </section>
  </div>
<?php endif; ?>

<?php /* ======================= STATISTIK ======================= */ elseif ($tab === 'statistik'):
  $clicks = lx_stats_clicks(); $pages = lx_stats_pages();
  $hMax = max(1, ...$st['h']); $wdMax = max(1, ...$st['wd']);
  $homeV = (int)($st['p']['index.html'] ?? 0); $lpV = (int)($st['p']['angebot/index.html'] ?? 0);
  $funnel = ['Formular begonnen' => $st['c']['form-start'] ?? 0, 'Schritt 2 (Umfang)' => $st['c']['form-step2'] ?? 0, 'Schritt 3 (Kontakt)' => $st['c']['form-step3'] ?? 0, 'Anfrage gesendet' => $st['a']];
  $statsOn = !empty($c['site']['stats_on']);
?>
  <div class="page-head"><div><h1>Statistik</h1><p class="sub">Ohne Cookies, keine IP-Adressen gespeichert. Du selbst wirst nicht mitgezählt.</p></div><?= range_nav($range, 'statistik') ?></div>
  <?php if (!$statsOn): ?><p class="flash warn">Die Statistik ist ausgeschaltet – ganz unten kannst du sie wieder einschalten.</p><?php endif; ?>
  <section class="kpis k6">
    <div><span>Besucher</span><b><?= $n($st['u']) ?></b><?= $trend($st['u'], $st['prev']['u']) ?></div>
    <div><span>Seitenaufrufe</span><b><?= $n($st['v']) ?></b><?= $trend($st['v'], $st['prev']['v']) ?></div>
    <div><span>Anfragen</span><b><?= $n($st['a']) ?></b><?= $trend($st['a'], $st['prev']['a']) ?></div>
    <div><span>Anfrage-Quote</span><b><?= $st['u'] ? $n($st['a'] / $st['u'] * 100, 1) : '0' ?> %</b></div>
    <div><span>Ø Verweildauer</span><b><?= $st['v'] ? $dur((int)round($st['t'] / $st['v'])) : '–' ?></b></div>
    <div><span>Echtes Interesse</span><b><?= $pct($st['e'], $st['v']) ?> %</b><small>länger als 15 Sek.</small></div>
  </section>
  <div class="cols-1-1">
    <section class="card"><h2>Wie weit wird gescrollt?</h2><p class="hint">Startseite: Anteil der Besuche, die den Abschnitt gesehen haben.</p>
      <div class="funnel"><?php foreach (lx_stats_sections() as $k => $t): $p = min(100, $pct($st['s'][$k] ?? 0, $homeV)); ?><div class="frow"><span><?= e($t) ?></span><i style="--w:<?= $p ?>%"></i><b><?= $p ?> %</b></div><?php endforeach; ?></div>
      <?php if ($lpV): ?><h3 class="mt">Werbe-Landingpage</h3>
      <div class="funnel"><?php foreach (lx_stats_sections_lp() as $k => $t): $p = min(100, $pct($st['s'][$k] ?? 0, $lpV)); ?><div class="frow"><span><?= e($t) ?></span><i style="--w:<?= $p ?>%"></i><b><?= $p ?> %</b></div><?php endforeach; ?></div><?php endif; ?>
    </section>
    <section class="card"><h2>Weg zur Anfrage</h2><p class="hint">Wo springen Interessenten im Formular ab?</p>
      <div class="funnel"><?php $f0 = max(1, reset($funnel)); foreach ($funnel as $t => $v): ?><div class="frow"><span><?= e($t) ?></span><i style="--w:<?= min(100, round($v / $f0 * 100)) ?>%"></i><b><?= $n($v) ?></b></div><?php endforeach; ?></div>
      <h3 class="mt">Seiten</h3><div class="bars"><?= bars($st['p'], 0, 8, fn($k) => $pages[$k] ?? $k) ?></div>
    </section>
  </div>
  <div class="cols-3">
    <section class="card"><h2>Herkunft</h2><div class="bars"><?= bars($st['r'], 0, 10, $srcName) ?></div>
      <?php if ($st['cp']): ?><h3 class="mt">Kampagnen <small>(utm_campaign)</small></h3><div class="bars"><?= bars($st['cp'], 0, 6) ?></div><?php endif; ?>
      <p class="hint mt">Tipp: Hänge an Links in Anzeigen oder Posts <code>?utm_source=instagram&amp;utm_campaign=herbst</code> an – dann siehst du hier genau, was wirkt.</p></section>
    <section class="card"><h2>Klicks</h2><div class="bars"><?= bars($st['c'], max(1, $st['v']), 12, fn($k) => $clicks[$k] ?? $k) ?></div></section>
    <section class="card"><h2>Geräte</h2><div class="bars"><?= bars(array_filter(['Handy' => $st['g']['m'] ?? 0, 'Computer' => $st['g']['d'] ?? 0, 'Tablet' => $st['g']['t'] ?? 0])) ?></div>
      <h3 class="mt">Systeme</h3><div class="bars"><?= bars($st['os'], 0, 6) ?></div>
      <h3 class="mt">Browser &amp; Apps</h3><div class="bars"><?= bars($st['br'], 0, 7) ?></div></section>
  </div>
  <div class="cols-1-1">
    <section class="card"><h2>Uhrzeit</h2><p class="hint">Seitenaufrufe nach Tageszeit – hilft beim Timing deiner Posts.</p>
      <div class="hours"><?php foreach ($st['h'] as $h => $v): ?><span style="--h:<?= round($v / $hMax * 100) ?>%" data-tip="<?= $h ?>–<?= $h + 1 ?> Uhr · <?= $v ?>"><i></i><small><?= $h % 3 === 0 ? $h : '' ?></small></span><?php endforeach; ?></div></section>
    <section class="card"><h2>Wochentage</h2><p class="hint">Seitenaufrufe nach Wochentag.</p>
      <div class="hours wd"><?php foreach (['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'] as $i => $t): ?><span style="--h:<?= round($st['wd'][$i] / $wdMax * 100) ?>%" data-tip="<?= $t ?> · <?= $st['wd'][$i] ?>"><i></i><small><?= $t ?></small></span><?php endforeach; ?></div></section>
  </div>
  <section class="card stats-foot">
    <form method="post" class="inline"><?= $hidden('stats') ?>
      <label class="check"><input type="checkbox" name="stats_on" value="1"<?= $statsOn ? ' checked' : '' ?> onchange="this.form.submit()"> Statistik eingeschaltet</label></form>
    <form method="post" class="inline" onsubmit="return confirm('Alle Statistikdaten löschen?')"><?= $hidden('stats_reset') ?><button class="btn btn-small btn-danger">Zurücksetzen</button></form>
  </section>

<?php /* ======================= INHALTE ======================= */ elseif ($tab === 'inhalte'): ?>
  <div class="page-head"><div><h1>Inhalte</h1><p class="sub">Preise, Kontaktdaten, Bilder und Texte – nach dem Speichern sofort auf loggx.de sichtbar.</p></div><a class="btn btn-ghost btn-small" href="<?= e(lx_url('')) ?>" target="_blank" rel="noopener">Seite ansehen ↗</a></div>
  <nav class="subnav" aria-label="Abschnitte"><a href="#kontakt">Preise &amp; Kontakt</a><a href="#bilder">Bilder</a><a href="#texte">Texte</a></nav>
  <div class="grid">
    <!-- Webseite: Preise, Kontakt, Hinweise -->
    <section class="card card-wide" id="kontakt">
      <h2>Preise, Kontakt &amp; Hinweise</h2>
      <p class="hint">Diese Werte erscheinen überall auf der Webseite, der Werbe-Landingpage, im Impressum und in der Datenschutzerklärung. Änderungen sind nach dem Speichern sofort sichtbar.</p>
      <?php $S = $c['site']; ?>
      <form method="post" class="site-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="site">
        <fieldset><legend>Preise</legend>
          <div class="cols">
            <label>Visitenkarte ab <span class="unit-in"><input type="number" name="price_visit" min="0" max="99999" value="<?= (int)$S['price_visit'] ?>"><i>€</i></span></label>
            <label>Firmenwebseite ab <span class="unit-in"><input type="number" name="price_firm" min="0" max="99999" value="<?= (int)$S['price_firm'] ?>"><i>€</i></span></label>
            <label>Änderungen &amp; Support ab <span class="unit-in"><input type="number" name="price_hourly" min="0" max="999" value="<?= (int)$S['price_hourly'] ?>"><i>€/Std.</i></span></label>
          </div>
        </fieldset>
        <fieldset><legend>Preise auf der Werbe-Landingpage <small style="font:600 .8rem 'Jakarta',sans-serif;color:var(--muted)">/angebot/</small></legend>
          <p class="hint">Eigene Aktionspreise nur für die Werbe-Landingpage. Leer lassen = gleiche Preise wie oben. <a href="<?= e(lx_url('angebot/')) ?>" target="_blank" rel="noopener">Landingpage ansehen ↗</a></p>
          <div class="cols">
            <label>Visitenkarte ab <span class="unit-in"><input type="number" name="lp_price_visit" min="0" max="99999" value="<?= (int)$S['lp_price_visit'] ?: '' ?>" placeholder="<?= (int)$S['price_visit'] ?>"><i>€</i></span></label>
            <label>Firmenwebseite ab <span class="unit-in"><input type="number" name="lp_price_firm" min="0" max="99999" value="<?= (int)$S['lp_price_firm'] ?: '' ?>" placeholder="<?= (int)$S['price_firm'] ?>"><i>€</i></span></label>
          </div>
          <label class="check"><input type="checkbox" name="lp_strike" value="1"<?= !empty($S['lp_strike']) ? ' checked' : '' ?>> Normalpreis durchgestrichen daneben zeigen (wenn günstiger)</label>
        </fieldset>
        <fieldset><legend>Lieferzeit &amp; Antwortzeit</legend>
          <div class="cols">
            <label>Visitenkarte online in<input type="text" name="time_visit" maxlength="30" value="<?= e($S['time_visit']) ?>" placeholder="14 Tagen"></label>
            <label>Firmenwebseite online in<input type="text" name="time_firm" maxlength="30" value="<?= e($S['time_firm']) ?>" placeholder="3–4 Wochen"></label>
            <label>Antwort innerhalb von <span class="unit-in"><input type="number" name="response_hours" min="1" max="168" value="<?= (int)$S['response_hours'] ?>"><i>Std.</i></span></label>
          </div>
        </fieldset>
        <fieldset><legend>Kontaktdaten</legend>
          <div class="cols">
            <label>Telefon <small>(nur für dich, wenn unten aus)</small><input type="text" name="phone" maxlength="40" value="<?= e($S['phone']) ?>" placeholder="+49 (0) 541 123456"></label>
            <label>E-Mail <small>(auch Empfänger der Anfragen)</small><input type="email" name="email" required value="<?= e($S['email']) ?>"></label>
            <label>Straße<input type="text" name="street" maxlength="80" value="<?= e($S['street']) ?>"></label>
            <label>PLZ und Ort<input type="text" name="city" maxlength="80" value="<?= e($S['city']) ?>"></label>
          </div>
          <label class="check" style="margin-top:10px"><input type="checkbox" name="phone_show" value="1"<?= !empty($S['phone_show']) ? ' checked' : '' ?>> Telefonnummer auf der Webseite zeigen <small>(Kontakt, Impressum, E-Mails) – aus: nur E-Mail und Formular</small></label>
        </fieldset>
        <fieldset><legend>Hinweis über der Überschrift</legend>
          <p class="hint">Kurzer Satz mit leuchtendem Punkt, z. B. „Noch 2 Projektplätze im November frei“.</p>
          <div class="cols">
            <label>Startseite<input type="text" name="kicker_home" maxlength="90" value="<?= e($S['kicker_home']) ?>"></label>
            <label>Werbe-Landingpage<input type="text" name="kicker_angebot" maxlength="90" value="<?= e($S['kicker_angebot']) ?>"></label>
          </div>
        </fieldset>
        <fieldset class="bar-fs"><legend>Ankündigungsleiste <?= !empty($S['bar_on']) && $S['bar_text'] !== '' ? '<span class="tag on">sichtbar</span>' : '<span class="tag no">aus</span>' ?></legend>
          <p class="hint">Leiste ganz oben auf Startseite und Landingpage – z. B. für Aktionen. Besucher können sie mit ✕ schließen; eine neue Ankündigung erscheint wieder.</p>
          <div class="bar-preview" aria-hidden="true">
            <span class="bp-badge"<?= trim((string)$S['bar_badge']) === '' ? ' hidden' : '' ?>>✦ <span data-bp="bar_badge"><?= e($S['bar_badge']) ?></span></span>
            <span class="bp-text" data-bp="bar_text"><?= e($S['bar_text'] !== '' ? $S['bar_text'] : 'Ihr Ankündigungstext erscheint hier') ?></span>
            <span class="bp-link"<?= $S['bar_link_text'] === '' ? ' hidden' : '' ?>><span data-bp="bar_link_text"><?= e($S['bar_link_text']) ?></span> →</span>
            <span class="bp-x">✕</span>
          </div>
          <label class="check"><input type="checkbox" name="bar_on" value="1"<?= !empty($S['bar_on']) ? ' checked' : '' ?>> Leiste anzeigen</label>
          <div class="cols">
            <label class="span2">Text<input type="text" name="bar_text" maxlength="160" value="<?= e($S['bar_text']) ?>" placeholder="Herbstaktion: Kostenloser Google-Eintrag zu jeder Webseite"></label>
            <label>Etikett <small>(optional, z. B. „Aktion“, „Neu“)</small><input type="text" name="bar_badge" maxlength="20" value="<?= e($S['bar_badge']) ?>"></label>
            <label>Link-Text <small>(optional)</small><input type="text" name="bar_link_text" maxlength="40" value="<?= e($S['bar_link_text']) ?>" placeholder="Jetzt anfragen"></label>
            <label class="span2">Link-Ziel<input type="text" name="bar_link" maxlength="300" value="<?= e($S['bar_link']) ?>" placeholder="#kontakt oder /angebot/"></label>
          </div>
          <?php if (!empty($S['bar_on']) || $S['bar_text'] !== ''): ?>
          <input type="hidden" name="bar_remove" value="" id="bar-remove"><button type="button" class="btn btn-small btn-danger" id="bar-remove-btn">Leiste entfernen</button>
          <?php endif; ?>
        </fieldset>
        <fieldset><legend>Impressum &amp; Datenschutz</legend>
          <p class="hint">Ersetzt die gelb markierten Stellen. Leere Felder blenden den jeweiligen Abschnitt aus.</p>
          <div class="cols">
            <label>Steuernummer <small>(optional – leer = nicht im Impressum)</small><input type="text" name="tax_number" maxlength="40" value="<?= e($S['tax_number']) ?>" placeholder="z. B. 66/123/45678"></label>
            <label>Zuständiges Finanzamt<input type="text" name="tax_office" maxlength="60" value="<?= e($S['tax_office']) ?>"></label>
            <label class="span2">Hosting-Anbieter (Name und Anschrift)<input type="text" name="hoster" maxlength="160" value="<?= e($S['hoster']) ?>" placeholder="IONOS SE, Elgendorfer Str. 57, 56410 Montabaur"></label>
            <label class="span2">Statistik-Tool <small>(nur ausfüllen, wenn eins eingebunden ist – Anbieter, Zweck, Speicherdauer)</small><textarea name="stats_text" rows="2" maxlength="800"><?= e($S['stats_text']) ?></textarea></label>
          </div>
          <label class="check"><input type="checkbox" name="thumio" value="1"<?= !empty($S['thumio']) ? ' checked' : '' ?>> Referenz-Screenshots werden über image.thum.io geladen <small style="font-weight:500;color:var(--muted)">(ausschalten, sobald eigene Screenshots in img/ liegen)</small></label>
          <?php if (trim((string)$S['hoster']) === ''): ?><p class="flash warn" style="margin:0">Der Hosting-Anbieter fehlt noch in der Datenschutzerklärung.</p><?php endif; ?>
        </fieldset>
        <button class="btn">Webseite speichern</button>
      </form>
    </section>

    <!-- Bilder -->
    <section class="card" id="bilder">
      <h2>Bilder</h2>
      <p class="hint">JPG, PNG oder WebP, max. 8 MB. Ersetzt das Bild sofort auf der Webseite.</p>
      <div class="img-slots">
      <?php foreach (lx_image_slots() as $slot => [$label, $hint, $default]):
          $cur = (string)($S['img_' . $slot] ?? ''); $show = $cur !== '' ? $cur : (is_file(lx_root() . '/' . $default) ? $default : ''); ?>
        <div class="img-slot">
          <div class="img-thumb<?= $slot === 'portrait' ? ' tall' : '' ?>"><?php if ($show !== ''): ?><img src="<?= e(lx_url($show)) ?>" alt=""><?php else: ?><span>Kein Bild</span><?php endif; ?></div>
          <div class="img-meta">
            <strong><?= e($label) ?></strong><small><?= e($hint) ?></small>
            <form method="post" enctype="multipart/form-data" class="img-form">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="image"><input type="hidden" name="slot" value="<?= e($slot) ?>">
              <label class="btn btn-small btn-ghost file-btn">Bild wählen<input type="file" name="file" accept="image/jpeg,image/png,image/webp" onchange="this.form.submit()"></label>
            </form>
            <?php if ($cur !== ''): ?>
            <form method="post" onsubmit="return confirm('Bild entfernen?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="image_delete"><input type="hidden" name="slot" value="<?= e($slot) ?>"><button class="btn btn-small btn-danger">Entfernen</button></form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
    </section>

    <!-- Texte -->
    <section class="card" id="texte">
      <h2>Texte</h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="texts">
        <h3>Landingpage</h3>
        <label>Zeile über der Überschrift<input type="text" name="l_kicker" value="<?= e($c['landing']['kicker']) ?>"></label>
        <label>Überschrift<input type="text" name="l_title" value="<?= e($c['landing']['title']) ?>"></label>
        <label>Text<textarea name="l_text" rows="3"><?= e($c['landing']['text']) ?></textarea></label>
        <label class="check"><input type="checkbox" name="l_signup" value="1"<?= $c['landing']['signup'] ? ' checked' : '' ?>> E-Mail-Benachrichtigung anbieten</label>
        <h3>Wartungsseite</h3>
        <label>Überschrift<input type="text" name="m_title" value="<?= e($c['maintenance']['title']) ?>"></label>
        <label>Text<textarea name="m_text" rows="3"><?= e($c['maintenance']['text']) ?></textarea></label>
        <button class="btn">Texte speichern</button>
      </form>
    </section>

  </div>

<?php /* ======================= VERÖFFENTLICHUNG ======================= */ elseif ($tab === 'veroeffentlichung'): ?>
  <section class="hero-status s-<?= e($eff) ?>">
    <div>
      <p class="eyebrow">Was Besucher gerade sehen</p>
      <h1><?= e($modeNames[$eff]) ?></h1>
      <p class="sub">
        <?php if ($eff === 'prelaunch' && $launchTs): ?>Landingpage mit Countdown bis <strong><?= e(lx_date_de($launchTs)) ?></strong><?= $c['auto_launch'] ? ' – danach geht die Seite automatisch online.' : ' – die Seite bleibt danach gesperrt, bis du sie freischaltest.' ?>
        <?php elseif ($eff === 'prelaunch'): ?>Landingpage ohne Countdown (kein Launch-Zeitpunkt gesetzt).
        <?php elseif ($eff === 'maintenance'): ?>Wartungsseite (HTTP 503). Personen mit Zugang und du sehen die echte Seite.
        <?php else: ?>Die Webseite ist für alle erreichbar.<?php if ($c['mode'] === 'prelaunch'): ?> Der Launch-Zeitpunkt ist erreicht.<?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($eff !== 'live'): ?>
    <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="launch_now">
      <button class="btn btn-sun" onclick="return confirm('Seite jetzt für alle freischalten?')">Jetzt veröffentlichen</button></form>
    <?php endif; ?>
  </section>
  <nav class="subnav" aria-label="Abschnitte"><a href="#status">Status &amp; Launch</a><a href="#zugang">Zugänge</a><a href="#benachrichtigungen">Benachrichtigungen</a><a href="#indexnow">Suchmaschinen</a></nav>
  <div class="grid">
    <!-- Status & Launch -->
    <section class="card" id="status">
      <h2>Status &amp; Launch</h2>
      <form method="post" class="stack">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="status">
        <fieldset class="modes">
          <legend>Modus</legend>
          <?php foreach (['prelaunch' => ['Vor dem Launch', 'Landingpage mit Countdown und E-Mail-Benachrichtigung'], 'maintenance' => ['Wartungsarbeiten', 'Wartungsseite, z. B. während Updates'], 'live' => ['Öffentlich', 'Webseite für alle sichtbar']] as $k => [$t, $d]): ?>
          <label class="mode"><input type="radio" name="mode" value="<?= $k ?>"<?= $c['mode'] === $k ? ' checked' : '' ?>><span><strong><?= e($t) ?></strong><small><?= e($d) ?></small></span></label>
          <?php endforeach; ?>
        </fieldset>
        <label>Launch-Zeitpunkt <small>(deutsche Zeit)</small><input type="datetime-local" name="launch_at" value="<?= e($c['launch_at']) ?>"></label>
        <label class="check"><input type="checkbox" name="auto_launch" value="1"<?= $c['auto_launch'] ? ' checked' : '' ?>> Zum Launch-Zeitpunkt automatisch veröffentlichen</label>
        <label>Wartung voraussichtlich bis <small>(optional, wird auf der Wartungsseite angezeigt)</small><input type="datetime-local" name="until" value="<?= e($c['maintenance']['until']) ?>"></label>
        <button class="btn">Status speichern</button>
      </form>
    </section>
    <!-- Zugänge -->
    <section class="card" id="zugang">
      <h2>Wer hat Zugriff?</h2>
      <p class="hint">Personen mit Zugang sehen die echte Webseite – auch vor dem Launch und während Wartungsarbeiten. Du selbst hast als Admin immer Zugriff.</p>
      <form method="post" class="row">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="add_code">
        <label class="grow">Name<input type="text" name="name" placeholder="z. B. Frank (Kunde)" required maxlength="80"></label>
        <label>Gültig bis <small>(optional)</small><input type="date" name="expires" min="<?= date('Y-m-d') ?>"></label>
        <button class="btn">Zugang anlegen</button>
      </form>
      <?php if (!$codes): ?><p class="empty">Noch keine Zugänge angelegt.</p><?php else: ?>
      <ul class="codes">
        <?php foreach (array_reverse($codes) as $cd): $ok = lx_code_valid($cd); $link = $siteUrl . '?zugang=' . $cd['code']; ?>
        <li class="code<?= $ok ? '' : ' off' ?><?= $newCode === $cd['id'] ? ' new' : '' ?>">
          <div class="code-main">
            <strong><?= e($cd['name']) ?></strong>
            <span class="tag <?= $ok ? 'on' : 'no' ?>"><?= $ok ? 'aktiv' : (!empty($cd['active']) ? 'abgelaufen' : 'gesperrt') ?></span>
            <code><?= e($cd['code']) ?></code>
            <small><?= !empty($cd['expires']) ? 'gültig bis ' . e(date('d.m.Y', (int)lx_ts($cd['expires']))) : 'unbegrenzt' ?> · <?= $cd['last_used'] ? 'zuletzt ' . e(date('d.m. H:i', strtotime($cd['last_used']))) . ' (' . (int)$cd['uses'] . '×)' : 'noch nicht genutzt' ?></small>
          </div>
          <div class="code-actions">
            <button type="button" class="btn btn-small btn-ghost" data-copy="<?= e($link) ?>">Link kopieren</button>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_code"><input type="hidden" name="id" value="<?= e($cd['id']) ?>"><button class="btn btn-small btn-ghost"><?= !empty($cd['active']) ? 'Sperren' : 'Entsperren' ?></button></form>
            <form method="post" onsubmit="return confirm('Zugang endgültig löschen?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="delete_code"><input type="hidden" name="id" value="<?= e($cd['id']) ?>"><button class="btn btn-small btn-danger" aria-label="Zugang löschen">Löschen</button></form>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

      <details class="more">
        <summary>Gemeinsames Vorschau-Passwort <?= $c['shared_password'] !== '' ? '<span class="tag on">an</span>' : '<span class="tag no">aus</span>' ?></summary>
        <p class="hint">Optional: Ein Passwort für alle, z. B. für ein Team. Wird auf der Landingpage unter „Zugangscode“ eingegeben.</p>
        <form method="post" class="row">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="shared">
          <label class="grow">Neues Vorschau-Passwort<input type="text" name="shared_password" minlength="6" autocomplete="off"></label>
          <button class="btn">Setzen</button>
          <?php if ($c['shared_password'] !== ''): ?><button class="btn btn-ghost" name="clear" value="1">Ausschalten</button><?php endif; ?>
        </form>
      </details>
    </section>

    <!-- Anmeldungen -->
    <section class="card" id="benachrichtigungen">
      <h2>Benachrichtigungen <span class="count"><?= count($signups) ?></span></h2>
      <p class="hint">E-Mail-Adressen von Besuchern, die beim Launch informiert werden möchten. Nach dem Versand der Info bitte löschen (Datenschutz).</p>
      <?php if (!$signups): ?><p class="empty">Noch keine Anmeldungen.</p><?php else: ?>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="export"><button class="btn btn-ghost btn-small">Als CSV herunterladen</button></form>
      <ul class="signups">
        <?php foreach (array_reverse($signups) as $s): ?>
        <li><span><?= e($s['email']) ?></span><small><?= e(date('d.m.Y H:i', strtotime($s['date']))) ?></small>
          <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="delete_signup"><input type="hidden" name="email" value="<?= e($s['email']) ?>"><button class="icon" aria-label="<?= e($s['email']) ?> löschen" title="Löschen">✕</button></form></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>

    </section>
    <!-- IndexNow -->
    <section class="card" id="indexnow">
      <h2>Bing &amp; Co. sofort informieren</h2>
      <?php $inl = $c['indexnow_last'] ?? []; $inKey = lx_indexnow_key(); ?>
      <p class="hint">Über <strong>IndexNow</strong> erfahren Bing, Yandex, Seznam und Naver direkt von Änderungen – statt Tage auf den nächsten Besuch zu warten. Passiert automatisch, wenn du hier im Admin die Webseite speicherst (höchstens alle 10 Minuten). Google nutzt IndexNow nicht, dafür reicht die Search Console.</p>
      <p class="hint">Zuletzt: <?= !empty($inl['ts']) ? '<strong>' . e(lx_ago((int)$inl['ts'])) . '</strong> – ' . e((string)$inl['msg']) : 'noch nie gemeldet' ?></p>
      <p class="hint" style="font-size:.85rem">Schlüsseldatei: <a href="<?= e(lx_url($inKey . '.txt')) ?>" target="_blank" rel="noopener"><?= e($inKey) ?>.txt</a></p>
      <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="indexnow"><button class="btn btn-ghost">Alle Seiten jetzt melden</button></form>
    </section>

  </div>

<?php /* ======================= EINSTELLUNGEN ======================= */ elseif ($tab === 'einstellungen'):
  $log = array_reverse(lx_read('adminlog', []));
  $dataSize = 0; foreach ((array)glob(LX_DATA . '/*') as $f) if (is_file($f)) $dataSize += (int)filesize($f);
  $free = @disk_free_space(LX_DATA);
  $cf = lx_ip_in((string)($_SERVER['REMOTE_ADDR'] ?? ''), lx_cloudflare_ranges());
  $fromHost = substr(strrchr(lx_mail_from(), '@') ?: '', 1); $host = preg_replace('/^www\./', '', (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
  $checks = [
      [version_compare(PHP_VERSION, '8.1', '>='), 'PHP ' . PHP_VERSION, 'Ab PHP 8.1 läuft alles am schnellsten und sichersten (IONOS → PHP-Version).'],
      [lx_https(), 'HTTPS (verschlüsselt)', 'Ohne HTTPS warnen Browser. In Cloudflare „SSL/TLS: Full (strict)“ wählen.'],
      [is_writable(LX_DATA), 'Datenordner beschreibbar', 'Der Ordner _launch/data braucht Schreibrechte, sonst gehen Anfragen verloren.'],
      [is_file(lx_root() . '/_launch/.htaccess'), 'Datenordner geschützt (.htaccess)', 'Die Datei _launch/.htaccess fehlt – bitte mit hochladen.'],
      [function_exists('mail'), 'E-Mail-Versand verfügbar', 'Die PHP-Funktion mail() ist ausgeschaltet.'],
      [$fromHost !== '' && ($fromHost === $host || str_ends_with($host, '.' . $fromHost) || str_ends_with($fromHost, $host)), 'Absender passt zur Domain (' . e(lx_mail_from()) . ')', 'Absender sollte eine Adresse deiner Domain sein, sonst landen Mails im Spam.'],
      [!empty($c['totp_on']), 'Zwei-Faktor-Anmeldung', 'Noch nicht eingerichtet – siehe oben.'],
      [$cf || PHP_SAPI === 'cli-server' || !preg_match('/loggx\.de$/', $host), 'Cloudflare davor', 'Anfragen kommen nicht über Cloudflare – ist der Proxy (orange Wolke) an?'],
      [trim((string)$c['site']['hoster']) !== '', 'Hosting-Anbieter in der Datenschutzerklärung', 'Unter Inhalte → Impressum & Datenschutz eintragen.'],
  ];
  $backs = lx_backups();
  $errCount = count(array_filter(lx_read('log-fehler', []), fn($x) => (int)$x['ts'] > time() - 7 * 86400));
  $mailFail = count(array_filter(lx_read('log-mail', []), fn($x) => empty($x['x']['ok']) && (int)$x['ts'] > time() - 7 * 86400));
  $checks[] = [$backs && $backs[0]['ts'] > time() - 2 * 86400, 'Automatische Sicherung aktuell', 'Noch keine Sicherung – „Jetzt sichern“ klicken. Danach läuft es täglich von selbst.'];
  $checks[] = [!$errCount, 'Keine Fehler in den letzten 7 Tagen', $errCount . ' Fehlermeldung(en) – siehe Protokolle.'];
  $checks[] = [!$mailFail, 'Alle E-Mails zugestellt (7 Tage)', $mailFail . ' E-Mail(s) gingen nicht raus – Absender prüfen.'];
  $okCount = count(array_filter($checks, fn($x) => $x[0]));
?>
  <div class="page-head"><div><h1>Einstellungen</h1><p class="sub">Sicherheit, E-Mail, Datensicherung und ein kurzer Technik-Check.</p></div></div>
  <nav class="subnav" aria-label="Abschnitte"><a href="#sicherheit">Zwei-Faktor</a><a href="#email">E-Mail</a><a href="#passwort">Passwort</a><a href="#protokoll">Anmeldungen</a><a href="#check">System-Check</a><a href="#sicherungen">Sicherungen</a><a href="#protokolle">Protokolle</a><a href="#bericht">Wochenbericht</a><a href="#ueberwachung">Überwachung</a></nav>
  <div class="grid">
    <!-- Sicherheit: Zwei-Faktor-Anmeldung -->
    <?php $totpOn = !empty($c['totp_on']) && $c['totp_secret'] !== ''; $pending = (string)($_SESSION['lx_totp_pending'] ?? ''); $showCodes = $_SESSION['lx_recovery_show'] ?? null; unset($_SESSION['lx_recovery_show']); ?>
    <section class="card card-wide" id="sicherheit">
      <h2>Sicherheit: Zwei-Faktor-Anmeldung <?= $totpOn ? '<span class="tag on">aktiv</span>' : '<span class="tag no">aus</span>' ?></h2>
      <?php if ($showCodes): ?>
      <div class="flash warn" style="margin-bottom:16px">
        <strong>Deine Wiederherstellungscodes – nur jetzt sichtbar!</strong><br>
        Speichere sie in deinem Passwort-Manager oder druck sie aus. Jeder Code funktioniert genau einmal, falls du dein Handy nicht hast.
        <pre class="rc-codes" id="rc-codes"><?= e(implode("\n", $showCodes)) ?></pre>
        <button type="button" class="btn btn-small btn-ghost" data-copy="<?= e(implode("\n", $showCodes)) ?>">Codes kopieren</button>
      </div>
      <?php endif; ?>
      <?php if ($totpOn): ?>
        <p class="hint">Beim Login fragt der Admin nach dem Passwort zusätzlich einen 6-stelligen Code aus deiner Authenticator-App ab. Noch <strong><?= count($c['totp_recovery']) ?></strong> Wiederherstellungscodes übrig.<?= lx_trust_ok($c) ? ' Dieses Gerät ist für 30 Tage als vertrauenswürdig gespeichert.' : '' ?></p>
        <div class="cols">
          <form method="post" class="stack"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_recovery_new">
            <label>Neue Wiederherstellungscodes <small>(Code aus der App)</small><input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required></label>
            <button class="btn btn-small btn-ghost">Neue Codes erstellen</button></form>
          <form method="post" class="stack" onsubmit="return confirm('Zwei-Faktor-Anmeldung wirklich ausschalten?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_off">
            <label>Ausschalten <small>(Passwort + Code aus der App)</small><input type="password" name="password" autocomplete="current-password" placeholder="Admin-Passwort" required></label>
            <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required>
            <button class="btn btn-small btn-danger">Zwei-Faktor ausschalten</button></form>
        </div>
        <?php if (lx_trust_ok($c)): ?><form method="post" style="margin-top:12px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_untrust"><button class="btn btn-small btn-ghost">Diesem Gerät nicht mehr vertrauen</button></form><?php endif; ?>
      <?php elseif ($pending !== ''): $uri = lx_totp_uri($pending, preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'loggx.de'))); ?>
        <div class="totp-setup">
          <div class="totp-qr" id="totp-qr" data-uri="<?= e($uri) ?>" role="img" aria-label="QR-Code für die Authenticator-App"></div>
          <ol class="totp-steps">
            <li>Installiere eine Authenticator-App auf deinem Handy, z. B. <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong> oder deinen Passwort-Manager.</li>
            <li>Scanne den QR-Code in der App. Geht das nicht, gib den Schlüssel von Hand ein:<br><code class="totp-key"><?= e(trim(chunk_split($pending, 4, ' '))) ?></code></li>
            <li>Gib den 6-stelligen Code ein, den die App jetzt anzeigt:
              <form method="post" class="row" style="margin:10px 0 0"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_confirm">
                <label class="grow">Code<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required autofocus></label>
                <button class="btn">Aktivieren</button></form></li>
          </ol>
        </div>
        <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_cancel"><button class="btn btn-small btn-ghost">Abbrechen</button></form>
        <script src="qrcode.js"></script>
        <script>(function(){var el=document.getElementById("totp-qr");if(!el||typeof qrcode!=="function")return;var q=qrcode(0,"M");q.addData(el.getAttribute("data-uri"));q.make();el.innerHTML=q.createSvgTag(5,2);})();</script>
      <?php else: ?>
        <p class="hint">Schützt deinen Admin-Bereich (und die Finanzen) zusätzlich: Selbst wenn jemand dein Passwort kennt, braucht er noch einen Code von deinem Handy. Dauert 2 Minuten.</p>
        <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="totp_start"><button class="btn">Zwei-Faktor einrichten</button></form>
      <?php endif; ?>
    </section>
    <section class="card" id="email">
      <h2>E-Mail</h2>
      <p class="hint">Gilt für Anfragen, Terminvorschläge, Bestätigungen und Erinnerungen.</p>
      <form method="post" class="stack"><?= $hidden('mail_settings') ?>
        <label>Deine E-Mails gehen an <small>(änderbar unter Inhalte → Kontaktdaten)</small><input type="email" value="<?= e($c['site']['email']) ?>" disabled></label>
        <label>Kopie an <small>(bekommt alle deine E-Mails mit – mehrere mit Komma)</small><input type="text" name="mail_cc" value="<?= e($c['site']['mail_cc'] ?? '') ?>" placeholder="info@loggx.de"></label>
        <label>Absender-Adresse <small>(eine Adresse deiner Domain, z. B. kontakt@loggx.de)</small><input type="email" name="mail_from" value="<?= e($c['site']['mail_from'] ?? '') ?>" placeholder="<?= e(lx_mail_from()) ?>"></label>
        <div class="btnrow"><button class="btn btn-small">Speichern</button></div>
      </form>
      <form method="post" class="mt"><?= $hidden('test_mail') ?><button class="btn btn-small btn-ghost">Test-E-Mail an mich schicken</button></form>
    </section>
    <section class="card" id="passwort">
      <h2>Admin-Passwort</h2>
      <form method="post" class="stack"><?= $hidden('admin_pw') ?>
        <label>Aktuelles Passwort<input type="password" name="old" required autocomplete="current-password"></label>
        <label>Neues Passwort <small>(mind. 10 Zeichen)</small><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
        <button class="btn">Passwort ändern</button>
      </form>
      <p class="hint mt">Der Finanzbereich hat ein eigenes Passwort – das änderst du direkt in <a href="finanzen.php">FinanzKlar</a>.</p>
    </section>
    <section class="card" id="protokoll">
      <h2>Letzte Anmeldungen</h2>
      <p class="hint">Siehst du hier etwas, das nicht von dir ist? Sofort das Passwort ändern.</p>
      <?php if (!$log): ?><p class="empty">Noch keine Einträge.</p><?php else: ?>
      <ul class="log"><?php foreach (array_slice($log, 0, 15) as $lg): ?><li class="<?= !empty($lg['ok']) ? '' : 'bad' ?>"><span class="lg-i"><?= !empty($lg['ok']) ? '✓' : '✕' ?></span><span class="lm"><b><?= e($lg['t']) ?></b><small><?= e($lg['dev'] ?? '') ?> · <?= e($lg['ip'] ?? '') ?></small></span><small><?= e(date('d.m.Y H:i', (int)$lg['ts'])) ?></small></li><?php endforeach; ?></ul>
      <form method="post" class="mt" onsubmit="return confirm('Protokoll leeren?')"><?= $hidden('log_clear') ?><button class="btn btn-ghost btn-small">Protokoll leeren</button></form><?php endif; ?>
    </section>
    <section class="card" id="check">
      <h2>System-Check <span class="tag <?= $okCount === count($checks) ? 'on' : 'warn' ?>"><?= $okCount ?>/<?= count($checks) ?></span></h2>
      <ul class="checks"><?php foreach ($checks as [$ok, $label, $tip]): ?><li class="<?= $ok ? 'ok' : 'no' ?>"><span><?= $ok ? '✓' : '!' ?></span><div><b><?= $label ?></b><?php if (!$ok): ?><small><?= e($tip) ?></small><?php endif; ?></div></li><?php endforeach; ?></ul>
      <p class="hint mt">Gespeicherte Daten: <?= $n($dataSize / 1024, 0) ?> KB<?= $free ? ' · freier Speicher: ' . $n($free / 1073741824, 1) . ' GB' : '' ?></p>
    </section>
    <section class="card card-wide" id="sicherungen">
      <h2>Sicherungen <?= $backs ? '<span class="tag on">zuletzt ' . e(lx_ago($backs[0]['ts'])) . '</span>' : '<span class="tag warn">noch keine</span>' ?></h2>
      <p class="hint">Jeden Tag sichert die Seite automatisch alle Daten (Anfragen, Termine, Einstellungen, FinanzKlar-Sync) auf dem Server – die letzten 14 Tage bleiben erhalten. Vor dem Wiederherstellen wird der aktuelle Stand zusätzlich gesichert.</p>
      <div class="btnrow" style="margin-bottom:14px"><form method="post"><?= $hidden('backup_now') ?><button class="btn btn-small">Jetzt sichern</button></form><form method="post"><?= $hidden('backup') ?><button class="btn btn-small btn-ghost">Kundendaten herunterladen (ohne Passwörter)</button></form></div>
      <?php if ($backs): ?><div class="table-scroll"><table class="tbl"><thead><tr><th>Stand</th><th>Art</th><th>Inhalt</th><th>Größe</th><th></th></tr></thead><tbody>
      <?php foreach (array_slice($backs, 0, 20) as $i => $bk): $why = ['taeglich' => 'automatisch', 'manuell' => 'von dir', 'vor-wiederherstellung' => 'vor Wiederherstellung'][$bk['why']] ?? $bk['why']; $meta = $i < 5 ? (lx_backup_load($bk['name'])['meta']['n'] ?? []) : []; ?>
        <tr><td><b><?= e(date('d.m.Y', $bk['ts'])) ?></b> <small><?= e(date('H:i', $bk['ts'])) ?></small></td><td><span class="tag"><?= e($why) ?></span></td>
          <td><small><?= $meta ? (int)$meta['leads'] . ' Anfragen · ' . (int)$meta['bookings'] . ' Termine' : '–' ?></small></td><td><small><?= $n($bk['size'] / 1024, 0) ?> KB</small></td>
          <td><details class="restore"><summary>Wiederherstellen</summary><form method="post" class="row" style="margin:8px 0 0"><?= $hidden('backup_restore') ?><input type="hidden" name="name" value="<?= e($bk['name']) ?>"><input type="password" name="password" placeholder="Admin-Passwort" required autocomplete="current-password"><button class="btn btn-small btn-danger">Diesen Stand laden</button></form>
            <form method="post" style="margin-top:6px"><?= $hidden('backup_download') ?><input type="hidden" name="name" value="<?= e($bk['name']) ?>"><button class="btn btn-small btn-ghost">Herunterladen</button></form></details></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <section class="card card-wide" id="protokolle">
      <?php $lg = in_array($_GET['log'] ?? '', ['fehler', 'mail', '404'], true) ? (string)$_GET['log'] : 'fehler'; $logs = $lg === '404' ? array_values(lx_read('log-404', [])) : lx_log_read($lg); if ($lg === '404') usort($logs, fn($a, $b) => $b['n'] <=> $a['n']); ?>
      <div class="card-h"><h2>Protokolle</h2><nav class="range"><a href="?tab=einstellungen&amp;log=fehler#protokolle"<?= $lg === 'fehler' ? ' aria-current="true"' : '' ?>>Fehler <?= $errCount ? '(' . $errCount . ')' : '' ?></a><a href="?tab=einstellungen&amp;log=mail#protokolle"<?= $lg === 'mail' ? ' aria-current="true"' : '' ?>>E-Mails</a><a href="?tab=einstellungen&amp;log=404#protokolle"<?= $lg === '404' ? ' aria-current="true"' : '' ?>>Kaputte Links</a></nav></div>
      <p class="hint"><?= ['fehler' => 'Technische Fehler der Webseite (z. B. E-Mail ging nicht raus). Leer = alles gut.', 'mail' => 'Jede E-Mail, die die Seite verschickt hat – an Kunden und an dich.', '404' => 'Adressen, die jemand aufgerufen hat, die es nicht gibt. Häufige Einträge mit Herkunft = irgendwo steht ein falscher Link.'][$lg] ?></p>
      <?php if (!$logs): ?><p class="empty"><?= $lg === 'fehler' ? 'Keine Fehler. 🎉' : 'Noch keine Einträge.' ?></p><?php else: ?>
      <ul class="log"><?php foreach (array_slice($logs, 0, 60) as $e): ?>
        <?php if ($lg === '404'): ?><li class="<?= !empty($e['bot']) ? '' : 'bad' ?>"><span class="lg-i"><?= (int)$e['n'] ?>×</span><span class="lm"><b><?= e($e['p']) ?></b><small><?= !empty($e['bot']) ? 'Suchmaschine/Bot' : 'Besucher' ?><?= ($e['ref'] ?? '') !== '' ? ' · kam von ' . e($e['ref']) : '' ?></small></span><small><?= e(lx_ago((int)$e['ts'])) ?></small></li>
        <?php elseif ($lg === 'mail'): $okm = !empty($e['x']['ok']); ?><li class="<?= $okm ? '' : 'bad' ?>"><span class="lg-i"><?= $okm ? '✓' : '✕' ?></span><span class="lm"><b><?= e($e['t']) ?></b><small>an <?= e((string)($e['x']['to'] ?? '')) ?><?= !empty($e['x']['att']) ? ' · mit Anhang' : '' ?><?= (int)($e['n'] ?? 1) > 1 ? ' · ' . (int)$e['n'] . '×' : '' ?></small></span><small><?= e(date('d.m. H:i', (int)$e['ts'])) ?></small></li>
        <?php else: ?><li class="bad"><span class="lg-i"><?= !empty($e['x']['fatal']) ? '‼' : '!' ?></span><span class="lm"><b><?= e($e['t']) ?></b><small><?= e(trim(($e['x']['f'] ?? '') . ' ' . ($e['x']['u'] ?? ''))) ?><?= (int)($e['n'] ?? 1) > 1 ? ' · ' . (int)$e['n'] . '×' : '' ?></small></span><small><?= e(date('d.m. H:i', (int)$e['ts'])) ?></small></li><?php endif; ?>
      <?php endforeach; ?></ul>
      <form method="post" class="mt" onsubmit="return confirm('Protokoll leeren?')"><?= $hidden('log_clear_ch') ?><input type="hidden" name="ch" value="<?= e($lg) ?>"><button class="btn btn-ghost btn-small">Leeren</button></form><?php endif; ?>
    </section>
    <section class="card" id="bericht">
      <h2>Wochenbericht</h2>
      <p class="hint">Jeden Montag ab 7 Uhr eine kurze E-Mail: Besucher, neue Anfragen, fällige Wiedervorlagen, Termine der Woche und ob es Fehler gab.</p>
      <form method="post" class="stack"><?= $hidden('weekly') ?><label class="check"><input type="checkbox" name="weekly_on" value="1"<?= !empty($c['site']['weekly_on']) ? ' checked' : '' ?> onchange="this.form.submit()"> Wochenbericht per E-Mail</label></form>
      <form method="post" class="mt"><?= $hidden('weekly_send') ?><button class="btn btn-ghost btn-small">Jetzt einen schicken (Test)</button></form>
    </section>
    <section class="card" id="ueberwachung">
      <h2>Überwachung</h2>
      <p class="hint">Damit du sofort merkst, wenn die Seite mal nicht erreichbar ist: kostenlos bei <strong>UptimeRobot</strong> (uptimerobot.com) einen „HTTP(s)“-Monitor mit dieser Adresse anlegen. Er meldet sich per E-Mail oder App, sobald etwas nicht stimmt.</p>
      <div class="feed"><code><?= e(lx_abs_url('_launch/api.php?a=health')) ?></code></div>
      <div class="btnrow"><button type="button" class="btn btn-ghost btn-small" data-copy="<?= e(lx_abs_url('_launch/api.php?a=health')) ?>">Adresse kopieren</button><a class="btn btn-ghost btn-small" href="<?= e(lx_url('_launch/api.php?a=health')) ?>" target="_blank" rel="noopener">Testen ↗</a></div>
    </section>
  </div>

<?php /* ======================= SUCHE ======================= */ elseif ($tab === 'suche'):
  $q = trim((string)($_GET['q'] ?? '')); $ql = mb_strtolower($q);
  $hitL = $q === '' ? [] : array_values(array_filter($leads, fn($x) => str_contains(mb_strtolower(implode(' ', [$x['data']['name'], $x['data']['firma'], $x['data']['email'], $x['data']['ort'], $x['data']['telefon'], $x['data']['nachricht'], implode(' ', array_column((array)$x['notes'], 't'))])), $ql)));
  $hitB = $q === '' ? [] : array_reverse(array_values(array_filter(lx_bookings(), fn($b) => str_contains(mb_strtolower(implode(' ', [$b['name'], $b['email'], $b['type_name'], $b['where'], $b['memo'] ?? '', $b['answer']])), $ql))));
?>
  <div class="page-head"><div><h1>Suche</h1><p class="sub"><?= $q === '' ? 'Anfragen und Termine durchsuchen.' : count($hitL) + count($hitB) . ' Treffer für „' . e($q) . '“' ?></p></div></div>
  <form class="search" method="get"><input type="hidden" name="tab" value="suche"><input type="search" name="q" value="<?= e($q) ?>" placeholder="Name, Firma, E-Mail, Ort, Telefon …" autofocus></form>
  <?php if ($q !== ''): ?>
  <section class="card flush"><h2 class="pad-h">Anfragen <span class="count"><?= count($hitL) ?></span></h2>
    <?php if (!$hitL): ?><p class="empty pad">Keine Anfrage gefunden.</p><?php endif; ?><ul class="leads"><?php foreach (array_slice($hitL, 0, 50) as $x) echo lead_row($x); ?></ul></section>
  <section class="card"><h2>Termine <span class="count"><?= count($hitB) ?></span></h2>
    <?php if (!$hitB): ?><p class="empty">Kein Termin gefunden.</p><?php else: ?><ul class="agenda"><?php foreach (array_slice($hitB, 0, 50) as $b): ?>
      <li><a href="?tab=termine&amp;id=<?= e($b['id']) ?>"><span class="ag-d"><b><?= e(date('d.m.Y', (int)$b['start'])) ?></b><small><?= date('H:i', (int)$b['start']) ?> Uhr</small></span><span class="lm"><b><?= e($b['name']) ?></b><small><?= e($b['type_name']) ?> · <?= e(LX_BK_STATUS[$b['status']] ?? '') ?></small></span></a></li>
    <?php endforeach; ?></ul><?php endif; ?></section>
  <?php endif; ?>
<?php endif; ?>
</main>
<script>
  document.querySelectorAll("[data-copy]").forEach(function (b) {
    b.addEventListener("click", function () {
      var t = b.getAttribute("data-copy");
      var done = function () { var o = b.textContent; b.textContent = "Kopiert ✓"; setTimeout(function () { b.textContent = o; }, 1600); };
      if (navigator.clipboard) navigator.clipboard.writeText(t).then(done, function () { prompt("Link kopieren:", t); });
      else prompt("Link kopieren:", t);
    });
  });
  /* Suche: Taste „/“ springt ins Suchfeld */
  document.addEventListener("keydown", function (e) {
    if (e.key !== "/" || /input|textarea|select/i.test((document.activeElement || {}).tagName || "")) return;
    var q = document.querySelector(".gsearch input"); if (q && q.offsetParent) { e.preventDefault(); q.focus(); }
  });
  /* „Mehr“-Menü am Handy: schließen bei Klick daneben */
  document.addEventListener("click", function (e) { var m = document.querySelector(".tabs-more[open]"); if (m && !m.contains(e.target)) m.removeAttribute("open"); });
  /* Listen mit Vorlage (z. B. Terminarten): hinzufügen / löschen */
  document.addEventListener("click", function (e) {
    var add = e.target.closest("[data-add]");
    if (add) { var tpl = document.getElementById(add.getAttribute("data-add")), to = document.getElementById(add.getAttribute("data-to")); if (tpl && to) { var k = "n" + Date.now(); to.insertAdjacentHTML("beforeend", tpl.innerHTML.replace(/__/g, k)); var f = to.lastElementChild.querySelector("input:not([type=hidden])"); if (f) f.focus(); } return; }
    var rm = e.target.closest("[data-remove]"); if (rm) { rm.closest(".trow").remove(); }
  });
  /* Termine: mehrere Zeiten, freie Zeiten, Terminart */
  document.querySelectorAll("form").forEach(function (form) {
    var times = form.querySelector("[data-times]"); if (!times) return;
    var addBtn = form.querySelector("[data-add-time]");
    var rows = function () { return times.querySelectorAll(".t-time"); };
    var renum = function () { rows().forEach(function (r, i) { r.querySelector(".t-n").textContent = i + 1; }); if (addBtn) addBtn.hidden = rows().length >= 3 || (mode() !== "vorschlag"); };
    var mode = function () { var m = form.querySelector('[name="mode"]:checked'); return m ? m.value : "vorschlag"; };
    var addRow = function (d, t) {
      if (rows().length >= 3) return null;
      var r = rows()[0].cloneNode(true);
      r.querySelectorAll("input").forEach(function (i) { i.value = ""; i.required = false; });
      var last = r.lastElementChild; if (last.tagName !== "BUTTON") { var b = document.createElement("button"); b.type = "button"; b.className = "icon"; b.setAttribute("data-remove-time", ""); b.setAttribute("aria-label", "Zeit entfernen"); b.textContent = "✕"; r.replaceChild(b, last); }
      times.appendChild(r); if (d) { r.querySelector('[type="date"]').value = d; r.querySelector('[type="time"]').value = t; } renum(); return r;
    };
    if (addBtn) addBtn.addEventListener("click", function () { var r = addRow(); if (r) r.querySelector("input").focus(); });
    times.addEventListener("click", function (e) { var b = e.target.closest("[data-remove-time]"); if (b) { b.closest(".t-time").remove(); renum(); markChips(); } });
    // freie Zeiten als Knöpfe
    var freeBox = form.querySelector("#bkFree"), art = form.querySelector("#bkArt"), minIn = form.querySelector("#bkMin");
    var free = {}; try { free = JSON.parse(freeBox ? freeBox.getAttribute("data-free") : "{}") || {}; } catch (e) {}
    var markChips = function () {
      if (!freeBox) return; var used = {};
      rows().forEach(function (r) { used[r.querySelector('[type="date"]').value + " " + r.querySelector('[type="time"]').value] = 1; });
      freeBox.querySelectorAll(".chip").forEach(function (c) { c.classList.toggle("used", !!used[c.dataset.d + " " + c.dataset.t]); });
    };
    var drawChips = function () {
      if (!freeBox) return;
      var m = String(minIn ? minIn.value : 30), list = free[m] || free[Object.keys(free)[0]] || [];
      var box = freeBox.querySelector(".free-c"); box.innerHTML = "";
      list.forEach(function (x) { var b = document.createElement("button"); b.type = "button"; b.className = "chip"; b.dataset.d = x[0]; b.dataset.t = x[1]; b.textContent = x[2]; box.appendChild(b); });
      freeBox.hidden = !list.length; markChips();
    };
    if (freeBox) freeBox.addEventListener("click", function (e) {
      var c = e.target.closest(".chip"); if (!c) return;
      if (c.classList.contains("used")) { rows().forEach(function (r) { if (r.querySelector('[type="date"]').value === c.dataset.d && r.querySelector('[type="time"]').value === c.dataset.t) { if (rows().length > 1 && r !== rows()[0]) r.remove(); else { r.querySelector('[type="date"]').value = ""; r.querySelector('[type="time"]').value = ""; } } }); renum(); markChips(); return; }
      var target = null; rows().forEach(function (r) { if (!target && !r.querySelector('[type="date"]').value) target = r; });
      if (!target) target = mode() === "vorschlag" ? addRow() : rows()[0];
      if (!target) target = rows()[rows().length - 1];
      target.querySelector('[type="date"]').value = c.dataset.d; target.querySelector('[type="time"]').value = c.dataset.t; markChips();
    });
    times.addEventListener("change", markChips);
    if (art) art.addEventListener("change", function () { var o = art.selectedOptions[0]; minIn.value = o.dataset.min; form.querySelector("#bkWhere").value = o.dataset.where; form.querySelector("#bkTName").value = o.dataset.name; drawChips(); });
    if (minIn) minIn.addEventListener("change", drawChips);
    // Modus: Vorschlag / fest / nur eintragen
    var submit = form.querySelector("#bkSubmit"), mail = form.querySelector("#bkMail"), email = form.querySelector("#bkEmail");
    var applyMode = function () {
      var m = mode();
      if (m !== "vorschlag") rows().forEach(function (r, i) { if (i) r.remove(); });
      if (mail) mail.hidden = m !== "vorschlag";
      if (email) email.required = m !== "still";
      if (submit) submit.textContent = m === "vorschlag" ? "Vorschlag per E-Mail senden" : (m === "fest" ? "Eintragen & Bestätigung senden" : "Termin eintragen");
      renum(); markChips();
    };
    form.querySelectorAll('[name="mode"]').forEach(function (r) { r.addEventListener("change", applyMode); });
    drawChips(); renum();
  });
  /* Antwortvorlagen in der Anfrage */
  (function () {
    var box = document.querySelector(".tpl-pick"); if (!box) return;
    var t = []; try { t = JSON.parse(box.getAttribute("data-tpls")) || []; } catch (e) {}
    var f = box.parentNode.querySelector("form"), subj = f.querySelector('[name="subject"]'), txt = f.querySelector('[name="text"]');
    var mark = function (i) { box.querySelectorAll("[data-tpl]").forEach(function (b) { b.classList.toggle("on", +b.getAttribute("data-tpl") === i); }); };
    mark(0);
    box.addEventListener("click", function (e) {
      var b = e.target.closest("[data-tpl]"); if (!b) return; var i = +b.getAttribute("data-tpl"), x = t[i]; if (!x) return;
      if (txt.value.trim() && txt.dataset.edited === "1" && !confirm("Deinen geänderten Text ersetzen?")) return;
      subj.value = x.s; txt.value = x.t; txt.dataset.edited = ""; mark(i); txt.focus();
    });
    txt.addEventListener("input", function () { txt.dataset.edited = "1"; });
  })();
  /* Ankündigungsleiste: Live-Vorschau + Entfernen */
  (function () {
    var fs = document.querySelector(".bar-fs"); if (!fs) return;
    var pv = fs.querySelector(".bar-preview");
    var sync = function () {
      var v = function (n) { var i = fs.querySelector('[name="' + n + '"]'); return i ? i.value.trim() : ""; };
      pv.querySelector('[data-bp="bar_text"]').textContent = v("bar_text") || "Ihr Ankündigungstext erscheint hier";
      pv.querySelector('[data-bp="bar_badge"]').textContent = v("bar_badge"); pv.querySelector(".bp-badge").hidden = !v("bar_badge");
      pv.querySelector('[data-bp="bar_link_text"]').textContent = v("bar_link_text"); pv.querySelector(".bp-link").hidden = !v("bar_link_text");
      pv.classList.toggle("is-off", !fs.querySelector('[name="bar_on"]').checked);
    };
    fs.addEventListener("input", sync); fs.addEventListener("change", sync); sync();
    var rb = document.getElementById("bar-remove-btn");
    if (rb) rb.addEventListener("click", function () {
      if (!confirm("Ankündigungsleiste entfernen und Text löschen?")) return;
      document.getElementById("bar-remove").value = "1"; rb.form.requestSubmit ? rb.form.requestSubmit() : rb.form.submit();
    });
  })();
</script>
<?php foot();

/* ==========================================================================
   Layout
   ========================================================================== */
/* ==========================================================================
   Bausteine
   ========================================================================== */
/** Balkenliste mit Anteil in % */
function bars(array $items, int $total = 0, int $limit = 8, ?callable $label = null): string {
    $items = array_filter($items, fn($v) => (int)$v > 0);
    if (!$items) return '<p class="empty">Noch keine Daten.</p>';
    arsort($items);
    $max = max($items); $total = $total ?: array_sum($items); $o = '';
    foreach (array_slice($items, 0, $limit, true) as $k => $v) {
        $o .= '<div class="brow"><span class="fill" style="--w:' . round($v / max(1, $max) * 100) . '%"></span><span class="lbl">' . e($label ? $label((string)$k) : (string)$k) . '</span><b>' . number_format((float)$v, 0, ',', '.') . '</b><small>' . round($v / max(1, $total) * 100) . ' %</small></div>';
    }
    return $o;
}
/** Kurze Hinweise aus den Zahlen */
function insights(array $st, array $leads, int $range): array {
    $o = [];
    $hot = array_filter($leads, fn($x) => $x['status'] === 'neu' && (int)$x['score'] >= 65);
    $new = array_filter($leads, fn($x) => $x['status'] === 'neu');
    if ($hot) $o[] = ['hot', '<b>' . count($hot) . ' heiße Anfrage' . (count($hot) > 1 ? 'n' : '') . '</b> ' . (count($hot) > 1 ? 'warten' : 'wartet') . ' auf Antwort – am besten heute melden.'];
    elseif ($new) $o[] = ['form', '<b>' . count($new) . ' neue Anfrage' . (count($new) > 1 ? 'n' : '') . '</b> noch nicht bearbeitet.'];
    $src = array_diff_key($st['r'], ['direkt' => 1]);
    if ($src) { arsort($src); $top = (string)array_key_first($src); $o[] = ['src', '<b>' . e($top) . '</b> bringt die meisten Besucher (' . round($src[$top] / max(1, array_sum($st['r'])) * 100) . ' %).']; }
    if (array_sum($st['h']) >= 10) {
        $h = (int)array_search(max($st['h']), $st['h'], true); $wd = (int)array_search(max($st['wd']), $st['wd'], true);
        $wdn = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
        $o[] = ['time', "Die meisten Besuche: <b>{$wdn[$wd]}s</b> gegen <b>{$h}–" . ($h + 1) . ' Uhr</b> – guter Zeitpunkt für neue Posts.'];
    }
    $all = array_sum($st['g']);
    if ($all >= 5) $o[] = ['phone', '<b>' . round(($st['g']['m'] ?? 0) / $all * 100) . ' %</b> der Besucher sind am Handy unterwegs.'];
    $apps = ($st['br']['Instagram-App'] ?? 0) + ($st['br']['Facebook-App'] ?? 0) + ($st['br']['LinkedIn-App'] ?? 0);
    if ($apps) $o[] = ['ig', "<b>$apps</b> Besucher kamen direkt aus Instagram, Facebook oder LinkedIn (Link in Bio, Story oder Nachricht)."];
    $names = ['plan-visit' => 'Visitenkarte', 'plan-firm' => 'Firmenwebseite', 'plan-indiv' => 'Individuell', 'plan-hosting' => 'Hosting & Technik', 'plan-change' => 'Änderungen & Support'];
    $plans = array_intersect_key($st['c'], $names);
    if ($plans) { arsort($plans); $o[] = ['plan', 'Am häufigsten angeklickt: Paket <b>' . e($names[array_key_first($plans)]) . '</b>.']; }
    if (($ex = (int)($st['c']['example'] ?? 0)) > 0) $o[] = ['ex', "<b>{$ex}×</b> wurde eine Beispielseite angesehen" . (($cta = (int)($st['c']['example-cta'] ?? 0)) ? ", <b>{$cta}×</b> ging es danach direkt zur Anfrage." : '.')];
    $fs = (int)($st['c']['form-start'] ?? 0);
    if ($fs >= 3) $o[] = ['form', "<b>{$fs}×</b> wurde die Anfrage begonnen, <b>{$st['a']}×</b> abgeschickt (" . round($st['a'] / $fs * 100) . ' %).'];
    $lpV = (int)($st['p']['angebot/index.html'] ?? 0);
    if ($lpV) {
        $since = strtotime(date('Y-m-d', strtotime('-' . ($range - 1) . ' days')));
        $lpLeads = count(array_filter($leads, fn($x) => ($x['page'] ?? '') === 'angebot' && $x['created'] >= $since));
        $o[] = ['lp', "Werbe-Landingpage: <b>$lpV</b> Aufrufe, <b>$lpLeads</b> Anfrage" . ($lpLeads === 1 ? '' : 'n') . '.'];
    }
    return $o;
}
function lead_row(array $x): string {
    $d = $x['data']; [$tk, $tl] = lx_lead_temp((int)$x['score']);
    $sub = implode(' · ', array_filter([$d['firma'] ? $d['name'] : '', lx_lead_branche($d), $d['ort']]));
    return '<li class="lead' . (empty($x['seen']) && $x['status'] === 'neu' ? ' unread' : '') . '"><a href="?tab=anfragen&amp;id=' . e($x['id']) . '">'
        . '<span class="temp t-' . $tk . '" title="Priorität ' . (int)$x['score'] . '/100">' . $tl . '</span>'
        . '<span class="lm"><b>' . e($d['firma'] ?: $d['name']) . '</b><small>' . e($sub !== '' ? $sub : $d['email']) . '</small></span>'
        . '<span class="lr"><span class="pill-s"><i class="dot st-' . e($x['status']) . '"></i>' . e(lx_lead_status()[$x['status']] ?? '') . '</span>'
        . (($x['follow'] ?? '') !== '' ? '<span class="follow-s' . ($x['follow'] <= date('Y-m-d') ? ' due' : '') . '">⏰ ' . e(date('d.m.', (int)strtotime($x['follow']))) . '</span>' : '<span class="rec-s">' . e($x['rec']) . '</span>')
        . '<small>' . e(lx_ago((int)$x['created'])) . '</small></span></a></li>';
}
function lead_wa(string $tel): string {
    $d = (string)preg_replace('/\D+/', '', str_replace('(0)', '', $tel));
    if (str_starts_with($d, '00')) $d = substr($d, 2); elseif (str_starts_with($d, '0')) $d = '49' . substr($d, 1);
    return $d;
}
function range_nav(int $range, string $tab): string {
    $o = '<nav class="range" aria-label="Zeitraum">';
    foreach ([7 => '7 Tage', 30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr'] as $k => $t) $o .= '<a href="?tab=' . $tab . '&amp;tage=' . $k . '"' . ($range === $k ? ' aria-current="true"' : '') . '>' . $t . '</a>';
    return $o . '</nav>';
}
function icon(string $k): string {
    $p = ['start' => '<path d="M4 11 12 4l8 7v8a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z"/>',
          'anfragen' => '<rect x="3" y="5" width="18" height="14" rx="2.5"/><path d="m4 7 8 6 8-6"/>',
          'statistik' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
          'webseite' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>',
          'termine' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M3 10h18M8 3v4M16 3v4M8 14h3v3H8z"/>',
          'inhalte' => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>',
          'veroeffentlichung' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
          'einstellungen' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
          'suche' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
          'mehr' => '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
          'finanzen' => '<path d="M4 19V5M4 19h16"/><path d="m7 15 4-4 3 3 5-6"/>'][$k] ?? '';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

function render_auth(string $type, ?string $error): void {
    head($type === 'setup' ? 'Einrichtung – LoggX Admin' : 'Anmelden – LoggX Admin'); ?>
<main class="auth">
  <form method="post" class="card auth-card">
    <svg viewBox="0 0 512 512" class="auth-logo" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#0F2540"/><path d="M70 375V137H129V375ZM80 375V325H219V375Z" fill="#fff"/><path d="M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z" fill="#FFD166"/></svg>
    <?php if ($type === 'setup'): ?>
      <h1>Admin einrichten</h1>
      <p class="hint">Den Einrichtungscode findest du auf deinem Webspace in der Datei <code>_launch/data/einrichtungscode.php</code> (z. B. über den IONOS Webspace-Explorer oder per FTP öffnen).</p>
      <?php if (PHP_SAPI === 'cli-server'): /* nur beim lokalen Testserver */ ?>
      <p class="flash warn">Lokaler Test – dein Einrichtungscode: <strong><?= e(setup_code()) ?></strong></p>
      <?php endif; ?>
      <input type="hidden" name="action" value="setup">
      <label>Einrichtungscode<input type="text" name="setup_code" required autocomplete="off" placeholder="XXXX-XXXX-XXXX-XXXX"></label>
      <label>Admin-Passwort <small>(mind. 10 Zeichen)</small><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
      <label>Passwort wiederholen<input type="password" name="password2" required minlength="10" autocomplete="new-password"></label>
      <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
      <button class="btn">Einrichten</button>
    <?php elseif ($type === 'totp'): ?>
      <h1>Bestätigungscode</h1>
      <p class="hint">Öffne deine Authenticator-App und gib den 6-stelligen Code für <strong>LoggX Admin</strong> ein.</p>
      <input type="hidden" name="action" value="login2">
      <label>Code<input type="text" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="123456" style="font-size:1.4rem;letter-spacing:.3em;text-align:center"></label>
      <label class="check"><input type="checkbox" name="trust" value="1"> Diesem Gerät 30 Tage vertrauen</label>
      <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
      <button class="btn">Bestätigen</button>
      <p class="hint" style="font-size:.85rem">Handy nicht zur Hand? Gib einen deiner Wiederherstellungscodes ein (Format XXXXX-XXXXX). <a href="?abbrechen=1">Abbrechen</a></p>
    <?php else: ?>
      <h1>Admin-Bereich</h1>
      <input type="hidden" name="action" value="login">
      <label>Passwort<input type="password" name="password" required autofocus autocomplete="current-password"></label>
      <?php if ($error): ?><p class="flash error"><?= e($error) ?></p><?php endif; ?>
      <button class="btn">Anmelden</button>
    <?php endif; ?>
  </form>
</main>
<?php foot();
}

function head(string $title, bool $app = false): void { ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="icon" href="data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Crect width='512' height='512' rx='112' fill='%230F2540'/%3E%3Cpath d='M70 375V137H129V375ZM80 375V325H219V375Z' fill='%23fff'/%3E%3Cpath d='M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z' fill='%23FFD166'/%3E%3C/svg%3E">
<style>
  @font-face { font-family: "Bricolage"; src: url("<?= e(lx_url('fonts/bricolage-grotesque-latin-standard-normal.woff2')) ?>") format("woff2-variations"); font-weight: 200 800; font-stretch: 75% 100%; }
  @font-face { font-family: "Jakarta"; src: url("<?= e(lx_url('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>") format("woff2-variations"); font-weight: 200 800; }
  :root { --ink: #0F2540; --sun: #FFD166; --paper: #F3F6FB; --line: #D9E1EC; --muted: #5B6B80; --cobalt: #3B5BFF; --ok: #1FA67A; --danger: #D93B41; }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--paper); color: var(--ink); font: 400 15.5px/1.55 "Jakarta", system-ui, sans-serif; -webkit-font-smoothing: antialiased; }
  h1, h2, h3 { font-family: "Bricolage", sans-serif; letter-spacing: -0.02em; margin: 0; }
  a { color: inherit; }
  :focus-visible { outline: 3px solid var(--cobalt); outline-offset: 2px; border-radius: 6px; }
  .bar { position: sticky; top: 0; z-index: 5; display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 14px clamp(16px, 3vw, 32px); background: #fff; border-bottom: 1px solid var(--line); }
  .brand { display: inline-flex; align-items: center; gap: 10px; font: 800 1.25rem/1 "Bricolage", sans-serif; text-decoration: none; }
  .brand svg { width: 30px; height: 30px; } .brand span { font: 600 .8rem/1 "Jakarta", sans-serif; padding: 4px 8px; border-radius: 999px; background: var(--paper); color: var(--muted); }
  .bar nav { display: flex; gap: 18px; font-weight: 600; font-size: .92rem; } .bar nav a { text-decoration: none; } .bar nav a:hover { color: var(--cobalt); }
  .admin { max-width: 1180px; margin: 0 auto; padding: 24px clamp(16px, 3vw, 32px) 60px; }
  .hero-status { display: flex; justify-content: space-between; align-items: center; gap: 20px; flex-wrap: wrap; padding: 26px 28px; border-radius: 22px; background: var(--ink); color: #fff; margin-bottom: 20px; position: relative; overflow: hidden; }
  .hero-status::before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 6px; background: var(--ok); }
  .hero-status.s-prelaunch::before { background: var(--sun); } .hero-status.s-maintenance::before { background: #FF8A3D; }
  .eyebrow { margin: 0 0 4px; color: #A9B8CC; font-weight: 600; font-size: .88rem; }
  .hero-status h1 { font-size: clamp(1.8rem, 3.5vw, 2.6rem); }
  .sub { margin: 6px 0 0; color: #C3CFDE; max-width: 70ch; } .sub strong { color: var(--sun); }

  .stats-card { margin-bottom: 20px; }
  .stats-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
  .stats-head h2 { margin: 0 0 4px; }
  .range { display: inline-flex; padding: 4px; background: var(--paper); border-radius: 999px; }
  .range a { padding: .4rem .85rem; border-radius: 999px; font-weight: 700; font-size: .85rem; color: var(--muted); text-decoration: none; }
  .range a[aria-current] { background: var(--ink); color: #fff; }
  .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 22px; }
  .kpis > div { background: var(--paper); border-radius: 14px; padding: 14px 16px; display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
  .kpis span { flex-basis: 100%; font-size: .8rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .05em; }
  .kpis b { font: 800 1.9rem/1.1 "Bricolage", sans-serif; letter-spacing: -.02em; }
  .kpis small { color: var(--muted); font-size: .8rem; }
  .trend { flex-basis: auto !important; font-size: .78rem !important; text-transform: none !important; letter-spacing: 0 !important; padding: 2px 8px; border-radius: 999px; }
  .trend.up { background: #E3F6EE; color: #127A55 !important; } .trend.down { background: #FDECEC; color: #B4262B !important; }
  .chart { display: flex; align-items: flex-end; gap: 3px; height: 150px; padding: 10px 0 0; border-bottom: 1.5px solid var(--line); }
  .chart .cbar { flex: 1; height: 100%; display: flex; align-items: flex-end; cursor: default; }
  .chart .cbar i { display: block; width: 100%; height: var(--h); min-height: 0; border-radius: 4px 4px 0 0; background: var(--ink); opacity: .85; transition: opacity .15s; }
  .chart .cbar.has-a i { background: linear-gradient(var(--sun) 0 6px, var(--ink) 6px); }
  .chart .cbar:hover i { opacity: 1; background: var(--cobalt); }
  .chart-axis { display: flex; justify-content: space-between; font-size: .78rem; color: var(--muted); margin: 6px 0 20px; }
  .stats-lists { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
  .stats-lists h3 { font-size: .95rem; margin: 0 0 10px; }
  .stats-lists .row { position: relative; display: flex; justify-content: space-between; gap: 10px; padding: 7px 10px; border-radius: 8px; font-size: .9rem; margin-bottom: 4px; overflow: hidden; }
  .stats-lists .row .fill { position: absolute; inset: 0 auto 0 0; width: var(--w); background: var(--paper); border-radius: 8px; z-index: 0; }
  .stats-lists .row span:not(.fill), .stats-lists .row b { position: relative; z-index: 1; }
  .stats-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--line); }
  .stats-foot form.inline { margin: 0; }
  @media (max-width: 900px) { .kpis { grid-template-columns: 1fr 1fr; } .stats-lists { grid-template-columns: 1fr; } .chart { gap: 1px; } }
  .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; align-items: start; }
  .card { background: #fff; border: 1px solid var(--line); border-radius: 20px; padding: 24px; }
  .card h2 { font-size: 1.35rem; margin-bottom: 14px; display: flex; align-items: center; gap: 10px; }
  .card h3 { font-size: 1.05rem; margin-top: 8px; }
  .hint { color: var(--muted); font-size: .92rem; margin: -6px 0 16px; }
  .stack { display: grid; gap: 14px; }
  .row { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; margin-bottom: 16px; } .row .grow { flex: 1 1 180px; }
  label { display: grid; gap: 6px; font-weight: 600; font-size: .9rem; } label small { font-weight: 500; color: var(--muted); }
  input[type=text], input[type=password], input[type=email], input[type=date], input[type=datetime-local], textarea { width: 100%; padding: .7rem .85rem; border: 1.5px solid var(--line); border-radius: 12px; font: 500 .95rem/1.4 "Jakarta", sans-serif; color: var(--ink); background: #fff; }
  input:focus, textarea:focus { outline: none; border-color: var(--cobalt); box-shadow: 0 0 0 3px rgba(59,91,255,.15); }
  textarea { resize: vertical; }
  .check { display: flex; gap: 10px; align-items: center; font-weight: 600; } .check input { width: 18px; height: 18px; accent-color: var(--ink); }
  fieldset.modes { border: 0; padding: 0; margin: 0; display: grid; gap: 8px; } fieldset.modes legend { font-weight: 600; font-size: .9rem; margin-bottom: 6px; }
  .mode { display: flex; gap: 12px; align-items: flex-start; padding: 12px 14px; border: 1.5px solid var(--line); border-radius: 14px; cursor: pointer; transition: border-color .2s, background .2s; }
  .mode:has(input:checked) { border-color: var(--ink); background: var(--paper); }
  .mode input { margin-top: 3px; accent-color: var(--ink); width: 18px; height: 18px; } .mode span { display: grid; } .mode small { color: var(--muted); font-weight: 500; }
  .btn { display: inline-flex; align-items: center; justify-content: center; padding: .75rem 1.2rem; border-radius: 999px; border: 0; background: var(--ink); color: #fff; font: 700 .92rem/1 "Jakarta", sans-serif; cursor: pointer; text-decoration: none; transition: background .2s, transform .2s; justify-self: start; }
  .btn:hover { background: var(--cobalt); } .btn:active { transform: scale(.98); }
  .btn-sun { background: var(--sun); color: var(--ink); } .btn-sun:hover { background: #fff; }
  .btn-ghost { background: transparent; color: var(--ink); box-shadow: inset 0 0 0 1.5px var(--line); } .btn-ghost:hover { background: var(--paper); }
  .btn-danger { background: transparent; color: var(--danger); box-shadow: inset 0 0 0 1.5px #F2C4C6; } .btn-danger:hover { background: #FDECEC; }
  .btn-small { padding: .5rem .8rem; font-size: .82rem; }
  .flash { padding: 12px 16px; border-radius: 12px; font-weight: 600; margin: 0 0 16px; background: #E4F6EF; color: #0F6B4E; border: 1px solid #BCE7D6; }
  .flash.error { background: #FDECEC; color: #A3262B; border-color: #F2C4C6; } .flash.warn { background: #FFF6DD; color: #7A5B00; border-color: #F5DE9B; }
  .codes, .signups { list-style: none; padding: 0; margin: 0; display: grid; gap: 10px; }
  .code { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px; border: 1.5px solid var(--line); border-radius: 14px; }
  .code.off { opacity: .6; } .code.new { border-color: var(--sun); background: #FFFBEF; animation: pop .6s ease; }
  @keyframes pop { from { transform: scale(.97); } }
  .code-main { display: grid; grid-template-columns: auto auto; justify-content: start; gap: 4px 10px; align-items: center; }
  .code-main code { grid-column: 1 / -1; font: 700 1.05rem/1 ui-monospace, monospace; letter-spacing: .06em; }
  .code-main small { grid-column: 1 / -1; color: var(--muted); }
  .code-actions { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; } .code-actions form { margin: 0; }
  .tag { padding: 2px 8px; border-radius: 999px; font: 700 .72rem/1.4 "Jakarta", sans-serif; } .tag.on { background: #E4F6EF; color: #0F6B4E; } .tag.no { background: #F1F3F7; color: var(--muted); }
  .count { padding: 2px 10px; border-radius: 999px; background: var(--paper); font: 700 .85rem/1.4 "Jakarta", sans-serif; }
  .signups { margin-top: 12px; max-height: 340px; overflow: auto; }
  .signups li { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: var(--paper); } .signups span { flex: 1; overflow-wrap: anywhere; } .signups small { color: var(--muted); } .signups form { margin: 0; }
  .icon { border: 0; background: none; cursor: pointer; color: var(--muted); font-size: 1rem; padding: 4px 6px; border-radius: 6px; } .icon:hover { color: var(--danger); background: #FDECEC; }
  .empty { color: var(--muted); padding: 14px; border: 1.5px dashed var(--line); border-radius: 14px; text-align: center; margin: 0; }
  details.more { margin-top: 20px; border-top: 1px solid var(--line); padding-top: 14px; } details.more summary { cursor: pointer; font-weight: 700; display: flex; align-items: center; gap: 8px; } details.more[open] summary { margin-bottom: 12px; }
  .auth { min-height: 100svh; display: grid; place-items: center; padding: 20px; }
  .auth-card { width: min(420px, 100%); display: grid; gap: 14px; padding: 32px; } .auth-card h1 { font-size: 1.8rem; } .auth-logo { width: 46px; height: 46px; }
  .auth-card .hint { margin: 0; } .auth-card code { background: var(--paper); padding: 1px 6px; border-radius: 6px; font-size: .85em; } .auth-card .btn { justify-self: stretch; padding: .9rem; }
.card-wide { grid-column: 1 / -1; }
  .site-form { display: grid; gap: 18px; }
  .site-form fieldset { border: 1.5px solid var(--line); border-radius: 16px; padding: 16px 18px 18px; margin: 0; display: grid; gap: 12px; }
  .site-form legend { font: 700 1rem/1 "Bricolage", sans-serif; padding: 0 6px; display: flex; gap: 8px; align-items: center; }
  .site-form .hint { margin: 0; }
  .img-slots { display: grid; gap: 14px; }
  .img-slot { display: flex; gap: 14px; align-items: center; }
  .img-thumb { flex: 0 0 120px; height: 75px; border-radius: 10px; overflow: hidden; background: var(--paper); display: grid; place-items: center; box-shadow: inset 0 0 0 1.5px var(--line); }
  .img-thumb.tall { flex-basis: 75px; height: 92px; }
  .img-thumb img { width: 100%; height: 100%; object-fit: cover; object-position: top; }
  .img-thumb span { font-size: .75rem; color: var(--muted); }
  .img-meta { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; }
  .img-meta strong { flex-basis: 100%; } .img-meta small { flex-basis: 100%; color: var(--muted); margin-top: -4px; }
  .img-meta form { margin: 0; }
  .file-btn { position: relative; overflow: hidden; } .file-btn input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
  .bar-preview { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 10px 44px 10px 14px; position: relative; border-radius: 12px; background: linear-gradient(100deg, #FFD166, #FFE4A0 55%, #FFD166); color: var(--ink); font-size: .88rem; font-weight: 600; transition: opacity .2s, filter .2s; }
  .bar-preview.is-off { opacity: .45; filter: grayscale(1); }
  .bar-preview .bp-badge { padding: 3px 9px; border-radius: 999px; background: var(--ink); color: var(--sun); font-size: .72rem; font-weight: 800; letter-spacing: .04em; text-transform: uppercase; }
  .bar-preview .bp-link { padding: 4px 11px; border-radius: 999px; background: rgba(15,37,64,.1); font-weight: 700; }
  .bar-preview .bp-x { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); opacity: .6; }
  .bar-preview [hidden] { display: none; }
  .cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px 14px; }
  .cols .span2 { grid-column: span 2; }
  .unit-in { display: flex; align-items: center; gap: 8px; } .unit-in input { flex: 1; } .unit-in i { font-style: normal; color: var(--muted); font-weight: 600; white-space: nowrap; }
  input[type=number] { width: 100%; padding: .7rem .85rem; border: 1.5px solid var(--line); border-radius: 12px; font: 500 .95rem/1.4 "Jakarta", sans-serif; color: var(--ink); }
  @media (max-width: 900px) { .grid { grid-template-columns: 1fr; } .cols .span2 { grid-column: auto; } }
  /* ---------- Tabs, Übersicht, Anfragen, Statistik ---------- */
  .bar { gap: 12px; }
  .tabs { display: flex; gap: 4px; margin: 0 auto; }
  .tabs a { position: relative; display: flex; align-items: center; gap: 8px; padding: 9px 14px; border-radius: 999px; text-decoration: none; font-weight: 700; font-size: .9rem; color: var(--muted); transition: background .2s, color .2s; }
  .tabs a svg { width: 19px; height: 19px; }
  .tabs a:hover { color: var(--ink); background: var(--paper); }
  .tabs a[aria-current] { background: var(--ink); color: #fff; }
  .badge-n { font-style: normal; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: var(--sun); color: var(--ink); font-size: .72rem; font-weight: 800; display: grid; place-items: center; }
  .bar-r { display: flex; gap: 16px; font-weight: 600; font-size: .9rem; white-space: nowrap; } .bar-r a { text-decoration: none; } .bar-r a:hover { color: var(--cobalt); }
  .page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 14px; flex-wrap: wrap; margin: 4px 0 20px; }
  .page-head h1 { font-size: clamp(1.8rem, 3.6vw, 2.5rem); }
  .page-head .sub { color: var(--muted); margin: 4px 0 0; }
  .backlink { display: inline-block; font-size: .9rem; font-weight: 600; text-decoration: none; color: var(--muted); padding: 4px 0; margin-bottom: 4px; }
  .cols-2-1, .cols-1-1, .cols-3 { display: grid; gap: 20px; align-items: start; margin-bottom: 20px; }
  .cols-2-1 { grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); } .cols-1-1 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .cols-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .admin > .card { margin-bottom: 20px; }
  .card-h { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 14px; } .card-h h2 { margin: 0; }
  .more { font-size: .88rem; font-weight: 700; color: var(--cobalt); text-decoration: none; white-space: nowrap; }
  .hint code { background: var(--paper); padding: 1px 6px; border-radius: 6px; font-size: .82em; overflow-wrap: anywhere; word-break: break-all; }
  .head-tags .pill-s { background: #fff; border: 1px solid var(--line); padding: 4px 10px; }
  .mt { margin-top: 22px !important; }
  .card.flush { padding: 0; overflow: hidden; } .empty.pad { margin: 18px; }
  .kpis.k6 { grid-template-columns: repeat(6, 1fr); }
  .kpis > div { background: #fff; border: 1px solid var(--line); }
  .kpis b { white-space: nowrap; font-size: 1.75rem; }
  .trend { font-style: normal; font-size: .76rem; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
  .legend { display: flex; align-items: center; gap: 6px; font-size: .8rem; color: var(--muted); } .legend i { width: 10px; height: 10px; border-radius: 3px; background: var(--sun); }
  .chart .cbar { position: relative; }
  .chart .cbar.has-a i { background: linear-gradient(var(--sun) 0 6px, var(--ink) 6px); }
  [data-tip]:hover::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--ink); color: #fff; font-size: .74rem; font-weight: 600; padding: 5px 9px; border-radius: 8px; white-space: nowrap; z-index: 3; pointer-events: none; }
  .bars .brow { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) auto 42px; gap: 10px; align-items: center; padding: 8px 10px; margin-bottom: 4px; border-radius: 9px; overflow: hidden; font-size: .9rem; }
  .bars .fill { position: absolute; inset: 0 auto 0 0; width: var(--w); background: var(--paper); border-radius: 9px; z-index: 0; }
  .bars .brow > *:not(.fill) { position: relative; z-index: 1; }
  .bars .lbl { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .bars small { color: var(--muted); text-align: right; font-size: .78rem; }
  .card h3 small { font: 500 .8rem "Jakarta", sans-serif; color: var(--muted); }
  .funnel .frow { display: grid; grid-template-columns: 150px minmax(0, 1fr) 56px; gap: 10px; align-items: center; margin-bottom: 8px; font-size: .88rem; }
  .funnel i { display: block; height: 22px; border-radius: 7px; background: linear-gradient(90deg, var(--ink) var(--w), var(--paper) var(--w)); }
  .funnel b { text-align: right; }
  .hours { display: flex; align-items: flex-end; gap: 3px; height: 150px; }
  .hours span { position: relative; flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 4px; }
  .hours i { display: block; width: 100%; height: var(--h); min-height: 2px; background: var(--ink); border-radius: 4px 4px 1px 1px; opacity: .85; }
  .hours span:hover i { background: var(--cobalt); opacity: 1; }
  .hours small { font-size: .68rem; color: var(--muted); height: 12px; } .hours.wd small { font-size: .78rem; }
  .insights { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
  .insights li { position: relative; padding: 10px 12px 10px 42px; border-radius: 12px; background: var(--paper); font-size: .92rem; line-height: 1.45; }
  .insights li::before { position: absolute; left: 13px; top: 9px; font-size: 1rem; }
  .in-hot { background: #FFF3D1 !important; } .in-hot::before { content: "🔥"; } .in-form::before { content: "📝"; } .in-src::before { content: "🧭"; } .in-time::before { content: "🕒"; }
  .in-phone::before { content: "📱"; } .in-ig::before { content: "📷"; } .in-plan::before { content: "📦"; } .in-ex::before { content: "🖼️"; } .in-lp::before { content: "📣"; }
  .stats-foot { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
  .stats-foot form { margin: 0; }
  /* Anfragen */
  .pipeline { display: grid; grid-template-columns: repeat(6, 1fr); gap: 8px; margin-bottom: 14px; }
  .pipeline a { background: #fff; border: 1px solid var(--line); border-radius: 14px; padding: 10px 12px; text-decoration: none; font-size: .82rem; font-weight: 600; color: var(--muted); display: flex; flex-direction: column; gap: 2px; }
  .pipeline b { display: flex; align-items: center; gap: 7px; font: 800 1.3rem/1.2 "Bricolage", sans-serif; color: var(--ink); }
  .pipeline a.on { border-color: var(--ink); box-shadow: inset 0 0 0 1px var(--ink); }
  .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
  .dot.st-neu { background: var(--sun); box-shadow: 0 0 0 2px #FFF3D1; } .dot.st-kontakt { background: var(--cobalt); } .dot.st-angebot { background: #8B5CF6; } .dot.st-gewonnen { background: var(--ok); } .dot.st-verloren { background: #B8C2D0; }
  .search input { width: 100%; margin-bottom: 14px; padding: .7rem .9rem; border: 1.5px solid var(--line); border-radius: 12px; font: 500 .95rem "Jakarta", sans-serif; }
  .leads { list-style: none; margin: 0; padding: 0; }
  .lead a { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px 20px; text-decoration: none; border-top: 1px solid var(--line); }
  .lead:first-child a { border-top: 0; } .lead a:hover { background: var(--paper); }
  .leads.compact .lead a { padding: 11px 0; } .leads.compact .lead a:hover { background: none; }
  .lead.unread .lm b::before { content: ""; display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: var(--cobalt); margin-right: 8px; vertical-align: middle; }
  .lm { min-width: 0; } .lm b { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .lm small { display: block; color: var(--muted); font-size: .82rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .lr { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; } .lr small { font-size: .76rem; color: var(--muted); } .rec-s { font-size: .78rem; font-weight: 700; }
  .temp { font-size: .74rem; font-weight: 800; padding: 4px 10px; border-radius: 999px; white-space: nowrap; }
  .t-hot { background: #FFD166; color: var(--ink); } .t-warm { background: #E7ECFF; color: #2B44C9; } .t-cold { background: #EEF1F5; color: var(--muted); }
  .pill-s { display: inline-flex; align-items: center; gap: 6px; font-size: .76rem; font-weight: 700; padding: 3px 10px; border-radius: 999px; background: var(--paper); white-space: nowrap; }
  .head-tags { display: flex; gap: 8px; align-items: center; }
  .quick { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .rec-box { display: grid; gap: 2px; background: var(--ink); color: #fff; border-radius: 14px; padding: 14px 16px; margin-bottom: 18px; }
  .rec-box span { font-size: .72rem; letter-spacing: .12em; text-transform: uppercase; color: var(--sun); font-weight: 800; }
  .rec-box b { font: 800 1.5rem/1.15 "Bricolage", sans-serif; } .rec-box small { color: #C3CFDE; }
  .facts { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 9px 14px; margin: 0 0 16px; font-size: .94rem; }
  .facts dt { color: var(--muted); } .facts dd { margin: 0; overflow-wrap: anywhere; }
  .chip-s { display: inline-block; background: var(--paper); border-radius: 999px; padding: 2px 10px; margin: 0 4px 4px 0; font-size: .84rem; font-weight: 600; }
  .msg { background: var(--paper); border-radius: 12px; padding: 14px; margin: 8px 0 0; }
  .status-pick { display: grid; gap: 6px; }
  .st { display: flex; align-items: center; gap: 10px; text-align: left; border: 1.5px solid var(--line); background: #fff; border-radius: 12px; padding: 11px 14px; font: 600 .92rem "Jakarta", sans-serif; color: var(--ink); cursor: pointer; }
  .st:hover { border-color: #B8C2D0; } .st.on { border-color: var(--ink); background: var(--paper); } .st.on::after { content: "✓"; margin-left: auto; font-weight: 800; }
  .notes { list-style: none; padding: 0; margin: 14px 0 0; display: grid; gap: 8px; }
  .notes li { background: var(--paper); border-radius: 12px; padding: 10px 12px; font-size: .9rem; } .notes li.sys { background: transparent; border: 1.5px dashed var(--line); color: var(--muted); }
  .notes small { display: block; font-size: .74rem; color: var(--muted); margin-bottom: 2px; }
  .btnrow { display: flex; gap: 8px; flex-wrap: wrap; }
  .btn[disabled] { opacity: .5; pointer-events: none; }
  input:disabled { background: var(--paper); color: var(--muted); }
  @media (max-width: 1100px) {
    .kpis.k6 { grid-template-columns: repeat(3, 1fr); } .cols-3 { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 1240px) and (min-width: 761px) { .tg-l { display: none; } }
  @media (max-width: 1000px) and (min-width: 761px) { .tabs a > span { display: none; } .tabs a { padding: 10px; } }
  @media (max-width: 760px) {
    body.app { padding-bottom: calc(72px + env(safe-area-inset-bottom, 0px)); }
    .bar-r a:first-child { display: none; }
    .tabs { position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; margin: 0; justify-content: space-around; gap: 0; background: rgba(255,255,255,.97); padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px)); border-top: 1px solid var(--line); box-shadow: 0 -6px 20px rgba(15,37,64,.06); }
    .tabs a { flex-direction: column; gap: 3px; padding: 6px 4px; border-radius: 12px; font-size: .66rem; min-width: 62px; }
    .tabs a span { display: block; } .tabs a svg { width: 22px; height: 22px; }
    .tabs a[aria-current] { background: transparent; color: var(--ink); } .tabs a[aria-current] svg { color: var(--cobalt); }
    .badge-n { position: absolute; top: 0; right: 10px; }
    .cols-2-1, .cols-1-1, .cols-3 { grid-template-columns: 1fr; }
    .kpis, .kpis.k6 { grid-template-columns: 1fr 1fr; gap: 10px; } .kpis b { font-size: 1.45rem; }
    .range { width: 100%; } .range a { flex: 1; text-align: center; padding: .45rem .3rem; }
    .pipeline { display: flex; overflow-x: auto; scrollbar-width: none; margin: 0 -16px 14px; padding: 0 16px 4px; } .pipeline a { flex: 0 0 auto; min-width: 104px; }
    .lead a { grid-template-columns: minmax(0, 1fr) auto; padding: 13px 16px; } .lead .temp { grid-column: 1 / -1; justify-self: start; }
    .facts { grid-template-columns: 1fr; gap: 2px; } .facts dd { margin-bottom: 10px; }
    .quick .btn { flex: 1 1 45%; }
    .funnel .frow { grid-template-columns: 110px minmax(0, 1fr) 46px; font-size: .82rem; }
    .card { padding: 18px; }
  }
  .totp-setup { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 22px; align-items: start; margin-bottom: 14px; }
  .totp-qr { width: 210px; aspect-ratio: 1; padding: 8px; background: #fff; border: 1.5px solid var(--line); border-radius: 14px; }
  .totp-qr svg { width: 100%; height: 100%; display: block; }
  .totp-steps { margin: 0; padding-left: 20px; display: grid; gap: 14px; }
  .totp-key { display: inline-block; margin-top: 6px; padding: 6px 10px; background: var(--paper); border-radius: 8px; font: 700 .95rem/1.4 ui-monospace, monospace; letter-spacing: .05em; word-break: break-all; }
  .rc-codes { margin: 12px 0; padding: 12px 14px; background: #fff; border-radius: 10px; font: 700 1rem/1.7 ui-monospace, monospace; letter-spacing: .06em; color: var(--ink); white-space: pre-wrap; }
  @media (max-width: 700px) { .totp-setup { grid-template-columns: 1fr; } .totp-qr { width: min(240px, 100%); } }
  /* ---------- Neue Kopfzeile: Suche + Bereiche in Gruppen ---------- */
  .bar { position: relative; }
  .gsearch { position: relative; flex: 0 1 340px; margin: 0 auto; }
  .gsearch input { width: 100%; padding: .6rem 2.4rem .6rem 2.3rem; border: 1.5px solid var(--line); border-radius: 999px; background: var(--paper) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235B6B80' stroke-width='2' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E") no-repeat 12px 50% / 16px; font: 500 .92rem "Jakarta", sans-serif; color: var(--ink); }
  .gsearch input:focus { background-color: #fff; }
  .gsearch kbd { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); font: 700 .72rem ui-monospace, monospace; color: var(--muted); border: 1px solid var(--line); border-radius: 5px; padding: 1px 6px; background: #fff; }
  .gsearch input:focus + kbd { display: none; }
  nav.tabs { position: sticky; top: 0; z-index: 6; display: flex; align-items: center; gap: 6px; margin: 0; padding: 8px clamp(16px, 3vw, 32px); background: rgba(255,255,255,.97); border-bottom: 1px solid var(--line); overflow-x: auto; scrollbar-width: none; }
  .tg { display: flex; align-items: center; gap: 2px; }
  .tg + .tg { padding-left: 10px; margin-left: 6px; border-left: 1.5px solid var(--line); }
  .tg-l { font-size: .68rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; color: #93A1B5; margin-right: 6px; }
  .tg-fin { margin-left: auto !important; border-left: 0 !important; }
  .tabs a { white-space: nowrap; }
  .tabs-more { display: none; }
  .tabs-more summary { list-style: none; cursor: pointer; } .tabs-more summary::-webkit-details-marker { display: none; }
  .subnav { display: flex; gap: 6px; flex-wrap: wrap; margin: -6px 0 18px; }
  .subnav a { padding: 6px 12px; border-radius: 999px; background: #fff; border: 1px solid var(--line); font-size: .84rem; font-weight: 700; text-decoration: none; color: var(--muted); }
  .subnav a:hover { color: var(--ink); border-color: #B8C2D0; }
  section[id], .card[id] { scroll-margin-top: 70px; }
  .cols { align-items: end; }
  input:not([type]) { width: 100%; padding: .7rem .85rem; border: 1.5px solid var(--line); border-radius: 12px; font: 500 .95rem/1.4 "Jakarta", sans-serif; color: var(--ink); background: #fff; }
  input:not([type]):focus { outline: none; border-color: var(--cobalt); box-shadow: 0 0 0 3px rgba(59,91,255,.15); }
  select, input[type=time], input[type=search] { width: 100%; padding: .7rem .85rem; border: 1.5px solid var(--line); border-radius: 12px; font: 500 .95rem/1.4 "Jakarta", sans-serif; color: var(--ink); background: #fff; }
  select { appearance: none; background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%235B6B80' stroke-width='2'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E") no-repeat right 12px center / 16px; padding-right: 36px; }
  select:focus, input[type=time]:focus, input[type=number]:focus { outline: none; border-color: var(--cobalt); box-shadow: 0 0 0 3px rgba(59,91,255,.15); }
  .tag.warn { background: #FFF1CC; color: #7A5B00; } .tag.bad { background: #FDECEC; color: #A3262B; } .tag:not(.on):not(.no):not(.warn):not(.bad) { background: var(--paper); color: var(--ink); }
  .muted-s { font: 600 .85rem "Jakarta", sans-serif; color: var(--muted); }
  details.tech { border: 1.5px solid var(--line); border-radius: 14px; padding: 12px 14px; }
  details.tech summary { cursor: pointer; font-weight: 700; } details.tech[open] summary { margin-bottom: 12px; }
  details.tech summary small { color: var(--muted); font-weight: 600; }
  /* Übersicht: Termine & Aufgaben */
  .agenda { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
  .agenda a { display: grid; grid-template-columns: 74px minmax(0, 1fr); gap: 12px; align-items: center; padding: 9px 10px; border-radius: 12px; text-decoration: none; background: var(--paper); }
  .agenda a:hover { background: #E9EEF5; }
  .agenda li.today a { background: #FFF6DD; box-shadow: inset 3px 0 0 var(--sun); }
  .ag-d { display: grid; } .ag-d b { font-size: .9rem; } .ag-d small { color: var(--muted); font-size: .8rem; font-weight: 600; }
  .todo a { text-decoration: none; } .todo a:hover { text-decoration: underline; }
  .in-follow::before { content: "⏰"; } .in-warn::before { content: "⚠️"; } .in-cal::before { content: "📅"; } .in-wait::before { content: "⏳"; } .in-offer::before { content: "📨"; }
  .follow-s { font-size: .76rem; font-weight: 700; color: var(--muted); } .follow-s.due { color: #A3262B; }
  .follow-now { margin: 0 0 8px; font-weight: 700; } .follow-now.due { color: #A3262B; }
  .follow .row { flex-wrap: nowrap; } .follow input[type=date] { flex: 1; }
  /* Termine */
  .week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 8px; }
  .wday { min-height: 150px; display: flex; flex-direction: column; gap: 5px; padding: 8px; border-radius: 14px; background: var(--paper); }
  .wday.off { background: repeating-linear-gradient(-45deg, #F3F6FB 0 8px, #EDF1F7 8px 16px); }
  .wday.today { background: #FFF6DD; box-shadow: inset 0 0 0 2px var(--sun); }
  .wd-h { display: grid; grid-template-columns: auto 1fr; gap: 0 6px; align-items: baseline; margin-bottom: 4px; }
  .wd-h b { font: 800 1.05rem "Bricolage", sans-serif; } .wd-h span { font-size: .8rem; font-weight: 700; color: var(--muted); } .wd-h small { grid-column: 1 / -1; font-size: .7rem; color: #93A1B5; font-weight: 600; }
  .wev { display: grid; padding: 6px 8px; border-radius: 9px; background: #fff; text-decoration: none; border-left: 4px solid var(--ok); font-size: .78rem; line-height: 1.3; box-shadow: 0 1px 2px rgba(15,37,64,.06); }
  .wev:hover { box-shadow: 0 4px 12px rgba(15,37,64,.12); }
  .wev b { font-size: .8rem; } .wev span { font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; } .wev small { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .wev.st-offen { border-left-color: var(--sun); background: #FFFDF5; border-style: solid dashed dashed solid; border-width: 0 0 0 4px; } .wev.st-abgelehnt { border-left-color: var(--danger); opacity: .75; }
  .wadd { margin-top: auto; display: grid; place-items: center; height: 30px; border-radius: 9px; border: 1.5px dashed #C9D3E0; color: #93A1B5; font-weight: 800; text-decoration: none; opacity: 0; transition: opacity .2s; }
  .wday:hover .wadd, .wadd:focus { opacity: 1; }
  .legend-row { display: flex; flex-wrap: wrap; gap: 14px; margin: 12px 0 0; font-size: .8rem; color: var(--muted); } .legend-row span { display: inline-flex; gap: 6px; align-items: center; }
  .lg { width: 12px; height: 12px; border-radius: 3px; background: var(--ok); } .lg.st-offen { background: var(--sun); } .lg.st-abgelehnt { background: var(--danger); }
  fieldset.fs { border: 1.5px solid var(--line); border-radius: 16px; padding: 14px 16px 16px; margin: 0; display: grid; gap: 12px; }
  fieldset.fs legend { font: 700 1rem "Bricolage", sans-serif; padding: 0 6px; }
  .mode-row { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
  .mode-s { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border: 1.5px solid var(--line); border-radius: 12px; cursor: pointer; }
  .mode-s:has(input:checked) { border-color: var(--ink); background: var(--paper); }
  .mode-s input { accent-color: var(--ink); width: 17px; height: 17px; margin-top: 2px; flex: none; }
  .mode-s span { display: grid; } .mode-s b { font-size: .9rem; } .mode-s small { color: var(--muted); font-weight: 500; font-size: .8rem; }
  .times { display: grid; gap: 8px; }
  .trow { display: grid; grid-template-columns: 30px minmax(0, 1fr) minmax(0, 140px) 34px; gap: 8px; align-items: center; }
  .t-n { display: grid; place-items: center; width: 28px; height: 28px; border-radius: 50%; background: var(--ink); color: var(--sun); font: 800 .85rem "Bricolage", sans-serif; }
  .types-ed { display: grid; gap: 8px; } .types-ed .trow { grid-template-columns: minmax(0, 1.2fr) 110px minmax(0, 1.5fr) 34px; }
  .free { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; } .free-l { font-size: .82rem; font-weight: 700; color: var(--muted); margin-right: 2px; }
  .free-c { display: contents; }
  .chip { border: 1.5px solid var(--line); background: #fff; border-radius: 999px; padding: 6px 11px; font: 700 .8rem "Jakarta", sans-serif; color: var(--ink); cursor: pointer; }
  .chip:hover { border-color: var(--cobalt); color: var(--cobalt); } .chip.used { background: var(--ink); color: #fff; border-color: var(--ink); }
  .bk-list { display: grid; }
  .bk-dayh { font-size: 1rem; margin: 16px 0 6px; display: flex; gap: 8px; align-items: center; } .bk-dayh:first-child { margin-top: 0; }
  .bk-row { display: grid; grid-template-columns: 64px minmax(0, 1fr) auto; gap: 14px; align-items: start; padding: 12px 0; border-top: 1px solid var(--line); }
  .bk-time { display: grid; } .bk-time b { font: 800 1.15rem "Bricolage", sans-serif; } .bk-time small { color: var(--muted); font-size: .78rem; }
  .bk-who { display: flex; flex-wrap: wrap; gap: 4px 6px; align-items: center; } .bk-who a { text-decoration: none; } .bk-who a:hover { color: var(--cobalt); }
  .bk-who small { flex-basis: 100%; color: var(--muted); font-size: .82rem; } .bk-optl { color: var(--ink) !important; font-weight: 600; }
  .bk-answer { flex-basis: 100%; margin: 4px 0 0; padding: 8px 10px; border-radius: 10px; background: var(--paper); font-size: .88rem; }
  .bk-warn { flex-basis: 100%; margin: 2px 0 0; font-size: .82rem; font-weight: 700; color: #7A5B00; }
  .bk-row .btnrow form { margin: 0; }
  .bk-big { display: grid; gap: 2px; padding: 14px 16px; margin-bottom: 16px; border-radius: 14px; background: var(--ink); color: #fff; }
  .bk-big span { color: var(--sun); font-weight: 700; font-size: .9rem; } .bk-big b { font: 800 1.6rem "Bricolage", sans-serif; }
  .bk-when-list { margin: 0 0 16px; padding-left: 22px; display: grid; gap: 6px; }
  .bk-tools { display: grid; gap: 10px; margin-top: 16px; }
  .table-scroll { overflow-x: auto; } .tbl { width: 100%; border-collapse: collapse; font-size: .88rem; }
  .tbl th { text-align: left; font-size: .74rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); padding: 8px; border-bottom: 1.5px solid var(--line); }
  .tbl td { padding: 8px; border-bottom: 1px solid var(--line); vertical-align: middle; } .tbl td small { color: var(--muted); } .tbl form { margin: 0; }
  .hours-ed { display: grid; gap: 6px; }
  .hrow { display: grid; grid-template-columns: 150px 130px 28px 130px; justify-content: start; gap: 8px; align-items: center; } .hrow span { color: var(--muted); font-size: .85rem; }
  .hrow:has(input[type=checkbox]:not(:checked)) input[type=time] { opacity: .4; }
  .steps-s { margin: 0 0 14px; padding-left: 20px; display: grid; gap: 4px; font-size: .9rem; }
  .feed { padding: 10px 12px; margin-bottom: 10px; border-radius: 10px; background: var(--paper); font: 600 .78rem/1.5 ui-monospace, monospace; word-break: break-all; }
  .card h2 small.muted-s { margin-left: 4px; }
  /* Einstellungen */
  .log { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; max-height: 420px; overflow: auto; }
  .log li { display: grid; grid-template-columns: 26px minmax(0, 1fr) auto; gap: 10px; align-items: center; padding: 8px 10px; border-radius: 10px; background: var(--paper); font-size: .88rem; }
  .log li > small { color: var(--muted); font-size: .78rem; white-space: nowrap; }
  .lg-i { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: #E4F6EF; color: #0F6B4E; font-weight: 800; font-size: .8rem; }
  .log li.bad { background: #FDF3F3; } .log li.bad .lg-i { background: #FDECEC; color: #A3262B; }
  .checks { list-style: none; margin: 0; padding: 0; display: grid; gap: 6px; }
  .checks li { display: grid; grid-template-columns: 26px minmax(0, 1fr); gap: 10px; align-items: start; font-size: .9rem; padding: 6px 0; }
  .checks li > span { display: grid; place-items: center; width: 24px; height: 24px; border-radius: 50%; background: #E4F6EF; color: #0F6B4E; font-weight: 800; font-size: .8rem; }
  .checks li.no > span { background: #FFF1CC; color: #7A5B00; } .checks li small { display: block; color: var(--muted); }
  .pad-h { padding: 20px 20px 0; }
  @media (max-width: 1100px) { .week { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
  @media (max-width: 760px) {
    .gsearch { display: none; }
    nav.tabs { position: fixed; top: auto; overflow: visible; padding: 6px 8px calc(6px + env(safe-area-inset-bottom, 0px)); border-bottom: 0; gap: 0; }
    .tg, .tg + .tg { display: contents; } .tg-l, .tabs a.sec { display: none !important; }
    .tabs-more { display: block; position: relative; }
    .tabs-more summary { display: flex; flex-direction: column; align-items: center; gap: 3px; padding: 6px 4px; border-radius: 12px; font-size: .66rem; font-weight: 700; color: var(--muted); min-width: 62px; }
    .tabs-more summary svg { width: 22px; height: 22px; }
    .tabs-more[data-cur] summary { color: var(--ink); } .tabs-more[data-cur] summary svg { color: var(--cobalt); }
    .more-sheet { position: absolute; right: -4px; bottom: calc(100% + 12px); width: min(300px, calc(100vw - 24px)); display: grid; gap: 2px; padding: 8px; background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 20px 50px rgba(15,37,64,.22); }
    .more-sheet a { flex-direction: row !important; gap: 12px !important; padding: 10px 12px !important; font-size: .95rem !important; color: var(--ink) !important; justify-content: flex-start; }
    .more-sheet a span { display: grid !important; } .more-sheet small { font-size: .74rem; color: var(--muted); font-weight: 600; }
    .more-sheet a[aria-current] { background: var(--paper) !important; }
    .week { grid-template-columns: repeat(7, minmax(120px, 1fr)); overflow-x: auto; margin: 0 -18px; padding: 0 18px 6px; scroll-snap-type: x mandatory; } .wday { scroll-snap-align: start; }
    .mode-row { grid-template-columns: 1fr; }
    .trow { grid-template-columns: 28px minmax(0, 1fr) 110px 30px; }
    .types-ed .trow { grid-template-columns: minmax(0, 1fr) 96px 30px; } .types-ed .trow input[name$="[where]"] { grid-column: 1 / 3; grid-row: 2; }
    .bk-row { grid-template-columns: 54px minmax(0, 1fr); } .bk-row .btnrow { grid-column: 1 / -1; }
    .hrow { grid-template-columns: 1fr 1fr auto 1fr; } .hrow label { grid-column: 1 / -1; }
    .subnav { flex-wrap: nowrap; overflow-x: auto; scrollbar-width: none; margin: -6px -16px 16px; padding: 0 16px; } .subnav a { white-space: nowrap; }
  }
  .reply { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--line); }
  .reply h3 small { font: 500 .82rem "Jakarta", sans-serif; color: var(--muted); margin-left: 6px; }
  .tpl-pick { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin: 10px 0 12px; } .tpl-pick .more { margin-left: auto; }
  .chip.on { background: var(--ink); color: #fff; border-color: var(--ink); }
  .tpl-ed { display: grid; gap: 8px; }
  details.restore summary { cursor: pointer; font-weight: 700; font-size: .84rem; color: var(--cobalt); white-space: nowrap; }
  details.restore .row { flex-wrap: nowrap; } details.restore input { min-width: 150px; }
</style>
</head>
<body class="<?= $app ? 'app' : 'auth-page' ?>">
<?php }
function foot(): void { echo "</body>\n</html>\n"; }
