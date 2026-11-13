<?php
/* ==========================================================================
   LoggX Launch-System – gemeinsame Funktionen
   Daten liegen in _launch/data/*.php (beginnen mit "<?php exit; ?>",
   sind also selbst dann nicht lesbar, wenn .htaccess ignoriert würde).
   ========================================================================== */

declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

/* ---------- Ersatz für fehlende PHP-Erweiterungen (z. B. PHP unter Windows ohne mbstring) ---------- */
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $enc = null) { preg_match_all('/./us', (string)$s, $m); return implode('', array_slice($m[0], (int)$start, $length === null ? null : (int)$length)); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $enc = null) { return (int)preg_match_all('/./us', (string)$s); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $enc = null) { return strtr(strtolower((string)$s), ['Ä' => 'ä', 'Ö' => 'ö', 'Ü' => 'ü']); }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return $n === '' || strpos((string)$h, (string)$n) === 0; }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($h, $n) { return $n === '' || substr((string)$h, -strlen((string)$n)) === (string)$n; }
}

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
    $data = lx_decode_file($file);
    if ($data === null && filesize($file) > strlen(LX_GUARD)) {
        // Datei beschädigt → letzten guten Stand nehmen (.bak vom letzten Speichern, sonst die neueste Tagessicherung) und Datei reparieren
        static $tried = [];
        if (isset($tried[$name])) return $default;
        $tried[$name] = true;
        $from = '';
        $bak = LX_DATA . "/$name.bak.php";
        if (is_file($bak) && ($data = lx_decode_file($bak)) !== null) $from = 'vom letzten Speichern';
        if ($data === null && function_exists('lx_backups')) foreach (lx_backups() as $b) {
            $d = lx_backup_load($b['name']); $raw = (string)($d['files'][$name] ?? '');
            if ($raw !== '' && is_array($j = json_decode(substr($raw, strlen(LX_GUARD)), true))) { $data = $j; $from = 'aus der Sicherung vom ' . date('d.m.Y H:i', $b['ts']); break; }
        }
        if ($data !== null) { @copy($file, LX_DATA . "/$name.kaputt-" . date('Ymd-His') . '.txt'); try { lx_write($name, $data, false); } catch (Throwable $e) {} }
        if (function_exists('lx_log')) lx_log('fehler', "Datei $name.php war beschädigt – " . ($data !== null ? "automatisch repariert (Stand $from)." : 'kein Ersatz gefunden.'));
    }
    return is_array($data) ? $data : $default;
}
function lx_decode_file(string $file): ?array {
    $raw = (string)@file_get_contents($file);
    if (strpos($raw, LX_GUARD) === 0) $raw = substr($raw, strlen(LX_GUARD));
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
/** Sicher speichern: erst in eine Zwischendatei, dann austauschen. Der vorige Stand bleibt als .bak erhalten. */
function lx_write(string $name, array $data, bool $bak = true): void {
    if (!is_dir(LX_DATA)) mkdir(LX_DATA, 0750, true);
    $file = LX_DATA . "/$name.php";
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException("Daten für $name konnten nicht gespeichert werden.");
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $ok = @file_put_contents($tmp, LX_GUARD . $json, LOCK_EX);
    if ($ok === false || $ok < strlen(LX_GUARD . $json)) {             // z. B. Speicher voll → alten Stand behalten
        @unlink($tmp);
        if (function_exists('lx_log') && !str_starts_with($name, 'log-')) lx_log('fehler', "Speichern von $name fehlgeschlagen (Speicherplatz voll?).");
        throw new RuntimeException("Speichern fehlgeschlagen ($name).");
    }
    if ($bak && is_file($file) && filesize($file) > strlen(LX_GUARD)) @copy($file, LX_DATA . "/$name.bak.php");
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
            'phone_show' => false,         // Telefonnummer auf der Webseite zeigen (aus = nur E-Mail & Formular)
            'email' => 'constantinloggen@icloud.com',
            'street' => 'Zum Himmelreich 5',
            'city' => '49082 Osnabrück',
            'price_visit' => 790,
            'price_firm' => 1890,
            'price_hosting' => 29,
            'price_hourly' => 70,          // Änderungen & Support nach Aufwand (€/Stunde)
            'lp_price_visit' => 0,         // Werbe-Landingpage (/angebot/): 0 = gleicher Preis wie Hauptseite
            'lp_price_firm' => 0,
            'lp_strike' => true,           // Normalpreis durchgestrichen daneben zeigen, wenn der Landingpage-Preis günstiger ist
            'time_visit' => '14 Tagen',
            'time_firm' => '3–4 Wochen',
            'response_hours' => 24,
            'kicker_home' => 'Webdesign für Unternehmen',
            'kicker_angebot' => 'Für Betriebe in ganz Deutschland',
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
            'mail_cc' => 'info@loggx.de',  // bekommt zusätzlich alle E-Mails an dich (Anfragen, Tests, Codes); mehrere mit Komma
            'check_on' => true,            // Abschnitt „Kostenloser Webseiten-Check“ auf der Startseite
            'check_time' => '3 Werktagen', // so schnell kommt die Einschätzung
            'weekly_on' => true,           // montags Wochenbericht per E-Mail
            'weekly_last' => '',
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
        // Zwei-Faktor-Anmeldung (Authenticator-App) für den Admin-Bereich
        'totp_on' => false,
        'totp_secret' => '',
        'totp_last' => 0,
        'totp_recovery' => [],
        // Eigener zweiter Faktor nur für den Finanzbereich (eigener Eintrag "LoggX Finanzen" in der App)
        'fin_totp_on' => false,
        'fin_totp_secret' => '',
        'fin_totp_last' => 0,
        'fin_totp_recovery' => [],
        'fin_pw_hash' => '',              // eigenes Passwort nur für den Finanzbereich
        // IndexNow (Bing, Yandex, Seznam …): neue/geänderte Seiten sofort melden
        'indexnow_key' => '',
        'indexnow_last' => [],
        // Kundenstimmen (Admin → Inhalte → Kundenstimmen) – nur echte Rückmeldungen
        'reviews' => [],
        // Terminvorschläge (Admin → Termine)
        'booking' => lx_bk_defaults(),
        'schema' => 2,
    ];
}
function lx_config(): array {
    $c = lx_read('config', []);
    $d = lx_defaults();
    if ($c && !isset($c['schema'])) $c['schema'] = 1; // gespeicherte Einstellungen von vor der Umstellung
    foreach ($d as $k => $v) {
        if (!array_key_exists($k, $c)) $c[$k] = $v;
        elseif (is_array($v)) $c[$k] = array_merge($v, is_array($c[$k]) ? $c[$k] : []);
    }
    // Einmalige Umstellung älterer Einstellungen (Stand Oktober 2026)
    if ((int)($c['schema'] ?? 1) < 2) {
        if ((int)($c['site']['price_hourly'] ?? 0) === 50) $c['site']['price_hourly'] = 70;
        if (($c['site']['kicker_home'] ?? '') === 'Webdesign aus Osnabrück') $c['site']['kicker_home'] = $d['site']['kicker_home'];
        if (($c['site']['kicker_angebot'] ?? '') === 'Für Betriebe aus Osnabrück und Umgebung') $c['site']['kicker_angebot'] = $d['site']['kicker_angebot'];
        $c['schema'] = 2;
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
/** Echte Besucher-IP – hinter Cloudflare aus CF-Connecting-IP, aber nur wenn die Anfrage wirklich von Cloudflare kommt */
function lx_remote_ip(): string {
    static $ip = null;
    if ($ip !== null) return $ip;
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP) && lx_ip_in($ip, lx_cloudflare_ranges())) $ip = $cf;
    return $ip;
}
/** Adressbereiche von Cloudflare (https://www.cloudflare.com/ips/) */
function lx_cloudflare_ranges(): array {
    return ['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32'];
}
function lx_ip_in(string $ip, array $ranges): bool {
    $bin = @inet_pton($ip);
    if ($bin === false) return false;
    foreach ($ranges as $r) {
        [$net, $bits] = explode('/', $r);
        $nb = @inet_pton($net);
        if ($nb === false || strlen($nb) !== strlen($bin)) continue;
        $bits = (int)$bits; $bytes = intdiv($bits, 8); $rest = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) continue;
        if ($rest === 0) return true;
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        if ((ord($bin[$bytes]) & $mask) === (ord($nb[$bytes]) & $mask)) return true;
    }
    return false;
}
function lx_ip(): string { return hash('sha256', lx_remote_ip() . lx_secret()); }
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
/** Text-E-Mail (UTF-8), optional mit Anhängen [['name'=>…, 'type'=>…, 'data'=>…]]. Lokal (php -S) landet sie in data/mail-test.log. */
function lx_mail(string $to, string $subject, string $text, string $replyTo = '', string $replyName = '', array $attach = []): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = lx_mail_from();
    $clean = fn($s) => str_replace(["\r", "\n"], ' ', (string)$s);
    $b64 = fn($s) => '=?UTF-8?B?' . base64_encode($clean($s)) . '?=';
    $headers = 'From: ' . $b64('LoggX') . " <$from>\r\n";
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $headers .= 'Reply-To: ' . ($replyName !== '' ? $b64($replyName) . ' ' : '') . "<$replyTo>\r\n";
    }
    $text = str_replace("\r\n", "\n", $text);
    $logAtt = '';
    if ($attach) {
        $bd = 'lx-' . bin2hex(random_bytes(8));
        $headers .= "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"$bd\"\r\nX-Mailer: LoggX\r\n";
        $body = "--$bd\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n" . str_replace("\n", "\r\n", $text) . "\r\n";
        foreach ($attach as $a) {
            $name = (string)preg_replace('/[^\w.\-]/', '_', (string)$a['name']);
            $body .= "--$bd\r\nContent-Type: {$a['type']}; name=\"$name\"\r\nContent-Disposition: attachment; filename=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode((string)$a['data'])) . "\r\n";
            $logAtt .= "[Anhang: $name, " . strlen((string)$a['data']) . " Bytes]\n";
        }
        $body .= "--$bd--\r\n";
    } else {
        $headers .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nX-Mailer: LoggX\r\n";
        $body = str_replace("\n", "\r\n", $text);
    }
    if (PHP_SAPI === 'cli-server') {
        @file_put_contents(LX_DATA . '/mail-test.log', "TO: $to\nSUBJECT: $subject\n$headers\n$text\n$logAtt\n-----\n", FILE_APPEND);
        lx_log('mail', $subject, ['to' => $to, 'ok' => 1, 'att' => $attach ? count($attach) : 0], 200);
        return true;
    }
    $ok = @mail($to, $b64($subject), $body, $headers, '-f' . $from);
    lx_log('mail', $subject, ['to' => $to, 'ok' => $ok ? 1 : 0, 'att' => $attach ? count($attach) : 0], 200);
    if (!$ok) lx_log('fehler', 'E-Mail an ' . $to . ' konnte nicht gesendet werden (Absender: ' . $from . ').');
    return $ok;
}

