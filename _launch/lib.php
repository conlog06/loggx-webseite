<?php
/* ==========================================================================
   LoggX Launch-System – gemeinsame Funktionen
   Daten liegen in _launch/data/*.php (beginnen mit "<?php exit; ?>",
   sind also selbst dann nicht lesbar, wenn .htaccess ignoriert würde).
   ========================================================================== */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

const LX_DATA = __DIR__ . '/data';
const LX_GUARD = "<?php exit; ?>\n";
const LX_COOKIE = 'loggx_zugang';

/* ---------- Pfade / URLs ---------- */
function lx_root(): string { return dirname(__DIR__); }

/** URL-Basis der Webseite, z. B. "" (Domain-Wurzel) oder "/unterordner" */
function lx_base(): string {
    static $base = null;
    if ($base !== null) return $base;
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    // Skript liegt in /_launch/ oder /admin/ → eine Ebene höher ist die Seite
    // ohne dirname(): unter Windows liefert dirname("/x") ein "\\" und Links würden zu "\/seite.html"
    $base = (string)preg_replace('~/(_launch|admin)/[^/]*$~', '', $script);
    $base = rtrim($base, '/');
    return $base;
}
function lx_url(string $path = ''): string { return lx_base() . '/' . ltrim($path, '/'); }

function lx_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Datenspeicher (JSON hinter PHP-Sperre) ---------- */
function lx_read(string $name, $default) {
    $file = LX_DATA . "/$name.php";
    if (!is_file($file)) return $default;
    $raw = (string)file_get_contents($file);
    if (strpos($raw, LX_GUARD) === 0) $raw = substr($raw, strlen(LX_GUARD));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}
