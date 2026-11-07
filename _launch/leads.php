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
    return ['en' => ' (englische Seite)', 'angebot' => ' (Werbe-Landingpage)', 'praxis' => ' (Seite Praxen)', 'studio' => ' (Seite Studios)', 'agentur' => ' (Seite Agenturen)', 'gastro' => ' (Seite Gastronomie)', 'preise' => ' (Preise-Seite)'];
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
    ];
}
function lx_reply_templates(?array $c = null): array {
    $c = $c ?? lx_config();
    $t = (array)($c['leads']['templates'] ?? []);
    return $t ?: lx_reply_defaults();
}
function lx_reply_fill(string $tpl, array $lead): string {
    $d = $lead['data'];
    return strtr($tpl, ['{name}' => (string)$d['name'], '{firma}' => (string)$d['firma'], '{firma_bei}' => $d['firma'] !== '' ? ' für ' . $d['firma'] : '',
        '{paket}' => (string)$lead['rec'], '{ort}' => (string)$d['ort'], '{webseite}' => lx_abs_url('')]);
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

