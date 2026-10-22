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
    if ($t === '') { $a = (string)($_POST['action'] ?? ''); $t = in_array($a, ['login', 'setup'], true) ? 'start' : (str_starts_with($a, 'lead_') || $a === 'test_mail' ? 'anfragen' : (str_starts_with($a, 'stats') ? 'statistik' : 'webseite')); }
    header('Location: ' . $self . '?tab=' . urlencode($t) . $q, true, 303); exit;
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
$tabs = ['start' => 'Übersicht', 'anfragen' => 'Anfragen', 'statistik' => 'Statistik', 'webseite' => 'Webseite'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? (string)$_GET['tab'] : 'start';

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
    if ($post && $action === 'login') {
        if (lx_throttled('login', 6)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
        elseif (password_verify((string)($_POST['password'] ?? ''), $c['admin_hash'])) {
            session_regenerate_id(true);
            $_SESSION['lx_admin'] = true; $_SESSION['lx_admin_until'] = time() + 8 * 3600;
            back();
        } else { lx_fail('login'); $error = 'Passwort falsch.'; }
    }
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
            lx_save_config($c);
            break;
        case 'launch_now':
            $c['mode'] = 'live'; lx_save_config($c); flash('Die Seite ist jetzt öffentlich.'); break;
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
            lx_save_config($c); flash('Webseite aktualisiert – die Änderungen sind sofort sichtbar.');
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
        case 'admin_pw':
            $old = (string)($_POST['old'] ?? ''); $new = (string)($_POST['new'] ?? '');
            if (!password_verify($old, $c['admin_hash'])) flash('Aktuelles Passwort falsch.', 'error');
            elseif (strlen($new) < 10) flash('Das neue Passwort braucht mindestens 10 Zeichen.', 'error');
            else { $c['admin_hash'] = password_hash($new, PASSWORD_DEFAULT); lx_save_config($c); flash('Admin-Passwort geändert.'); }
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
            $from = trim((string)($_POST['mail_from'] ?? ''));
            if ($from !== '' && !filter_var($from, FILTER_VALIDATE_EMAIL)) { flash('Die Absender-Adresse ist keine gültige E-Mail-Adresse.', 'error'); back(); }
            $c['site']['mail_from'] = $from;
            lx_save_config($c); flash('Einstellungen für Anfragen gespeichert.');
            back();
        case 'test_mail':
            $ok = lx_mail((string)$c['site']['email'], 'Test-E-Mail von loggx.de', "Hallo Constantin,\n\nwenn du diese E-Mail liest, kommen Anfragen von deiner Webseite richtig bei dir an.\n\nAbsender: " . lx_mail_from() . "\n\nLoggX Webseite\n");
            flash($ok ? 'Test-E-Mail an ' . $c['site']['email'] . ' verschickt – bitte auch im Spam-Ordner nachsehen.' : 'Versand fehlgeschlagen. Trag als Absender eine Adresse deiner Domain ein (z. B. kontakt@loggx.de) und versuch es noch einmal.', $ok ? 'ok' : 'error');
            back();
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
  <nav class="tabs" aria-label="Bereiche">
    <?php foreach ($tabs as $k => $t): ?><a href="?tab=<?= $k ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= icon($k) ?><span><?= $t ?></span><?= $k === 'anfragen' && $newLeads ? '<i class="badge-n">' . $newLeads . '</i>' : '' ?></a><?php endforeach; ?>
  </nav>
  <nav class="bar-r"><a href="<?= e(lx_url('')) ?>" target="_blank" rel="noopener">Seite ansehen ↗</a><a href="?logout">Abmelden</a></nav>
</header>
<main class="admin">
  <?php if ($flash): ?><p class="flash <?= e($flash[1]) ?>" role="status"><?= e($flash[0]) ?></p><?php endif; ?>
  <?php if ($eff !== 'live' && $tab !== 'webseite'): ?><p class="flash warn">Besucher sehen gerade: <strong><?= e($modeNames[$eff]) ?></strong>. <a href="?tab=webseite">Ändern</a></p><?php endif; ?>

<?php /* ======================= ÜBERSICHT ======================= */ if ($tab === 'start'):
  $maxDay = max(1, ...array_map(fn($d) => $d['u'], $st['days']));
  $conv = $st['u'] ? $st['a'] / $st['u'] * 100 : 0;
  $hello = (int)date('G') < 11 ? 'Guten Morgen' : ((int)date('G') < 18 ? 'Hallo' : 'Guten Abend');
?>
  <div class="page-head">
    <div><h1><?= $hello ?>, Constantin.</h1><p class="sub">So läuft loggx.de in den letzten <?= $range === 365 ? '12 Monaten' : $range . ' Tagen' ?>.</p></div>
    <?= range_nav($range, 'start') ?>
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
    <div><h1><?= e($d['firma'] ?: $d['name']) ?></h1><p class="sub"><?= e(lx_date_de((int)$one['created'])) ?> · über <?= e($one['src']) ?><?= ($one['page'] ?? '') === 'angebot' ? ' (Landingpage)' : '' ?> · <?= e($one['device']) ?></p></div>
    <div class="head-tags"><span class="temp t-<?= $tk ?>"><?= $tl ?> · <?= (int)$one['score'] ?></span><span class="pill-s"><i class="dot st-<?= e($one['status']) ?>"></i><?= e($stat[$one['status']] ?? '') ?></span></div>
  </div>
  <div class="cols-2-1">
    <section class="card">
      <div class="quick">
        <a class="btn" href="<?= e($mailto) ?>">✉️ Per E-Mail antworten</a>
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
    </section>
    <aside class="card">
      <h2>Status</h2>
      <form method="post" class="status-pick"><?= $hidden('lead_status') ?><input type="hidden" name="id" value="<?= e($one['id']) ?>">
        <?php foreach ($stat as $k => $t): ?><button name="status" value="<?= $k ?>" class="st<?= $one['status'] === $k ? ' on' : '' ?>"><i class="dot st-<?= $k ?>"></i><?= e($t) ?></button><?php endforeach; ?>
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
  <section class="card">
    <h2>Einstellungen für Anfragen</h2>
    <form method="post" class="stack"><?= $hidden('lead_settings') ?>
      <label class="check"><input type="checkbox" name="autoreply" value="1"<?= !empty($c['leads']['autoreply']) ? ' checked' : '' ?>> Automatische Bestätigung an den Kunden senden (mit Paket-Empfehlung)</label>
      <div class="cols">
        <label>Anfragen gehen an <small>(änderbar unter Webseite → Kontaktdaten)</small><input type="email" value="<?= e($c['site']['email']) ?>" disabled></label>
        <label>Absender-Adresse <small>(eine Adresse deiner Domain, z. B. kontakt@loggx.de)</small><input type="email" name="mail_from" value="<?= e($c['site']['mail_from'] ?? '') ?>" placeholder="<?= e(lx_mail_from()) ?>"></label>
        <label>Anfragen automatisch löschen nach <span class="unit-in"><input type="number" name="keep_months" min="0" max="60" value="<?= (int)$c['leads']['keep_months'] ?>"><i>Monaten</i></span><small>0 = nie · „Auftrag erhalten“ bleibt immer</small></label>
      </div>
      <div class="btnrow"><button class="btn btn-small">Speichern</button></div>
    </form>
    <form method="post" class="mt"><?= $hidden('test_mail') ?><button class="btn btn-small btn-ghost">Test-E-Mail an mich schicken</button></form>
  </section>
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

<?php /* ======================= WEBSEITE ======================= */ else: ?>
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
  <div class="grid">
    <!-- Status & Launch -->
    <section class="card">
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
    <section class="card">
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

    <!-- Webseite: Preise, Kontakt, Hinweise -->
    <section class="card card-wide">
      <h2>Webseite: Preise, Kontakt &amp; Hinweise</h2>
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
            <label>Telefon<input type="text" name="phone" maxlength="40" value="<?= e($S['phone']) ?>" placeholder="+49 (0) 541 123456"></label>
            <label>E-Mail <small>(auch Empfänger der Anfragen)</small><input type="email" name="email" required value="<?= e($S['email']) ?>"></label>
            <label>Straße<input type="text" name="street" maxlength="80" value="<?= e($S['street']) ?>"></label>
            <label>PLZ und Ort<input type="text" name="city" maxlength="80" value="<?= e($S['city']) ?>"></label>
          </div>
          <?php if ($S['phone'] === lx_defaults()['site']['phone']): ?><p class="flash warn" style="margin:10px 0 0">Die Telefonnummer ist noch der Platzhalter – bitte vor dem Livegang eintragen.</p><?php endif; ?>
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
    <section class="card">
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
    <section class="card">
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

    <!-- Anmeldungen -->
    <section class="card">
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

      <details class="more">
        <summary>Admin-Passwort ändern</summary>
        <form method="post" class="stack">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="admin_pw">
          <label>Aktuelles Passwort<input type="password" name="old" required autocomplete="current-password"></label>
          <label>Neues Passwort <small>(mind. 10 Zeichen)</small><input type="password" name="new" required minlength="10" autocomplete="new-password"></label>
          <button class="btn">Passwort ändern</button>
        </form>
      </details>
    </section>
  </div>
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
        . '<span class="lr"><span class="pill-s"><i class="dot st-' . e($x['status']) . '"></i>' . e(lx_lead_status()[$x['status']] ?? '') . '</span><span class="rec-s">' . e($x['rec']) . '</span><small>' . e(lx_ago((int)$x['created'])) . '</small></span></a></li>';
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
          'webseite' => '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M3 9h18M7 6.5h.01M10 6.5h.01"/>'][$k] ?? '';
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
    .tabs a span { display: none; } .tabs a { padding: 10px; }
  }
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
</style>
</head>
<body class="<?= $app ? 'app' : 'auth-page' ?>">
<?php }
function foot(): void { echo "</body>\n</html>\n"; }
