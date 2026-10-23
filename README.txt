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
  5. Anfrageformular: läuft mit PHP über _launch/api.php (siehe unten "Anfragen").
     Ohne PHP (GitHub Pages) geht die Anfrage über formsubmit.co an die E-Mail-Adresse
     aus dem Kontaktbereich – beim allerersten Mal einmal "Activate" klicken.
  6. Kein Cookie-Hinweis nötig: Die Seite setzt keine Statistik- oder Werbe-Cookies.
     Wer später Google Analytics o. Ä. einbaut, braucht wieder einen Einwilligungs-Hinweis.

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

Werbe-Landingpage: /angebot/
  angebot/index.html – fokussierte Seite für Anzeigen, Flyer, QR-Codes (ein Ziel: Anfrage).
  Aufruf: https://deine-domain.de/angebot/  ·  Anfragen von dort sind im Admin mit
  "(Landingpage)" markiert, so siehst du, woher sie stammen.
  Vor dem Launch / in der Wartung ist sie wie die übrige Seite gesperrt.

Inhalte im Admin ändern (Karte "Webseite: Preise, Kontakt & Hinweise")
  Preise (Visitenkarte, Firmenwebseite, Änderungen & Support pro Stunde), Lieferzeiten, Antwortzeit,
  Telefon, E-Mail (auch Empfänger des Kontaktformulars), Adresse,
  Hinweis über der Überschrift (z. B. "Noch 2 Projektplätze frei") und eine
  gelbe Ankündigungsleiste mit Link. Technik: Elemente mit data-cms="…" in den
  HTML-Dateien werden beim Ausliefern durch gate.php ersetzt. Ohne PHP bleiben
  die Werte aus den HTML-Dateien stehen.

==========================================================================
Anfragen & Statistik (Admin → Übersicht, Anfragen, Statistik)
==========================================================================
Anfrage in 3 Schritten (Startseite und /angebot/)
  1. Vorhaben, Branche, Firma, Ort  2. Umfang, Extras, Start, Budget  3. Kontaktdaten
  - Live-Empfehlung (Visitenkarte, Firmenwebseite, Individuell, Änderungen & Support)
    mit den Preisen aus dem Admin. Dieselbe Logik steht in _launch/leads.php und js/main.js.
  - Priorität heiß/warm/kalt (Start, Budget, Telefon, Nachricht …).
  - E-Mail an dich (Antworten geht direkt an den Kunden) + automatische Bestätigung
    an den Kunden mit Empfehlung (im Admin abschaltbar).
  - Spam-Schutz: verstecktes Feld, Zeitsperre, max. 5 Anfragen pro Stunde und Gerät.
  - "… anfragen"-Buttons bei den Paketen wählen das Paket im Formular vor.
  Admin → Anfragen: Postfach mit Status (Neu → Kontaktiert → Angebot → Auftrag/kein Auftrag),
  Notizen, Antworten per E-Mail/Telefon/WhatsApp, CSV-Export, Löschfrist.
  E-Mail-Versand: unter Anfragen → Einstellungen eine Absender-Adresse deiner Domain
  eintragen (z. B. kontakt@loggx.de) und "Test-E-Mail an mich schicken".

Statistik (ohne Cookies)
  Besucher, Herkunft (auch Instagram/Facebook-App), Kampagnen (?utm_source=…&utm_campaign=…),
  Geräte, Systeme, Browser, Uhrzeit, Wochentage, Scroll-Tiefe pro Abschnitt,
  Klicks (Pakete, Beispiele, Telefon, E-Mail), Weg durchs Formular, Verweildauer.
  Die Übersicht fasst das in kurzen Hinweisen zusammen.

llms.txt (für ChatGPT, Claude, Gemini & Co.)
  https://loggx.de/llms.txt – kurze Beschreibung von LoggX für KI-Assistenten.
  Kommt aus _launch/llms.php, Preise und Kontakt also immer aktuell aus dem Admin.
  Ohne PHP (GitHub Pages) gilt die statische Datei llms.txt im Hauptordner.

Tempo (Komprimierung & Browser-Cache, in der .htaccess)
  CSS/JS bekommen automatisch eine Versionsnummer (?v=Änderungsdatum), Änderungen kommen also sofort an.
  Bilder in img/work/ (.webp) werden 1 Jahr zwischengespeichert: Wer dort ein Bild austauscht,
  sollte einen neuen Dateinamen verwenden. Hero-Handys nutzen kleine Extra-Bilder (*-h.webp).

Finanzen (FinanzKlar, nur für dich)
  Admin → Finanzen öffnet deine Buchhaltungs-App. Sie liegt in _launch/private/finanzklar.html,
  ist per .htaccess gesperrt und wird nur ausgeliefert, wenn du im Admin eingeloggt bist.
  Die Datei ist absichtlich NICHT im Git-Repo (öffentlich) – sie liegt nur im Upload-Ordner.
  Die Buchungen speichert die App im Browser (nicht auf dem Server): regelmäßig ein Backup
  (Einstellungen → Backup exportieren) machen. Bibliotheken/Schriften liegen in admin/finanzen-assets/.

Zwei-Faktor-Anmeldung (Admin → Webseite → Sicherheit)
  Nach dem Passwort zusätzlich ein 6-stelliger Code aus einer Authenticator-App
  (Google/Microsoft Authenticator, 1Password, Bitwarden …). Optional "Diesem Gerät 30 Tage vertrauen".
  8 Wiederherstellungscodes (je einmal nutzbar) für den Fall, dass das Handy weg ist.
  Notfall ohne Handy und ohne Codes: in _launch/data/config.php "totp_on": true auf false setzen
  (per WinSCP), dann geht der Login wieder nur mit Passwort.
