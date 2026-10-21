<?php
/* ==========================================================================
   LoggX – Besucherstatistik (ohne Cookies, ohne gespeicherte IP-Adressen)
   - Jeder Seitenaufruf wird pro Tag gezählt (Seite, Herkunft, Gerät).
   - "Besucher" werden über einen Tages-Hash aus IP + Browser + Zufallswert
     erkannt. Der Zufallswert wird jeden Tag neu erzeugt und danach gelöscht,
     dadurch lässt sich niemand nachverfolgen oder wiedererkennen.
   - Admins, Bots und Browser mit "Do Not Track" / GPC werden nicht gezählt.
   ========================================================================== */

declare(strict_types=1);

const LX_STATS_KEEP_MONTHS = 14;

/** Lesbare Namen für die Seiten */
function lx_stats_pages(): array {
    return [
        'index.html' => 'Startseite',
        'angebot/index.html' => 'Werbe-Landingpage',
        'impressum.html' => 'Impressum',
        'datenschutz.html' => 'Datenschutz',
        'danke.html' => 'Anfrage gesendet',
        '(launch)' => 'Vor-Launch-Seite',
        '(wartung)' => 'Wartungsseite',
    ];
}

function lx_stats_is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|preview|headless|lighthouse|pagespeed|curl|wget|python|java/|go-http|monitor|uptime|facebookexternalhit|whatsapp|telegram|discord|semrush|ahrefs~i', $ua);
}

function lx_stats_device(string $ua): string {
    if (preg_match('~iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))~i', $ua)) return 't';
    if (preg_match('~Mobi|iPhone|iPod|Android|Windows Phone~i', $ua)) return 'm';
    return 'd';
}

/** Herkunft: Suchmaschine, soziales Netzwerk, andere Seite oder direkt */
function lx_stats_source(): string {
    $utm = trim((string)($_GET['utm_source'] ?? ''));
    if ($utm !== '') return strtolower(substr(preg_replace('/[^\w.\- ]/u', '', $utm) ?? '', 0, 40));
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    if ($host === '') return 'direkt';
    $own = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $own = preg_replace('/:\d+$/', '', $own);
    if ($host === $own || ($own !== '' && str_ends_with($host, '.' . $own))) return 'intern';
    $map = ['google.' => 'Google', 'bing.' => 'Bing', 'duckduckgo.' => 'DuckDuckGo', 'ecosia.' => 'Ecosia', 'yahoo.' => 'Yahoo',
            'instagram.' => 'Instagram', 'facebook.' => 'Facebook', 'fb.' => 'Facebook', 'linkedin.' => 'LinkedIn', 'lnkd.' => 'LinkedIn',
            't.co' => 'X / Twitter', 'twitter.' => 'X / Twitter', 'tiktok.' => 'TikTok', 'youtube.' => 'YouTube', 'pinterest.' => 'Pinterest',
            'whatsapp.' => 'WhatsApp', 'linktr.ee' => 'Linktree', 'maps.google' => 'Google Maps'];
    foreach (['maps.google' => 'Google Maps'] + $map as $needle => $name) if (strpos($host, $needle) !== false) return $name;
    return preg_replace('/^www\./', '', $host) ?: 'direkt';
}

