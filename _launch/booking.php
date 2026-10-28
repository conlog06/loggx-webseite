<?php
/* ==========================================================================
   LoggX – Terminvorschläge
   1. Du schlägst im Admin bis zu drei Zeiten vor (Art, Dauer, Ort)
   2. Die Person bekommt eine E-Mail mit Link zu /termin?t=…
      (bei nur einer Zeit hängt die Kalender-Datei gleich mit dran)
   3. Dort wählt sie eine Zeit („Passt mir“) oder schreibt, was besser passt
   4. Du bekommst eine E-Mail (mit Kalendereintrag) und siehst alles im Admin
   5. Einen Tag vorher geht automatisch eine Erinnerung raus
   Kein externer Dienst, keine Kosten, keine Cookies.
   ========================================================================== */
declare(strict_types=1);

const LX_BK_STATUS = ['offen' => 'Wartet auf Antwort', 'bestaetigt' => 'Bestätigt', 'abgelehnt' => 'Passt nicht', 'abgesagt' => 'Abgesagt'];
const LX_BK_SIGN = "Viele Grüße\nConstantin Moritz Loggen\nLoggX – Webseiten für Unternehmen";

function lx_bk_defaults(): array {
    return [
        'types' => [
            ['id' => 'erstgespraech', 'name' => 'Kostenloses Erstgespräch', 'min' => 30, 'where' => 'Telefon'],
            ['id' => 'video', 'name' => 'Erstgespräch per Video', 'min' => 30, 'where' => 'Zoom oder Teams – den Link bekommen Sie vorher per E-Mail'],
            ['id' => 'entwurf', 'name' => 'Entwurf besprechen', 'min' => 45, 'where' => 'Zoom oder Teams'],
            ['id' => 'abnahme', 'name' => 'Abnahme & Livegang', 'min' => 30, 'where' => 'Telefon'],
        ],
        // Arbeitszeiten je Wochentag (0 = Sonntag … 6 = Samstag), leer = frei
        'hours' => ['', '09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-16:00', ''],
        'buffer' => 15,          // Minuten Abstand zwischen zwei Terminen
        'lead_h' => 12,          // frühestens so viele Stunden ab jetzt vorschlagen
        'mail_subject' => 'Terminvorschlag: {terminart}',
        'mail_text' => "Guten Tag {name},\n\nvielen Dank für Ihr Interesse! Gern würde ich mit Ihnen sprechen. Hier mein Vorschlag:\n\n{terminart} ({dauer} Minuten)\n{zeiten}\nOrt: {ort}\n\n{nachricht}\n\nBitte sagen Sie mir mit einem Klick, ob es passt – oder was Ihnen besser passt:\n{link}\n\n" . LX_BK_SIGN,
        'notify' => true,        // Mail an dich, wenn jemand antwortet
        'remind' => true,        // Erinnerung an die Person 24 Std. vorher
        'feed_key' => '',        // geheimer Schlüssel für das Kalender-Abo
        'tick' => 0,
    ];
}

/* ---------- Speicher ---------- */
function lx_bookings(): array { return lx_read('bookings', []); }
function lx_bookings_save(array $b): void {
    usort($b, fn($x, $y) => (int)$x['start'] <=> (int)$y['start']);
    lx_write('bookings', array_values($b));
}
function lx_bk_cfg(): array { return array_merge(lx_bk_defaults(), (array)(lx_config()['booking'] ?? [])); }
function lx_booking_types(?array $B = null): array {
    $B = $B ?? lx_bk_cfg();
    return array_values(array_filter((array)$B['types'], fn($t) => trim((string)($t['name'] ?? '')) !== '' && (int)($t['min'] ?? 0) > 0));
}
function lx_booking_type(string $id, ?array $B = null): ?array {
    foreach (lx_booking_types($B) as $t) if ($t['id'] === $id) return $t;
    return null;
}
function lx_booking_find(string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
    foreach (lx_bookings() as $b) if (hash_equals($b['token'], $token)) return $b;
    return null;
}
function lx_booking_get(string $id): ?array {
    foreach (lx_bookings() as $b) if ($b['id'] === $id) return $b;
    return null;
}
function lx_booking_update(string $id, callable $fn): ?array {
    return lx_locked('bookings', function () use ($id, $fn) {
        $all = lx_bookings(); $hit = null;
        foreach ($all as &$b) if ($b['id'] === $id) { $fn($b); $hit = $b; }
        unset($b);
        if ($hit) lx_bookings_save($all);
        return $hit;
    });
}
function lx_booking_delete(string $id): void {
    lx_locked('bookings', function () use ($id) { lx_bookings_save(array_filter(lx_bookings(), fn($b) => $b['id'] !== $id)); });
}

