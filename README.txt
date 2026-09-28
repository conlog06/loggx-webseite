LoggX – Webseite
================

Dateien
  index.html        Startseite (Hero, Leistungen, Referenzen, Ablauf, Preise, Über mich, FAQ, Kontakt)
  impressum.html    Impressum (Vorlage)
  danke.html        Bestätigungsseite nach dem Absenden des Formulars
  datenschutz.html  Datenschutzerklärung (Vorlage)
  css/style.css     Gesamtes Styling, responsiv bis Smartphone
  kontakt.php       Server-Skript für den E-Mail-Versand des Formulars
  js/main.js        Mobile Navigation, Bottom-Leiste (Handy), Scroll-Reveal, rotierendes
                    Hero-Wort, Fortschrittsbalken, Paketauswahl, Formularprüfung

Schriftart
  Plus Jakarta Sans (Google Fonts). Zum Tauschen: Link im <head> jeder HTML-Datei
  und die Variablen --font-display / --font-body in css/style.css ändern.
  Rotierende Wörter im Hero: Liste "words" in js/main.js.

Vor der Veröffentlichung anpassen
  1. Steuernummer, Telefonnummer und Hoster (gelb markiert) in impressum.html
     und datenschutz.html eintragen.
  2. E-Mail (constantinloggen@icloud.com) in index.html, impressum.html und js/main.js ersetzen,
     falls abweichend.
  3. Referenzen: Die Karten zeigen automatisch einen Screenshot der Live-Seiten
     (über image.thum.io), solange kein eigenes Bild vorhanden ist. Für die
     Veröffentlichung eigene Screenshots als img/dr-loggen.jpg und
     img/fitness4fun.jpg ablegen (Format 16:10, z. B. 1200×750 px) – dann wird
     kein externer Dienst mehr geladen (besser für den Datenschutz).
     Klick auf eine Karte oder "Live-Vorschau" öffnet die Seite im Popup mit
     Desktop- und Handy-Ansicht. fitness4fun.de erlaubt kein Einbetten – dort wird
     stattdessen ein scrollbarer Ganzseiten-Screenshot gezeigt (automatisch geladen;
     eigene Bilder: img/fitness4fun-de-full.jpg und img/fitness4fun-de-full-mobile.jpg).
     Für andere Referenzen ohne Einbettung einfach data-embed="false" setzen.
  4. Portrait: In index.html den Block .portrait-frame durch ein <img> ersetzen.
  5. Kontaktformular – Anfragen kommen direkt per E-Mail an (ohne Server-Setup):
     a) In js/main.js oben bei CONFIG.contactEmail die eigene Adresse eintragen.
     b) In index.html beim <form ...> im action-Attribut dieselbe Adresse eintragen
        (Fallback, falls JavaScript aus ist).
     c) Seite hochladen, Formular einmal selbst absenden. Es kommt eine
        Aktivierungs-Mail von formsubmit.co – auf "Activate" klicken. Fertig.
        Ab dann landet jede Anfrage als übersichtliche Tabelle im Postfach,
        Antworten geht direkt an den Absender.
     Ablauf: Erst wird im Hintergrund gesendet (Erfolg direkt im Formular).
     Klappt das nicht (z. B. lokal geöffnet oder noch nicht aktiviert), wird das
     Formular klassisch an formsubmit.co übergeben und danach auf danke.html
     zurückgeleitet. Beim allerersten Absenden zeigt formsubmit.co eine
     Aktivierungsseite – Mail bestätigen, ab dann läuft alles.
     Alternative ohne Drittanbieter: CONFIG.formEndpoint = "kontakt.php" setzen
     und in kontakt.php die Adressen eintragen (nur bei Hosting mit PHP).
     Schlägt der Versand fehl, öffnet sich automatisch das Mailprogramm.

  6. Cookie-Hinweis: Erscheint bei jedem Besucher ohne gespeicherte Auswahl (also
     auch wieder, wenn Cookies gelöscht wurden). Statistik-Tools (Google Analytics,
     Matomo) nur in js/main.js in der Funktion applyConsent() einhängen, damit sie
     ausschließlich nach Zustimmung laden. Dann in datenschutz.html Abschnitt 6 ergänzen.

Hochladen
  Alle Dateien und Ordner unverändert in das Web-Verzeichnis des Hosters kopieren.
  Es wird keine Datenbank und kein Framework benötigt.
