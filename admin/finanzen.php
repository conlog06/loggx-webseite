<?php
/* ==========================================================================
   LoggX Admin – Finanzen (FinanzKlar)
   - Nur für eingeloggte Admins UND mit eigenem zweiten Faktor:
     eigener Eintrag "LoggX Finanzen" in der Authenticator-App (getrennt vom
     Admin-Login). Entsperrung gilt 30 Minuten ab der letzten Aktivität.
   - Die App liegt gesperrt in _launch/private/finanzklar.html (nicht im
     Git-Repo, per .htaccess nicht direkt abrufbar).
   - Geräte-Sync: Die App verschlüsselt alle Daten im Browser (AES-256-GCM,
     Schlüssel aus deiner Sync-Passphrase). Auf dem Server liegt nur der
     verschlüsselte Block – ohne Passphrase ist er nicht lesbar.
   Notfall: Wiederherstellungscode – oder in _launch/data/config.php
   "fin_totp_on": false setzen, dann wird der Finanz-Code neu eingerichtet.
   ========================================================================== */
declare(strict_types=1);
require dirname(__DIR__) . '/_launch/lib.php';

const LX_FIN_IDLE = 1800;               // 30 Minuten ohne Aktivität → gesperrt
const LX_FIN_MAX_SYNC = 45 * 1024 * 1024;  // max. Größe des verschlüsselten Sync-Blocks

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

$api = (string)($_GET['api'] ?? '');
lx_session();
$isAdmin = !empty($_SESSION['lx_admin']) && ($_SESSION['lx_admin_until'] ?? 0) > time();
if (!$isAdmin) {
    if ($api !== '') fin_json(['ok' => false, 'locked' => true, 'error' => 'Admin-Sitzung abgelaufen.'], 401);
    header('Location: ./', true, 303); exit;
}
$_SESSION['lx_admin_until'] = time() + 8 * 3600; // Admin-Sitzung verlängern

$c = lx_config();
$self = lx_url('admin/finanzen.php');
$unlocked = !empty($c['fin_totp_on']) && $c['fin_totp_secret'] !== '' && ($_SESSION['lx_fin_until'] ?? 0) > time();
$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$action = (string)($_POST['action'] ?? '');
$error = null;

/* ---------- Sperren ---------- */
if (isset($_GET['lock'])) {
    unset($_SESSION['lx_fin_until'], $_SESSION['lx_fin_codes']);
    header('Location: ' . $self . (isset($_GET['idle']) ? '?idle=1' : ''), true, 303); exit;
}