function lx_write(string $name, array $data): void {
    if (!is_dir(LX_DATA)) mkdir(LX_DATA, 0750, true);
    $file = LX_DATA . "/$name.php";
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    file_put_contents($tmp, LX_GUARD . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    rename($tmp, $file);
}

/* ---------- Konfiguration ---------- */
function lx_defaults(): array {
    return [
        'mode' => 'live',                 // live | prelaunch | maintenance
        'launch_at' => '',                // "2026-11-01T18:00" (Europe/Berlin)
        'auto_launch' => true,            // zum Zeitpunkt automatisch live gehen
        'landing' => [
            'kicker' => 'Bald online',
            'title' => 'Webseiten, die Anfragen bringen.',
            'text' => 'LoggX startet in Kürze. Tragen Sie sich ein und erfahren Sie als Erstes, wenn es losgeht.',
            'signup' => true,
        ],
        'maintenance' => [
            'title' => 'Wir sind gleich zurück.',
            'text' => 'Die Webseite wird gerade überarbeitet. Bitte schauen Sie in Kürze wieder vorbei.',
            'until' => '',                // optional, "2026-11-01T20:00"
        ],
        // Inhalte der Webseite, die im Admin-Bereich änderbar sind (Elemente mit data-cms="…")
        'site' => [
            'phone' => '+49 (0) 000 000 00 00',
            'email' => 'constantinloggen@icloud.com',
            'street' => 'Zum Himmelreich 5',
            'city' => '49082 Osnabrück',
            'price_visit' => 790,
            'price_firm' => 1890,
            'price_hosting' => 29,
            'price_hourly' => 50,          // Änderungen nach Aufwand (€/Stunde)
            'lp_price_visit' => 0,         // Werbe-Landingpage (/angebot/): 0 = gleicher Preis wie Hauptseite
            'lp_price_firm' => 0,
            'lp_strike' => true,           // Normalpreis durchgestrichen daneben zeigen, wenn der Landingpage-Preis günstiger ist
            'time_visit' => '14 Tagen',
            'time_firm' => '3–4 Wochen',
            'response_hours' => 24,
            'kicker_home' => 'Webdesign aus Osnabrück',
            'kicker_angebot' => 'Für Betriebe aus Osnabrück und Umgebung',
            'tax_number' => '',            // leer = Abschnitt im Impressum ausgeblendet
            'tax_office' => 'Osnabrück',
            'hoster' => '',                // z. B. "IONOS SE, Elgendorfer Str. 57, 56410 Montabaur"
            'stats_text' => '',            // leer = kein Statistik-Tool, Absatz ausgeblendet
            'thumio' => true,              // Referenz-Screenshots über image.thum.io
            'stats_on' => true,            // eigene Besucherstatistik ohne Cookies (Admin → Statistik)
            'bar_on' => false,
            'bar_text' => '',
            'bar_link_text' => '',
            'bar_link' => '',
            'bar_badge' => 'Aktion',
            'bar_id' => '',
            'mail_from' => '',             // Absender für E-Mails (leer = noreply@eigene-domain)
        ],
        // Anfragen über das Formular
        'leads' => [
            'autoreply' => true,           // Bestätigung mit Empfehlung an den Kunden
            'keep_months' => 12,           // danach automatisch löschen (0 = nie); "Auftrag erhalten" bleibt
        ],
        'shared_password' => '',          // Hash; leer = aus
        'contact_email' => 'constantinloggen@icloud.com',
        'admin_hash' => '',
        'secret' => '',
    ];
}
function lx_config(): array {
    $c = lx_read('config', []);
    $d = lx_defaults();
    foreach ($d as $k => $v) {
        if (!array_key_exists($k, $c)) $c[$k] = $v;
        elseif (is_array($v)) $c[$k] = array_merge($v, is_array($c[$k]) ? $c[$k] : []);
    }
    if (!empty($c['site']['email'])) $c['contact_email'] = $c['site']['email'];
    return $c;
}
function lx_save_config(array $c): void { lx_write('config', $c); }
function lx_secret(): string {
    $c = lx_config();
    if (empty($c['secret'])) { $c['secret'] = bin2hex(random_bytes(32)); lx_save_config($c); }
    return $c['secret'];
}

function lx_ts(?string $local): ?int {
    if (!$local) return null;
    $d = DateTime::createFromFormat('Y-m-d\TH:i', $local, new DateTimeZone('Europe/Berlin'));
    return $d ? $d->getTimestamp() : null;
}

/** Tatsächlich gültiger Modus (Prelaunch wird zum Zeitpunkt automatisch live) */
function lx_effective_mode(array $c): string {
    if ($c['mode'] === 'prelaunch' && $c['auto_launch']) {
        $t = lx_ts($c['launch_at']);
        if ($t !== null && time() >= $t) return 'live';
    }
    return $c['mode'];
}

/* ---------- Zugangscodes ---------- */
function lx_codes(): array { return lx_read('codes', []); }
function lx_save_codes(array $codes): void { lx_write('codes', array_values($codes)); }

function lx_new_code(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // ohne 0/O/1/I
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return substr($s, 0, 4) . '-' . substr($s, 4);
}
function lx_norm_code(string $c): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c)); }

function lx_code_valid(array $code): bool {
    if (empty($code['active'])) return false;
    if (!empty($code['expires'])) { $t = lx_ts($code['expires']); if ($t !== null && time() > $t) return false; }
    return true;
}
function lx_find_code(string $input): ?array {
    $n = lx_norm_code($input);
    if ($n === '') return null;
    foreach (lx_codes() as $code) if (hash_equals(lx_norm_code($code['code']), $n)) return $code;
    return null;
}
function lx_touch_code(string $id): void {
    $codes = lx_codes();
    foreach ($codes as &$code) if ($code['id'] === $id) { $code['last_used'] = date('Y-m-d H:i'); $code['uses'] = ($code['uses'] ?? 0) + 1; }
    lx_save_codes($codes);
}