/* ---------- Hilfen ---------- */
function lx_bk_min(array $b): int { return max(5, (int)round(((int)$b['end'] - (int)$b['start']) / 60)); }
/** Alle vorgeschlagenen Zeiten (bei bestätigten Terminen nur die gewählte) */
function lx_bk_opts(array $b): array {
    if ($b['status'] !== 'offen') return [(int)$b['start']];
    $o = array_map('intval', (array)($b['opts'] ?? []));
    return $o ?: [(int)$b['start']];
}
function lx_bk_day(int $ts, bool $year = true): string {
    $tage = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
    $monate = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    return $tage[(int)date('w', $ts)] . ', ' . date('j', $ts) . '. ' . $monate[(int)date('n', $ts)] . ($year ? ' ' . date('Y', $ts) : '');
}
/** "Dienstag, 4. November 2026, 10:00–10:30 Uhr" */
function lx_bk_when(int $start, int $min): string {
    return lx_bk_day($start) . ', ' . date('H:i', $start) . '–' . date('H:i', $start + $min * 60) . ' Uhr';
}
function lx_booking_when(array $b): string { return lx_bk_when((int)$b['start'], lx_bk_min($b)); }
function lx_booking_link(array $b): string { return lx_abs_url('termin?t=' . $b['token']); }
function lx_bk_first(array $b): string { $n = trim((string)$b['name']); return $n !== '' ? $n : 'Kunde'; }
/** Ist der Vorschlag abgelaufen (alle Zeiten vorbei)? */
function lx_bk_expired(array $b): bool { return max(lx_bk_opts($b)) + lx_bk_min($b) * 60 < time(); }

/** Platzhalter in Betreff/Text ersetzen */
function lx_booking_fill(string $tpl, array $b): string {
    $min = lx_bk_min($b); $opts = lx_bk_opts($b);
    $zeiten = count($opts) > 1
        ? implode("\n", array_map(fn($i, $t) => 'Möglichkeit ' . ($i + 1) . ': ' . lx_bk_when($t, $min), array_keys($opts), $opts))
        : lx_bk_when($opts[0], $min);
    $map = ['{name}' => lx_bk_first($b), '{terminart}' => $b['type_name'], '{zeiten}' => $zeiten,
            '{datum}' => lx_bk_day($opts[0]), '{uhrzeit}' => date('H:i', $opts[0]), '{bis}' => date('H:i', $opts[0] + $min * 60),
            '{dauer}' => (string)$min, '{ort}' => (string)($b['where'] !== '' ? $b['where'] : 'nach Absprache'), '{link}' => lx_booking_link($b), '{nachricht}' => (string)($b['note'] ?? '')];
    $out = strtr($tpl, $map);
    $out = (string)preg_replace("/\n{3,}/", "\n\n", $out);
    return trim($out) . "\n";
}

/* ---------- Kalender-Datei (.ics) – iPhone, Android, Outlook, Google ---------- */
function lx_ics_esc(string $s): string { return str_replace(["\\", ';', ',', "\r", "\n"], ['\\\\', '\;', '\,', '', '\n'], $s); }
function lx_ics_wrap(array $events, string $method = 'PUBLISH', string $name = ''): string {
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//LoggX//Termine//DE', 'CALSCALE:GREGORIAN', 'METHOD:' . $method];
    if ($name !== '') array_push($lines, 'X-WR-CALNAME:' . lx_ics_esc($name), 'X-WR-TIMEZONE:Europe/Berlin', 'REFRESH-INTERVAL;VALUE=DURATION:PT30M', 'X-PUBLISHED-TTL:PT30M');
    foreach ($events as $ev) $lines = array_merge($lines, $ev);
    $lines[] = 'END:VCALENDAR';
    $fold = function (string $l): string {
        $out = ''; $cur = '';
        foreach (preg_split('//u', $l, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (strlen($cur . $ch) > 73) { $out .= $cur . "\r\n "; $cur = ''; }
            $cur .= $ch;
        }
        return $out . $cur;
    };
    return implode("\r\n", array_map($fold, $lines)) . "\r\n";
}
/** Ein Termin als VEVENT. $owner = Ansicht für dich (Name des Kunden im Titel). */
function lx_ics_event(array $b, bool $owner, bool $cancel = false, ?int $start = null, string $uidSuffix = '', bool $tentative = false): array {
    $utc = fn($t) => gmdate('Ymd\THis\Z', (int)$t);
    $start = $start ?? (int)$b['start']; $end = $start + lx_bk_min($b) * 60;
    $title = $owner ? ($tentative ? '(Vorschlag) ' : '') . $b['type_name'] . ' – ' . $b['name'] : $b['type_name'] . ' mit Constantin (LoggX)';
    $desc = $owner ? $b['name'] . ' · ' . $b['email'] . (($b['phone'] ?? '') !== '' ? ' · ' . $b['phone'] : '') . "\n" . lx_abs_url('admin/?tab=termine&id=' . $b['id'])
                   : 'Termin ansehen, verschieben oder absagen: ' . lx_booking_link($b);
    $host = preg_replace('/^www\./', '', (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'loggx.de')));
    $ev = ['BEGIN:VEVENT', 'UID:' . $b['id'] . $uidSuffix . '@' . $host, 'SEQUENCE:' . (int)($b['seq'] ?? 0), 'DTSTAMP:' . $utc(time()),
        'DTSTART:' . $utc($start), 'DTEND:' . $utc($end), 'SUMMARY:' . lx_ics_esc($title), 'DESCRIPTION:' . lx_ics_esc($desc),
        'STATUS:' . ($cancel ? 'CANCELLED' : ($tentative ? 'TENTATIVE' : 'CONFIRMED'))];
    if (($b['where'] ?? '') !== '') $ev[] = 'LOCATION:' . lx_ics_esc((string)$b['where']);
    if (preg_match('~https?://\S+~', (string)($b['where'] ?? ''), $m)) $ev[] = 'URL:' . $m[0];
    if (!$cancel && !$tentative) $ev = array_merge($ev, ['BEGIN:VALARM', 'TRIGGER:-PT' . ($owner ? '15M' : '1H'), 'ACTION:DISPLAY', 'DESCRIPTION:' . lx_ics_esc($title), 'END:VALARM']);
    $ev[] = 'END:VEVENT';
    return $ev;
}
function lx_booking_ics(array $b, bool $owner = false): string {
    $cancel = $b['status'] === 'abgesagt' || $b['status'] === 'abgelehnt';
    return lx_ics_wrap([lx_ics_event($b, $owner, $cancel, null, '', $b['status'] === 'offen')], $cancel ? 'CANCEL' : 'PUBLISH');
}
function lx_booking_att(array $b, bool $owner = false): array {
    $cancel = $b['status'] === 'abgesagt' || $b['status'] === 'abgelehnt';
    return [['name' => $cancel ? 'termin-abgesagt.ics' : 'termin.ics', 'type' => 'text/calendar; charset=utf-8; method=' . ($cancel ? 'CANCEL' : 'PUBLISH'), 'data' => lx_booking_ics($b, $owner)]];
}
/** Kalender-Abo für dich: alle kommenden (und kürzlich abgesagten) Termine */
function lx_booking_feed(): string {
    $ev = [];
    foreach (lx_bookings() as $b) {
        if ((int)$b['end'] < strtotime('-60 days')) continue;
        if ($b['status'] === 'offen') { foreach (lx_bk_opts($b) as $i => $t) $ev[] = lx_ics_event($b, true, false, $t, $i ? '-' . $i : '', true); continue; }
        $ev[] = lx_ics_event($b, true, $b['status'] !== 'bestaetigt');
    }
    return lx_ics_wrap($ev, 'PUBLISH', 'LoggX Termine');
}
function lx_booking_feed_key(bool $new = false): string {
    return lx_locked('config', function () use ($new) {
        $c = lx_config();
        $k = (string)($c['booking']['feed_key'] ?? '');
        if ($new || !preg_match('/^[a-f0-9]{40}$/', $k)) { $c['booking']['feed_key'] = $k = bin2hex(random_bytes(20)); lx_save_config($c); }
        return $k;
    });
}