/** Alle Adressen, die deine E-Mails bekommen: Kontakt-Adresse + Kopie-Empfänger (z. B. info@loggx.de) */
function lx_admin_rcpts(?array $c = null): array {
    $c = $c ?? lx_config();
    $list = array_merge([(string)($c['site']['email'] ?? '')], preg_split('/[\s,;]+/', (string)($c['site']['mail_cc'] ?? '')) ?: []);
    $out = [];
    foreach ($list as $m) { $m = strtolower(trim($m)); if ($m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) && !in_array($m, $out, true)) $out[] = $m; }
    return $out;
}
/** E-Mail an dich – geht an alle Empfänger aus lx_admin_rcpts(). true, wenn mindestens eine rausging. */
function lx_mail_admin(string $subject, string $text, string $replyTo = '', string $replyName = '', array $attach = []): bool {
    $ok = false;
    foreach (lx_admin_rcpts() as $to) $ok = lx_mail($to, $subject, $text, $replyTo, $replyName, $attach) || $ok;
    return $ok;
}

/* ---------- IndexNow: Suchmaschinen über neue/geänderte Seiten informieren ---------- */
function lx_indexnow_key(): string {
    $c = lx_config();
    if (!preg_match('/^[a-f0-9]{32}$/', (string)$c['indexnow_key'])) { $c['indexnow_key'] = bin2hex(random_bytes(16)); lx_save_config($c); }
    return $c['indexnow_key'];
}
/** Adressen aus der sitemap.xml */
function lx_indexnow_urls(): array {
    $xml = @file_get_contents(lx_root() . '/sitemap.xml');
    if (!$xml || !preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~', $xml, $m)) return [];
    return array_values(array_unique(array_map('html_entity_decode', $m[1])));
}
/** Meldet die Seiten an api.indexnow.org. Gibt [ok, Meldung] zurück und merkt sich das Ergebnis. */
function lx_indexnow_submit(?array $urls = null): array {
    $urls = $urls ?? lx_indexnow_urls();
    if (!$urls) return [false, 'Keine Adressen in der sitemap.xml gefunden.'];
    $host = (string)parse_url($urls[0], PHP_URL_HOST);
    $key = lx_indexnow_key();
    $body = json_encode(['host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . '/' . $key . '.txt', 'urlList' => $urls], JSON_UNESCAPED_SLASHES);
    $status = 0; $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.indexnow.org/indexnow');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=utf-8']]);
        curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json; charset=utf-8\r\n", 'content' => $body, 'timeout' => 8, 'ignore_errors' => true]]);
        @file_get_contents('https://api.indexnow.org/indexnow', false, $ctx);
        if (isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $mm)) $status = (int)$mm[1];
    }
    $ok = $status === 200 || $status === 202;
    $msg = $ok ? count($urls) . ' Seite(n) gemeldet – Bing & Co. schauen in Kürze vorbei.'
        : ($status === 403 ? 'Abgelehnt (403): Der Schlüssel ist unter ' . $host . '/' . $key . '.txt noch nicht erreichbar – ist die neue .htaccess hochgeladen?'
        : ($status === 422 ? 'Abgelehnt (422): Die Adressen passen nicht zur Domain.' : ($status === 429 ? 'Zu viele Meldungen – später noch einmal.' : 'Keine Antwort von IndexNow (' . ($status ?: $err ?: 'Verbindung fehlgeschlagen') . ').')));
    lx_locked('config', function () use ($ok, $msg, $urls) { $c = lx_config(); $c['indexnow_last'] = ['ts' => time(), 'ok' => $ok, 'msg' => $msg, 'n' => count($urls)]; lx_save_config($c); });
    return [$ok, $msg];
}
/** Nach dem Speichern automatisch melden (höchstens alle 10 Minuten, nur wenn die Seite live ist) – läuft nach der Antwort */
function lx_indexnow_later(): void {
    if (PHP_SAPI === 'cli-server') return;
    $c = lx_config();
    if (lx_effective_mode($c) !== 'live' || (int)($c['indexnow_last']['ts'] ?? 0) > time() - 600) return;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
        try { lx_indexnow_submit(); } catch (Throwable $e) { error_log('LoggX IndexNow: ' . $e->getMessage()); }
    });
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
/** Telefonnummer öffentlich zeigen? Nur wenn eingetragen UND im Admin eingeschaltet (Inhalte → Kontaktdaten). */
function lx_phone_public(?array $s = null): bool {
    $s = $s ?? lx_config()['site'];
    $p = trim((string)($s['phone'] ?? ''));
    return $p !== '' && $p !== lx_defaults()['site']['phone'] && !empty($s['phone_show']);
}
function lx_tel(string $phone): string {
    $p = str_replace('(0)', '', $phone);
    $plus = strpos(ltrim($p), '+') === 0;
    $digits = preg_replace('/\D+/', '', $p);
    return ($plus ? '+' : '') . $digits;
}
function lx_site_value(array $s, string $key, bool $de = false, bool $en = false): ?string {
    if (!array_key_exists($key, $s)) return null;
    $v = $s[$key];
    if (is_int($v) || is_float($v)) return $en ? number_format((float)$v, 0, '.', ',') : ($de ? number_format((float)$v, 0, ',', '.') : (string)$v);
    return (string)$v;
}
function lx_apply_site(string $html, array $s): string {
    // Seitentitel/Beschreibung im <head> (dort gehen keine data-cms-Elemente)
    $html = preg_replace_callback('~<head>.*?</head>~is', function ($m) use ($s) {
        $pv = (int)$s['price_visit']; $pf = (int)$s['price_firm']; $ph = (int)($s['price_hourly'] ?? 70);
        $h = str_replace(['ab 790 €', 'ab 790&nbsp;€'], 'ab ' . number_format($pv, 0, ',', '.') . ' €', $m[0]);
        $h = str_replace(['ab 1.890 €', 'ab 1.890&nbsp;€'], 'ab ' . number_format($pf, 0, ',', '.') . ' €', $h);
        $h = str_replace('ab 70 €/Std.', 'ab ' . $ph . ' €/Std.', $h);
        // strukturierte Daten (JSON-LD) müssen zum sichtbaren Preis passen
        $h = str_replace(['"price": "790"', '"price": "1890"'], ['"price": "' . $pv . '"', '"price": "' . $pf . '"'], $h);
        if (trim((string)$s['time_visit']) !== '') $h = str_replace('in 14 Tagen', 'in ' . e((string)$s['time_visit']), $h);
        return $h;
    }, $html, 1) ?? $html;
    // Markierung: Seite wurde vom Server aufbereitet (main.js räumt sonst Platzhalter selbst auf)
    $html = preg_replace('~<html\b(?![^>]*data-cms-live)~i', '<html data-cms-live data-api="' . e(lx_url('_launch/api.php')) . '"' . (!empty($s['stats_on']) ? ' data-stats' : ''), $html, 1) ?? $html;
    // Telefonnummer noch Platzhalter → Telefon-Links und -Zeilen ausblenden
    if (!lx_phone_public($s)) {
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
    // Text in Elementen mit data-cms="schlüssel" ersetzen (englische Seiten: 1,890 statt 1.890)
    $en = (bool)preg_match('~<html\b[^>]*\blang="en~i', $html);
    $html = preg_replace_callback('~<([a-z0-9]+)(\b[^>]*?\bdata-cms="([a-z_]+)"[^>]*)>(.*?)</\1>~is', function ($m) use ($s, $en) {
        [$all, $tag, $attrs, $key] = $m;
        $de = strpos($attrs, 'data-format="de"') !== false || strpos($attrs, 'data-format="en"') !== false || in_array($key, ['price_visit', 'price_firm'], true);
        $val = lx_site_value($s, $key, $de, $en && $de);
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
    // Größere Bereiche ein-/ausblenden: <!--cms-if:schlüssel--> … <!--/cms-if:schlüssel-->
    $html = preg_replace_callback('~<!--cms-if:(!?)([a-z_]+)-->(.*?)<!--/cms-if:\\1\\2-->~is', function ($m) use ($s) {
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
    // CSS/JS mit Versionsnummer (Änderungsdatum der Datei) – Browser dürfen sie lange zwischenspeichern
    $html = preg_replace_callback('~\b(href|src)="((?:\.\./)*)((?:css|js)/[A-Za-z0-9_./-]+\.(?:css|js))"~', function ($m) {
        $f = lx_root() . '/' . $m[3];
        return is_file($f) ? $m[1] . '="' . $m[2] . $m[3] . '?v=' . filemtime($f) . '"' : $m[0];
    }, $html) ?? $html;
    // Hinweis "gelb markierte Felder" nur zeigen, solange noch Platzhalter übrig sind
    if (strpos($html, 'class="placeholder"') === false) $html = preg_replace('~<p data-placeholder-note>.*?</p>\s*~is', '', $html) ?? $html;
    // Kundenstimmen (Admin → Inhalte → Kundenstimmen)
    if (strpos($html, '<!--cms:reviews') !== false) $html = lx_reviews_apply($html);
    // Häufige Fragen als strukturierte Daten (FAQPage) – immer passend zum sichtbaren Text
    $html = lx_faq_schema($html);
    return $html;
}

/** Text aus HTML für strukturierte Daten */
function lx_plain(string $h): string {
    return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $h)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/* ---------- Kundenstimmen ---------- */
/** Projekte, denen eine Kundenstimme zugeordnet werden kann: key => [Name, Seite] */
function lx_review_projects(): array {
    return ['dr-loggen' => ['Zahnarztpraxis Dr. Loggen', 'projekt-dr-loggen'], '4fun' => ['4 fun Fitness & Beauty', 'projekt-4fun'], 'reserved' => ['Reserved.', '']];
}
function lx_reviews(?array $c = null): array {
    $c = $c ?? lx_config();
    return array_values(array_filter((array)($c['reviews'] ?? []), fn($r) => is_array($r) && trim((string)($r['text'] ?? '')) !== ''));
}
function lx_review_initials(string $name): string {
    $p = preg_split('/\s+/u', trim($name)) ?: [];
    $i = ''; foreach ([reset($p), count($p) > 1 ? end($p) : ''] as $w) if ($w) $i .= mb_strtoupper(mb_substr($w, 0, 1));
    return $i ?: '★';
}
function lx_review_card(array $r, bool $withLink): string {
    $pr = lx_review_projects()[$r['projekt'] ?? ''] ?? null;
    $who = implode(', ', array_filter([trim((string)($r['role'] ?? '')), trim((string)($r['firma'] ?? ''))]));
    $pic = trim((string)($r['img'] ?? '')) !== ''
        ? '<img src="' . e(lx_url((string)$r['img'])) . '" alt="" width="52" height="52" loading="lazy" decoding="async">'
        : '<span aria-hidden="true">' . e(lx_review_initials((string)$r['name'])) . '</span>';
    $h  = '<figure class="review spotlight reveal"><svg class="review-q" viewBox="0 0 48 36" aria-hidden="true"><path d="M0 36V22C0 9.6 6.4 2.3 19.2 0l2 5.4C14 7.4 10.6 11.5 10.4 18H20v18zm27 0V22C27 9.6 33.4 2.3 46.2 0l1.8 5.4C41 7.4 37.6 11.5 37.4 18H47v18z"/></svg>';
    $h .= '<blockquote><p>' . nl2br(e(trim((string)$r['text']))) . '</p></blockquote>';
    $h .= '<figcaption><span class="review-pic">' . $pic . '</span><span class="review-who"><b>' . e((string)$r['name']) . '</b>' . ($who !== '' ? '<small>' . e($who) . '</small>' : '') . '</span>';
    if ($withLink && $pr && $pr[1] !== '') $h .= '<a class="review-link" href="' . e($pr[1]) . '">Projekt ansehen<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14m-5-5 5 5-5 5"/></svg></a>';
    return $h . '</figcaption></figure>';
}
/** <!--cms:reviews-->…<!--/cms:reviews--> (alle) bzw. <!--cms:reviews:projekt-->…<!--/cms:reviews--> (nur dieses Projekt) */
function lx_reviews_apply(string $html): string {
    $all = array_values(array_filter(lx_reviews(), fn($r) => !empty($r['on'])));
    return preg_replace_callback('~<!--cms:reviews(?::([a-z0-9-]+))?-->(.*?)<!--/cms:reviews-->~is', function ($m) use ($all) {
        $f = (string)($m[1] ?? '');
        $list = $f === '' ? array_slice($all, 0, 6) : array_values(array_filter($all, fn($r) => ($r['projekt'] ?? '') === $f));
        if (!$list) return '';
        $cards = implode('', array_map(fn($r) => lx_review_card($r, $f === ''), $list));
        return str_replace(['{{reviews}}', '{{n}}'], [$cards, (string)count($list)], $m[2]);
    }, $html) ?? $html;
}

/** FAQPage-JSON-LD aus allen <details class="faq">-Blöcken einer Seite */
function lx_faq_schema(string $html): string {
    if (stripos($html, '"FAQPage"') !== false) return $html;
    if (!preg_match_all('~<details\b[^>]*class="faq\b[^"]*"[^>]*>\s*<summary\b[^>]*>(.*?)</summary>(.*?)</details>~is', $html, $m, PREG_SET_ORDER)) return $html;
    $items = [];
    foreach ($m as $x) {
        $q = lx_plain($x[1]); $a = lx_plain($x[2]);
        if ($q === '' || $a === '') continue;
        $items[] = ['@type' => 'Question', 'name' => $q, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]];
    }
    if (!$items) return $html;
    $json = json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return preg_replace('~</head>~i', '  <script type="application/ld+json">' . $json . "</script>\n</head>", $html, 1) ?? $html;
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
require_once __DIR__ . '/totp.php';
require_once __DIR__ . '/booking.php';
require_once __DIR__ . '/system.php';
lx_error_handlers();