/* ---------- Zugangs-Cookie (signiert, wird bei jedem Aufruf gegen die Liste geprüft) ---------- */
function lx_sign(string $v): string { return hash_hmac('sha256', $v, lx_secret()); }
function lx_grant_cookie(string $who, int $days = 30): void {
    $exp = time() + $days * 86400;
    $val = $who . '|' . $exp;
    setcookie(LX_COOKIE, base64_encode($val . '|' . lx_sign($val)), [
        'expires' => $exp, 'path' => lx_base() ?: '/', 'secure' => lx_https(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}
function lx_clear_cookie(): void {
    setcookie(LX_COOKIE, '', ['expires' => time() - 3600, 'path' => lx_base() ?: '/', 'secure' => lx_https(), 'httponly' => true, 'samesite' => 'Lax']);
}
/** Liefert "code:<id>", "shared" oder null */
function lx_cookie_who(): ?string {
    $raw = $_COOKIE[LX_COOKIE] ?? '';
    if ($raw === '') return null;
    $dec = base64_decode($raw, true);
    if ($dec === false) return null;
    $parts = explode('|', $dec);
    if (count($parts) !== 3) return null;
    [$who, $exp, $sig] = $parts;
    if (!hash_equals(lx_sign($who . '|' . $exp), $sig) || time() > (int)$exp) return null;
    if ($who === 'shared') return lx_config()['shared_password'] !== '' ? 'shared' : null;
    if (strpos($who, 'code:') === 0) {
        $id = substr($who, 5);
        foreach (lx_codes() as $code) if ($code['id'] === $id && lx_code_valid($code)) return $who;
    }
    return null;
}

/* ---------- Admin-Sitzung ---------- */
function lx_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('loggx_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => lx_base() ?: '/', 'secure' => lx_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
function lx_is_admin(): bool {
    // Sitzung nur starten, wenn bereits ein Admin-Cookie existiert (Besucher bekommen keine Sitzung)
    if (empty($_COOKIE['loggx_admin'])) return false;
    lx_session();
    return !empty($_SESSION['lx_admin']) && ($_SESSION['lx_admin_until'] ?? 0) > time();
}
function lx_csrf(): string {
    lx_session();
    if (empty($_SESSION['lx_csrf'])) $_SESSION['lx_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['lx_csrf'];
}
function lx_check_csrf(): bool {
    lx_session();
    return !empty($_POST['csrf']) && !empty($_SESSION['lx_csrf']) && hash_equals($_SESSION['lx_csrf'], (string)$_POST['csrf']);
}

/* ---------- Schutz gegen Durchprobieren (Login & Codes) ---------- */
function lx_ip(): string { return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . lx_secret()); }
function lx_throttled(string $bucket, int $max = 8, int $window = 900): bool {
    $t = lx_read('throttle', []);
    $key = $bucket . ':' . lx_ip();
    $now = time();
    $hits = array_values(array_filter($t[$key] ?? [], fn($ts) => $ts > $now - $window));
    return count($hits) >= $max;
}
function lx_fail(string $bucket, int $window = 900): void {
    $t = lx_read('throttle', []);
    $key = $bucket . ':' . lx_ip();
    $now = time();
    foreach ($t as $k => $hits) { $t[$k] = array_values(array_filter($hits, fn($ts) => $ts > $now - $window)); if (!$t[$k]) unset($t[$k]); }
    $t[$key][] = $now;
    lx_write('throttle', $t);
}
function lx_hit(string $bucket, int $window = 900): void { lx_locked('throttle', fn() => lx_fail($bucket, $window)); }

/** Mit Dateisperre lesen, ändern und schreiben */
function lx_locked(string $lock, callable $fn) {
    if (!is_dir(LX_DATA)) @mkdir(LX_DATA, 0750, true);
    $h = @fopen(LX_DATA . "/$lock.lock", 'c');
    if ($h) flock($h, LOCK_EX);
    try { return $fn(); }
    finally { if ($h) { flock($h, LOCK_UN); fclose($h); } }
}
function lx_cut(string $v, int $max): string { return function_exists('mb_substr') ? mb_substr(trim($v), 0, $max) : substr(trim($v), 0, $max); }
function lx_abs_url(string $path = ''): string {
    return (lx_https() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . lx_url($path);
}

/** Absender für E-Mails: eingetragene Adresse oder noreply@eigene-domain.de */
function lx_mail_from(): string {
    $from = trim((string)(lx_config()['site']['mail_from'] ?? ''));
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) return $from;
    $host = preg_replace('/^www\./', '', (string)preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? 'localhost'))));
    return 'noreply@' . $host;
}
/** Einfache Text-E-Mail (UTF-8). Lokal (php -S) landet sie in data/mail-test.log. */
function lx_mail(string $to, string $subject, string $text, string $replyTo = '', string $replyName = ''): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = lx_mail_from();
    $clean = fn($s) => str_replace(["\r", "\n"], ' ', (string)$s);
    $b64 = fn($s) => '=?UTF-8?B?' . base64_encode($clean($s)) . '?=';
    $headers = 'From: ' . $b64('LoggX') . " <$from>\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= 'Reply-To: ' . ($replyName !== '' ? $b64($replyName) . ' ' : '') . "<$replyTo>\r\n";
    }
    $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-Mailer: LoggX\r\n";
    if (PHP_SAPI === 'cli-server') {
        @file_put_contents(LX_DATA . '/mail-test.log', "TO: $to\nSUBJECT: $subject\n$headers\n$text\n\n-----\n", FILE_APPEND);
        return true;
    }
    return @mail($to, $b64($subject), str_replace("\n", "\r\n", str_replace("\r\n", "\n", $text)), $headers, '-f' . $from);
}