/* ---------- Freie Zeiten finden ---------- */
/** Belegte Zeiträume [start, end] (offene Vorschläge blockieren alle ihre Zeiten) */
function lx_bk_busy(string $exceptId = ''): array {
    $busy = [];
    foreach (lx_bookings() as $b) {
        if ($b['id'] === $exceptId || !in_array($b['status'], ['offen', 'bestaetigt'], true)) continue;
        $len = lx_bk_min($b) * 60;
        foreach (lx_bk_opts($b) as $t) $busy[] = [$t, $t + $len, $b];
    }
    return $busy;
}
/** Überschneidung mit einem anderen Termin? Gibt den Termin zurück oder null. */
function lx_bk_clash(int $start, int $min, string $exceptId = '', bool $confirmedOnly = false): ?array {
    $buf = (int)lx_bk_cfg()['buffer'] * 60; $end = $start + $min * 60;
    foreach (lx_bk_busy($exceptId) as [$s, $e, $b]) {
        if ($confirmedOnly && $b['status'] !== 'bestaetigt') continue;
        if ($start < $e + $buf && $end + $buf > $s) return $b;
    }
    return null;
}
/** Arbeitszeit an einem Tag als [von, bis] (Zeitstempel) oder null */
function lx_bk_hours(int $day, ?array $B = null): ?array {
    $B = $B ?? lx_bk_cfg();
    $h = (string)(((array)$B['hours'])[(int)date('w', $day)] ?? '');
    if (!preg_match('/^(\d{2}):(\d{2})-(\d{2}):(\d{2})$/', $h, $m)) return null;
    $d = date('Y-m-d', $day);
    $from = lx_ts($d . 'T' . $m[1] . ':' . $m[2]); $to = lx_ts($d . 'T' . $m[3] . ':' . $m[4]);
    return $from && $to && $to > $from ? [$from, $to] : null;
}
/** Nächste freie Zeiten innerhalb der Arbeitszeit (höchstens 2 pro Tag, gut verteilt) */
function lx_booking_free(int $min, int $count = 8): array {
    $B = lx_bk_cfg(); $out = [];
    $earliest = time() + max(1, (int)$B['lead_h']) * 3600;
    $day = strtotime('today', $earliest);
    for ($i = 0; $i < 28 && count($out) < $count; $i++, $day = strtotime('+1 day', $day)) {
        $h = lx_bk_hours($day, $B);
        if (!$h) continue;
        $free = [];
        for ($t = $h[0]; $t + $min * 60 <= $h[1]; $t += 1800) if ($t >= $earliest && !lx_bk_clash($t, $min)) $free[] = $t;
        if (!$free) continue;
        $pick = [$free[0]];
        foreach ($free as $t) if ((int)date('G', $t) >= 13 && $t - $free[0] >= 3 * 3600) { $pick[] = $t; break; } // einmal vormittags, einmal nachmittags
        foreach ($pick as $t) if (count($out) < $count) $out[] = $t;
    }
    return $out;
}
/** Außerhalb der Arbeitszeit? (nur Hinweis, kein Verbot) */
function lx_bk_outside(int $start, int $min): bool {
    $h = lx_bk_hours($start);
    return !$h || $start < $h[0] || $start + $min * 60 > $h[1];
}

