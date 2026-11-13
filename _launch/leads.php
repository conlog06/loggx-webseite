<?php
/* ==========================================================================
   LoggX – Anfragen
   Formular in 3 Schritten → Paket-Empfehlung, Priorität (heiß/warm/kalt),
   E-Mail an dich, automatische Bestätigung an den Kunden,
   Postfach mit Status und Notizen im Admin-Bereich.
   ========================================================================== */

declare(strict_types=1);

function lx_lead_options(): array {
    return [
        'projekt' => ['neu' => 'Neue Webseite', 'relaunch' => 'Bestehende Seite erneuern', 'landing' => 'Landingpage für eine Aktion',
                      'shop' => 'Shop, Buchung oder Sonderfunktion', 'aenderung' => 'Änderung oder Support für meine Seite'],
        'branche' => ['praxis' => 'Praxis & Gesundheit', 'studio' => 'Studio, Beauty & Fitness', 'handwerk' => 'Handwerk & Bau', 'gastro' => 'Gastronomie & Café',
                      'dienst' => 'Dienstleistung & Beratung', 'handel' => 'Handel', 'verein' => 'Verein & Organisation', 'andere' => 'Etwas anderes'],
        'seiten' => ['one' => 'Eine Seite reicht', 'few' => '2 bis 6 Unterseiten', 'many' => 'Mehr als 6 Seiten', 'unsure' => 'Weiß ich noch nicht'],
        'extras' => ['texte' => 'Hilfe bei Texten', 'fotos' => 'Fotos & Bilder', 'logo' => 'Logo & Design', 'google' => 'Google-Profil',
                     'termin' => 'Online-Terminbuchung', 'shop' => 'Onlineshop', 'sprachen' => 'Mehrsprachig'],
        'start' => ['asap' => 'So schnell wie möglich', 'month' => 'In den nächsten 4 Wochen', 'later' => 'In 1–3 Monaten', 'info' => 'Erst mal informieren'],
        'budget' => ['b1' => 'bis 1.000 €', 'b2' => '1.000–2.500 €', 'b3' => 'über 2.500 €', 'open' => 'Noch offen'],
        'kontakt' => ['mail' => 'E-Mail', 'phone' => 'Telefon', 'whatsapp' => 'WhatsApp', 'video' => 'Videocall (Zoom/Teams)'],
    ];
}
function lx_lead_status(): array {
    return ['neu' => 'Neu', 'kontakt' => 'Kontaktiert', 'angebot' => 'Angebot gesendet', 'gewonnen' => 'Auftrag erhalten', 'verloren' => 'Kein Auftrag'];
}

/** Paket-Empfehlung – dieselbe Logik läuft im Formular (main.js) live mit */
function lx_lead_recommend(array $d, array $s): array {
    $p = $d['projekt'] ?? ''; $pages = $d['seiten'] ?? ''; $x = $d['extras'] ?? [];
    $eur = fn($v) => number_format((int)$v, 0, ',', '.') . ' €';
    if ($p === 'aenderung') return ['Änderungen & Support', 'Abrechnung nach Aufwand ab ' . $eur($s['price_hourly']) . ' pro Stunde. Kein Abo, keine Grundgebühr.'];
    if ($p === 'shop' || $pages === 'many' || array_intersect($x, ['shop', 'termin', 'sprachen'])) {
        return ['Individuell', 'Für Sonderfunktionen wie Shop, Terminbuchung oder mehrere Sprachen. Sie bekommen vorher ein Angebot zum Festpreis.'];
    }
    if ($pages === 'one' || $p === 'landing') return ['Visitenkarte', 'Eine schlanke Seite mit allem Wichtigen auf einen Blick – ab ' . $eur($s['price_visit']) . ', online in ' . $s['time_visit'] . '.'];
    if ($pages === 'few' || $p === 'relaunch' || count($x) >= 3) return ['Firmenwebseite', 'Mehrere Unterseiten für Leistungen, Team und Referenzen – ab ' . $eur($s['price_firm']) . ', online in ' . $s['time_firm'] . '.'];
    return ['Visitenkarte', 'Eine schlanke Seite mit allem Wichtigen auf einen Blick – ab ' . $eur($s['price_visit']) . ', online in ' . $s['time_visit'] . '.'];
}

/** Priorität 0–100 (wie dringend und wie konkret) */
function lx_lead_score(array $d): int {
    $s = ['asap' => 30, 'month' => 22, 'later' => 12, 'info' => 4][$d['start'] ?? ''] ?? 8;
    $s += ['neu' => 15, 'relaunch' => 15, 'shop' => 15, 'landing' => 12, 'aenderung' => 8][$d['projekt'] ?? ''] ?? 5;
    $s += ['b3' => 20, 'b2' => 15, 'b1' => 8, 'open' => 4][$d['budget'] ?? ''] ?? 0;
    $s += min(12, count($d['extras'] ?? []) * 3);
    if (($d['telefon'] ?? '') !== '') $s += 10;
    if (($d['firma'] ?? '') !== '') $s += 6;
    if (($d['webseite'] ?? '') !== '') $s += 4;
    if (mb_strlen($d['nachricht'] ?? '') > 40) $s += 8;
    return min(100, $s);
}
function lx_lead_temp(int $score): array {
    if ($score >= 65) return ['hot', 'Heiß'];
    if ($score >= 40) return ['warm', 'Warm'];
    return ['cold', 'Kalt'];
}

