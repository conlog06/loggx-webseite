<?php
/* ==========================================================================
   LoggX – Stabilität & Wartung
   - Protokolle (Fehler, E-Mails, kaputte Links) – im Admin unter Einstellungen
   - Tägliche Sicherung aller Daten (14 Tage) + Wiederherstellen
   - Wöchentlicher Bericht per E-Mail (montags)
   Läuft nebenbei bei Seitenaufrufen (kein Cronjob nötig).
   ========================================================================== */
declare(strict_types=1);

const LX_BACKUP_DIR = LX_DATA . '/sicherung';
const LX_BACKUP_KEEP = 14;

/* ---------- Protokolle ---------- */
/** Eintrag in ein Protokoll (fehler | mail | 404). Gleiche Einträge hintereinander werden gezählt statt doppelt gespeichert. */
function lx_log(string $ch, string $text, array $extra = [], int $keep = 150): void {
    static $busy = false;
    if ($busy || !preg_match('/^[a-z0-9]+$/', $ch)) return;
    $busy = true;
    try {
        $text = lx_cut(str_replace(["\r", "\n"], ' ', $text), 400);
        lx_locked('log-' . $ch, function () use ($ch, $text, $extra, $keep) {
            $l = lx_read('log-' . $ch, []);
            $last = end($l);
            if ($last && $last['t'] === $text && ($last['x'] ?? []) == $extra && (int)$last['ts'] > time() - 86400) {
                $l[key($l)]['n'] = (int)($last['n'] ?? 1) + 1; $l[key($l)]['ts'] = time();
            } else $l[] = ['ts' => time(), 't' => $text, 'x' => $extra, 'n' => 1];
            lx_write('log-' . $ch, array_slice(array_values($l), -$keep), false);
        });
    } catch (Throwable $e) {
    } finally { $busy = false; }
}
function lx_log_read(string $ch): array { return array_reverse(lx_read('log-' . $ch, [])); }
function lx_log_clear(string $ch): void { lx_write('log-' . $ch, [], false); }

/** Kaputte Links merken (Adresse → Anzahl), damit du sie im Admin siehst */
function lx_log_404(string $path): void {
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($path === '' || strlen($path) > 200 || preg_match('~\.(php|env|git|sql|bak|asp|cgi)|wp-|xmlrpc|/\.~i', $path)) return; // Angriffs-Scans nicht protokollieren
    try {
        lx_locked('log-404', function () use ($path, $ua) {
            $l = lx_read('log-404', []);
            $k = strtolower($path);
            $ref = (string)parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST);
            $l[$k] = ['p' => $path, 'n' => (int)($l[$k]['n'] ?? 0) + 1, 'ts' => time(), 'ref' => $ref ?: ($l[$k]['ref'] ?? ''), 'bot' => lx_stats_is_bot($ua)];
            if (count($l) > 120) { uasort($l, fn($a, $b) => $b['ts'] <=> $a['ts']); $l = array_slice($l, 0, 100, true); }
            lx_write('log-404', $l, false);
        });
    } catch (Throwable $e) {}
}

/** PHP-Fehler mitschreiben (zusätzlich zum normalen Fehlerprotokoll des Servers) */
function lx_error_handlers(): void {
    set_error_handler(function (int $no, string $msg, string $file, int $line) {
        if (!(error_reporting() & $no)) return false;                   // mit @ unterdrückt
        if (in_array($no, [E_WARNING, E_USER_WARNING, E_NOTICE, E_USER_NOTICE], true))
            lx_log('fehler', ($no === E_WARNING || $no === E_USER_WARNING ? 'Warnung: ' : 'Hinweis: ') . $msg, ['f' => basename($file) . ':' . $line, 'u' => lx_cut((string)($_SERVER['REQUEST_URI'] ?? ''), 120)]);
        return false;                                                    // normale Behandlung fortsetzen
    }, E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true))
            lx_log('fehler', 'Absturz: ' . preg_replace('~\s+Stack trace:.*$~s', '', (string)$e['message']), ['f' => basename((string)$e['file']) . ':' . $e['line'], 'u' => lx_cut((string)($_SERVER['REQUEST_URI'] ?? ''), 120), 'fatal' => 1]);
    });
}