/* ---------- Mails ---------- */
function lx_booking_send(array $b): bool {
    $text = (string)($b['mail_text'] ?? '');
    if (!str_contains($text, '{link}') && !str_contains($text, lx_booking_link($b))) $text .= "\n\nBitte bestätigen Sie den Termin hier: {link}";
    if (count(lx_bk_opts($b)) > 1 && !str_contains($text, '{zeiten}')) $text = str_replace('{datum}', '{zeiten}', $text);
    $att = count(lx_bk_opts($b)) === 1 ? lx_booking_att($b) : [];
    return lx_mail($b['email'], trim(lx_booking_fill((string)($b['mail_subject'] ?: 'Terminvorschlag'), $b)), lx_booking_fill($text, $b),
        (string)lx_config()['site']['email'], 'Constantin Moritz Loggen', $att);
}
/** Bestätigung mit Kalendereintrag an die Person */
function lx_booking_confirm_mail(array $b): bool {
    $t = "Guten Tag " . lx_bk_first($b) . ",\n\nvielen Dank – der Termin steht:\n\n{$b['type_name']}\n" . lx_booking_when($b) . ($b['where'] !== '' ? "\nOrt: {$b['where']}" : '')
       . "\n\nDen Kalendereintrag finden Sie im Anhang. Falls doch etwas dazwischenkommt, sagen Sie hier kurz Bescheid:\n" . lx_booking_link($b) . "\n\n" . LX_BK_SIGN . "\n" . lx_abs_url('') . "\n";
    return lx_mail($b['email'], 'Bestätigt: ' . $b['type_name'] . ' am ' . date('d.m.', (int)$b['start']), $t, (string)lx_config()['site']['email'], 'Constantin Moritz Loggen', lx_booking_att($b));
}
/** Notiz bei der verknüpften Anfrage speichern (und neue Anfragen auf „Kontaktiert“ setzen) */
function lx_booking_lead_note(array $b, string $text, bool $contacted = false): void {
    if (($b['lead'] ?? '') === '') return;
    lx_locked('leads', function () use ($b, $text, $contacted) {
        $l = lx_leads(); $hit = false;
        foreach ($l as &$x) if ($x['id'] === $b['lead']) {
            $x['notes'][] = ['ts' => time(), 't' => $text, 'sys' => true]; $hit = true;
            if ($contacted && $x['status'] === 'neu') $x['status'] = 'kontakt';
        }
        unset($x);
        if ($hit) lx_save_leads($l);
    });
}

/* ---------- Anlegen, Antworten, Absagen, Verschieben ---------- */
/** Zeiten aus dem Formular lesen: date[]/time[] */
function lx_bk_parse_opts(array $dates, array $times): array {
    $o = [];
    foreach ($dates as $i => $d) {
        $t = lx_ts(trim((string)$d) . 'T' . trim((string)($times[$i] ?? '')));
        if ($t && !in_array($t, $o, true)) $o[] = $t;
    }
    sort($o);
    return array_slice($o, 0, 3);
}
/**
 * Neuer Termin aus dem Admin. $in['mode']: vorschlag (Person bestätigt) | fest (gleich bestätigt + Mail) | still (nur eintragen)
 * Gibt [ok, Termin|Fehler, Überschneidung|null] zurück.
 */
