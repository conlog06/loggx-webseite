<?php
/* ==========================================================================
   LoggX – Schnittstelle für die Webseite
   POST ?a=anfrage   Anfrage aus dem Formular (JSON)
   POST ?a=t         Statistik-Signal (gesehene Abschnitte, Klicks, Verweildauer)
   POST ?a=termin    Antwort auf einen Terminvorschlag (passt / passt nicht)
   GET  ?a=ics&t=…   Kalender-Datei zu einem Termin
   GET  ?a=kalender&k=…  Kalender-Abo mit allen Terminen (nur mit geheimem Schlüssel)
   ========================================================================== */
declare(strict_types=1);
// PHP-Hinweise/Warnungen dürfen die JSON-Antwort nie kaputt machen (sie landen nur im Fehlerprotokoll)
ini_set('display_errors', '0');
ob_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');
$out = function (array $d, int $code = 200) { while (ob_get_level()) ob_end_clean(); http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
set_exception_handler(function (Throwable $e) use ($out) {
    error_log('LoggX api.php: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $out(['ok' => false, 'error' => 'Technischer Fehler auf dem Server (' . $e->getMessage() . ').'], 500);
});
require __DIR__ . '/lib.php';

/* ---------- Health-Check für Überwachungsdienste (z. B. UptimeRobot): GET ?a=health ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['a'] ?? '') === 'health') {
    $okData = is_dir(LX_DATA) && is_writable(LX_DATA);
    $cfg = lx_read('config', null);
    $ok = $okData && is_array($cfg);
    $out(['ok' => $ok, 'status' => $ok ? 'alles in Ordnung' : 'Problem: ' . (!$okData ? 'Datenordner nicht beschreibbar' : 'Einstellungen nicht lesbar'), 'zeit' => date('c')], $ok ? 200 : 503);
}

/* ---------- Kalender (GET) ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && in_array($_GET['a'] ?? '', ['ics', 'kalender'], true)) {
    while (ob_get_level()) ob_end_clean();
    header('X-Robots-Tag: noindex');
    if ($_GET['a'] === 'kalender') {
        $k = (string)($_GET['k'] ?? '');
        $key = (string)(lx_config()['booking']['feed_key'] ?? '');
        if (lx_throttled('feed', 30, 900) || !preg_match('/^[a-f0-9]{40}$/', $key) || !hash_equals($key, $k)) { lx_hit('feed', 900); http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Nicht gefunden.'; exit; }
        header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: inline; filename="loggx-termine.ics"');
        echo lx_booking_feed(); exit;
    }
    $b = lx_booking_find((string)($_GET['t'] ?? ''));
    if (!$b) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Termin nicht gefunden.'; exit; }
    header('Content-Type: text/calendar; charset=utf-8'); header('Content-Disposition: attachment; filename="termin-loggx.ics"');
    echo lx_booking_ics($b, lx_is_admin()); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $out(['ok' => false, 'error' => 'Nur POST erlaubt.'], 405);
// Nur Anfragen von der eigenen Seite annehmen
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$host = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
if ($origin !== '' && $origin !== 'null' && preg_replace('/^www\./', '', (string)parse_url($origin, PHP_URL_HOST)) !== preg_replace('/^www\./', '', $host)) {
    $out(['ok' => false, 'error' => 'Nicht erlaubt.'], 403);
}

$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;
$a = (string)($_GET['a'] ?? '');

if ($a === 't') {
    if (!empty(lx_config()['site']['stats_on']) && !lx_throttled('beacon', 120, 3600)) { lx_stats_event($in); lx_hit('beacon', 3600); }
    http_response_code(204); exit;
}

if ($a === 'anfrage') {
    if (!empty($in['website'])) $out(['ok' => true, 'rec' => 'Firmenwebseite', 'why' => '']);                 // Spam-Falle
    if ((int)($in['t'] ?? 0) > 0 && (int)$in['t'] < 2500) $out(['ok' => false, 'error' => 'Das ging zu schnell – bitte versuchen Sie es noch einmal.'], 400);
    if (lx_throttled('anfrage', 5, 3600)) $out(['ok' => false, 'error' => 'Zu viele Anfragen in kurzer Zeit. Bitte später erneut versuchen.'], 429);
    [$ok, $res] = lx_lead_create($in);
    if (!$ok) $out(['ok' => false, 'error' => $res], 400);
    lx_hit('anfrage', 3600);
    $out(['ok' => true, 'rec' => $res['rec'], 'why' => $res['why']]);
}
if ($a === 'termin') {
    if (lx_throttled('termin', 20, 3600)) $out(['ok' => false, 'error' => 'Zu viele Versuche. Bitte später erneut probieren.'], 429);
    lx_hit('termin', 3600);
    [$b, $err] = lx_booking_answer((string)($in['t'] ?? ''), ($in['antwort'] ?? '') === 'ja', (int)($in['opt'] ?? 0), (string)($in['nachricht'] ?? ''));
    if (!$b) $out(['ok' => false, 'error' => $err], 400);
    $out(['ok' => true, 'status' => $b['status']]);
}
$out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 404);
