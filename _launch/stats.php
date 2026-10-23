<?php
/* ==========================================================================
   LoggX – Besucherstatistik (ohne Cookies, ohne gespeicherte IP-Adressen)
   Server zählt jeden Seitenaufruf (Seite, Herkunft, Gerät, System, Browser,
   Uhrzeit, Kampagne). Ein kleines Signal aus dem Browser ergänzt: welche
   Abschnitte gesehen wurden, Klicks auf wichtige Buttons und die Verweildauer.
   "Besucher" werden über einen Tages-Hash (IP + Browser + Tages-Zufallswert)
   gezählt – der Zufallswert wird täglich verworfen, niemand ist wiedererkennbar.
   Admins, Bots und Browser mit "Do Not Track"/GPC werden nicht gezählt.
   ========================================================================== */

declare(strict_types=1);

const LX_STATS_KEEP_MONTHS = 14;

/** Lesbare Namen für die Seiten */
function lx_stats_pages(): array {
    return [
        'index.html' => 'Startseite',
        'angebot/index.html' => 'Werbe-Landingpage',
        'webseite-praxis.html' => 'Branche: Praxen',
        'webseite-handwerk.html' => 'Branche: Handwerk',
        'webseite-studio.html' => 'Branche: Studios',
        'webseite-gastronomie.html' => 'Branche: Gastronomie',
        'preise.html' => 'Preise',
        'impressum.html' => 'Impressum',
        'datenschutz.html' => 'Datenschutz',
        'danke.html' => 'Danke-Seite',
        '(launch)' => 'Vor-Launch-Seite',
        '(wartung)' => 'Wartungsseite',
        'andere' => 'Andere',
    ];
}
/** Abschnitte in Reihenfolge (für "Wie weit wird gescrollt?") – Startseite und Landingpage (lp-…) */
function lx_stats_sections(): array {
    return ['top' => 'Start', 'referenzen' => 'Referenzen', 'leistungen' => 'Leistungen', 'beispiele' => 'Beispiele', 'ablauf' => 'Ablauf',
            'preise' => 'Preise', 'ueber' => 'Über mich', 'faq' => 'Fragen', 'kontakt' => 'Anfrage'];
}
function lx_stats_sections_lp(): array {
    return ['lp-top' => 'Start', 'lp-vorteile' => 'Vorteile', 'lp-beispiele' => 'Beispiele', 'lp-ablauf' => 'Ablauf',
            'lp-preise' => 'Preise', 'lp-faq' => 'Fragen', 'lp-anfrage' => 'Anfrage'];
}
/** Klickziele mit lesbarem Namen */
function lx_stats_clicks(): array {
    return ['plan-visit' => 'Paket „Visitenkarte“ angefragt', 'plan-firm' => 'Paket „Firmenwebseite“ angefragt', 'plan-indiv' => 'Paket „Individuell“ angefragt',
            'plan-hosting' => 'Hosting angefragt', 'plan-change' => 'Änderungen & Support angefragt', 'advisor' => 'Paket-Berater benutzt',
            'cta-hero' => 'Startseite: Anfrage-Button oben', 'cta-nav' => 'Menü: Anfrage-Button', 'lp-cta' => 'Landingpage: Anfrage-Button', 'lp-sticky' => 'Landingpage: Handy-Leiste',
            'example' => 'Beispielseite angesehen', 'example-cta' => 'Beispiel → „So etwas für meinen Betrieb“', 'ref' => 'Referenz-Webseite besucht',
            'mail' => 'E-Mail-Adresse geklickt', 'tel' => 'Telefonnummer geklickt',
            'form-start' => 'Anfrage begonnen', 'form-step2' => 'Anfrage: Schritt 2', 'form-step3' => 'Anfrage: Schritt 3'];
}