/** "vor 5 Min." / "gestern" / "12.11." */
function lx_ago(int $ts): string {
    $d = time() - $ts;
    if ($d < 60) return 'gerade eben';
    if ($d < 3600) return 'vor ' . floor($d / 60) . ' Min.';
    if ($d < 86400 && date('Y-m-d', $ts) === date('Y-m-d')) return 'vor ' . floor($d / 3600) . ' Std.';
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'gestern';
    if ($d < 6 * 86400) return ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', $ts)] . ', ' . date('H:i', $ts);
    return date('d.m.' . (date('Y', $ts) !== date('Y') ? 'Y' : ''), $ts);
}

/** "Samstag, 1. November 2026, 18:00 Uhr" */
function lx_date_de(int $ts, bool $withDay = true): string {
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return ($withDay ? $tage[(int)date('w', $ts)] . ', ' : '') . date('j', $ts) . '. ' . $monate[(int)date('n', $ts)] . ' ' . date('Y', $ts) . ', ' . date('H:i', $ts) . ' Uhr';
}

/* ---------- Inhalte der Webseite einsetzen (Preise, Kontaktdaten, Hinweise) ---------- */
function lx_tel(string $phone): string {
    $p = str_replace('(0)', '', $phone);
    $plus = strpos(ltrim($p), '+') === 0;
    $digits = preg_replace('/\D+/', '', $p);
    return ($plus ? '+' : '') . $digits;
}
function lx_site_value(array $s, string $key, bool $de = false): ?string {
    if (!array_key_exists($key, $s)) return null;
    $v = $s[$key];
    if (is_int($v) || is_float($v)) return $de ? number_format((float)$v, 0, ',', '.') : (string)$v;
    return (string)$v;
}
function lx_apply_site(string $html, array $s): string {
    // Seitentitel/Beschreibung im <head> (dort gehen keine data-cms-Elemente)
    $html = preg_replace_callback('~<head>.*?</head>~is', function ($m) use ($s) {
        $h = str_replace(['ab 790 €', 'ab 790&nbsp;€'], 'ab ' . number_format((int)$s['price_visit'], 0, ',', '.') . ' €', $m[0]);
        if (trim((string)$s['time_visit']) !== '') $h = str_replace('in 14 Tagen', 'in ' . e((string)$s['time_visit']), $h);
        return $h;
    }, $html, 1) ?? $html;
    // Markierung: Seite wurde vom Server aufbereitet (main.js räumt sonst Platzhalter selbst auf)
    $html = preg_replace('~<html\b(?![^>]*data-cms-live)~i', '<html data-cms-live data-api="' . e(lx_url('_launch/api.php')) . '"' . (!empty($s['stats_on']) ? ' data-stats' : ''), $html, 1) ?? $html;
    // Telefonnummer noch Platzhalter → Telefon-Links und -Zeilen ausblenden
    if (trim((string)$s['phone']) === '' || $s['phone'] === lx_defaults()['site']['phone']) {
        $html = preg_replace('~<a\b[^>]*data-cms-href="tel"[^>]*>.*?</a>~is', '', $html) ?? $html;
        $html = preg_replace('~<span data-phone-line>.*?<br></span>~is', '', $html) ?? $html;
    }
    // Hochgeladene Bilder (Porträt, Referenz-Screenshots)
    $html = preg_replace_callback('~<img\b([^>]*\bdata-cms-img="([a-z0-9-]+)"[^>]*)>~i', function ($m) use ($s) {
        $attrs = $m[1]; $slot = $m[2];
        $src = (string)($s['img_' . $slot] ?? '');
        if ($src === '') {
            $def = lx_image_slots()[$slot][2] ?? '';
            if ($def === '' || !is_file(lx_root() . '/' . $def)) return $m[0];
            $src = $def;
        }
        $attrs = preg_replace('/\s(data-)?src="[^"]*"/', '', $attrs);
        $attrs = preg_replace('/\bis-missing\b/', '', $attrs);
        return '<img src="' . e(lx_url($src)) . '"' . $attrs . '>';
    }, $html) ?? $html;
    // Durchgestrichener Normalpreis (nur Landingpage): <!--cms-was:visit-->
    $html = preg_replace_callback('~<!--cms-was:([a-z]+)-->~', function ($m) use ($s) {
        $v = (string)($s['was_' . $m[1]] ?? '');
        return $v === '' ? '' : '<s class="plan-was" aria-label="statt ' . e($v) . ' Euro">' . e($v) . '&nbsp;€</s>';
    }, $html) ?? $html;
    // Text in Elementen mit data-cms="schlüssel" ersetzen
    $html = preg_replace_callback('~<([a-z0-9]+)(\b[^>]*?\bdata-cms="([a-z_]+)"[^>]*)>(.*?)</\1>~is', function ($m) use ($s) {
        [$all, $tag, $attrs, $key] = $m;
        $de = strpos($attrs, 'data-format="de"') !== false || in_array($key, ['price_visit', 'price_firm'], true);
        $val = lx_site_value($s, $key, $de);
        if ($val === null || $val === '') return $all;
        if (preg_match('/data-to="/', $attrs) && is_numeric($s[$key])) $attrs = preg_replace('/data-to="[^"]*"/', 'data-to="' . (int)$s[$key] . '"', $attrs);
        $def = lx_defaults()['site'][$key] ?? null;
        if ($def === null || (string)$def === '' || $val !== (string)$def) $attrs = preg_replace('/\s*class="placeholder"/', '', $attrs);
        return '<' . $tag . $attrs . '>' . e($val) . '</' . $tag . '>';
    }, $html) ?? $html;
    // Bereiche ein-/ausblenden: <div data-cms-if="schlüssel">…</div> verschwindet, wenn der Wert leer/aus ist
    $html = preg_replace_callback('~<div data-cms-if="(!?)([a-z_]+)">(.*?)</div>~is', function ($m) use ($s) {
        $on = !empty($s[$m[2]]) && trim((string)$s[$m[2]]) !== '';
        if ($m[1] === '!') $on = !$on;
        return $on ? $m[3] : '';
    }, $html) ?? $html;
    // Links und Formular-Ziel
    $tel = lx_tel((string)$s['phone']);
    $mail = (string)$s['email'];
    $html = preg_replace_callback('~<a\b([^>]*\bdata-cms-href="(tel|mailto)"[^>]*)>~i', function ($m) use ($tel, $mail) {
        $href = $m[2] === 'tel' ? 'tel:' . $tel : 'mailto:' . $mail;
        return '<a' . preg_replace('/\bhref="[^"]*"/', 'href="' . e($href) . '"', $m[1]) . '>';
    }, $html) ?? $html;
    $html = preg_replace_callback('~<form\b([^>]*\bdata-cms-action="formsubmit"[^>]*)>~i', function ($m) use ($mail) {
        return '<form' . preg_replace('/\baction="[^"]*"/', 'action="https://formsubmit.co/' . e($mail) . '"', $m[1]) . '>';
    }, $html) ?? $html;
    // Hinweis "gelb markierte Felder" nur zeigen, solange noch Platzhalter übrig sind
    if (strpos($html, 'class="placeholder"') === false) $html = preg_replace('~<p data-placeholder-note>.*?</p>\s*~is', '', $html) ?? $html;
    return $html;
}

