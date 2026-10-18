<?php
/* ==========================================================================
   LoggX Launch-System – Torwächter
   Alle HTML-Seiten laufen über diese Datei (siehe /.htaccess).
   - live         → Seite wird normal ausgeliefert
   - prelaunch    → Landingpage mit Countdown (geht zum Zeitpunkt automatisch live)
   - maintenance  → Wartungsseite (HTTP 503)
   Admins und Personen mit Zugangscode sehen immer die echte Seite.
   ========================================================================== */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$c = lx_config();
$mode = lx_effective_mode($c);

/* ---------- Angefragte Datei bestimmen ---------- */
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$base = lx_base();
if ($base !== '' && strpos($uri, $base) === 0) $uri = substr($uri, strlen($base));
if ($uri === '' || substr($uri, -1) === '/') $uri .= 'index.html';
$rel = ltrim($uri, '/');
$file = realpath(lx_root() . '/' . $rel);
$root = realpath(lx_root());
$valid = $file && strpos($file, $root . DIRECTORY_SEPARATOR) === 0 && preg_match('/\.html?$/i', $file) && is_file($file);

/* Rechtliche Seiten sind immer erreichbar (Impressumspflicht) */
$always = ['impressum.html', 'datenschutz.html', 'danke.html'];
$isAlways = in_array(strtolower($rel), $always, true);

/* ---------- Aktionen ---------- */
$msg = null; $msgType = 'error'; $openCode = false;
$here = '/' . ltrim((string)strtok($_SERVER['REQUEST_URI'] ?? '/', '?'), '/'); // nur eigene Pfade (kein //fremde-domain)

// Zugangslink: …/?zugang=ABCD-EFGH
if (isset($_GET['zugang'])) {
    if (lx_throttled('code')) { $msg = 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.'; $openCode = true; }
    else {
        $code = lx_find_code((string)$_GET['zugang']);
        if ($code && lx_code_valid($code)) {
            lx_touch_code($code['id']);
            $days = 30;
            if (!empty($code['expires'])) { $t = lx_ts($code['expires']); if ($t) $days = max(1, (int)ceil(($t - time()) / 86400)); }
            lx_grant_cookie('code:' . $code['id'], $days);
            header('Location: ' . $here, true, 303); exit;
        }
        lx_fail('code'); $msg = 'Dieser Zugangslink ist ungültig oder abgelaufen.'; $openCode = true;
    }
}
if (isset($_GET['zugang-beenden'])) { lx_clear_cookie(); header('Location: ' . $here, true, 303); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'code') {
        $openCode = true;
        $input = trim((string)($_POST['code'] ?? ''));
        if (lx_throttled('code')) { $msg = 'Zu viele Versuche. Bitte in 15 Minuten erneut probieren.'; }
        else {
            $code = lx_find_code($input);
            if ($code && lx_code_valid($code)) {
                lx_touch_code($code['id']);
                lx_grant_cookie('code:' . $code['id']);
                header('Location: ' . $here, true, 303); exit;
            }
            if ($c['shared_password'] !== '' && $input !== '' && password_verify($input, $c['shared_password'])) {
                lx_grant_cookie('shared');
                header('Location: ' . $here, true, 303); exit;
            }
            lx_fail('code'); $msg = 'Der Code stimmt nicht. Bitte prüfen Sie die Schreibweise.';
        }
    } elseif ($action === 'notify' && $c['landing']['signup']) {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!empty($_POST['website'])) { $msg = 'Danke!'; $msgType = 'ok'; }         // Spam-Falle
        elseif (lx_throttled('notify', 5, 3600)) { $msg = 'Zu viele Anmeldungen von diesem Anschluss. Bitte später erneut versuchen.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $msg = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.'; }
        elseif (empty($_POST['consent'])) { $msg = 'Bitte bestätigen Sie die Einwilligung zur Benachrichtigung.'; }
        else {
            $list = lx_read('signups', []);
            $exists = false;
            foreach ($list as $s) if (strcasecmp($s['email'], $email) === 0) $exists = true;
            if (!$exists) { $list[] = ['email' => $email, 'date' => date('Y-m-d H:i')]; lx_write('signups', $list); }
            lx_fail('notify', 3600);
            $msg = 'Danke! Wir melden uns, sobald die Seite online ist.'; $msgType = 'ok';
        }
    }
}

/* ---------- Entscheidung ---------- */
$who = lx_cookie_who();
$admin = lx_is_admin();
$bypass = $admin || $who !== null;

if ($mode === 'live' || $isAlways || $bypass) {
    if (!$valid) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>Seite nicht gefunden</title><p style="font-family:sans-serif;padding:40px">Seite nicht gefunden. <a href="' . e(lx_url('')) . '">Zur Startseite</a></p>';
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    $html = (string)file_get_contents($file);
    if ($mode !== 'live' && $bypass && !$isAlways) {
        header('X-Robots-Tag: noindex');
        header('Cache-Control: no-store');
        $label = $mode === 'maintenance' ? 'Wartungsmodus aktiv' : 'Vorschau – noch nicht öffentlich';
        $whoText = $admin ? 'Admin' : ($who === 'shared' ? 'Vorschau-Passwort' : 'Zugangscode');
        $badge = '<div style="position:fixed;left:50%;bottom:16px;transform:translateX(-50%);z-index:99999;display:flex;gap:10px;align-items:center;padding:9px 14px;border-radius:999px;background:#0F2540;color:#fff;font:600 13px/1.2 system-ui,sans-serif;box-shadow:0 12px 30px rgba(0,0,0,.35)">'
            . '<span style="width:8px;height:8px;border-radius:50%;background:#FFD166"></span>' . e($label) . ' · ' . e($whoText)
            . ($admin ? ' · <a href="' . e(lx_url('admin/')) . '" style="color:#FFD166">Admin</a>' : ' · <a href="?zugang-beenden" style="color:#FFD166">Abmelden</a>')
            . '</div>';
        $html = preg_replace('~</body>~i', $badge . '</body>', $html, 1) ?? $html;
    }
    echo $html;
    exit;
}

/* Landingpage oder Wartungsseite */
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');
if ($mode === 'maintenance') {
    http_response_code(503);
    $until = lx_ts($c['maintenance']['until']);
    header('Retry-After: ' . ($until && $until > time() ? (string)($until - time()) : '3600'));
    require __DIR__ . '/templates/maintenance.php';
} else {
    require __DIR__ . '/templates/landing.php';
}