function lx_booking_create(array $in): array {
    $B = lx_bk_cfg();
    $name = str_replace(["\r", "\n"], ' ', lx_cut(strip_tags((string)($in['name'] ?? '')), 80));
    $email = lx_cut((string)($in['email'] ?? ''), 200);
    $mode = in_array($in['mode'] ?? '', ['vorschlag', 'fest', 'still'], true) ? $in['mode'] : 'vorschlag';
    if ($name === '') return [false, 'Bitte einen Namen eintragen.', null];
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) && !($mode === 'still' && $email === '')) return [false, 'Bitte eine gültige E-Mail-Adresse eintragen.', null];
    $opts = lx_bk_parse_opts((array)($in['date'] ?? []), (array)($in['time'] ?? []));
    if (!$opts) return [false, 'Bitte Datum und Uhrzeit wählen.', null];
    if ($opts[0] < time() - 3600) return [false, 'Der Termin liegt in der Vergangenheit.', null];
    if ($mode !== 'vorschlag') $opts = [$opts[0]];
    $type = lx_booking_type((string)($in['art'] ?? ''), $B);
    $min = max(10, min(480, (int)($in['min'] ?? ($type['min'] ?? 30))));
    $tname = lx_cut((string)($in['type_name'] ?? ''), 60) ?: ($type['name'] ?? 'Termin');
    $b = ['id' => bin2hex(random_bytes(6)), 'token' => bin2hex(random_bytes(16)), 'type' => $type['id'] ?? 'individuell', 'type_name' => $tname,
          'where' => lx_cut((string)($in['where'] ?? ''), 200), 'opts' => $opts, 'start' => $opts[0], 'end' => $opts[0] + $min * 60,
          'name' => $name, 'email' => $email, 'phone' => lx_cut((string)($in['phone'] ?? ''), 40),
          'note' => lx_cut(str_replace("\r", '', (string)($in['note'] ?? '')), 1500), 'memo' => lx_cut(str_replace("\r", '', (string)($in['memo'] ?? '')), 1500),
          'lead' => (string)preg_replace('/[^a-f0-9]/', '', (string)($in['lead'] ?? '')),
          'mail_subject' => lx_cut((string)($in['mail_subject'] ?? $B['mail_subject']), 200), 'mail_text' => lx_cut(str_replace("\r", '', (string)($in['mail_text'] ?? $B['mail_text'])), 5000),
          'status' => $mode === 'vorschlag' ? 'offen' : 'bestaetigt', 'created' => time(), 'sent' => 0, 'seq' => 0, 'answer' => '', 'answered' => $mode === 'vorschlag' ? 0 : time(),
          'reminded' => 0, 'log' => [['ts' => time(), 't' => $mode === 'vorschlag' ? (count($opts) > 1 ? count($opts) . ' Zeiten vorgeschlagen' : 'Vorgeschlagen') : 'Fest eingetragen']]];
    $clash = null;
    foreach ($opts as $t) if (!$clash) $clash = lx_bk_clash($t, $min);
    lx_locked('bookings', function () use ($b) { $all = lx_bookings(); $all[] = $b; lx_bookings_save($all); });
    $sent = $mode === 'vorschlag' ? lx_booking_send($b) : ($mode === 'fest' && $email !== '' ? lx_booking_confirm_mail($b) : false);
    if ($sent) $b = lx_booking_update($b['id'], function (&$x) { $x['sent'] = time(); }) ?? $b;
    $when = count($opts) > 1 ? count($opts) . ' Zeiten ab ' . date('d.m. H:i', $opts[0]) : date('d.m.Y H:i', $opts[0]);
    lx_booking_lead_note($b, ($mode === 'vorschlag' ? 'Termin vorgeschlagen: ' : 'Termin eingetragen: ') . $tname . ', ' . $when . ' Uhr', true);
    return [true, $b, $clash];
}

/** Antwort der Person: ja (+ gewählte Zeit) = bestätigt, nein = passt nicht (mit Nachricht) */
function lx_booking_answer(string $token, bool $yes, int $opt = 0, string $msg = ''): array {
    $msg = lx_cut(strip_tags(str_replace("\r", '', $msg)), 1500);
    $b = lx_booking_find($token);
    if (!$b || $b['status'] === 'abgesagt' || lx_bk_expired($b)) return [null, 'Dieser Termin kann nicht mehr beantwortet werden.'];
    $opts = lx_bk_opts($b);
    if ($b['status'] === 'abgelehnt') $opts = array_map('intval', (array)($b['opts'] ?? [$b['start']])) ?: [(int)$b['start']];
    $pick = $opts[$opt] ?? $opts[0];
    if ($yes) {
        if ($pick < time()) return [null, 'Diese Zeit ist leider schon vorbei.'];
        if ($b['status'] !== 'bestaetigt' && lx_bk_clash($pick, lx_bk_min($b), $b['id'], true)) return [null, 'Diese Zeit ist inzwischen leider vergeben – bitte wählen Sie eine andere oder schreiben Sie mir, was Ihnen passt.'];
    }
    $wasConfirmed = $b['status'] === 'bestaetigt';
    $b = lx_booking_update($b['id'], function (&$x) use ($yes, $msg, $pick, $wasConfirmed) {
        $len = lx_bk_min($x) * 60;
        if ($yes) { $x['start'] = $pick; $x['end'] = $pick + $len; $x['status'] = 'bestaetigt'; if (!$wasConfirmed) $x['seq'] = (int)($x['seq'] ?? 0) + 1; }
        else { $x['status'] = 'abgelehnt'; $x['seq'] = (int)($x['seq'] ?? 0) + 1; }
        $x['answer'] = $msg; $x['answered'] = time();
        $x['log'][] = ['ts' => time(), 't' => $yes ? 'Bestätigt (' . date('d.m. H:i', $pick) . ')' : ($wasConfirmed ? 'Abgesagt von der Person' : 'Passt nicht') . ($msg !== '' ? ': „' . lx_cut($msg, 120) . '“' : '')];
    });
    if (!$b) return [null, 'Dieser Termin kann nicht mehr beantwortet werden.'];
    $c = lx_config();
    if ($yes && !$wasConfirmed && $b['email'] !== '') lx_booking_confirm_mail($b);
    if (!$yes && $wasConfirmed && $b['email'] !== '') lx_mail($b['email'], 'Abgesagt: ' . $b['type_name'] . ' am ' . date('d.m.', (int)$b['start']),
        "Guten Tag " . lx_bk_first($b) . ",\n\ndanke für Ihre Nachricht – ich habe den Termin am " . lx_booking_when($b) . " gestrichen und melde mich mit einem neuen Vorschlag.\n\nIm Anhang finden Sie die Absage für Ihren Kalender, damit der Eintrag verschwindet.\n\n" . LX_BK_SIGN . "\n",
        (string)lx_config()['site']['email'], 'Constantin Moritz Loggen', lx_booking_att($b));
    if (!empty($c['booking']['notify'] ?? true)) {
        $head = $yes ? '✓ Bestätigt: ' : ($wasConfirmed ? '✗ Abgesagt: ' : '✗ Passt nicht: ');
        $text = $b['name'] . ($yes ? " hat den Termin bestätigt:\n\n" : ($wasConfirmed ? " hat den Termin abgesagt:\n\n" : " kann zu " . (count(lx_bk_opts($b)) > 1 ? 'keiner der Zeiten' : 'diesem Termin') . ":\n\n"))
              . $b['type_name'] . "\n" . lx_booking_when($b) . "\n" . ($msg !== '' ? "\nNachricht:\n$msg\n" : '')
              . "\nIm Admin ansehen: " . lx_abs_url('admin/?tab=termine&id=' . $b['id']) . "\n" . ($yes ? "\nDer Kalendereintrag hängt an – einfach öffnen und speichern.\n" : '');
        lx_mail_admin($head . $b['type_name'] . ' – ' . $b['name'], $text, $b['email'], $b['name'], $yes ? lx_booking_att($b, true) : []);
    }
    lx_booking_lead_note($b, ($yes ? 'Termin bestätigt: ' . lx_booking_when($b) : 'Termin passt nicht') . ($msg !== '' ? ' – „' . lx_cut($msg, 200) . '“' : ''));
    return [$b, ''];
}

