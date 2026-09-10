LoggX – Webseite
================

Dateien
  index.html        Startseite (Hero, Leistungen, Referenzen, Ablauf, Preise, Über mich, FAQ, Kontakt)
  impressum.html    Impressum (Vorlage)
  datenschutz.html  Datenschutzerklärung (Vorlage)
  css/style.css     Gesamtes Styling, responsiv bis Smartphone
  js/main.js        Mobile Navigation, aktive Nav-Links, Paketauswahl, Formularprüfung

Vor der Veröffentlichung anpassen
  1. Steuernummer, Telefonnummer und Hoster (gelb markiert) in impressum.html
     und datenschutz.html eintragen.
  2. E-Mail (kontakt@loggx.de) in index.html, impressum.html und js/main.js ersetzen,
     falls abweichend.
  3. Referenzen: Screenshots der beiden Seiten als img/dr-loggen.jpg und
     img/fitness4fun.jpg (Format 16:10) ablegen und in index.html den jeweiligen
     Block <span class="frame-body">…</span> durch
     <img src="img/dr-loggen.jpg" alt="Webseite Zahnarztpraxis Dr. Loggen"> ersetzen.
  4. Portrait: In index.html den Block .portrait-frame durch ein <img> ersetzen.
  5. Kontaktformular: Aktuell öffnet es das E-Mail-Programm (mailto). Für den Versand
     über den Server in js/main.js den mailto-Teil durch einen fetch()-Aufruf an ein
     Formular-Skript (z. B. PHP oder Formspree) ersetzen.

Hochladen
  Alle Dateien und Ordner unverändert in das Web-Verzeichnis des Hosters kopieren.
  Es wird keine Datenbank und kein Framework benötigt.
