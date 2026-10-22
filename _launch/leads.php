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
                      'shop' => 'Shop, Buchung oder Sonderfunktion', 'aenderung' => 'Änderung an meiner Seite', 'hosting' => 'Hosting & Technik'],
        'branche' => ['praxis' => 'Praxis & Gesundheit', 'studio' => 'Studio, Beauty & Fitness', 'handwerk' => 'Handwerk & Bau', 'gastro' => 'Gastronomie & Café',
                      'dienst' => 'Dienstleistung & Beratung', 'handel' => 'Handel', 'verein' => 'Verein & Organisation', 'andere' => 'Etwas anderes'],
        'seiten' => ['one' => 'Eine Seite reicht', 'few' => '2 bis 6 Unterseiten', 'many' => 'Mehr als 6 Seiten', 'unsure' => 'Weiß ich noch nicht'],
        'extras' => ['texte' => 'Hilfe bei Texten', 'fotos' => 'Fotos & Bilder', 'logo' => 'Logo & Design', 'google' => 'Google-Profil',
                     'termin' => 'Online-Terminbuchung', 'shop' => 'Onlineshop', 'sprachen' => 'Mehrsprachig', 'hosting' => 'Hosting danach'],
        'start' => ['asap' => 'So schnell wie möglich', 'month' => 'In den nächsten 4 Wochen', 'later' => 'In 1–3 Monaten', 'info' => 'Erst mal informieren'],
        'budget' => ['b1' => 'bis 1.000 €', 'b2' => '1.000–2.500 €', 'b3' => 'über 2.500 €', 'open' => 'Noch offen'],
        'kontakt' => ['mail' => 'E-Mail', 'phone' => 'Telefon', 'whatsapp' => 'WhatsApp'],
    ];
}
function lx_lead_status(): array {
    return ['neu' => 'Neu', 'kontakt' => 'Kontaktiert', 'angebot' => 'Angebot gesendet', 'gewonnen' => 'Auftrag erhalten', 'verloren' => 'Kein Auftrag'];
}

/** Paket-Empfehlung – dieselbe Logik läuft im Formular (main.js) live mit */
function lx_lead_recommend(array $d, array $s): array {
    $p = $d['projekt'] ?? ''; $pages = $d['seiten'] ?? ''; $x = $d['extras'] ?? [];
    $eur = fn($v) => number_format((int)$v, 0, ',', '.') . ' €';
    if ($p === 'hosting') return ['Hosting & Technik', 'Server in Deutschland, Domain, SSL-Zertifikat und tägliche Backups – ab ' . $eur($s['price_hosting']) . ' im Monat.'];
    if ($p === 'aenderung') return ['Änderungen', 'Abrechnung nach Aufwand mit ' . $eur($s['price_hourly']) . ' pro Stunde, in 15-Minuten-Schritten. Kein Abo.'];
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
    $s += ['neu' => 15, 'relaunch' => 15, 'shop' => 15, 'landing' => 12, 'aenderung' => 8, 'hosting' => 8][$d['projekt'] ?? ''] ?? 5;
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
    if ($d['name'] === '') return [false, 'Bitte geben Sie Ihren Namen an.'];
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL)) return [false, 'Bitte geben Sie eine gültige E-Mail-Adresse an.'];
    if (empty($in['consent'])) return [false, 'Bitte bestätigen Sie den Hinweis zum Datenschutz.'];

    $page = ($in['page'] ?? '') === 'angebot' ? 'angebot' : 'start';
    $c = lx_config();
    $site = $page === 'angebot' ? lx_site_for_lp($c['site']) : $c['site'];
    [$rec, $why] = lx_lead_recommend($d, $site);
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    $lead = [
        'id' => bin2hex(random_bytes(6)), 'created' => time(), 'status' => 'neu', 'seen' => false, 'notes' => [], 'data' => $d,
        'rec' => $rec, 'why' => $why, 'score' => lx_lead_score($d), 'page' => $page,
        'src' => lx_cut((string)preg_replace('/[^\w.\-: \/]/u', '', (string)($in['src'] ?? '')), 60) ?: 'direkt',
        'device' => ['m' => 'Handy', 't' => 'Tablet', 'd' => 'Computer'][lx_stats_device($ua)] . ' · ' . lx_stats_os($ua),
    ];
    lx_locked('leads', function () use ($lead) { $l = lx_leads(); $l[] = $lead; lx_save_leads($l); });
    lx_stats_lead();
    lx_lead_mails($lead, $site);
    if (random_int(1, 20) === 1) lx_leads_cleanup();
    return [true, $lead];
}

function lx_lead_branche(array $d): string {
    $o = lx_lead_options();
    return $d['branche'] === 'andere' && $d['branche_text'] !== '' ? $d['branche_text'] : ($o['branche'][$d['branche']] ?? '');
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
    $text .= 'Gekommen über:   ' . $lead['src'] . ($lead['page'] === 'angebot' ? ' (Werbe-Landingpage)' : '') . " · {$lead['device']}\n\n";
    $text .= lx_lead_summary($lead);
    $text .= "\nIm Admin-Bereich ansehen: " . lx_abs_url('admin/?tab=anfragen&id=' . $lead['id']) . "\n";
    lx_mail((string)$c['site']['email'], "Neue Anfrage: $who – $temp, {$lead['rec']}", $text, $d['email'], $d['name']);

    if (!empty($c['leads']['autoreply'])) {
        $how = ['phone' => ' telefonisch', 'whatsapp' => ' per WhatsApp'][$d['kontakt']] ?? '';
        $r  = "Guten Tag {$d['name']},\n\n";
        $r .= 'vielen Dank für Ihre Anfrage! Sie ist gut bei mir angekommen. Ich melde mich innerhalb von ' . (int)$site['response_hours'] . " Stunden$how bei Ihnen.\n\n";
        $r .= "Meine erste Einschätzung: Das Paket „{$lead['rec']}“ passt wahrscheinlich gut zu Ihnen.\n{$lead['why']}\n";
        $r .= "Im kostenlosen Erstgespräch schauen wir gemeinsam, was Sie wirklich brauchen. Danach bekommen Sie ein Angebot zum Festpreis.\n\n";
        $r .= "Ihre Angaben im Überblick:\n--------------------------\n" . lx_lead_summary($lead);
        $r .= "\nViele Grüße\nConstantin Moritz Loggen\nLoggX – Webseiten für Unternehmen\n";
        $phone = trim((string)$site['phone']);
        if ($phone !== '' && $phone !== lx_defaults()['site']['phone']) $r .= "Telefon: $phone\n";
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
