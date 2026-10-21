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
    $base = rtrim(dirname(dirname($script)), '/');
    if ($base === '.' || $base === '/') $base = '';
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
            'time_visit' => '14 Tagen',
            'time_firm' => '3–4 Wochen',
            'response_hours' => 24,
            'kicker_home' => 'Webdesign aus Osnabrück',
            'kicker_angebot' => 'Für Betriebe aus Osnabrück und Umgebung',
            'bar_on' => false,
            'bar_text' => '',
            'bar_link_text' => '',
            'bar_link' => '',
            'bar_id' => '',
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
    // Text in Elementen mit data-cms="schlüssel" ersetzen
    $html = preg_replace_callback('~<([a-z0-9]+)(\b[^>]*?\bdata-cms="([a-z_]+)"[^>]*)>(.*?)</\1>~is', function ($m) use ($s) {
        [$all, $tag, $attrs, $key] = $m;
        $de = strpos($attrs, 'data-format="de"') !== false || in_array($key, ['price_visit', 'price_firm'], true);
        $val = lx_site_value($s, $key, $de);
        if ($val === null || $val === '') return $all;
        if (preg_match('/data-to="/', $attrs) && is_numeric($s[$key])) $attrs = preg_replace('/data-to="[^"]*"/', 'data-to="' . (int)$s[$key] . '"', $attrs);
        if ($key === 'phone' && $val !== lx_defaults()['site']['phone']) $attrs = preg_replace('/\s*class="placeholder"/', '', $attrs);
        return '<' . $tag . $attrs . '>' . e($val) . '</' . $tag . '>';
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
    return $html;
}

/** Ankündigungsleiste oben auf der Seite (nur Startseite & Landingpage) */
function lx_bar_html(array $s): string {
    if (empty($s['bar_on']) || trim((string)$s['bar_text']) === '') return '';
    $id = e((string)$s['bar_id']);
    $link = '';
    if ($s['bar_link'] !== '' && $s['bar_link_text'] !== '') $link = ' <a href="' . e($s['bar_link']) . '">' . e($s['bar_link_text']) . ' →</a>';
    return '<div class="lx-bar" id="lx-bar" data-id="' . $id . '" role="region" aria-label="Hinweis"><p>' . e($s['bar_text']) . $link . '</p>'
        . '<button type="button" aria-label="Hinweis schließen" onclick="try{localStorage.setItem(\'lx-bar\',this.parentNode.getAttribute(\'data-id\'))}catch(e){}this.parentNode.remove();document.documentElement.classList.remove(\'has-bar\')">✕</button></div>'
        . '<script>(function(){var b=document.getElementById("lx-bar");try{if(localStorage.getItem("lx-bar")==="' . $id . '"){b.remove();return}}catch(e){}'
        . 'var r=document.documentElement;r.classList.add("has-bar");var f=function(){r.style.setProperty("--bar-h",b.offsetHeight+"px")};f();addEventListener("resize",f)})()</script>';
}