/** Von welcher Seite die Anfrage kam (für die E-Mail) */
function lx_lead_pages(): array {
    return ['angebot' => ' (Werbe-Landingpage)', 'praxis' => ' (Seite Praxen)', 'studio' => ' (Seite Studios)', 'agentur' => ' (Seite Agenturen)', 'gastro' => ' (Seite Gastronomie)', 'preise' => ' (Preise-Seite)'];
}

function lx_leads(): array { return lx_read('leads', []); }
function lx_save_leads(array $l): void { lx_write('leads', array_values($l)); }

/** Eingaben prüfen und Anfrage speichern. Gibt [ok, fehler|lead] zurück. */
function lx_lead_create(array $in): array {
    $o = lx_lead_options();
    $str = fn(string $k, int $max) => lx_cut(str_replace(["\r", "\0"], '', strip_tags((string)($in[$k] ?? ''))), $max);
    $pick = fn(string $k) => isset($o[$k][(string)($in[$k] ?? '')]) ? (string)$in[$k] : '';
    $d = [
        'name' => $str('name', 120), 'email' => $str('email', 200), 'telefon' => $str('telefon', 40), 'firma' => $str('firma', 160),
        'ort' => $str('ort', 80), 'webseite' => $str('webseite', 200), 'nachricht' => $str('nachricht', 4000),
        'projekt' => $pick('projekt'), 'branche' => $pick('branche'), 'branche_text' => $str('branche_text', 80), 'seiten' => $pick('seiten'),
        'start' => $pick('start'), 'budget' => $pick('budget'), 'kontakt' => $pick('kontakt') ?: 'mail', 'paket' => $str('paket', 40),
        'extras' => array_values(array_intersect(array_map('strval', (array)($in['extras'] ?? [])), array_keys($o['extras']))),
    ];
    $d['name'] = str_replace("\n", ' ', $d['name']);
    $d['webseite'] = str_replace("\n", ' ', $d['webseite']);
    if ($d['firma'] === '') return [false, 'Bitte geben Sie Ihre Firma an.'];
    if ($d['ort'] === '') return [false, 'Bitte geben Sie Ihren Ort an.'];
    if ($d['name'] === '') return [false, 'Bitte geben Sie Ihren Namen an.'];
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return [false, 'Bitte geben Sie eine gültige E-Mail-Adresse an.'];
    if (strlen((string)preg_replace('/\D+/', '', $d['telefon'])) < 6) return [false, 'Bitte geben Sie Ihre Telefonnummer an.'];
    if (empty($in['consent'])) return [false, 'Bitte bestätigen Sie den Hinweis zum Datenschutz.'];

    $page = in_array($in['page'] ?? '', ['angebot', 'praxis', 'studio', 'agentur', 'gastro', 'preise', 'en'], true) ? (string)$in['page'] : 'start';
    $lang = ($in['lang'] ?? '') === 'en' || $page === 'en' ? 'en' : 'de';
    $c = lx_config();
    $site = $page === 'angebot' ? lx_site_for_lp($c['site']) : $c['site'];
    [$rec, $why] = lx_lead_recommend($d, $site);
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $lead = [
        'id' => bin2hex(random_bytes(6)), 'created' => time(), 'status' => 'neu', 'seen' => false, 'notes' => [], 'data' => $d,
        'rec' => $rec, 'why' => $why, 'score' => lx_lead_score($d), 'page' => $page, 'lang' => $lang,
        'src' => lx_cut((string)preg_replace('/[^\w.\-: \/]/u', '', (string)($in['src'] ?? '')), 60) ?: 'direkt',
        'device' => ['m' => 'Handy', 't' => 'Tablet', 'd' => 'Computer'][lx_stats_device($ua)] . ' · ' . lx_stats_os($ua),
    ];
    lx_locked('leads', function () use ($lead) { $l = lx_leads(); $l[] = $lead; lx_save_leads($l); });
    lx_stats_lead();
    try { lx_lead_mails($lead, $site); } catch (Throwable $e) { error_log('LoggX Anfrage-Mail: ' . $e->getMessage()); }
    if (random_int(1, 20) === 1) lx_leads_cleanup();
    return [true, $lead];
}

function lx_lead_branche(array $d): string {
    $o = lx_lead_options();
    $b = (string)($d['branche'] ?? ''); $t = (string)($d['branche_text'] ?? '');
    return $b === 'andere' && $t !== '' ? $t : ($o['branche'][$b] ?? '');
}

function lx_lead_summary(array $lead): string {
    $o = lx_lead_options(); $d = $lead['data'];
    $lbl = fn($k) => $d[$k] !== '' ? ($o[$k][$d[$k]] ?? $d[$k]) : '–';
    $t  = "Name:            {$d['name']}\n";
    $t .= 'Firma:           ' . ($d['firma'] ?: '–') . "\n";
    $t .= 'Branche:         ' . (lx_lead_branche($d) ?: '–') . "\n";
    $t .= 'Ort:             ' . ($d['ort'] ?: '–') . "\n";
    $t .= "E-Mail:          {$d['email']}\n";
    $t .= 'Telefon:         ' . ($d['telefon'] ?: '–') . "\n";
    $t .= 'Lieber per:      ' . $lbl('kontakt') . "\n";
    if ($d['webseite'] !== '') $t .= "Jetzige Seite:   {$d['webseite']}\n";
    $t .= "\nVorhaben:        " . $lbl('projekt') . "\n";
    $t .= 'Umfang:          ' . $lbl('seiten') . "\n";
    $t .= 'Dazu gewünscht:  ' . ($d['extras'] ? implode(', ', array_map(fn($z) => $o['extras'][$z], $d['extras'])) : '–') . "\n";
    $t .= 'Start:           ' . $lbl('start') . "\n";
    $t .= 'Budget:          ' . $lbl('budget') . "\n";
    if ($d['paket'] !== '') $t .= "Paket geklickt:  {$d['paket']}\n";
    $t .= "\nNachricht:\n" . ($d['nachricht'] !== '' ? $d['nachricht'] : '–') . "\n";
    return $t;
}

