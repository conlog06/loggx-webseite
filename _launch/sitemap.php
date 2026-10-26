<?php
/* ==========================================================================
   LoggX – Sitemap mit echtem Änderungsdatum (lastmod)
   Liest die Adressen aus sitemap.xml und setzt lastmod auf das Datum der
   letzten Änderung der Seite bzw. der Einstellungen im Admin (Preise, Texte).
   ========================================================================== */
declare(strict_types=1);
require __DIR__ . '/lib.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: no-cache');
$root = lx_root();
$cfg = (int)@filemtime(LX_DATA . '/config.php');
$out = '';
foreach (lx_indexnow_urls() as $u) {
    $path = trim((string)parse_url($u, PHP_URL_PATH), '/');
    $file = $path === '' ? 'index.html' : (is_file("$root/$path.html") ? "$path.html" : "$path/index.html");
    $t = max((int)@filemtime("$root/$file"), $cfg);
    $out .= "  <url><loc>" . htmlspecialchars($u, ENT_XML1) . "</loc>" . ($t ? '<lastmod>' . gmdate('Y-m-d\TH:i:s\Z', $t) . '</lastmod>' : '') . "</url>\n";
}
$etag = '"' . substr(md5($out), 0, 20) . '"';
header('ETag: ' . $etag);
if (str_replace('-gzip', '', (string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n" . $out . "</urlset>\n";
