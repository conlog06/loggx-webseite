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
function back(): void { global $self; header('Location: ' . $self, true, 303); exit; }
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

head('Admin – LoggX');
?>
<header class="bar">
  <a class="brand" href="<?= e($self) ?>"><svg viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#0F2540"/><path d="M70 375V137H129V375ZM80 375V325H219V375Z" fill="#fff"/><path d="M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z" fill="#FFD166"/></svg>LoggX <span>Admin</span></a>
  <nav><a href="<?= e(lx_url('')) ?>" target="_blank" rel="noopener">Seite ansehen ↗</a><a href="?logout">Abmelden</a></nav>
</header>
<main class="admin">
  <?php if ($flash): ?><p class="flash <?= e($flash[1]) ?>" role="status"><?= e($flash[0]) ?></p><?php endif; ?>

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
            <label>Hosting ab <span class="unit-in"><input type="number" name="price_hosting" min="0" max="9999" value="<?= (int)$S['price_hosting'] ?>"><i>€/Monat</i></span></label>
            <label>Änderungen <span class="unit-in"><input type="number" name="price_hourly" min="0" max="999" value="<?= (int)$S['price_hourly'] ?>"><i>€/Std.</i></span></label>
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

function head(string $title): void { ?>
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
</style>
</head>
<body>
<?php }
function foot(): void { echo "</body>\n</html>\n"; }
