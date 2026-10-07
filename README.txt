LoggX – Webseite (Version 3: Animationen (nur transform/opacity – laufen flüssig, auch auf älteren Handys)
  - Hero: Überschrift fährt ein, Wort wechselt, Handys gleiten ein und schweben leicht
  - Abschnitte blenden beim Scrollen ein (teils von links/rechts), gelber Strich unter Überschriften
  - Ablauf: Linie füllt sich, Schritt-Nummern springen auf
  - Preise: Karten heben sich beim Hover, Lichtreflex, Zahlen zählen hoch
  - Designbeispiele: Vorschau scrollt beim Hover durch die ganze Seite
  Besucher mit "Bewegung reduzieren" im System sehen alles ohne Animation.

Dark Mode
  Umschalter (Sonne/Mond) oben rechts auf allen Seiten. Die Wahl wird im Browser
  gespeichert (localStorage "loggx-theme") und gilt auch für Impressum, Datenschutz
  und Danke-Seite. Ohne gespeicherte Wahl folgt die Seite der Systemeinstellung.
  Farben: Variablen unter :root und :root[data-theme="dark"] in css/style.css.

Designbeispiele
  img/work/*.webp       Vorschaubilder (Hero) für die Handys oben
  img/work/full/*.webp  Ganze Seiten (Desktop 1200 px breit, Handy "-m" 390 px)
  Klick öffnet die Großansicht mit Desktop/Handy-Umschalter und Funktionsliste.
  Beschreibung und Funktionen stehen in index.html an jeder <figure class="shot">
  (data-desc, data-features – mit | getrennt).

Live-Vorschau der Referenzen
  data-phone="false" am Link/Button blendet die Handy-Ansicht aus
  (aktuell bei fitness4fun.de, weil der Ganzseiten-Screenshot dort komisch aussieht).

und datenschutz.html eintragen. Telefonnummer auch in index.html (Kontakt).
  2. Portrait: eigenes Foto als img/portrait.jpg ablegen (Hochformat, ca. 900×1100 px).
     Solange es fehlt, werden die Initialen "CL" angezeigt.
  3. Referenzen: eigene Screenshots als img/dr-loggen.jpg und img/fitness4fun.jpg
     (1200×750 px). Solange sie fehlen, kommt ein Screenshot von image.thum.io.
  4. Designbeispiele: Die Bilder in img/work/ sind Entwürfe für fiktive Betriebe.
     Eigene Bilder einfach unter gleichem Namen ersetzen.
  5. Kontaktformular: E-Mail in js/main.js (CONFIG.contactEmail) und im
     action-Attribut des <form> in index.html. Beim ersten Absenden kommt eine
     Aktivierungs-Mail von formsubmit.co – einmal "Activate" klicken.
  6. Cookie-Hinweis: Statistik-Tools nur in js/main.js in applyConsent() einhängen.

Hochladen
  Alle Dateien und Ordner unverändert in das Web-Verzeichnis des Hosters kopieren.
  Keine Datenbank, kein Build-Schritt nötig.
