<?php
/* Gemeinsames Gerüst für Landingpage & Wartungsseite (Markenfarben, lokale Schriften) */
function lx_head(string $title, string $desc): void { ?>
<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= e($title) ?></title>
  <meta name="description" content="<?= e($desc) ?>">
  <meta name="theme-color" content="#0F2540">
  <link rel="icon" href="data:image/svg+xml,%3Csvg viewBox='0 0 512 512' xmlns='http://www.w3.org/2000/svg'%3E%3Crect width='512' height='512' rx='112' fill='%230F2540'/%3E%3Cpath d='M70 375V137H129V375ZM80 375V325H219V375Z' fill='%23fff'/%3E%3Cpath d='M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z' fill='%23FFD166'/%3E%3C/svg%3E">
  <style>
    @font-face { font-family: "Bricolage"; src: url("<?= e(lx_url('fonts/bricolage-grotesque-latin-standard-normal.woff2')) ?>") format("woff2-variations"); font-weight: 200 800; font-stretch: 75% 100%; font-display: swap; }
    @font-face { font-family: "Jakarta"; src: url("<?= e(lx_url('fonts/plus-jakarta-sans-latin-wght-normal.woff2')) ?>") format("woff2-variations"); font-weight: 200 800; font-display: swap; }
    :root { --ink: #0F2540; --ink-2: #16314F; --ink-3: #1F3D63; --sun: #FFD166; --white: #fff; --muted: #A9B8CC; --ease: cubic-bezier(.22,1,.36,1); }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; min-height: 100%; }
    body { min-height: 100svh; display: flex; flex-direction: column; background: var(--ink); color: var(--white); font: 400 1.0625rem/1.6 "Jakarta", system-ui, sans-serif; -webkit-font-smoothing: antialiased; overflow-x: hidden; }
    a { color: inherit; }
    :focus-visible { outline: 3px solid var(--sun); outline-offset: 3px; border-radius: 6px; }
    .bg { position: fixed; inset: 0; pointer-events: none; z-index: 0; overflow: hidden; }
    .bg-grid { position: absolute; inset: 0; background-image: linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px); background-size: 64px 64px; -webkit-mask-image: radial-gradient(ellipse 70% 60% at 50% 40%, #000 20%, transparent 75%); mask-image: radial-gradient(ellipse 70% 60% at 50% 40%, #000 20%, transparent 75%); }
    .bg-x { position: absolute; right: -10vw; top: 50%; width: min(70vw, 760px); transform: translateY(-50%); }
    .bg-x path { stroke: var(--ink-2); stroke-dasharray: 400; stroke-dashoffset: 400; animation: draw 2s var(--ease) .2s forwards; }
    .bg-x path + path { animation-delay: .5s; }
    @keyframes draw { to { stroke-dashoffset: 0; } }
    .wrap { position: relative; z-index: 1; flex: 1; width: 100%; max-width: 1100px; margin: 0 auto; padding: 28px clamp(16px, 4vw, 40px); display: flex; flex-direction: column; }
    .top { display: flex; justify-content: space-between; align-items: center; gap: 16px; }
    .brand { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; font: 800 1.45rem/1 "Bricolage", sans-serif; letter-spacing: -0.03em; }
    .brand svg { width: 34px; height: 34px; }
    .pill { display: inline-flex; align-items: center; gap: 8px; padding: 7px 14px; border-radius: 999px; background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12); font-size: .88rem; font-weight: 600; }
    .pill i { width: 8px; height: 8px; border-radius: 50%; background: var(--sun); box-shadow: 0 0 0 0 rgba(255,209,102,.6); animation: pulse 2s infinite; }
    @keyframes pulse { 70% { box-shadow: 0 0 0 10px rgba(255,209,102,0); } 100% { box-shadow: 0 0 0 0 rgba(255,209,102,0); } }
    main { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: clamp(40px, 8vh, 90px) 0; }
    .kicker { color: var(--sun); font-weight: 700; margin: 0 0 1rem; }
    h1 { font: 800 clamp(2.6rem, 7vw, 5.4rem)/.95 "Bricolage", sans-serif; letter-spacing: -0.04em; margin: 0 0 1.4rem; max-width: 14ch; }
    .lead { font-size: clamp(1.05rem, 1.6vw, 1.25rem); color: var(--muted); max-width: 46ch; margin: 0 0 2.2rem; }
    .in { animation: up .9s var(--ease) both; } .d1 { animation-delay: .08s; } .d2 { animation-delay: .16s; } .d3 { animation-delay: .24s; } .d4 { animation-delay: .32s; }
    @keyframes up { from { opacity: 0; transform: translateY(24px); } }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 1rem 1.5rem; border: 0; border-radius: 999px; background: var(--sun); color: var(--ink); font: 700 1rem/1 "Jakarta", sans-serif; cursor: pointer; text-decoration: none; transition: transform .3s var(--ease), box-shadow .3s; white-space: nowrap; }
    .btn:hover { transform: translateY(-2px); box-shadow: 0 14px 30px -12px rgba(255,209,102,.6); }
    .btn-ghost { background: transparent; color: var(--white); box-shadow: inset 0 0 0 2px rgba(255,255,255,.35); }
    .btn-ghost:hover { box-shadow: inset 0 0 0 2px var(--sun); }
    input[type=email], input[type=text], input[type=password] { width: 100%; padding: 1rem 1.1rem; border-radius: 14px; border: 1.5px solid rgba(255,255,255,.16); background: rgba(255,255,255,.06); color: var(--white); font: 500 1rem/1.3 "Jakarta", sans-serif; transition: border-color .3s, background .3s; }
    input::placeholder { color: #8293A9; }
    input:focus { outline: none; border-color: var(--sun); background: rgba(255,255,255,.09); }
    .msg { margin: 0 0 1rem; padding: .8rem 1rem; border-radius: 12px; font-size: .95rem; font-weight: 600; max-width: 560px; }
    .msg.error { background: rgba(255,138,142,.12); color: #FFB4B6; border: 1px solid rgba(255,138,142,.3); }
    .msg.ok { background: rgba(31,166,122,.14); color: #8FE3C3; border: 1px solid rgba(31,166,122,.35); }
    details.access { margin-top: 2rem; max-width: 560px; }
    details.access summary { cursor: pointer; color: var(--muted); font-weight: 600; font-size: .95rem; list-style: none; display: inline-flex; gap: 8px; align-items: center; }
    details.access summary::-webkit-details-marker { display: none; }
    details.access summary::before { content: "→"; color: var(--sun); transition: transform .3s var(--ease); }
    details.access[open] summary::before { transform: rotate(90deg); }
    details.access form { display: flex; gap: 10px; margin-top: 12px; animation: up .5s var(--ease) both; }
    footer { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 10px 24px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,.08); color: var(--muted); font-size: .9rem; }
    footer nav { display: flex; gap: 18px; } footer a { text-decoration: none; font-weight: 600; color: var(--white); } footer a:hover { color: var(--sun); }
    @media (max-width: 600px) { details.access form { flex-direction: column; } }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } .bg-x path { stroke-dashoffset: 0; } }
  </style>
</head>
<body>
  <div class="bg" aria-hidden="true">
    <div class="bg-grid"></div>
    <svg class="bg-x" viewBox="0 0 400 400" fill="none"><path d="M60 60 L340 340" stroke-width="64" stroke-linecap="round"/><path d="M340 60 L60 340" stroke-width="64" stroke-linecap="round"/></svg>
  </div>
  <div class="wrap">
<?php }

