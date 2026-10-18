<?php
/* ==========================================================================
   Nur zum Testen auf dem eigenen Rechner (nicht für IONOS nötig):
     php -S localhost:8000 _launch/testserver.php
   Dann im Browser: http://localhost:8000  und  http://localhost:8000/admin/
   Bildet die Regeln der .htaccess für den eingebauten PHP-Server nach.
   Auf dem echten Server ist diese Datei per _launch/.htaccess gesperrt.
   ========================================================================== */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/admin(/|$)#', $path)) {
    if ($path === '/admin') { header('Location: /admin/'); exit; }
    $_SERVER['SCRIPT_NAME'] = '/admin/index.php';
    require $root . '/admin/index.php'; return true;
}
if (preg_match('#^/_launch/#', $path)) { http_response_code(403); echo 'Gesperrt'; return true; }
if ($path === '/' || substr($path, -1) === '/' || preg_match('/\.html?$/', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/_launch/gate.php';
    require $root . '/_launch/gate.php'; return true;
}
return false; // Bilder, CSS, JS direkt ausliefern