/** Absagen durch dich – die Person bekommt eine Mail, der Kalendereintrag wird entfernt */
function lx_booking_cancel(string $id, string $msg = '', bool $mail = true): ?array {
    $msg = lx_cut(str_replace("\r", '', $msg), 600);
    $b = lx_booking_update($id, function (&$x) use ($msg) { $x['status'] = 'abgesagt'; $x['cancelled'] = time(); $x['seq'] = (int)($x['seq'] ?? 0) + 1; $x['log'][] = ['ts' => time(), 't' => 'Von dir abgesagt' . ($msg !== '' ? ': „' . lx_cut($msg, 120) . '“' : '')]; });
    if (!$b) return null;
    if ($mail && $b['email'] !== '') lx_mail($b['email'], 'Abgesagt: ' . $b['type_name'] . ' am ' . date('d.m.', (int)$b['start']),
        "Guten Tag " . lx_bk_first($b) . ",\n\nleider muss ich unseren Termin absagen:\n\n{$b['type_name']}\n" . lx_booking_when($b) . "\n\n" . ($msg !== '' ? "$msg\n\n" : '')
        . "Ich melde mich mit einem neuen Vorschlag. Entschuldigen Sie bitte!\n\n" . LX_BK_SIGN . "\n", (string)lx_config()['site']['email'], 'Constantin Moritz Loggen', lx_booking_att($b));
    lx_booking_lead_note($b, 'Termin abgesagt: ' . lx_booking_when($b));
    return $b;
}

/** Neue Zeit(en) vorschlagen: Status wieder „offen“, Mail mit Link */
function lx_booking_move(string $id, array $dates, array $times, string $note = ''): ?array {
    $opts = lx_bk_parse_opts($dates, $times);
    if (!$opts || $opts[0] < time() - 3600) return null;
    $b = lx_booking_update($id, function (&$x) use ($opts, $note) {
        $len = lx_bk_min($x) * 60;
        $x['opts'] = $opts; $x['start'] = $opts[0]; $x['end'] = $opts[0] + $len; $x['status'] = 'offen'; $x['answer'] = ''; $x['answered'] = 0; $x['reminded'] = 0;
        $x['seq'] = (int)($x['seq'] ?? 0) + 1;
        $x['mail_subject'] = 'Neuer Terminvorschlag: {terminart}';
        $x['mail_text'] = "Guten Tag {name},\n\nhier " . (count($opts) > 1 ? 'sind neue Vorschläge' : 'ist ein neuer Vorschlag') . " für unseren Termin:\n\n{terminart} ({dauer} Minuten)\n{zeiten}\nOrt: {ort}\n\n{nachricht}\n\nBitte sagen Sie mir mit einem Klick, ob es passt:\n{link}\n\n" . LX_BK_SIGN;
        if ($note !== '') $x['note'] = lx_cut(str_replace("\r", '', $note), 1500);
        $x['log'][] = ['ts' => time(), 't' => 'Neue Zeit vorgeschlagen (' . implode(', ', array_map(fn($t) => date('d.m. H:i', $t), $opts)) . ')'];
    });
    if ($b && $b['email'] !== '' && lx_booking_send($b)) $b = lx_booking_update($b['id'], function (&$x) { $x['sent'] = time(); });
    return $b;
}

