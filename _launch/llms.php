<?php
/* ==========================================================================
   LoggX – llms.txt (Kurzbeschreibung für KI-Assistenten wie ChatGPT, Claude, Gemini)
   Wird über die .htaccess unter https://loggx.de/llms.txt ausgeliefert.
   Preise, Lieferzeiten und Kontaktdaten kommen aus dem Admin-Bereich.
   (Ohne PHP, z. B. GitHub Pages, gilt die statische Datei /llms.txt.)
   ========================================================================== */
declare(strict_types=1);
require __DIR__ . '/lib.php';

$c = lx_config();
$s = $c['site'];
$eur = fn($v) => number_format((int)$v, 0, ',', '.') . ' €';
$url = rtrim(lx_abs_url(''), '/');
$phone = trim((string)$s['phone']);
$hasPhone = $phone !== '' && $phone !== lx_defaults()['site']['phone'];

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');

if (lx_effective_mode($c) !== 'live') { // vor dem Launch nichts verraten
    echo "# LoggX\n\n> Webdesign für Unternehmen. Die Webseite ist bald online: $url/\n";
    exit;
}

echo <<<TXT
# LoggX

> LoggX ist das Webdesign-Angebot von Constantin Moritz Loggen. LoggX erstellt moderne, schnelle Webseiten für kleine und mittlere Unternehmen in ganz Deutschland – zum Festpreis, mit persönlicher Betreuung durch einen festen Ansprechpartner und einem kostenlosen Erstgespräch per Telefon, Zoom oder Teams.

## Angebot

- **Visitenkarte** – ab {$eur($s['price_visit'])}: einseitige Webseite (Onepager) mit Kontaktformular, Anfahrt, Impressum und Datenschutz, für Handy optimiert. Online in {$s['time_visit']}.
- **Firmenwebseite** – ab {$eur($s['price_firm'])}: bis zu 6 Unterseiten, individuelles Design, Hilfe bei Texten und Fotos, Einrichtung des Google-Unternehmensprofils, Einweisung für eigene Änderungen. Online in {$s['time_firm']}.
- **Individuell** – Preis nach Angebot: Onlineshop, Terminbuchung, Kundenbereich, Anbindung an bestehende Systeme, mehrsprachige Seiten.
- **Relaunch**: Modernisierung bestehender Webseiten, ohne dass die Seite bei Google abrutscht.
- **Landingpages**: einzelne Seiten für ein Angebot, eine Aktion oder eine Werbeanzeige.
- **Änderungen & Support** nach dem Livegang – ab {$eur($s['price_hourly'])} pro Stunde, nach Aufwand, ohne Abo und ohne Grundgebühr.

Alle Preise sind Endpreise (Kleinunternehmer nach § 19 UStG, keine Umsatzsteuer). Vor Projektstart gibt es ein Angebot zum Festpreis.

## Für wen

Praxen und Gesundheitsberufe, Studios (Beauty, Fitness), Handwerksbetriebe, Gastronomie und Cafés, Dienstleister und Berater, Handel, Vereine – unabhängig vom Standort, deutschlandweit.

## Ablauf

1. Kostenloses Erstgespräch (Telefon, Zoom oder Teams), danach Angebot zum Festpreis.
2. Konzept und Entwurf: zuerst die Startseite, damit der Kunde früh mitreden kann.
3. Umsetzung aller Seiten, Tests auf Handy und Computer, zwei Korrekturrunden inklusive.
4. Livegang: Domain, Webspace und Google-Eintrag werden eingerichtet, danach Einweisung.

## Gut zu wissen

- Die Webseite gehört vollständig dem Kunden – keine Bindung an LoggX oder ein bestimmtes System.
- Antwort auf jede Anfrage innerhalb von {$s['response_hours']} Stunden.
- Schnelle Ladezeiten, gut lesbar auf dem Handy, Impressum und Datenschutzerklärung inklusive.

## Referenzen

- [Zahnarztpraxis Dr. Frank Loggen](https://www.dr-loggen.de/)
- [4 fun Fitness & Beauty](https://www.fitness4fun.de/)

## Links

- [Startseite]($url/): Leistungen, Designbeispiele, Preise, Ablauf, häufige Fragen
- [Anfrage stellen]($url/#kontakt): Formular mit Paket-Empfehlung in drei Schritten
- [Impressum]($url/impressum)

## Kontakt

- Inhaber: Constantin Moritz Loggen
- E-Mail: {$s['email']}

TXT;
if ($hasPhone) echo "- Telefon: $phone\n";