function lx_stats_is_bot(string $ua): bool {
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|preview|headless|lighthouse|pagespeed|curl|wget|python|java/|go-http|monitor|uptime|facebookexternalhit|whatsapp|telegram|discord|semrush|ahrefs|playwright~i', $ua);
}
function lx_stats_device(string $ua): string {
    if (preg_match('~iPad|Tablet|PlayBook|Silk|Kindle|(Android(?!.*Mobile))~i', $ua)) return 't';
    if (preg_match('~Mobi|iPhone|iPod|Android|Windows Phone~i', $ua)) return 'm';
    return 'd';
}
function lx_stats_os(string $ua): string {
    if (preg_match('~iPhone|iPad|iPod~i', $ua)) return 'iOS';
    if (stripos($ua, 'Android') !== false) return 'Android';
    if (stripos($ua, 'Windows') !== false) return 'Windows';
    if (stripos($ua, 'Mac OS X') !== false || stripos($ua, 'Macintosh') !== false) return 'macOS';
    if (stripos($ua, 'CrOS') !== false) return 'ChromeOS';
    if (stripos($ua, 'Linux') !== false) return 'Linux';
    return 'Andere';
}
function lx_stats_browser(string $ua): string {
    if (stripos($ua, 'Instagram') !== false) return 'Instagram-App';
    if (preg_match('~FBAN|FBAV|FB_IAB~', $ua)) return 'Facebook-App';
    if (stripos($ua, 'LinkedInApp') !== false) return 'LinkedIn-App';
    if (stripos($ua, 'TikTok') !== false || stripos($ua, 'musical_ly') !== false || stripos($ua, 'BytedanceWebview') !== false) return 'TikTok-App';
    if (stripos($ua, 'SamsungBrowser') !== false) return 'Samsung Internet';
    if (stripos($ua, 'Edg/') !== false) return 'Edge';
    if (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) return 'Opera';
    if (stripos($ua, 'Firefox') !== false || stripos($ua, 'FxiOS') !== false) return 'Firefox';
    if (stripos($ua, 'CriOS') !== false || stripos($ua, 'Chrome') !== false) return 'Chrome';
    if (stripos($ua, 'Safari') !== false) return 'Safari';
    return 'Andere';
}

/** Herkunft: Suchmaschine, soziales Netzwerk (auch In-App-Browser ohne Referrer), andere Seite oder direkt */
function lx_stats_source(string $ua = ''): string {
    $utm = trim((string)($_GET['utm_source'] ?? ''));
    if ($utm !== '') return mb_strtolower(mb_substr((string)preg_replace('/[^\w.\- ]/u', '', $utm), 0, 40));
    $ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string)parse_url($ref, PHP_URL_HOST));
    if ($host === '') {
        if (stripos($ua, 'Instagram') !== false) return 'Instagram';
        if (preg_match('~FBAN|FBAV~', $ua)) return 'Facebook';
        if (stripos($ua, 'LinkedInApp') !== false) return 'LinkedIn';
        if (stripos($ua, 'TikTok') !== false || stripos($ua, 'musical_ly') !== false) return 'TikTok';
        return 'direkt';
    }
    $own = (string)preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
    $own = (string)preg_replace('/^www\./', '', $own);
    if ($host === $own || ($own !== '' && str_ends_with($host, '.' . $own))) return 'intern';
    $map = ['maps.google' => 'Google Maps', 'google.' => 'Google', 'bing.' => 'Bing', 'duckduckgo.' => 'DuckDuckGo', 'ecosia.' => 'Ecosia', 'yahoo.' => 'Yahoo',
            'instagram.' => 'Instagram', 'facebook.' => 'Facebook', 'fb.' => 'Facebook', 'linkedin.' => 'LinkedIn', 'lnkd.' => 'LinkedIn',
            'tiktok.' => 'TikTok', 'youtube.' => 'YouTube', 'pinterest.' => 'Pinterest', 'whatsapp.' => 'WhatsApp', 'linktr.ee' => 'Linktree',
            't.co' => 'X / Twitter', 'twitter.' => 'X / Twitter', 'x.com' => 'X / Twitter', 'github.' => 'GitHub', 'reserved' => 'Reserved.'];
    foreach ($map as $needle => $name) if (strpos($host, $needle) !== false) return $name;
    return (string)preg_replace('/^www\./', '', $host);
}
function lx_stats_skip(string $ua): bool {
    return lx_stats_is_bot($ua)
        || ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1'
        || strtolower((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '')) === 'prefetch';
}