/** Erinnerung (manuell aus dem Admin oder automatisch) */
function lx_booking_remind(array $b): bool {
    if ($b['email'] === '') return false;
    if ($b['status'] === 'offen') {
        $ok = lx_mail($b['email'], 'Erinnerung: Terminvorschlag ' . $b['type_name'],
            lx_booking_fill("Guten Tag {name},\n\nkurze Erinnerung an meinen Terminvorschlag:\n\n{terminart} ({dauer} Minuten)\n{zeiten}\n\nPasst Ihnen eine der Zeiten? Ein Klick genügt:\n{link}\n\n" . LX_BK_SIGN, $b),
            (string)lx_config()['site']['email'], 'Constantin Moritz Loggen');
    } else {
        $tomorrow = date('Y-m-d', (int)$b['start']) === date('Y-m-d', strtotime('+1 day'));
        $ok = lx_mail($b['email'], 'Erinnerung: ' . $b['type_name'] . ($tomorrow ? ' morgen um ' : ' am ' . date('d.m.', (int)$b['start']) . ' um ') . date('H:i', (int)$b['start']) . ' Uhr',
            "Guten Tag " . lx_bk_first($b) . ",\n\nich freue mich auf unseren Termin:\n\n{$b['type_name']}\n" . lx_booking_when($b) . ($b['where'] !== '' ? "\nOrt: {$b['where']}" : '')
            . "\n\nFalls etwas dazwischenkommt, sagen Sie hier kurz Bescheid:\n" . lx_booking_link($b) . "\n\n" . LX_BK_SIGN . "\n", (string)lx_config()['site']['email'], 'Constantin Moritz Loggen', lx_booking_att($b));
    }
    if ($ok) lx_booking_update($b['id'], function (&$x) { $x['reminded'] = time(); $x['log'][] = ['ts' => time(), 't' => 'Erinnerung gesendet']; });
    return $ok;
}

/** Läuft nebenbei bei Seitenaufrufen (höchstens alle 10 Minuten): Erinnerungen 24 Std. vorher */
function lx_booking_tick(): void {
    $f = LX_DATA . '/booking-tick.lock';
    if (is_file($f) && filemtime($f) > time() - 600) return;
    @touch($f);
    if (empty(lx_bk_cfg()['remind'])) return;
    $now = time();
    foreach (lx_bookings() as $b) {
        if ($b['status'] !== 'bestaetigt' || !empty($b['reminded']) || $b['email'] === '') continue;
        $s = (int)$b['start'];
        if ($s - $now > 24 * 3600 || $s - $now < 2 * 3600) continue;           // 2–24 Std. vorher
        if ((int)$b['answered'] > $now - 6 * 3600 && $s - (int)$b['answered'] < 30 * 3600) continue; // gerade erst bestätigt → keine Extra-Mail
        lx_booking_remind($b);
    }
}