/** Bilder, die im Admin-Bereich ersetzt werden können: slot => [Name, Hinweis, Standarddatei] */
function lx_image_slots(): array {
    return [
        'portrait'    => ['Porträtfoto (Über mich)', 'Hochformat, ca. 900 × 1100 px', 'img/portrait.jpg'],
        'dr-loggen'   => ['Referenz: Zahnarztpraxis Dr. Loggen', 'Screenshot der Startseite, ca. 1200 × 750 px', 'img/dr-loggen.jpg'],
        'fitness4fun' => ['Referenz: 4 fun Fitness & Beauty', 'Screenshot der Startseite, ca. 1200 × 750 px', 'img/fitness4fun.jpg'],
    ];
}

/** Einstellungen für die Werbe-Landingpage: eigene Preise + durchgestrichener Normalpreis */
function lx_site_for_lp(array $s): array {
    foreach (['visit', 'firm'] as $k) {
        $lp = (int)($s['lp_price_' . $k] ?? 0); $norm = (int)$s['price_' . $k];
        $s['was_' . $k] = '';
        if ($lp > 0) {
            if (!empty($s['lp_strike']) && $lp < $norm) $s['was_' . $k] = number_format($norm, 0, ',', '.');
            $s['price_' . $k] = $lp;
        }
    }
    return $s;
}