/* ---------- Sicherungen ---------- */
/** Welche Dateien gesichert werden: alles in data/, außer Statistik, Protokolle, Sperren und Zwischenstände */
function lx_backup_files(): array {
    $out = [];
    foreach ((array)glob(LX_DATA . '/*.php') as $f) {
        $n = basename((string)$f, '.php');
        if (preg_match('/^(stats-|log-|throttle|einrichtungscode)|\.bak$/', $n)) continue;
        $out[$n] = (string)$f;
    }
    return $out;
}
/** Sicherung anlegen. $why: taeglich | manuell | vor-wiederherstellung. Gibt den Dateinamen zurück. */
function lx_backup_create(string $why = 'taeglich'): string {
    if (!is_dir(LX_BACKUP_DIR)) @mkdir(LX_BACKUP_DIR, 0750, true);
    if (!is_file(LX_BACKUP_DIR . '/.htaccess')) @file_put_contents(LX_BACKUP_DIR . '/.htaccess', "Require all denied\n");
    $files = [];
    foreach (lx_backup_files() as $n => $f) $files[$n] = (string)file_get_contents($f);
    $meta = ['ts' => time(), 'why' => $why, 'n' => ['leads' => count(lx_read('leads', [])), 'bookings' => count(lx_read('bookings', [])), 'signups' => count(lx_read('signups', []))]];
    $name = date('Y-m-d_His') . '-' . $why . '.php';
    $raw = json_encode(['meta' => $meta, 'files' => $files], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('gzencode')) { $raw = 'gz:' . base64_encode((string)gzencode((string)$raw, 6)); }
    file_put_contents(LX_BACKUP_DIR . '/' . $name, LX_GUARD . $raw, LOCK_EX);
    lx_backup_prune();
    return $name;
}
function lx_backup_load(string $name): ?array {
    if (!preg_match('/^[0-9_-]+-[a-z-]+\.php$/', $name) || !is_file(LX_BACKUP_DIR . '/' . $name)) return null;
    $raw = (string)file_get_contents(LX_BACKUP_DIR . '/' . $name);
    if (strpos($raw, LX_GUARD) === 0) $raw = substr($raw, strlen(LX_GUARD));
    if (str_starts_with($raw, 'gz:')) $raw = (string)@gzdecode((string)base64_decode(substr($raw, 3)));
    $d = json_decode($raw, true);
    return is_array($d) && isset($d['files']) ? $d : null;
}
/** Liste der Sicherungen, neueste zuerst */
function lx_backups(): array {
    $out = [];
    foreach ((array)glob(LX_BACKUP_DIR . '/*.php') as $f) {
        $n = basename((string)$f);
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})_(\d{2})(\d{2})(\d{2})-([a-z-]+)\.php$/', $n, $m)) continue;
        $out[] = ['name' => $n, 'ts' => (int)strtotime("{$m[1]} {$m[2]}:{$m[3]}:{$m[4]}"), 'why' => $m[5], 'size' => (int)filesize((string)$f)];
    }
    usort($out, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $out;
}
/** Alte Sicherungen löschen: tägliche nur 14 Tage, manuelle/Wiederherstellungs-Stände die letzten 10 */
function lx_backup_prune(): void {
    $daily = 0; $other = 0;
    foreach (lx_backups() as $b) {
        $keep = $b['why'] === 'taeglich' ? ++$daily <= LX_BACKUP_KEEP : ++$other <= 10;
        if (!$keep) @unlink(LX_BACKUP_DIR . '/' . $b['name']);
    }
}
/** Stand zurückholen – vorher wird der aktuelle Stand automatisch gesichert */
function lx_backup_restore(string $name): bool {
    $d = lx_backup_load($name);
    if (!$d) return false;
    lx_backup_create('vor-wiederherstellung');
    foreach ($d['files'] as $n => $content) {
        if (!preg_match('/^[a-z0-9_.-]+$/i', (string)$n) || !str_starts_with((string)$content, LX_GUARD)) continue;
        $file = LX_DATA . '/' . $n . '.php'; $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, (string)$content, LOCK_EX); rename($tmp, $file);
    }
    return true;
}