/* ---------- Schnittstelle für die App (nur entsperrt) ---------- */
if ($api !== '') {
    if (!$unlocked) fin_json(['ok' => false, 'locked' => true, 'error' => 'Finanzbereich gesperrt.'], 401);
    if ($post && !hash_equals(lx_csrf(), (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) fin_json(['ok' => false, 'error' => 'Sitzung ungültig – bitte Seite neu laden.'], 403);
    if ($api === 'ping') {
        if (empty($_GET['passive'])) $_SESSION['lx_fin_until'] = time() + LX_FIN_IDLE;
        fin_json(['ok' => true, 'left' => max(0, (int)$_SESSION['lx_fin_until'] - time())]);
    }
    $_SESSION['lx_fin_until'] = time() + LX_FIN_IDLE;
    session_write_close();
    if ($api === 'sync') fin_api_sync($post);
    if ($api === 'leads') fin_api_leads($post);
    fin_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 404);
}

/* ---------- Formulare ---------- */
if ($post && !lx_check_csrf()) { $error = 'Sitzung abgelaufen – bitte noch einmal versuchen.'; $post = false; }

if ($post && $action === 'fin_setup' && empty($c['fin_totp_on'])) {
    $secret = (string)($_SESSION['lx_fin_setup'] ?? '');
    if ($secret === '') $error = 'Die Einrichtung ist abgelaufen – bitte den neuen QR-Code scannen.';
    elseif (lx_throttled('fin', 8)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
    elseif (($step = lx_totp_check($secret, (string)($_POST['code'] ?? ''))) !== null) {
        $codes = lx_recovery_new();
        $c = lx_config();
        $c['fin_totp_on'] = true; $c['fin_totp_secret'] = $secret; $c['fin_totp_last'] = $step;
        $c['fin_totp_recovery'] = array_map(fn($x) => password_hash(lx_recovery_norm($x), PASSWORD_DEFAULT), $codes);
        lx_save_config($c);
        unset($_SESSION['lx_fin_setup']);
        session_regenerate_id(true);
        $_SESSION['lx_fin_until'] = time() + LX_FIN_IDLE;
        $_SESSION['lx_fin_codes'] = $codes;
        header('Location: ' . $self . '?codes=1', true, 303); exit;
    } else { lx_hit('fin'); $error = 'Der Code stimmt nicht. Prüfe, ob du den Eintrag „LoggX Finanzen“ verwendest.'; }
}

if ($post && $action === 'fin_unlock' && !empty($c['fin_totp_on'])) {
    if (lx_throttled('fin', 6)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
    else {
        $res = fin_check_code($c, (string)($_POST['code'] ?? ''));
        if ($res) {
            session_regenerate_id(true);
            $_SESSION['lx_fin_until'] = time() + LX_FIN_IDLE;
            if ($res === 'recovery') $_SESSION['lx_fin_notice'] = 'Wiederherstellungscode verwendet – noch ' . count(lx_config()['fin_totp_recovery']) . ' übrig. Erstelle in den Einstellungen neue Codes.';
            header('Location: ' . $self, true, 303); exit;
        }
        lx_hit('fin'); $error = 'Der Code stimmt nicht. Bitte den aktuellen Code von „LoggX Finanzen“ eingeben.';
    }
}

// Aus der App heraus (entsperrt + aktueller Code nötig): neue Notfallcodes / Authenticator neu verbinden
if ($post && $unlocked && in_array($action, ['fin_codes_new', 'fin_relink'], true)) {
    if (lx_throttled('fin', 6)) $error = 'Zu viele Fehlversuche. Bitte 15 Minuten warten.';
    elseif (fin_check_code($c, (string)($_POST['code'] ?? ''), false) !== 'totp') { lx_hit('fin'); $error = 'Der Code stimmt nicht – bitte den aktuellen 6-stelligen Code aus der App.'; }
    else {
        $c = lx_config();
        if ($action === 'fin_relink') {
            $c['fin_totp_on'] = false; $c['fin_totp_secret'] = ''; $c['fin_totp_last'] = 0; $c['fin_totp_recovery'] = [];
            lx_save_config($c);
            unset($_SESSION['lx_fin_until'], $_SESSION['lx_fin_setup']);
            header('Location: ' . $self, true, 303); exit;
        }
        $codes = lx_recovery_new();
        $c['fin_totp_recovery'] = array_map(fn($x) => password_hash(lx_recovery_norm($x), PASSWORD_DEFAULT), $codes);
        lx_save_config($c);
        $_SESSION['lx_fin_codes'] = $codes;
        header('Location: ' . $self . '?codes=1', true, 303); exit;
    }
    $unlocked = true;
}

$c = lx_config();
$unlocked = !empty($c['fin_totp_on']) && $c['fin_totp_secret'] !== '' && ($_SESSION['lx_fin_until'] ?? 0) > time();

/* ---------- Noch nicht eingerichtet → Einrichtung (Pflicht) ---------- */
if (empty($c['fin_totp_on']) || $c['fin_totp_secret'] === '') {
    if (empty($_SESSION['lx_fin_setup'])) $_SESSION['lx_fin_setup'] = lx_totp_new_secret();
    $secret = $_SESSION['lx_fin_setup'];
    $host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'loggx.de'));
    fin_page('setup', $error, ['secret' => $secret, 'uri' => lx_totp_uri($secret, $host, 'LoggX Finanzen')]);
}

/* ---------- Wiederherstellungscodes einmalig anzeigen ---------- */
if ($unlocked && isset($_GET['codes']) && !empty($_SESSION['lx_fin_codes'])) {
    $codes = $_SESSION['lx_fin_codes']; unset($_SESSION['lx_fin_codes']);
    fin_page('codes', null, ['codes' => $codes]);
}

/* ---------- Gesperrt → Code abfragen ---------- */
if (!$unlocked) fin_page('unlock', $error, ['idle' => isset($_GET['idle'])]);

/* ---------- Entsperrt → App ausliefern ---------- */
$file = dirname(__DIR__) . '/_launch/private/finanzklar.html';
if (!is_file($file)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Finanzen – LoggX Admin</title>'
       . '<p style="font:16px/1.5 system-ui,sans-serif;padding:40px">FinanzKlar ist auf dem Server noch nicht vorhanden. '
       . 'Lade die Datei <code>_launch/private/finanzklar.html</code> hoch. <a href="./">← Admin</a></p>';
    exit;
}
$notice = (string)($_SESSION['lx_fin_notice'] ?? ''); unset($_SESSION['lx_fin_notice']);
$boot = [
    'api' => $self, 'csrf' => lx_csrf(), 'idle' => LX_FIN_IDLE, 'left' => (int)$_SESSION['lx_fin_until'] - time(),
    'lock' => $self . '?lock=1', 'admin' => lx_url('admin/'), 'notice' => $notice,
    'recovery' => count($c['fin_totp_recovery']), 'error' => $error ?? '',
];
session_write_close();
fin_csp();
header('Content-Type: text/html; charset=utf-8');
$html = (string)file_get_contents($file);
$inject = '<script>window.LX_FIN=' . json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) . ';</script>';
$pos = stripos($html, '</head>');
echo $pos === false ? $inject . $html : substr($html, 0, $pos) . $inject . substr($html, $pos);
exit;

