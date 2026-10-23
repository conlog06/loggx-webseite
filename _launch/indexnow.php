<?php
/* ==========================================================================
   LoggX – IndexNow-Schlüsseldatei (/<schlüssel>.txt)
   Bing & Co. prüfen hierüber, dass die Meldungen wirklich von loggx.de kommen.
   ========================================================================== */
declare(strict_types=1);
require __DIR__ . '/lib.php';
$key = (string)($_GET['key'] ?? '');
$c = lx_config();
if (!preg_match('/^[a-f0-9]{32}$/', $key) || !hash_equals((string)$c['indexnow_key'], $key)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Nicht gefunden'; exit; }
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $key;