function lx_lead_mails(array $lead, array $site): void {
    $c = lx_config(); $d = $lead['data'];
    [, $temp] = lx_lead_temp($lead['score']);
    $who = $d['firma'] !== '' ? $d['firma'] : $d['name'];
    $text  = "Neue Anfrage über loggx.de\n==========================\n\n";
    $text .= "Priorität:       $temp ({$lead['score']}/100)\n";
    $text .= "Empfehlung:      {$lead['rec']}\n";
    $text .= 'Gekommen über:   ' . $lead['src'] . (lx_lead_pages()[$lead['page']] ?? '') . " · {$lead['device']}\n";
    $text .= (($lead['lang'] ?? 'de') === 'en' ? "Sprache:         ENGLISCH – bitte auf Englisch antworten\n" : '') . "\n";
    $text .= lx_lead_summary($lead);
    $text .= "\nIm Admin-Bereich ansehen: " . lx_abs_url('admin/?tab=anfragen&id=' . $lead['id']) . "\n";
    lx_mail_admin('Neue Anfrage' . (($lead['lang'] ?? 'de') === 'en' ? ' (EN)' : '') . ": $who – $temp, {$lead['rec']}", $text, $d['email'], $d['name']);

    if (!empty($c['leads']['autoreply']) && ($lead['lang'] ?? 'de') === 'en') {
        // Englische Bestätigung (Anfrage kam über loggx.de/en/)
        $hours = (int)$site['response_hours'];
        $how = ['phone' => "I'll call you within $hours hours.", 'whatsapp' => "I'll get in touch via WhatsApp within $hours hours.",
                'video' => "I'll get back to you within $hours hours with a suggested time for a video call (Zoom or Teams)."][$d['kontakt']] ?? "I'll get back to you within $hours hours.";
        $plan = ['Visitenkarte' => 'Business card', 'Firmenwebseite' => 'Business website', 'Individuell' => 'Custom', 'Änderungen & Support' => 'Changes & support'][$lead['rec']] ?? $lead['rec'];
        $r  = "Hello {$d['name']},\n\nthank you for your enquiry – it has arrived safely. $how\n\n";
        $r .= "My first assessment: the \"$plan\" package is probably a good fit for you.\n\n";
        $r .= $d['projekt'] === 'aenderung'
            ? "If you like, simply reply to this email with what you'd like changed – then I can estimate the effort straight away.\n\n"
            : "In the free initial consultation we'll look together at what you really need. Afterwards you'll receive a fixed-price quote.\n\n";
        $r .= "Best regards\nConstantin Moritz Loggen\nLoggX – Websites for businesses\n";
        if (lx_phone_public($site)) $r .= 'Phone: ' . trim((string)$site['phone']) . "\n";
        $r .= lx_abs_url('en/') . "\n";
        lx_mail($d['email'], 'Your enquiry at LoggX', $r, (string)$c['site']['email'], 'Constantin Moritz Loggen');
    } elseif (!empty($c['leads']['autoreply'])) {
        $hours = (int)$site['response_hours'];
        $how = [
            'phone' => "Ich rufe Sie innerhalb von $hours Stunden an.",
            'whatsapp' => "Ich melde mich innerhalb von $hours Stunden per WhatsApp bei Ihnen.",
            'video' => "Ich melde mich innerhalb von $hours Stunden mit einem Terminvorschlag für einen Videocall (Zoom oder Teams).",
        ][$d['kontakt']] ?? "Ich melde mich innerhalb von $hours Stunden bei Ihnen.";
        $r  = "Guten Tag {$d['name']},\n\n";
        $r .= "vielen Dank für Ihre Anfrage! Sie ist gut bei mir angekommen. $how\n\n";
        $r .= "Meine erste Einschätzung: Das Paket „{$lead['rec']}“ passt wahrscheinlich gut zu Ihnen.\n{$lead['why']}\n";
        $r .= $d['projekt'] === 'aenderung'
            ? "Wenn Sie mögen, schicken Sie mir als Antwort auf diese E-Mail schon vorab, was geändert werden soll – dann kann ich den Aufwand direkt einschätzen.\n\n"
            : "Im kostenlosen Erstgespräch schauen wir gemeinsam, was Sie wirklich brauchen. Danach bekommen Sie ein Angebot zum Festpreis.\n\n";
        $r .= "Ihre Angaben im Überblick:\n--------------------------\n" . lx_lead_summary($lead);
        $r .= "\nViele Grüße\nConstantin Moritz Loggen\nLoggX – Webseiten für Unternehmen\n";
        if (lx_phone_public($site)) $r .= 'Telefon: ' . trim((string)$site['phone']) . "\n";
        $r .= lx_abs_url('') . "\n";
        lx_mail($d['email'], 'Ihre Anfrage bei LoggX', $r, (string)$c['site']['email'], 'Constantin Moritz Loggen');
    }
}