/* ---------- Wochenbericht ---------- */
function lx_weekly_report(bool $force = false): bool {
    $c = lx_config();
    if (!$force && empty($c['site']['weekly_on'])) return false;
    $from = strtotime('monday last week'); $to = strtotime('monday this week');
    $leads = lx_leads();
    $new = array_filter($leads, fn($x) => (int)$x['created'] >= $from && (int)$x['created'] < $to);
    $open = array_filter($leads, fn($x) => $x['status'] === 'neu');
    $due = array_filter($leads, fn($x) => ($x['follow'] ?? '') !== '' && $x['follow'] <= date('Y-m-d') && !in_array($x['status'], ['gewonnen', 'verloren'], true));
    $bk = array_filter(lx_bookings(), fn($b) => $b['status'] === 'bestaetigt' && (int)$b['start'] >= time() && (int)$b['start'] < strtotime('+7 days'));
    $st = lx_stats_summary(7);
    $err = count(array_filter(lx_read('log-fehler', []), fn($x) => (int)$x['ts'] >= $from));
    $t = "Hallo Constantin,\n\nso lief loggx.de letzte Woche (" . date('d.m.', $from) . '–' . date('d.m.', $to - 1) . "):\n\n"
       . 'Besucher:        ' . (int)$st['u'] . "\n" . 'Seitenaufrufe:   ' . (int)$st['v'] . "\n" . 'Neue Anfragen:   ' . count($new) . "\n";
    foreach ($new as $x) $t .= '  · ' . ($x['data']['firma'] ?: $x['data']['name']) . ' – ' . $x['rec'] . "\n";
    $t .= "\nNoch unbeantwortet: " . count($open) . "\nWiedervorlagen fällig: " . count($due) . "\n";
    foreach ($due as $x) $t .= '  · ' . ($x['data']['firma'] ?: $x['data']['name']) . "\n";
    $t .= "\nTermine diese Woche: " . count($bk) . "\n";
    foreach ($bk as $b) $t .= '  · ' . lx_bk_day((int)$b['start'], false) . ', ' . date('H:i', (int)$b['start']) . ' – ' . $b['name'] . ' (' . $b['type_name'] . ")\n";
    if ($err) $t .= "\n⚠ $err technische Fehlermeldung(en) – siehe Admin → Einstellungen → Protokolle.\n";
    $t .= "\nZum Admin: " . lx_abs_url('admin/') . "\n\nLoggX Webseite\n";
    return lx_mail_admin('Wochenbericht loggx.de: ' . count($new) . ' neue Anfrage' . (count($new) === 1 ? '' : 'n') . ', ' . count($bk) . ' Termin' . (count($bk) === 1 ? '' : 'e'), $t);
}

/* ---------- Nebenbei-Aufgaben (bei Seitenaufrufen, höchstens alle 10 Minuten) ---------- */
function lx_maintenance_tick(): void {
    $f = LX_DATA . '/wartung.lock';
    if (is_file($f) && filemtime($f) > time() - 600) return;
    @touch($f);
    try { lx_booking_tick(); } catch (Throwable $e) { lx_log('fehler', 'Termine: ' . $e->getMessage()); }
    try {
        $last = lx_backups()[0] ?? null;
        if (!$last || date('Y-m-d', $last['ts']) !== date('Y-m-d')) lx_backup_create('taeglich');
    } catch (Throwable $e) { lx_log('fehler', 'Sicherung: ' . $e->getMessage()); }
    try {
        $c = lx_config();
        $week = date('o-W');
        if (!empty($c['site']['weekly_on']) && (int)date('N') === 1 && (int)date('G') >= 7 && ($c['site']['weekly_last'] ?? '') !== $week) {
            lx_locked('config', function () use ($week) { $c = lx_config(); $c['site']['weekly_last'] = $week; lx_save_config($c); });
            lx_weekly_report();
        }
    } catch (Throwable $e) { lx_log('fehler', 'Wochenbericht: ' . $e->getMessage()); }
    try { lx_leads_cleanup(); } catch (Throwable $e) {}
}