/* =================================================================== */

function fin_json(array $d, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fin_csp(): void {
    // Nur eigene Dateien laden, keine Verbindungen zu fremden Servern (Schutz der Finanzdaten)
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' data: blob:; worker-src 'self' blob:; "
         . "frame-src 'self' blob: data:; object-src 'self' blob: data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
}

/** Prüft TOTP (und optional Wiederherstellungscode). Gibt 'totp', 'recovery' oder null zurück und speichert den Verbrauch. */
function fin_check_code(array $c, string $code, bool $allowRecovery = true): ?string {
    return lx_locked('config', function () use ($code, $allowRecovery) {
        $c = lx_config();
        $step = lx_totp_check($c['fin_totp_secret'], $code, (int)$c['fin_totp_last']);
        if ($step !== null) { $c['fin_totp_last'] = $step; lx_save_config($c); return 'totp'; }
        if ($allowRecovery && strlen($n = lx_recovery_norm($code)) === 10) {
            foreach ($c['fin_totp_recovery'] as $i => $h) {
                if (password_verify($n, (string)$h)) {
                    unset($c['fin_totp_recovery'][$i]);
                    $c['fin_totp_recovery'] = array_values($c['fin_totp_recovery']);
                    lx_save_config($c); return 'recovery';
                }
            }
        }
        return null;
    });
}

/* ---------- Geräte-Sync (nur verschlüsselte Daten) ---------- */
function fin_sync_meta(): array {
    return lx_read('finanzsync', ['version' => 0, 'updated' => 0, 'salt' => '', 'size' => 0, 'device' => '']);
}
function fin_blob_file(int $n = 0): string { return LX_DATA . '/finanzsync' . ($n ? '-' . $n : '') . '.blob.php'; }

function fin_api_sync(bool $post): void {
    if (!$post) {
        $m = fin_sync_meta();
        if (empty($_GET['get'])) fin_json(['ok' => true] + $m);
        $f = fin_blob_file();
        if (!$m['version'] || !is_file($f)) fin_json(['ok' => false, 'error' => 'Auf dem Server liegt noch nichts.'], 404);
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Fin-Version: ' . (int)$m['version']);
        $h = fopen($f, 'rb'); fseek($h, strlen(LX_GUARD)); fpassthru($h); fclose($h);
        exit;
    }
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len > LX_FIN_MAX_SYNC) fin_json(['ok' => false, 'error' => 'Die Daten sind zu groß für den Sync (max. 45 MB).'], 413);
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' && $len > 0) fin_json(['ok' => false, 'error' => 'Der Server hat die Daten abgelehnt (zu groß für die PHP-Einstellung post_max_size).'], 413);
    $in = json_decode($raw, true);
    if (!is_array($in)) fin_json(['ok' => false, 'error' => 'Ungültige Daten.'], 400);
    $blob = (string)($in['blob'] ?? ''); $salt = (string)($in['salt'] ?? '');
    if (!preg_match('~^[A-Za-z0-9+/=]{40,}$~', $blob) || !preg_match('~^[A-Za-z0-9+/=]{16,64}$~', $salt)) fin_json(['ok' => false, 'error' => 'Ungültige Daten.'], 400);
    $base = (int)($in['base'] ?? -1); $force = !empty($in['force']);
    $device = lx_cut((string)preg_replace('/[^\w .\-()·]/u', '', (string)($in['device'] ?? '')), 60);
    $res = lx_locked('finanzsync', function () use ($blob, $salt, $base, $force, $device) {
        $m = fin_sync_meta();
        if (!$force && $base !== (int)$m['version']) return ['conflict' => true] + $m;
        if (!is_dir(LX_DATA)) mkdir(LX_DATA, 0750, true);
        // die letzten zwei Stände als Sicherheit behalten
        if (is_file(fin_blob_file(1))) @rename(fin_blob_file(1), fin_blob_file(2));
        if (is_file(fin_blob_file())) @rename(fin_blob_file(), fin_blob_file(1));
        $tmp = fin_blob_file() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, LX_GUARD . $blob, LOCK_EX);
        rename($tmp, fin_blob_file());
        $m = ['version' => (int)$m['version'] + 1, 'updated' => time(), 'salt' => $salt, 'size' => strlen($blob), 'device' => $device];
        lx_write('finanzsync', $m);
        return $m;
    });
    if (!empty($res['conflict'])) { unset($res['conflict']); fin_json(['ok' => false, 'conflict' => true, 'error' => 'Auf einem anderen Gerät wurde inzwischen gespeichert.'] + $res, 409); }
    fin_json(['ok' => true] + $res);
}