/** Anfragen nach eingestellter Frist löschen (Datenschutz) */
function lx_leads_cleanup(): void {
    $months = (int)(lx_config()['leads']['keep_months'] ?? 12);
    if ($months <= 0) return;
    $limit = strtotime("-$months months");
    lx_locked('leads', function () use ($limit) {
        $l = lx_leads();
        $n = array_filter($l, fn($x) => $x['created'] >= $limit || $x['status'] === 'gewonnen');
        if (count($n) !== count($l)) lx_save_leads($n);
    });
}

/* ---------- Antwortvorlagen (Admin → Anfrage → „Antworten“) ---------- */
function lx_reply_defaults(): array {
    $sig = "\n\nViele Grüße\nConstantin Moritz Loggen\nLoggX – Webseiten für Unternehmen\nloggx.de";
    return [
        ['id' => 'danke', 'name' => 'Danke & Erstgespräch', 'subject' => 'Ihre Anfrage bei LoggX', 'text' => "Guten Tag {name},\n\nvielen Dank für Ihre Anfrage{firma_bei}! Ich habe mir Ihre Angaben angesehen – das Paket „{paket}“ passt sehr wahrscheinlich gut.\n\nAm besten sprechen wir kurz (kostenlos, ca. 30 Minuten) per Telefon, Zoom oder Teams. Wann passt es Ihnen in den nächsten Tagen?" . $sig],
        ['id' => 'fragen', 'name' => 'Rückfragen', 'subject' => 'Kurze Rückfragen zu Ihrer Webseite', 'text' => "Guten Tag {name},\n\ndanke für Ihre Anfrage! Damit ich Ihnen ein passendes Angebot machen kann, habe ich noch ein paar kurze Fragen:\n\n1. Haben Sie schon eine Domain (z. B. ihre-firma.de)?\n2. Gibt es Texte und Fotos, oder soll ich dabei helfen?\n3. Welche Webseiten gefallen Ihnen (gern 1–2 Beispiele)?\n\nAntworten Sie einfach auf diese E-Mail." . $sig],
        ['id' => 'angebot', 'name' => 'Angebot kommt', 'subject' => 'Ihr Angebot von LoggX', 'text' => "Guten Tag {name},\n\nvielen Dank für das nette Gespräch! Wie besprochen bekommen Sie Ihr Angebot zum Festpreis in den nächsten 1–2 Tagen per E-Mail.\n\nFalls Ihnen vorher noch etwas einfällt, melden Sie sich gern." . $sig],
        ['id' => 'nachfassen', 'name' => 'Nachfassen', 'subject' => 'Kurze Nachfrage zu meinem Angebot', 'text' => "Guten Tag {name},\n\nich wollte kurz nachfragen, ob Sie mein Angebot erhalten haben und ob noch Fragen offen sind. Gern passe ich es an oder wir sprechen kurz am Telefon.\n\nIch freue mich auf Ihre Rückmeldung." . $sig],
        ['id' => 'absage', 'name' => 'Freundliche Absage', 'subject' => 'Ihre Anfrage bei LoggX', 'text' => "Guten Tag {name},\n\nvielen Dank für Ihr Interesse an LoggX. Leider kann ich Ihr Projekt im Moment nicht übernehmen. Ich wünsche Ihnen viel Erfolg – und melden Sie sich gern, wenn es zu einem späteren Zeitpunkt wieder passt." . $sig],
        ['id' => 'check', 'v' => 2, 'name' => 'Ergebnis Webseiten-Check', 'subject' => 'Ihr Webseiten-Check für {ihre_seite}', 'text' => "Guten Tag {name},\n\nwie versprochen habe ich mir {ihre_seite} angesehen. Hier meine Einschätzung:\n\nDas ist schon gut:\n• \n• \n\nDas würde ich verbessern:\n1. \n2. \n3. \n\nDie wichtigsten Punkte lassen sich meist schnell beheben. Wenn Sie möchten, sprechen wir 20 Minuten kostenlos darüber – per Telefon, Zoom oder Teams. Antworten Sie einfach auf diese E-Mail." . $sig],
        ['id' => 'stimme', 'v' => 2, 'name' => 'Bitte um Kundenstimme', 'subject' => 'Kurze Bitte: Ihre Meinung zu Ihrer neuen Webseite', 'text' => "Guten Tag {name},\n\nich hoffe, Sie sind mit Ihrer Webseite zufrieden! Darf ich Sie um einen kleinen Gefallen bitten?\n\nSchreiben Sie mir in 2–3 Sätzen, wie die Zusammenarbeit war und was Ihnen an der Seite gefällt. Mit Ihrem Einverständnis zeige ich das mit Ihrem Namen{firma_bei} auf loggx.de – gern auch mit einem Foto von Ihnen.\n\nVielen Dank!" . $sig],
    ];
}
function lx_reply_templates(?array $c = null): array {
    $c = $c ?? lx_config();
    $t = (array)($c['leads']['templates'] ?? []);
    if (!$t) return lx_reply_defaults();
    // Neue Standardvorlagen auch bei schon gespeicherten Vorlagen anbieten (einmalig, bis zum nächsten Speichern)
    if ((int)($c['leads']['tpl_v'] ?? 1) < 2) {
        $have = array_column($t, 'id');
        foreach (lx_reply_defaults() as $d) if (($d['v'] ?? 1) >= 2 && !in_array($d['id'], $have, true)) $t[] = $d;
    }
    return $t;
}
function lx_reply_fill(string $tpl, array $lead): string {
    $d = $lead['data'];
    return strtr($tpl, ['{name}' => (string)$d['name'], '{firma}' => (string)$d['firma'], '{firma_bei}' => $d['firma'] !== '' ? ' für ' . $d['firma'] : '',
        '{paket}' => (string)$lead['rec'], '{ort}' => (string)$d['ort'], '{webseite}' => lx_abs_url(''),
        '{ihre_seite}' => $d['webseite'] !== '' ? lx_check_host(preg_match('~^https?://~i', $d['webseite']) ? $d['webseite'] : 'https://' . $d['webseite']) : 'Ihre Webseite']);
}
/** E-Mail an den Interessenten schicken und als Notiz in der Anfrage speichern */
function lx_lead_reply(string $id, string $subject, string $text): array {
    $lead = null; foreach (lx_leads() as $x) if ($x['id'] === $id) $lead = $x;
    if (!$lead) return [false, 'Anfrage nicht gefunden.'];
    $subject = lx_cut(str_replace(["\r", "\n"], ' ', $subject), 200); $text = lx_cut(str_replace("\r", '', $text), 8000);
    if ($subject === '' || trim($text) === '') return [false, 'Bitte Betreff und Text ausfüllen.'];
    $c = lx_config();
    $ok = lx_mail($lead['data']['email'], $subject, $text . "\n", (string)$c['site']['email'], 'Constantin Moritz Loggen');
    if (!$ok) return [false, 'Die E-Mail konnte nicht gesendet werden – prüfe unter Einstellungen → E-Mail den Absender.'];
    lx_locked('leads', function () use ($id, $subject, $text) {
        $l = lx_leads();
        foreach ($l as &$x) if ($x['id'] === $id) {
            $x['notes'][] = ['ts' => time(), 't' => "✉️ E-Mail gesendet: „{$subject}“\n" . lx_cut($text, 600), 'sys' => true, 'mail' => 1];
            if ($x['status'] === 'neu') $x['status'] = 'kontakt';
            $x['seen'] = true;
        }
        unset($x); lx_save_leads($l);
    });
    return [true, 'E-Mail an ' . $lead['data']['email'] . ' gesendet und in den Notizen gespeichert.'];
}


