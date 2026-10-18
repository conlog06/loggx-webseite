LoggX – Webseite (Version 3: Animationen & Scroll-Verhalten (js/effects.js)
  - Lenis: weiches Scrollen mit Mausrad/Trackpad, auf Handys bleibt natives Scrollen
  - GSAP ScrollTrigger: Abschnitte gleiten ein (ein gemeinsamer Observer per batch),
    Hero-Parallaxe, Ablauf-Linie folgt dem Scrollen, Portrait-Zoom
  - Nachbauten aus React Bits (ohne React, als Vanilla-JS):
      ScrollFloat   Überschriften fallen Buchstabe für Buchstabe herein
      ScrollReveal  "Über mich"-Text füllt sich Wort für Wort
      RotatingText  wechselndes Wort im Hero, Buchstabe für Buchstabe
      CountUp       Zahlen im Hero und bei den Preisen
      Magnet        Buttons ziehen leicht zur Maus
      SpotlightCard Lichtkegel folgt der Maus (Preise, Berater, Formular)
      ShinyText     Glanz über "Webdesign aus Osnabrück"
    React Bits – Copyright (c) 2026 David Haz – MIT + Commons Clause
  - Nur transform/opacity, keine Blur-Filter, kein Pinning → ruckelfrei
  - Fällt GSAP aus oder ist "Bewegung reduzieren" aktiv, läuft die Seite ohne
    diese Effekte normal weiter (main.js übernimmt einfache Einblendungen).

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

==========================================================================
Launch-System: Landingpage, Wartungsseite, Zugänge (PHP, z. B. IONOS)
==========================================================================
Dateien
  .htaccess              leitet alle HTML-Seiten über _launch/gate.php
  _launch/gate.php       entscheidet: echte Seite, Landingpage oder Wartungsseite
  _launch/templates/     Aussehen von Landingpage und Wartungsseite
  _launch/data/          gespeicherte Einstellungen (wird automatisch angelegt,
                         per .htaccess gesperrt, nicht im Git-Repo)
  admin/                 Admin-Bereich: https://deine-domain.de/admin/

Einrichtung (einmalig)
  1. Alle Dateien per FTP/SFTP in das Web-Verzeichnis bei IONOS hochladen
     (die Datei .htaccess ist versteckt – mit hochladen!).
     PHP-Version im IONOS-Kundenbereich auf 8.1 oder neuer stellen.
  2. https://deine-domain.de/admin/ öffnen. Die Seite legt einen Einrichtungscode an.
  3. Den Code in der Datei _launch/data/einrichtungscode.php nachsehen
     (IONOS Webspace-Explorer oder FTP-Programm), eintragen, Admin-Passwort festlegen.
     Die Code-Datei wird danach automatisch gelöscht.

Modi (im Admin umschaltbar)
  Vor dem Launch     Landingpage mit Countdown und E-Mail-Benachrichtigung.
                     Zum eingestellten Zeitpunkt geht die Seite automatisch online.
  Wartungsarbeiten   Wartungsseite (HTTP 503, Suchmaschinen behalten den Eintrag),
                     optional mit "voraussichtlich bis".
  Öffentlich         normale Webseite.
  Impressum, Datenschutz und Danke-Seite sind immer erreichbar.

Zugriff vor dem Launch / während der Wartung
  - Du als Admin (eingeloggt) siehst immer die echte Seite, mit Hinweis-Leiste unten.
  - Persönliche Zugänge: im Admin Namen eintragen → Link kopieren und verschicken
    (https://deine-domain.de/?zugang=ABCD-EFGH). Sperren/Löschen wirkt sofort.
    Der Code kann auch auf der Landingpage unter "Sie haben einen Zugangscode?" eingegeben werden.
  - Optional ein gemeinsames Vorschau-Passwort für alle.

Hinweis
  Bilder, CSS und JavaScript bleiben direkt abrufbar (nur die HTML-Seiten sind gesperrt).
  Läuft die Seite ohne PHP (z. B. GitHub Pages), ist das Launch-System wirkungslos
  und die Webseite einfach öffentlich.

Testen auf dem eigenen Rechner
  PHP installieren (Windows: https://windows.php.net/download oder XAMPP), dann im
  Ordner der Webseite:  php -S localhost:8000 _launch/testserver.php
  Browser: http://localhost:8000 (Seite) und http://localhost:8000/admin/ (Admin)