/* ---------- LoggX-Anfragen für Kunden & Angebote ---------- */
function fin_api_leads(bool $post): void {
    if ($post) {
        $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $id = (string)($in['id'] ?? ''); $st = (string)($in['status'] ?? ''); $why = lx_cut((string)($in['note'] ?? ''), 120);
        $only = array_map('strval', is_array($in['only'] ?? null) ? $in['only'] : []); // nur ändern, wenn der Status gerade einer davon ist
        if (!isset(lx_lead_status()[$st])) fin_json(['ok' => false, 'error' => 'Unbekannter Status.'], 400);
        $ok = lx_locked('leads', function () use ($id, $st, $why, $only) {
            $l = lx_leads(); $hit = false;
            foreach ($l as &$x) {
                if ($x['id'] !== $id) continue;
                $hit = true;
                if ($only && !in_array($x['status'], $only, true)) continue;
                if ($x['status'] !== $st) { $x['status'] = $st; $x['notes'][] = ['ts' => time(), 't' => 'Status: ' . lx_lead_status()[$st] . ($why !== '' ? ' (' . $why . ')' : '') . ' – aus FinanzKlar', 'sys' => true]; }
            }
            unset($x);
            if ($hit) lx_save_leads($l);
            return $hit;
        });
        fin_json(['ok' => $ok]);
    }
    $out = [];
    foreach (lx_leads() as $x) {
        $d = $x['data'] ?? [];
        $out[] = ['id' => $x['id'], 'created' => (int)$x['created'], 'status' => $x['status'], 'statusText' => lx_lead_status()[$x['status']] ?? $x['status'],
                  'rec' => $x['rec'] ?? '', 'name' => $d['name'] ?? '', 'firma' => $d['firma'] ?? '', 'email' => $d['email'] ?? '',
                  'telefon' => $d['telefon'] ?? '', 'ort' => $d['ort'] ?? '', 'webseite' => $d['webseite'] ?? '', 'nachricht' => lx_cut((string)($d['nachricht'] ?? ''), 400)];
    }
    usort($out, fn($a, $b) => $b['created'] <=> $a['created']);
    fin_json(['ok' => true, 'leads' => $out]);
}