/** Mit Dateisperre lesen, ändern und schreiben */
function lx_stats_locked(callable $fn) {
    if (!is_dir(LX_DATA)) @mkdir(LX_DATA, 0750, true);
    $lock = @fopen(LX_DATA . '/stats.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    try { return $fn(); }
    finally { if ($lock) { flock($lock, LOCK_UN); fclose($lock); } }
}

/** Seitenaufruf zählen */
function lx_stats_track(string $page): void {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (lx_stats_is_bot($ua)) return;
        if (($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1') return;
        if (strtolower((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '')) === 'prefetch') return;
        $page = strtolower($page);
        if (!isset(lx_stats_pages()[$page])) $page = 'andere';
        $day = date('Y-m-d');
        $source = lx_stats_source();
        $device = lx_stats_device($ua);

        lx_stats_locked(function () use ($page, $day, $source, $device, $ua) {
            // Tages-Hash für "Besucher": Zufallswert gilt nur heute und wird danach verworfen
            $today = lx_read('stats-heute', []);
            if (($today['day'] ?? '') !== $day) $today = ['day' => $day, 'salt' => bin2hex(random_bytes(16)), 'seen' => []];
            $hash = substr(hash('sha256', $today['salt'] . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua), 0, 16);
            $isNew = !isset($today['seen'][$hash]);
            if ($isNew) $today['seen'][$hash] = 1;
            lx_write('stats-heute', $today);

            $file = 'stats-' . substr($day, 0, 7);
            $m = lx_read($file, []);
            $d = $m[$day] ?? ['v' => 0, 'u' => 0, 'a' => 0, 'p' => [], 'r' => [], 'g' => ['m' => 0, 't' => 0, 'd' => 0]];
            $d['v']++;
            if ($page === 'danke.html') $d['a']++;
            $d['p'][$page] = ($d['p'][$page] ?? 0) + 1;
            if ($isNew) {
                $d['u']++;
                $d['g'][$device] = ($d['g'][$device] ?? 0) + 1;
                if ($source !== 'intern') $d['r'][$source] = ($d['r'][$source] ?? 0) + 1;
                if (count($d['r']) > 60) { arsort($d['r']); $d['r'] = array_slice($d['r'], 0, 50, true); }
            }
            $m[$day] = $d;
            lx_write($file, $m);
        });

        // Alte Monate gelegentlich aufräumen
        if (random_int(1, 200) === 1) lx_stats_cleanup();
    } catch (Throwable $e) {
        // Statistik darf die Seite nie stören
    }
}

function lx_stats_cleanup(): void {
    $limit = date('Y-m', strtotime('-' . LX_STATS_KEEP_MONTHS . ' months'));
    foreach (glob(LX_DATA . '/stats-????-??.php') ?: [] as $f) {
        if (substr(basename($f, '.php'), 6) < $limit) @unlink($f);
    }
}

function lx_stats_reset(): void {
    lx_stats_locked(function () {
        foreach (glob(LX_DATA . '/stats-*.php') ?: [] as $f) @unlink($f);
    });
}

/** Auswertung für die letzten $days Tage */
function lx_stats_summary(int $days): array {
    $out = ['days' => [], 'v' => 0, 'u' => 0, 'a' => 0, 'p' => [], 'r' => [], 'g' => ['m' => 0, 't' => 0, 'd' => 0], 'prev_u' => 0, 'prev_v' => 0];
    $cache = [];
    $get = function (string $day) use (&$cache) {
        $f = 'stats-' . substr($day, 0, 7);
        if (!isset($cache[$f])) $cache[$f] = lx_read($f, []);
        return $cache[$f][$day] ?? null;
    };
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $d = $get($day);
        $out['days'][] = ['day' => $day, 'u' => (int)($d['u'] ?? 0), 'v' => (int)($d['v'] ?? 0), 'a' => (int)($d['a'] ?? 0)];
        if (!$d) continue;
        $out['v'] += (int)$d['v']; $out['u'] += (int)$d['u']; $out['a'] += (int)($d['a'] ?? 0);
        foreach ($d['p'] ?? [] as $k => $n) $out['p'][$k] = ($out['p'][$k] ?? 0) + $n;
        foreach ($d['r'] ?? [] as $k => $n) $out['r'][$k] = ($out['r'][$k] ?? 0) + $n;
        foreach ($d['g'] ?? [] as $k => $n) $out['g'][$k] = ($out['g'][$k] ?? 0) + $n;
    }
    // Vergleichszeitraum davor (für die Entwicklung in %)
    for ($i = 2 * $days - 1; $i >= $days; $i--) {
        $d = $get(date('Y-m-d', strtotime("-$i days")));
        if ($d) { $out['prev_u'] += (int)$d['u']; $out['prev_v'] += (int)$d['v']; }
    }
    arsort($out['p']); arsort($out['r']);
    return $out;
}