function lx_stats_empty_day(): array {
    return ['v' => 0, 'u' => 0, 'a' => 0, 'p' => [], 'r' => [], 'g' => ['m' => 0, 't' => 0, 'd' => 0], 'os' => [], 'br' => [],
            'h' => array_fill(0, 24, 0), 'cp' => [], 's' => [], 'c' => [], 't' => 0, 'e' => 0, 'ev' => 0];
}
/** Mit Dateisperre lesen, ändern und schreiben (älterer Name, wird noch benutzt) */
function lx_stats_locked(callable $fn) { return lx_locked('stats', $fn); }
function lx_stats_update(callable $fn): void {
    $day = date('Y-m-d');
    lx_locked('stats', function () use ($fn, $day) {
        $file = 'stats-' . substr($day, 0, 7);
        $m = lx_read($file, []);
        $d = array_merge(lx_stats_empty_day(), $m[$day] ?? []);
        $fn($d);
        $m[$day] = $d;
        lx_write($file, $m);
    });
}

/** Seitenaufruf zählen (aus gate.php) */
function lx_stats_track(string $page): void {
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if (lx_stats_skip($ua)) return;
        $page = strtolower($page);
        if (!isset(lx_stats_pages()[$page])) $page = 'andere';
        $day = date('Y-m-d');
        $source = lx_stats_source($ua);
        $camp = mb_substr((string)preg_replace('/[^\w.\- ]/u', '', (string)($_GET['utm_campaign'] ?? '')), 0, 40);
        lx_stats_update(function (array &$d) use ($page, $day, $source, $ua, $camp) {
            // Tages-Hash für "Besucher": Zufallswert gilt nur heute und wird danach verworfen
            $today = lx_read('stats-heute', []);
            if (($today['day'] ?? '') !== $day) $today = ['day' => $day, 'salt' => bin2hex(random_bytes(16)), 'seen' => []];
            $hash = substr(hash('sha256', $today['salt'] . '|' . ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $ua), 0, 16);
            $isNew = !isset($today['seen'][$hash]);
            if ($isNew) { $today['seen'][$hash] = 1; lx_write('stats-heute', $today); }
            $d['v']++;
            if ($page === 'danke.html') $d['a']++; // alte Formular-Variante (ohne Server)
            $d['p'][$page] = ($d['p'][$page] ?? 0) + 1;
            $d['h'][(int)date('G')]++;
            if ($isNew) {
                $d['u']++;
                $dev = lx_stats_device($ua); $d['g'][$dev] = ($d['g'][$dev] ?? 0) + 1;
                $os = lx_stats_os($ua); $d['os'][$os] = ($d['os'][$os] ?? 0) + 1;
                $br = lx_stats_browser($ua); $d['br'][$br] = ($d['br'][$br] ?? 0) + 1;
                if ($source !== 'intern') $d['r'][$source] = ($d['r'][$source] ?? 0) + 1;
                if ($camp !== '') $d['cp'][$camp] = ($d['cp'][$camp] ?? 0) + 1;
                if (count($d['r']) > 60) { arsort($d['r']); $d['r'] = array_slice($d['r'], 0, 50, true); }
            }
        });
        if (random_int(1, 200) === 1) lx_stats_cleanup();
    } catch (Throwable $e) {
        // Statistik darf die Seite nie stören
    }
}