/** Ankündigungsleiste oben auf der Seite (nur Startseite & Landingpage) */
function lx_bar_html(array $s): string {
    if (empty($s['bar_on']) || trim((string)$s['bar_text']) === '') return '';
    $rawId = preg_replace('/[^a-f0-9]/', '', (string)$s['bar_id']) ?: 'x';
    // Schon geschlossen? Dann gar nicht erst ausliefern (kein Aufblitzen)
    if (($_COOKIE['lx_bar'] ?? '') === $rawId) return '';
    $id = e($rawId);
    $badge = trim((string)($s['bar_badge'] ?? ''));
    $html = '<div class="lx-bar" id="lx-bar" data-id="' . $id . '" role="region" aria-label="Ankündigung"><div class="lx-bar-in">';
    if ($badge !== '') $html .= '<span class="lx-bar-badge"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z"/></svg>' . e($badge) . '</span>';
    $html .= '<p class="lx-bar-text">' . e($s['bar_text']) . '</p>';
    if ($s['bar_link'] !== '' && $s['bar_link_text'] !== '') {
        $html .= '<a class="lx-bar-link" href="' . e($s['bar_link']) . '">' . e($s['bar_link_text'])
               . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>';
    }
    $html .= '</div><button type="button" class="lx-bar-close" aria-label="Ankündigung schließen" title="Schließen">'
           . '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button></div>';
    $html .= '<script>(function(){var b=document.getElementById("lx-bar");if(!b)return;var id=b.getAttribute("data-id"),r=document.documentElement;'
           . 'try{if(localStorage.getItem("lx-bar")===id){b.parentNode.removeChild(b);return}}catch(e){}'
           . 'r.classList.add("has-bar");var h=0,o=function(){r.style.setProperty("--bar-off",Math.max(0,h-(window.scrollY||0))+"px")},f=function(){h=b.offsetHeight;r.style.setProperty("--bar-h",h+"px");o()};f();addEventListener("resize",f);addEventListener("scroll",o,{passive:true});'
           . 'function hide(){try{localStorage.setItem("lx-bar",id)}catch(e){}'
           . 'document.cookie="lx_bar="+id+";path=/;max-age=31536000;SameSite=Lax";'
           . 'removeEventListener("resize",f);removeEventListener("scroll",o);b.style.height=b.offsetHeight+"px";b.offsetHeight;b.classList.add("is-closing");'
           . 'r.classList.add("bar-closing");r.style.setProperty("--bar-off","0px");setTimeout(function(){if(b.parentNode)b.parentNode.removeChild(b);r.classList.remove("has-bar","bar-closing")},450)}'
           . 'b.querySelector(".lx-bar-close").addEventListener("click",function(e){e.preventDefault();e.stopPropagation();hide()})})()</script>';
    return $html;
}

require_once __DIR__ . '/stats.php';
require_once __DIR__ . '/leads.php';