/* ==========================================================================
   Kostenloser Webseiten-Check
   Formular auf der Startseite → Anfrage mit Typ „check“ im Postfach,
   automatische Vorprüfung der Seite (nur für dich), Bestätigung an den Kunden.
   ========================================================================== */

/** Adresse prüfen und vereinheitlichen (https:// ergänzen) */
function lx_check_url(string $u): string {
    $u = trim(str_replace(["\r", "\n", "\0", ' '], '', $u));
    if ($u === '') return '';
    if (!preg_match('~^https?://~i', $u)) $u = 'https://' . $u;
    $p = parse_url($u);
    $host = strtolower((string)($p['host'] ?? ''));
    $testIp = getenv('LX_PROBE_LOCAL') === '1' && PHP_SAPI === 'cli-server' && filter_var($host, FILTER_VALIDATE_IP); // nur lokaler Testserver
    if (!filter_var($u, FILTER_VALIDATE_URL) || !$testIp && !preg_match('~^[a-z0-9.-]+\.[a-z]{2,}$~', $host) || strlen($u) > 200) return '';
    return strtolower((string)$p['scheme']) . '://' . $host . (isset($p['port']) ? ':' . (int)$p['port'] : '') . ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
}
function lx_check_host(string $url): string { return preg_replace('~^www\.~', '', strtolower((string)parse_url($url, PHP_URL_HOST))); }

function lx_check_create(array $in): array {
    $str = fn(string $k, int $max) => lx_cut(str_replace(["\r", "\0"], '', strip_tags((string)($in[$k] ?? ''))), $max);
    $url = lx_check_url((string)($in['webseite'] ?? ''));
    $d = ['name' => str_replace("\n", ' ', $str('name', 120)), 'email' => $str('email', 200), 'telefon' => '', 'firma' => str_replace("\n", ' ', $str('firma', 160)),
          'ort' => '', 'webseite' => $url, 'nachricht' => $str('nachricht', 2000), 'projekt' => 'relaunch', 'branche' => '', 'branche_text' => '',
          'seiten' => '', 'start' => '', 'budget' => '', 'kontakt' => 'mail', 'paket' => '', 'extras' => []];
    if ($url === '') return [false, 'Bitte geben Sie die Adresse Ihrer Webseite an (z. B. ihre-firma.de).'];
    if ($d['name'] === '') return [false, 'Bitte geben Sie Ihren Namen an.'];
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return [false, 'Bitte geben Sie eine gültige E-Mail-Adresse an.'];
    if (empty($in['consent'])) return [false, 'Bitte bestätigen Sie den Hinweis zum Datenschutz.'];
    if (lx_check_host($url) === 'loggx.de') return [false, 'Das ist meine eigene Seite 🙂 Bitte geben Sie die Adresse Ihrer Webseite an.'];
    $c = lx_config();
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $page = in_array($in['page'] ?? '', ['angebot', 'praxis', 'studio', 'agentur', 'gastro', 'preise'], true) ? (string)$in['page'] : 'start';
    $lead = [
        'id' => bin2hex(random_bytes(6)), 'created' => time(), 'status' => 'neu', 'seen' => false, 'notes' => [], 'data' => $d, 'type' => 'check',
        'rec' => 'Webseiten-Check', 'why' => 'Kostenlose Einschätzung von ' . lx_check_host($url) . ' – versprochen innerhalb von ' . $c['site']['check_time'] . '.',
        'score' => min(100, 45 + ($d['firma'] !== '' ? 6 : 0) + (mb_strlen($d['nachricht']) > 30 ? 8 : 0)), 'page' => $page, 'lang' => 'de',
        'src' => lx_cut((string)preg_replace('/[^\w.\-: \/]/u', '', (string)($in['src'] ?? '')), 60) ?: 'direkt',
        'device' => ['m' => 'Handy', 't' => 'Tablet', 'd' => 'Computer'][lx_stats_device($ua)] . ' · ' . lx_stats_os($ua),
    ];
    try { $lead['probe'] = lx_check_probe($url); } catch (Throwable $e) { $lead['probe'] = ['ok' => false, 'error' => 'Vorprüfung nicht möglich.']; }
    lx_locked('leads', function () use ($lead) { $l = lx_leads(); $l[] = $lead; lx_save_leads($l); });
    lx_stats_lead();
    try { lx_check_mails($lead, $c); } catch (Throwable $e) { error_log('LoggX Check-Mail: ' . $e->getMessage()); }
    return [true, $lead];
}

