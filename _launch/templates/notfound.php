<?php
/** Eigene Fehlerseite „Seite nicht gefunden“ (404) – auch für Adressen, die der Server selbst nicht kennt */
require_once __DIR__ . '/_layout.php';
$c = $c ?? lx_config();
lx_head('Seite nicht gefunden – LoggX', 'Diese Seite gibt es nicht (mehr).');
lx_top('Fehler 404');
?>
    <style>
      .links { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; max-width: 760px; margin-top: 2.4rem; }
      .links a { display: block; padding: 18px 20px; border-radius: 16px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.12); text-decoration: none; transition: border-color .3s, transform .3s var(--ease); }
      .links a:hover { border-color: var(--sun); transform: translateY(-2px); }
      .links b { display: block; font: 700 1.05rem "Jakarta", sans-serif; } .links small { color: var(--muted); }
      .actions { display: flex; flex-wrap: wrap; gap: 10px; }
    </style>
    <main>
      <p class="kicker in">Hoppla – hier ist nichts.</p>
      <h1 class="in d1">Diese Seite gibt es nicht.</h1>
      <p class="lead in d2">Vielleicht hat sich die Adresse geändert oder es war ein Tippfehler. Hier geht es weiter:</p>
      <div class="actions in d3"><a class="btn" href="<?= e(lx_url('')) ?>">Zur Startseite</a><a class="btn btn-ghost" href="<?= e(lx_url('#kontakt')) ?>">Projekt anfragen</a></div>
      <nav class="links in d4" aria-label="Beliebte Seiten">
        <a href="<?= e(lx_url('preise')) ?>"><b>Preise</b><small>Pakete zum Festpreis</small></a>
        <a href="<?= e(lx_url('#beispiele')) ?>"><b>Designbeispiele</b><small>So könnte Ihre Seite aussehen</small></a>
        <a href="<?= e(lx_url('#referenzen')) ?>"><b>Referenzen</b><small>Webseiten, die online sind</small></a>
        <a href="<?= e(lx_url('webseite-praxis')) ?>"><b>Für Ihre Branche</b><small>Praxis, Studio, Agentur, Gastro</small></a>
      </nav>
    </main>
<?php
lx_foot($c);