/** Signal aus dem Browser: gesehene Abschnitte, Klicks, Verweildauer */
function lx_stats_event(array $in): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (lx_stats_skip($ua) || lx_is_admin()) return;
    $known = lx_stats_sections() + lx_stats_sections_lp();
    $secs = array_values(array_intersect(array_map('strval', (array)($in['s'] ?? [])), array_keys($known)));
    $clicks = array_values(array_intersect(array_map('strval', (array)($in['c'] ?? [])), array_keys(lx_stats_clicks())));
    $dur = max(0, min(1800, (int)($in['d'] ?? 0)));
    $engaged = !empty($in['e']);
    if (!$secs && !$clicks && !$dur && !$engaged) return;
    lx_stats_update(function (array &$d) use ($secs, $clicks, $dur, $engaged) {
        foreach (array_slice($secs, 0, 16) as $s) $d['s'][$s] = ($d['s'][$s] ?? 0) + 1;
        foreach (array_slice($clicks, 0, 20) as $c) $d['c'][$c] = ($d['c'][$c] ?? 0) + 1;
        $d['t'] += $dur;
        if ($engaged) $d['e']++;
        $d['ev']++;
    });
}

/** Anfrage zählen (aus leads.php) */
function lx_stats_lead(): void {
    try { lx_stats_update(function (array &$d) { $d['a']++; }); } catch (Throwable $e) {}
}

function lx_stats_cleanup(): void {
    $limit = date('Y-m', strtotime('-' . LX_STATS_KEEP_MONTHS . ' months'));
    foreach (glob(LX_DATA . '/stats-????-??.php') ?: [] as $f) {
        if (substr(basename($f, '.php'), 6) < $limit) @unlink($f);
    }
}

function lx_stats_reset(): void {
    lx_locked('stats', function () {
        foreach (glob(LX_DATA . '/stats-*.php') ?: [] as $f) @unlink($f);
    });
}

/** Auswertung für die letzten $days Tage + Vergleichszeitraum davor */
function lx_stats_summary(int $days): array {
    $out = lx_stats_empty_day();
    $out += ['days' => [], 'wd' => array_fill(0, 7, 0), 'prev' => ['u' => 0, 'v' => 0, 'a' => 0]];
    $cache = [];
    $get = function (string $day) use (&$cache) {
        $f = 'stats-' . substr($day, 0, 7);
        if (!isset($cache[$f])) $cache[$f] = lx_read($f, []);
        return $cache[$f][$day] ?? null;
    };
    $sum = function (array &$into, array $from): void { foreach ($from as $k => $n) $into[$k] = ($into[$k] ?? 0) + (int)$n; };
    for ($i = $days - 1; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-$i days"));
        $d = $get($day);
        $out['days'][] = ['day' => $day, 'u' => (int)($d['u'] ?? 0), 'v' => (int)($d['v'] ?? 0), 'a' => (int)($d['a'] ?? 0)];
        if (!$d) continue;
        foreach (['v', 'u', 'a', 't', 'e', 'ev'] as $k) $out[$k] += (int)($d[$k] ?? 0);
        foreach (['p', 'r', 'g', 'os', 'br', 'cp', 's', 'c'] as $k) $sum($out[$k], (array)($d[$k] ?? []));
        foreach ((array)($d['h'] ?? []) as $h => $n) $out['h'][(int)$h] += (int)$n;
        $out['wd'][(int)date('N', strtotime($day)) - 1] += (int)($d['v'] ?? 0);
    }
    for ($i = 2 * $days - 1; $i >= $days; $i--) {
        $d = $get(date('Y-m-d', strtotime("-$i days")));
        if ($d) foreach (['u', 'v', 'a'] as $k) $out['prev'][$k] += (int)($d[$k] ?? 0);
    }
    foreach (['p', 'r', 'os', 'br', 'cp', 'c'] as $k) arsort($out[$k]);
    return $out;
}