/** Nur öffentliche Adressen abrufen (kein Zugriff auf interne Server) */
function lx_check_public_ip(string $host): ?string {
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips = [$host];
    else { $ips = @gethostbynamel($host) ?: []; }
    if (!$ips) return null;
    $local = getenv('LX_PROBE_LOCAL') === '1' && PHP_SAPI === 'cli-server'; // nur lokaler Testserver
    foreach ($ips as $ip) if (!$local && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
    return $ips[0];
}

/** Eine Adresse abrufen (max. 2 MB, kurze Zeitlimits, Weiterleitungen werden einzeln geprüft) */
function lx_check_fetch(string $url, int $hops = 5): array {
    $chain = []; $deadline = microtime(true) + 12;
    for ($i = 0; $i <= $hops; $i++) {
        $left = (int)floor($deadline - microtime(true));
        if ($left < 2) return ['error' => 'Zeitüberschreitung beim Abruf.', 'chain' => $chain];
        $p = parse_url($url); $scheme = strtolower((string)($p['scheme'] ?? '')); $host = strtolower((string)($p['host'] ?? ''));
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || !in_array($port, [80, 443, 8080, 8443], true) && getenv('LX_PROBE_LOCAL') !== '1') return ['error' => 'Adresse nicht erlaubt.', 'chain' => $chain];
        $ip = lx_check_public_ip($host);
        if ($ip === null) return ['error' => 'Die Adresse ' . $host . ' wurde nicht gefunden.', 'chain' => $chain];
        $body = ''; $ssl = true;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip)],
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => false, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => min(8, $left),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LoggX-Webseiten-Check/1.0; +https://loggx.de/)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.8', 'Accept-Language: de-DE,de;q=0.9'], CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body) { $body .= $chunk; return strlen($body) > 2 * 1024 * 1024 ? 0 : strlen($chunk); },
        ]);
        $ok = curl_exec($ch);
        $errNo = curl_errno($ch); $err = curl_error($ch);
        if ($errNo === 60 || $errNo === 51 || $errNo === 35) { // Zertifikat ungültig → trotzdem ansehen, aber merken
            $ssl = false; $body = '';
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $ok = curl_exec($ch); $errNo = curl_errno($ch); $err = curl_error($ch);
        }
        $info = curl_getinfo($ch); curl_close($ch);
        if ($ok === false && $errNo !== 23) return ['error' => 'Seite nicht erreichbar (' . ($err ?: 'Fehler ' . $errNo) . ').', 'chain' => $chain];
        $code = (int)$info['http_code'];
        $chain[] = ['url' => $url, 'code' => $code, 'ssl' => $ssl, 'ttfb' => round((float)$info['starttransfer_time'], 2), 'time' => round((float)$info['total_time'], 2)];
        if ($code >= 300 && $code < 400 && !empty($info['redirect_url'])) { $url = (string)$info['redirect_url']; continue; }
        return ['code' => $code, 'url' => $url, 'body' => $body, 'type' => (string)($info['content_type'] ?? ''), 'bytes' => strlen($body), 'chain' => $chain, 'ssl' => $ssl,
                'ttfb' => round((float)$info['starttransfer_time'], 2), 'time' => round((float)$info['total_time'], 2)];
    }
    return ['error' => 'Zu viele Weiterleitungen.', 'chain' => $chain];
}

