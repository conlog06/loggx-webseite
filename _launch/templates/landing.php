<?php
/** @var array $c  @var ?string $msg  @var string $msgType  @var bool $openCode */
require __DIR__ . '/_layout.php';
$L = $c['landing'];
$launch = lx_ts($c['launch_at']);
lx_head('LoggX – ' . $L['kicker'], $L['text']);
lx_top($launch ? 'Launch am ' . date('d.m.Y', $launch) : 'Launch in Vorbereitung');
?>
    <style>
      .count { display: flex; gap: clamp(10px, 2vw, 18px); margin: 0 0 2.4rem; }
      .unit { min-width: clamp(70px, 12vw, 112px); padding: 16px 10px 12px; border-radius: 18px; background: rgba(255,255,255,.06); border: 1px solid rgba(255,255,255,.1); text-align: center; }
      .num { display: block; height: 1em; overflow: hidden; font: 800 clamp(2.2rem, 5.5vw, 3.6rem)/1 "Bricolage", sans-serif; letter-spacing: -0.03em; font-variant-numeric: tabular-nums; color: var(--sun); }
      .num span { display: block; }
      .num span.flip { animation: flip .5s var(--ease); }
      @keyframes flip { from { transform: translateY(-100%); opacity: 0; } }
      .lbl { display: block; margin-top: 8px; font-size: .82rem; font-weight: 600; color: var(--muted); }
      .when { color: var(--muted); font-weight: 600; margin: -1.2rem 0 2.2rem; font-size: .95rem; }
      .notify { display: flex; gap: 10px; max-width: 560px; }
      .consent { display: flex; gap: 10px; align-items: flex-start; margin-top: 12px; max-width: 560px; font-size: .88rem; color: var(--muted); }
      .consent input { margin-top: 3px; width: 18px; height: 18px; accent-color: var(--sun); flex: none; }
      .consent a { color: var(--sun); }
      .hp { position: absolute; left: -9999px; }
      @media (max-width: 600px) { .notify { flex-direction: column; } .unit { min-width: 0; flex: 1; } }
    </style>
    <main>
      <p class="kicker in"><?= e($L['kicker']) ?></p>
      <h1 class="in d1"><?= e($L['title']) ?></h1>
      <p class="lead in d2"><?= nl2br(e($L['text'])) ?></p>

      <?php if ($launch && $launch > time()): ?>
      <div class="count in d3" id="count" data-target="<?= $launch * 1000 ?>" role="timer" aria-label="Zeit bis zum Launch">
        <div class="unit"><span class="num" data-k="d"><span>00</span></span><span class="lbl">Tage</span></div>
        <div class="unit"><span class="num" data-k="h"><span>00</span></span><span class="lbl">Stunden</span></div>
        <div class="unit"><span class="num" data-k="m"><span>00</span></span><span class="lbl">Minuten</span></div>
        <div class="unit"><span class="num" data-k="s"><span>00</span></span><span class="lbl">Sekunden</span></div>
      </div>
      <p class="when in d3">Start: <?= e(lx_date_de($launch)) ?></p>
      <?php endif; ?>

      <?php if ($msg && !$openCode): ?><p class="msg <?= e($msgType) ?>" role="status"><?= e($msg) ?></p><?php endif; ?>

      <?php if ($L['signup'] && !($msg && $msgType === 'ok' && !$openCode)): ?>
      <form class="in d4" method="post">
        <input type="hidden" name="action" value="notify">
        <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
        <div class="notify">
          <label for="email" style="position:absolute;left:-9999px">E-Mail-Adresse</label>
          <input type="email" id="email" name="email" placeholder="Ihre E-Mail-Adresse" required autocomplete="email">
          <button class="btn" type="submit">Benachrichtigen</button>
        </div>
        <label class="consent"><input type="checkbox" name="consent" value="1" required>
          <span>Ich möchte einmalig per E-Mail informiert werden, wenn die Seite online ist. Details in der <a href="<?= e(lx_url('datenschutz')) ?>" target="_blank" rel="noopener">Datenschutzerklärung</a>.</span></label>
      </form>
      <?php endif; ?>

      <?php lx_access_form($msg, $msgType, $openCode); ?>
    </main>
    <script>
      (function () {
        var box = document.getElementById("count");
        if (!box) return;
        var target = +box.getAttribute("data-target");
        var els = {}; box.querySelectorAll(".num").forEach(function (n) { els[n.getAttribute("data-k")] = n; });
        var last = {}, ready = false;
        function set(k, v) {
          v = (v < 10 ? "0" : "") + v;
          if (last[k] === v) return;
          last[k] = v;
          var s = document.createElement("span"); s.textContent = v;
          if (ready) s.className = "flip";
          els[k].replaceChildren(s);
        }
        function tick() {
          var ms = Math.max(0, target - Date.now());
          var t = Math.floor(ms / 1000);
          set("d", Math.floor(t / 86400)); set("h", Math.floor(t % 86400 / 3600)); set("m", Math.floor(t % 3600 / 60)); set("s", t % 60);
          ready = true;
          if (ms <= 0) { setTimeout(function () { location.reload(); }, 1500); return; }
          setTimeout(tick, 1000 - (Date.now() % 1000));
        }
        tick();
      })();
    </script>
<?php
lx_foot($c);

