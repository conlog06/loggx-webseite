<?php
/* ==========================================================================
   LoggX Admin – Finanzen (FinanzKlar)
   Die App liegt gesperrt in _launch/private/finanzklar.html (nicht im Git-Repo,
   per .htaccess nicht direkt abrufbar) und wird nur für eingeloggte Admins
   ausgeliefert. Die Buchhaltungsdaten bleiben im Browser (localStorage) –
   sie werden NICHT auf dem Server gespeichert.
   ========================================================================== */
declare(strict_types=1);
require dirname(__DIR__) . '/_launch/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');

lx_session();
$isAdmin = !empty($_SESSION['lx_admin']) && ($_SESSION['lx_admin_until'] ?? 0) > time();
if (!$isAdmin) { header('Location: ./', true, 303); exit; }
$_SESSION['lx_admin_until'] = time() + 8 * 3600; // Sitzung verlängern
session_write_close();

$file = dirname(__DIR__) . '/_launch/private/finanzklar.html';
if (!is_file($file)) {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Finanzen – LoggX Admin</title>'
       . '<p style="font:16px/1.5 system-ui,sans-serif;padding:40px">FinanzKlar ist auf dem Server noch nicht vorhanden. '
       . 'Lade die Datei <code>_launch/private/finanzklar.html</code> hoch. <a href="./">← Admin</a></p>';
    exit;
}

// Nur eigene Dateien laden, keine Verbindungen zu fremden Servern (Schutz der Finanzdaten)
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; "
     . "img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' data: blob:; worker-src 'self' blob:; "
     . "frame-src 'self' blob: data:; object-src 'self' blob: data:; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Content-Type: text/html; charset=utf-8');
readfile($file);