/* ---------- Öffentliche Seite /termin?t=… ---------- */
function lx_render_termin(string $html): string {
    $b = lx_booking_find((string)($_GET['t'] ?? ''));
    if (!$b) return $html;
    $min = lx_bk_min($b);
    $ics = lx_url('_launch/api.php?a=ics&t=' . $b['token']);
    $where = (string)$b['where'];
    $whereHtml = preg_match('~^(.*?)(https?://\S+)(.*)$~s', $where, $m) ? e($m[1]) . '<a href="' . e($m[2]) . '" target="_blank" rel="noopener noreferrer">' . e($m[2]) . '</a>' . e($m[3]) : e($where);
    $past = (int)$b['end'] < time() && $b['status'] !== 'offen';
    $expired = $b['status'] === 'offen' && lx_bk_expired($b);
    $status = $past || $expired ? ['vorbei', $expired ? 'Vorschlag abgelaufen' : 'Termin ist vorbei']
        : [$b['status'], ['offen' => 'Wartet auf Ihre Antwort', 'bestaetigt' => 'Bestätigt', 'abgelehnt' => 'Sie haben abgesagt', 'abgesagt' => 'Abgesagt'][$b['status']] ?? ''];
    $first = e(lx_bk_first($b));
    $opts = lx_bk_opts($b);
    $o = '<div class="bk-prop" data-t="' . e($b['token']) . '"><span class="bk-status st-' . e($status[0]) . '">' . ($status[0] === 'bestaetigt' ? '<svg><use href="#i-check"/></svg>' : '<i></i>') . e($status[1]) . '</span>'
       . '<h2>' . e($b['type_name']) . '</h2><p class="bk-hi">Guten Tag ' . $first . ', ' . ($b['status'] === 'offen' && count($opts) > 1 && !$expired ? 'welche Zeit passt Ihnen am besten?' : 'hier ist Ihr Termin mit mir:') . '</p>';
    $facts = fn(int $start) => '<ul class="bk-facts"><li><svg><use href="#i-cal"/></svg>' . e(lx_bk_day($start)) . '</li>'
       . '<li><svg><use href="#i-clock"/></svg>' . date('H:i', $start) . '–' . date('H:i', $start + $min * 60) . ' Uhr <small>(' . $min . ' Min.)</small></li>'
       . ($where !== '' ? '<li><svg><use href="#i-pin"/></svg><span>' . $whereHtml . '</span></li>' : '') . '</ul>';
    $no = fn(string $label, string $btn) => '<div class="bk-no" hidden><label for="bkMsg">' . $label . ' <small>(optional)</small></label><textarea id="bkMsg" rows="3" maxlength="1500" placeholder="z. B. „Dienstags und donnerstags ab 14 Uhr passt es mir gut.“"></textarea><button type="button" class="btn btn-primary" data-answer="nein"><span>' . $btn . '</span></button></div>';
    $icsLink = '<a class="bk-ics" href="' . e($ics) . '"><svg><use href="#i-down"/></svg>In meinen Kalender eintragen</a>';
    $note = ($b['note'] ?? '') !== '' ? '<blockquote class="bk-note"><span>Nachricht von Constantin</span>' . nl2br(e($b['note'])) . '</blockquote>' : '';

    if ($b['status'] === 'abgesagt') {
        $o .= $facts((int)$b['start']) . '<p class="bk-info">Dieser Termin wurde abgesagt. Ich melde mich mit einem neuen Vorschlag bei Ihnen.</p>';
    } elseif ($past || $expired) {
        $o .= ($expired ? '' : $facts((int)$b['start'])) . '<p class="bk-info">' . ($expired ? 'Die vorgeschlagenen Zeiten sind leider schon vorbei. Antworten Sie einfach auf meine E-Mail – dann finden wir einen neuen Termin.' : 'Dieser Termin liegt in der Vergangenheit. Danke für das Gespräch!') . '</p>';
    } elseif ($b['status'] === 'bestaetigt') {
        $o .= $facts((int)$b['start']) . $note . '<p class="bk-info ok">Prima, der Termin steht! Die Bestätigung mit Kalendereintrag haben Sie per E-Mail bekommen.</p>'
            . '<div class="bk-actions"><a class="btn btn-primary" href="' . e($ics) . '"><span>In meinen Kalender</span></a><button type="button" class="btn btn-ghost" data-show-no><span>Kann doch nicht</span></button></div>'
            . $no('Schade! Wann passt es Ihnen besser?', 'Termin absagen');
    } elseif ($b['status'] === 'abgelehnt') {
        $all = array_values(array_filter(array_map('intval', (array)($b['opts'] ?? [$b['start']])), fn($t) => $t > time()));
        $o .= '<p class="bk-info">Danke für Ihre Rückmeldung' . ($b['answer'] !== '' ? ' („' . e(lx_cut($b['answer'], 160)) . '“)' : '') . ' – ich melde mich mit einem neuen Vorschlag.</p>';
        if ($all) {
            $o .= '<p class="bk-small">Passt es Ihnen doch?</p><div class="bk-actions">';
            foreach ((array)($b['opts'] ?? [$b['start']]) as $i => $t) if ((int)$t > time() && !lx_bk_clash((int)$t, $min, $b['id'], true)) $o .= '<button type="button" class="btn btn-ghost" data-answer="ja" data-opt="' . $i . '"><span>' . e(lx_bk_day((int)$t, false)) . ', ' . date('H:i', (int)$t) . ' Uhr</span></button>';
            $o .= '</div>';
        }
    } elseif (count($opts) > 1) {
        $o .= '<ol class="bk-opts">';
        foreach ($opts as $i => $t) {
            $taken = $t < time() || lx_bk_clash($t, $min, $b['id'], true);
            $o .= '<li class="bk-opt' . ($taken ? ' is-taken' : '') . '"><span class="bk-opt-n">' . ($i + 1) . '</span><span class="bk-opt-t"><b>' . e(lx_bk_day($t, false)) . '</b><small>' . date('H:i', $t) . '–' . date('H:i', $t + $min * 60) . ' Uhr</small></span>'
                . ($taken ? '<span class="bk-opt-x">nicht mehr frei</span>' : '<button type="button" class="btn btn-primary btn-s" data-answer="ja" data-opt="' . $i . '"><span>Passt mir</span></button>') . '</li>';
        }
        $o .= '</ol><ul class="bk-facts bk-facts-s"><li><svg><use href="#i-clock"/></svg>' . $min . ' Minuten</li>' . ($where !== '' ? '<li><svg><use href="#i-pin"/></svg><span>' . $whereHtml . '</span></li>' : '') . '</ul>' . $note
            . '<div class="bk-actions"><button type="button" class="btn btn-ghost" data-show-no><span>Keine Zeit passt mir</span></button></div>' . $no('Wann passt es Ihnen besser?', 'Absenden');
    } else {
        $taken = lx_bk_clash($opts[0], $min, $b['id'], true);
        $o .= $facts($opts[0]) . $note
            . ($taken ? '<p class="bk-info">Diese Zeit ist inzwischen leider vergeben. Schreiben Sie mir kurz, wann es Ihnen passt.</p><div class="bk-actions"><button type="button" class="btn btn-primary" data-show-no><span>Andere Zeit vorschlagen</span></button></div>'
                      : '<div class="bk-actions"><button type="button" class="btn btn-primary" data-answer="ja"><span>Ja, passt mir</span></button><button type="button" class="btn btn-ghost" data-show-no><span>Passt leider nicht</span></button></div>')
            . $no('Wann passt es Ihnen besser?', 'Absenden') . ($taken ? '' : $icsLink);
    }
    $o .= '<p class="bk-error" role="alert" hidden></p></div>';
    return (string)preg_replace('~<!--cms:termin-->.*?<!--/cms:termin-->~s', $o, $html, 1);
}