/* ---------- Seiten: Einrichtung, Code, Notfallcodes ---------- */
function fin_page(string $type, ?string $error, array $v = []): void {
    $csrf = lx_csrf();
    session_write_close();
    fin_csp();
    header('Content-Type: text/html; charset=utf-8'); ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="robots" content="noindex, nofollow">
<title>Finanzen entsperren – LoggX</title>
<link rel="stylesheet" href="finanzen-assets/fonts.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 64 64'%3E%3Crect width='64' height='64' rx='14' fill='%23070B14'/%3E%3Cpath d='M18 40l8-10 7 6 13-16' stroke='%233ED6C4' stroke-width='5' stroke-linecap='round' stroke-linejoin='round' fill='none'/%3E%3C/svg%3E">
<style>
  :root{--bg:#070B14;--panel:#0E1626;--soft:#0B1220;--line:rgba(147,161,184,.16);--ink:#E8EEF9;--muted:#93A1B8;--accent:#5B8CFF;--cyan:#3ED6C4;--bad:#FB7185;--amber:#FFB454;--grad:linear-gradient(100deg,#5B8CFF 0%,#9D6BFF 55%,#3ED6C4 110%)}
  *{box-sizing:border-box;margin:0}
  body{min-height:100vh;display:grid;place-items:center;padding:24px 16px;background:radial-gradient(60vmax 60vmax at 10% -10%,rgba(91,140,255,.18),transparent 60%),radial-gradient(50vmax 50vmax at 110% 110%,rgba(62,214,196,.14),transparent 60%),var(--bg);color:var(--ink);font:15px/1.6 Inter,system-ui,sans-serif;-webkit-font-smoothing:antialiased}
  .box{width:100%;max-width:440px;background:linear-gradient(180deg,rgba(255,255,255,.035),transparent 40%),var(--panel);border:1px solid var(--line);border-radius:22px;padding:34px 30px;box-shadow:0 30px 80px -30px rgba(0,0,0,.8)}
  .logo{width:56px;height:56px;margin-bottom:18px;filter:drop-shadow(0 10px 24px rgba(62,214,196,.35))}
  h1{font:800 1.55rem/1.2 Sora,sans-serif;letter-spacing:-.02em;margin-bottom:6px}
  h1 span{background:var(--grad);-webkit-background-clip:text;background-clip:text;color:transparent}
  p{color:var(--muted);font-size:.92rem;margin-bottom:16px} p b{color:var(--ink)}
  .tag{display:inline-flex;gap:6px;align-items:center;font:600 .68rem/1 "JetBrains Mono",monospace;letter-spacing:.06em;text-transform:uppercase;color:var(--cyan);background:rgba(62,214,196,.1);border:1px solid rgba(62,214,196,.3);padding:6px 10px;border-radius:99px;margin-bottom:16px}
  label{display:block;font-size:.8rem;font-weight:600;margin:0 0 6px}
  input{width:100%;background:var(--soft);border:1px solid var(--line);border-radius:12px;padding:14px;color:var(--ink);font:600 1.5rem/1 "JetBrains Mono",monospace;letter-spacing:.3em;text-align:center}
  input:focus{outline:none;border-color:var(--accent);box-shadow:0 0 0 3px rgba(91,140,255,.2)}
  button{width:100%;margin-top:14px;border:none;border-radius:12px;padding:14px;font:700 .95rem Inter,sans-serif;color:#fff;background:var(--grad);cursor:pointer;box-shadow:0 12px 30px -12px rgba(124,92,255,.7)}
  .err{background:rgba(251,113,133,.1);border:1px solid rgba(251,113,133,.35);color:var(--bad);border-radius:12px;padding:11px 14px;font-size:.88rem;margin:12px 0 0}
  .warn{background:rgba(255,180,84,.1);border:1px solid rgba(255,180,84,.35);color:var(--amber);border-radius:12px;padding:11px 14px;font-size:.85rem;margin:0 0 16px}
  .qr{background:#fff;border-radius:16px;padding:12px;width:220px;height:220px;margin:4px auto 14px;display:grid;place-items:center}
  .qr svg{width:100%;height:100%}
  .secret{font:600 .82rem/1.5 "JetBrains Mono",monospace;word-break:break-all;background:var(--soft);border:1px dashed var(--line);border-radius:10px;padding:10px 12px;color:var(--ink);margin-bottom:16px;text-align:center}
  ol{color:var(--muted);font-size:.88rem;padding-left:20px;margin-bottom:16px} ol li{margin-bottom:4px} ol b{color:var(--ink)}
  .codes{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:6px 0 16px}
  .codes code{font:600 .95rem "JetBrains Mono",monospace;background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:10px;text-align:center}
  .links{display:flex;justify-content:space-between;gap:12px;margin-top:18px;font-size:.85rem} .links a{color:var(--muted);text-decoration:none} .links a:hover{color:var(--ink)}
  a.btn{display:block;text-align:center;margin-top:14px;border-radius:12px;padding:14px;font-weight:700;color:#fff;background:var(--grad);text-decoration:none}
</style>
</head>
<body>
<main class="box">
  <svg class="logo" viewBox="0 0 64 64" aria-hidden="true"><rect width="64" height="64" rx="14" fill="#0E1626" stroke="rgba(62,214,196,.5)" stroke-width="2"/><path d="M16 40l9-11 7 6 14-17" stroke="url(#g)" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" fill="none"/><circle cx="46" cy="18" r="3.5" fill="#3ED6C4"/><defs><linearGradient id="g" x1="0" y1="1" x2="1" y2="0"><stop offset="0" stop-color="#5B8CFF"/><stop offset="1" stop-color="#3ED6C4"/></linearGradient></defs></svg>
<?php if ($type === 'setup'): ?>
  <span class="tag">🔐 Einmalige Einrichtung</span>
  <h1>Finanz<span>Klar</span> absichern</h1>
  <p>Der Finanzbereich bekommt einen <b>eigenen Code</b> – getrennt vom Admin-Login. So kommt selbst mit deinem Admin-Zugang niemand an deine Zahlen.</p>
  <ol>
    <li>Authenticator-App öffnen (Google, Microsoft, 1Password …)</li>
    <li>QR-Code scannen – es entsteht der Eintrag <b>LoggX Finanzen</b></li>
    <li>Den 6-stelligen Code unten eingeben</li>
  </ol>
  <div class="qr" id="qr" data-uri="<?= e($v['uri']) ?>"></div>
  <div class="secret" title="Falls der QR-Code nicht klappt: diesen Schlüssel manuell eintragen"><?= e(trim(chunk_split($v['secret'], 4, ' '))) ?></div>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="fin_setup">
    <label for="code">Code aus „LoggX Finanzen“</label>
    <input id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000">
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <button>Einrichten &amp; öffnen</button>
  </form>
  <div class="links"><a href="./?tab=start">← Admin</a><span style="color:var(--muted)">Pflicht für den Finanzbereich</span></div>
  <script src="qrcode.js"></script>
  <script>(function(){var el=document.getElementById("qr");if(!el||typeof qrcode!=="function")return;var q=qrcode(0,"M");q.addData(el.getAttribute("data-uri"));q.make();el.innerHTML=q.createSvgTag(5,0);})();</script>
<?php elseif ($type === 'codes'): ?>
  <span class="tag">✅ Fertig</span>
  <h1>Deine Notfall&shy;codes</h1>
  <p>Falls dein Handy weg ist: Jeder Code entsperrt den Finanzbereich <b>einmal</b>. Schreib sie auf oder speichere sie im Passwort-Manager – sie werden <b>nur jetzt</b> angezeigt.</p>
  <div class="codes"><?php foreach ($v['codes'] as $code): ?><code><?= e($code) ?></code><?php endforeach; ?></div>
  <a class="btn" href="finanzen.php">Ich habe sie gesichert – weiter</a>
<?php else: ?>
  <span class="tag">🔒 Gesperrt</span>
  <h1>Finanz<span>Klar</span></h1>
  <?php if (!empty($v['idle'])): ?><p class="warn">Automatisch gesperrt nach 30 Minuten ohne Aktivität.</p><?php endif; ?>
  <p>Gib den aktuellen Code aus deiner Authenticator-App ein – Eintrag <b>LoggX Finanzen</b> (nicht „LoggX Admin“).</p>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="fin_unlock">
    <label for="code">Code</label>
    <input id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="11" placeholder="000000">
    <?php if ($error): ?><p class="err"><?= e($error) ?></p><?php endif; ?>
    <button>Entsperren</button>
  </form>
  <p style="font-size:.8rem;margin:14px 0 0">Handy nicht zur Hand? Notfallcode im Format XXXXX-XXXXX eingeben.</p>
  <div class="links"><a href="./?tab=start">← Zurück zum Admin</a></div>
<?php endif; ?>
</main>
</body>
</html>
<?php
    exit;
}