/** Automatische Vorprüfung: Ergebnisse als Liste [stufe (ok|warn|bad|info), Text] */
function lx_check_probe(string $url): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'Auf dem Server fehlt cURL – Vorprüfung nicht möglich.', 'ts' => time()];
    $r = lx_check_fetch($url);
    $out = ['ok' => false, 'ts' => time(), 'url' => $url, 'items' => []];
    if (isset($r['error'])) { $out['error'] = $r['error']; return $out; }
    $it = &$out['items'];
    $final = (string)$r['url']; $out['final'] = $final; $out['code'] = $r['code'];
    if ($r['code'] >= 400) { $out['error'] = 'Die Seite antwortet mit Fehler ' . $r['code'] . '.'; return $out; }
    $out['ok'] = true;
    $h = (string)$r['body'];
    $isHttps = stripos($final, 'https://') === 0;
    $it[] = $isHttps && $r['ssl'] ? ['ok', 'Sichere Verbindung (HTTPS) mit gültigem Zertifikat'] : ($isHttps ? ['bad', 'HTTPS-Zertifikat ist ungültig oder abgelaufen – Browser zeigen eine Warnung'] : ['bad', 'Keine sichere Verbindung (nur http://) – Browser zeigen „Nicht sicher“']);
    if (count($r['chain']) > 2) $it[] = ['warn', (count($r['chain']) - 1) . ' Weiterleitungen bis zur Startseite – kostet Ladezeit'];
    $t = (float)$r['time'];
    $it[] = $t <= 1.5 ? ['ok', 'Startseite lädt schnell (' . number_format($t, 1, ',', '') . ' s vom Server)'] : ($t <= 3.5 ? ['warn', 'Startseite braucht ' . number_format($t, 1, ',', '') . ' s vom Server – könnte schneller sein'] : ['bad', 'Startseite ist langsam (' . number_format($t, 1, ',', '') . ' s vom Server)']);
    $kb = (int)round($r['bytes'] / 1024);
    if ($kb > 600) $it[] = ['warn', 'HTML der Startseite ist sehr groß (' . $kb . ' KB)'];
    // HTML auswerten
    $title = preg_match('~<title[^>]*>(.*?)</title>~is', $h, $m) ? lx_plain($m[1]) : '';
    $len = mb_strlen($title);
    $it[] = $title === '' ? ['bad', 'Kein Seitentitel (wichtig für Google)'] : ($len < 20 || $len > 65 ? ['warn', 'Seitentitel ' . ($len < 20 ? 'sehr kurz' : 'zu lang') . ' (' . $len . ' Zeichen): „' . lx_cut($title, 90) . '“'] : ['ok', 'Seitentitel passt: „' . $title . '“']);
    $desc = preg_match('~<meta\b[^>]*name=["\']description["\'][^>]*>~i', $h, $m) && preg_match('~content=["\']([^"\']*)~i', $m[0], $mm) ? lx_plain($mm[1]) : '';
    $dl = mb_strlen($desc);
    $it[] = $desc === '' ? ['warn', 'Keine Beschreibung für Google (Meta-Description)'] : ($dl < 70 || $dl > 170 ? ['warn', 'Beschreibung für Google ' . ($dl < 70 ? 'sehr kurz' : 'zu lang') . ' (' . $dl . ' Zeichen)'] : ['ok', 'Beschreibung für Google vorhanden (' . $dl . ' Zeichen)']);
    $it[] = preg_match('~<meta\b[^>]*name=["\']viewport["\'][^>]*width=device-width~i', $h) ? ['ok', 'Für Handys eingerichtet (Viewport)'] : ['bad', 'Nicht für Handys eingerichtet – die Seite wird dort winzig angezeigt'];
    $h1 = preg_match_all('~<h1\b~i', $h);
    $it[] = $h1 === 1 ? ['ok', 'Eine Hauptüberschrift (H1)'] : ($h1 === 0 ? ['warn', 'Keine Hauptüberschrift (H1) – Google erkennt das Thema schlechter'] : ['warn', $h1 . ' Hauptüberschriften (H1) – besser genau eine']);
    if (preg_match('~<meta\b[^>]*name=["\']robots["\'][^>]*noindex~i', $h)) $it[] = ['bad', 'Seite ist für Google gesperrt (noindex)'];
    $imgs = preg_match_all('~<img\b[^>]*>~i', $h, $mi);
    if ($imgs) {
        $noAlt = count(array_filter($mi[0], fn($tag) => !preg_match('~\balt=["\'][^"\']+~i', $tag)));
        $it[] = $noAlt === 0 ? ['ok', 'Alle ' . $imgs . ' Bilder haben eine Beschreibung (Alt-Text)'] : ['warn', $noAlt . ' von ' . $imgs . ' Bildern ohne Beschreibung (Alt-Text)'];
    }
    $links = strtolower(implode(' ', preg_match_all('~<a\b[^>]*>.*?</a>~is', $h, $ml) ? $ml[0] : []));
    $it[] = strpos($links, 'impressum') !== false ? ['ok', 'Impressum verlinkt'] : ['bad', 'Kein Impressum-Link auf der Startseite gefunden (Pflicht)'];
    $it[] = strpos($links, 'datenschutz') !== false || strpos($links, 'privacy') !== false ? ['ok', 'Datenschutzerklärung verlinkt'] : ['bad', 'Kein Link zur Datenschutzerklärung gefunden (Pflicht)'];
    $tel = strpos($links, 'href="tel:') !== false || strpos($links, "href='tel:") !== false;
    $form = stripos($h, '<form') !== false;
    $mail = strpos($links, 'mailto:') !== false;
    $ways = array_filter(['Telefon-Link' => $tel, 'E-Mail-Link' => $mail, 'Formular' => $form]);
    $it[] = $ways ? ['ok', 'Kontakt direkt möglich: ' . implode(', ', array_keys($ways))] : ['warn', 'Kein anklickbarer Kontakt auf der Startseite (Telefon, E-Mail oder Formular)'];
    $it[] = preg_match('~<script[^>]*application/ld\+json~i', $h) ? ['ok', 'Strukturierte Daten für Google vorhanden'] : ['info', 'Keine strukturierten Daten (Firmenangaben für Google)'];
    $it[] = preg_match('~<meta\b[^>]*property=["\']og:image["\']~i', $h) ? ['ok', 'Vorschaubild für WhatsApp & Social Media'] : ['info', 'Kein Vorschaubild beim Teilen (WhatsApp, Facebook)'];
    if (!preg_match('~<html\b[^>]*\blang=~i', $h)) $it[] = ['info', 'Sprache der Seite nicht angegeben (lang)'];
    // System erkennen
    $sys = '';
    if (preg_match('~<meta\b[^>]*name=["\']generator["\'][^>]*content=["\']([^"\']+)~i', $h, $m)) $sys = lx_cut(lx_plain($m[1]), 60);
    foreach (['wp-content' => 'WordPress', 'jimdo' => 'Jimdo', 'wix.com' => 'Wix', 'squarespace' => 'Squarespace', 'ionos' => 'IONOS Baukasten', 'webflow' => 'Webflow', 'shopify' => 'Shopify'] as $k => $v) if ($sys === '' && stripos($h, $k) !== false) $sys = $v;
    if ($sys !== '') $out['system'] = $sys;
    if (preg_match('~(?:©|&copy;|copyright)\s*(?:\d{4}\s*[-–]\s*)?((?:19|20)\d{2})~iu', $h, $m) && (int)$m[1] < (int)date('Y') - 1) $it[] = ['info', 'Copyright-Jahr im Footer: ' . $m[1] . ' – wirkt veraltet'];
    $out['title'] = $title;
    $out['time'] = $t; $out['kb'] = $kb;
    return $out;
}

