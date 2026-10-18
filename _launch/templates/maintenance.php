<?php
/** @var array $c  @var ?string $msg  @var string $msgType  @var bool $openCode */
require __DIR__ . '/_layout.php';
$M = $c['maintenance'];
$until = lx_ts($M['until']);
lx_head('LoggX – Wartungsarbeiten', $M['text']);
lx_top('Wartungsarbeiten');
?>
    <style>
      .tools { display: flex; align-items: center; gap: 18px; margin-bottom: 2rem; }
      .gear { width: 64px; height: 64px; color: var(--sun); animation: spin 8s linear infinite; }
      .gear.small { width: 40px; height: 40px; margin-left: -14px; margin-top: 30px; color: rgba(255,255,255,.5); animation-direction: reverse; animation-duration: 5s; }
      @keyframes spin { to { transform: rotate(360deg); } }
      .bar { position: relative; height: 8px; max-width: 420px; border-radius: 8px; background: rgba(255,255,255,.08); overflow: hidden; margin: 0 0 1rem; }
      .bar::after { content: ""; position: absolute; inset: 0; width: 40%; border-radius: inherit; background: linear-gradient(90deg, transparent, var(--sun), transparent); animation: slide 1.8s ease-in-out infinite; }
      @keyframes slide { from { transform: translateX(-100%); } to { transform: translateX(260%); } }
      .eta { color: var(--muted); font-weight: 600; margin: 0 0 1.4rem; }
      .eta strong { color: var(--white); }
      .actions { display: flex; flex-wrap: wrap; gap: 10px; }
    </style>
    <main>
      <div class="tools in" aria-hidden="true">
        <svg class="gear" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
        <svg class="gear small" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3.2"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
      </div>
      <h1 class="in d1"><?= e($M['title']) ?></h1>
      <p class="lead in d2"><?= nl2br(e($M['text'])) ?></p>
      <div class="bar in d3" aria-hidden="true"></div>
      <?php if ($until && $until > time()): ?>
        <p class="eta in d3">Voraussichtlich wieder erreichbar: <strong><?= e(lx_date_de($until)) ?></strong></p>
      <?php endif; ?>
      <div class="actions in d3">
        <a class="btn" href="mailto:<?= e($c['contact_email']) ?>">E-Mail schreiben</a>
        <a class="btn btn-ghost" href="">Erneut versuchen</a>
      </div>
      <?php lx_access_form($msg, $msgType, $openCode); ?>
    </main>
<?php
lx_foot($c);