function lx_top(string $pill): void { ?>
    <header class="top in">
      <a class="brand" href="<?= e(lx_url('')) ?>" aria-label="LoggX">
        <svg viewBox="0 0 512 512" aria-hidden="true"><rect width="512" height="512" rx="112" fill="#FFD166"/><path d="M70 375V137H129V375ZM80 375V325H219V375Z" fill="#0F2540"/><path d="M225 375 294 257 225 137H294L332 226H333L370 137H442L372 256L442 375H370L333 284H333L294 375Z" fill="#0F2540"/></svg>LoggX
      </a>
      <span class="pill"><i></i><?= e($pill) ?></span>
    </header>
<?php }

function lx_access_form(?string $msg, string $type, bool $open): void { ?>
      <details class="access in d4"<?= $open ? ' open' : '' ?>>
        <summary>Sie haben einen Zugangscode?</summary>
        <?php if ($msg && $open && $type === 'error'): ?><p class="msg error" role="alert" style="margin-top:12px"><?= e($msg) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="action" value="code">
          <label for="code" style="position:absolute;left:-9999px">Zugangscode</label>
          <input type="text" id="code" name="code" placeholder="z. B. ABCD-EFGH oder Passwort" required>
          <button class="btn btn-ghost" type="submit">Seite öffnen</button>
        </form>
      </details>
<?php }

function lx_foot(array $c): void { ?>
    <footer class="in d4">
      <span>© <?= date('Y') ?> LoggX · <a href="mailto:<?= e($c['contact_email']) ?>"><?= e($c['contact_email']) ?></a></span>
      <nav aria-label="Rechtliches"><a href="<?= e(lx_url('impressum.html')) ?>">Impressum</a><a href="<?= e(lx_url('datenschutz.html')) ?>">Datenschutz</a></nav>
    </footer>
  </div>
</body>
</html>
<?php }