function lx_check_counts(array $p): array {
    $n = ['ok' => 0, 'warn' => 0, 'bad' => 0, 'info' => 0];
    foreach ((array)($p['items'] ?? []) as $x) $n[$x[0]] = ($n[$x[0]] ?? 0) + 1;
    return $n;
}
function lx_check_report_text(array $p): string {
    if (empty($p['ok'])) return 'Vorprüfung: ' . ($p['error'] ?? 'nicht möglich') . "\n";
    $sym = ['ok' => '✓', 'warn' => '!', 'bad' => '✗', 'info' => '·'];
    $t = 'Geprüft: ' . ($p['final'] ?? $p['url']) . (!empty($p['system']) ? ' · System: ' . $p['system'] : '') . "\n";
    foreach (['bad', 'warn', 'info', 'ok'] as $lv) foreach ($p['items'] as $x) if ($x[0] === $lv) $t .= '  ' . $sym[$lv] . ' ' . $x[1] . "\n";
    return $t;
}

function lx_check_mails(array $lead, array $c): void {
    $d = $lead['data']; $host = lx_check_host($d['webseite']); $p = (array)($lead['probe'] ?? []);
    $n = lx_check_counts($p);
    $text  = "Neuer Webseiten-Check über loggx.de\n===================================\n\n";
    $text .= "Webseite:        {$d['webseite']}\n";
    $text .= "Name:            {$d['name']}\n";
    $text .= 'Firma:           ' . ($d['firma'] ?: '–') . "\n";
    $text .= "E-Mail:          {$d['email']}\n";
    $text .= 'Gekommen über:   ' . $lead['src'] . (lx_lead_pages()[$lead['page']] ?? '') . " · {$lead['device']}\n";
    $text .= "Versprochen:     Einschätzung innerhalb von {$c['site']['check_time']}\n\n";
    $text .= 'Worauf achten:   ' . ($d['nachricht'] !== '' ? $d['nachricht'] : '–') . "\n\n";
    $text .= "Automatische Vorprüfung (nur für dich" . (!empty($p['ok']) ? ": {$n['bad']} Probleme, {$n['warn']} Hinweise" : '') . ")\n-----------------------------------------------\n";
    $text .= lx_check_report_text($p);
    $text .= "\nIm Admin-Bereich ansehen und antworten: " . lx_abs_url('admin/?tab=anfragen&id=' . $lead['id']) . "\n";
    lx_mail_admin('Webseiten-Check: ' . $host . ' – ' . ($d['firma'] ?: $d['name']), $text, $d['email'], $d['name']);

    if (!empty($c['leads']['autoreply'])) {
        $r  = "Guten Tag {$d['name']},\n\n";
        $r .= "vielen Dank für Ihre Anfrage! Ich schaue mir {$host} persönlich an und schicke Ihnen innerhalb von {$c['site']['check_time']} eine kurze Einschätzung per E-Mail:\n\n";
        $r .= "• Wie gut funktioniert die Seite auf dem Handy?\n• Wie schnell lädt sie?\n• Wird sie bei Google gut gefunden?\n• Finden Besucher schnell, wie sie Sie erreichen?\n• Sind Impressum, Datenschutz und sichere Verbindung in Ordnung?\n\n";
        $r .= "Der Check ist kostenlos und unverbindlich – Sie bekommen danach keine Werbung, nur Ihr Ergebnis.\n\n";
        $r .= "Viele Grüße\nConstantin Moritz Loggen\nLoggX – Webseiten für Unternehmen\n" . lx_abs_url('') . "\n";
        lx_mail($d['email'], 'Ihr Webseiten-Check bei LoggX', $r, (string)$c['site']['email'], 'Constantin Moritz Loggen');
    }
}

/** Vorprüfung im Admin neu starten */
function lx_check_reprobe(string $id): bool {
    $url = ''; foreach (lx_leads() as $x) if ($x['id'] === $id) $url = (string)$x['data']['webseite'];
    if ($url === '') return false;
    $p = lx_check_probe($url);
    lx_locked('leads', function () use ($id, $p) { $l = lx_leads(); foreach ($l as &$x) if ($x['id'] === $id) $x['probe'] = $p; unset($x); lx_save_leads($l); });
    return true;
}
