/* ==========================================================================
   LoggX – effects.js
   Animationen & Scroll-Verhalten mit GSAP (ScrollTrigger, SplitText) und Lenis.

   Einige Effekte sind Vanilla-JS-Nachbauten von Komponenten aus React Bits
   (ScrollFloat, ScrollReveal, SpotlightCard, Magnet, ShinyText, CountUp,
   RotatingText):
   React Bits – Copyright (c) 2026 David Haz – MIT + Commons Clause
   https://github.com/DavidHDev/react-bits

   Performance-Regeln:
   - nur transform & opacity werden animiert (keine Filter/Blur, keine Masken)
   - kein Pinning, keine Daueranimationen per JavaScript
   - ein einziger Takt (gsap.ticker) für Lenis und ScrollTrigger
   - "Bewegung reduzieren" im System → alles aus, normales Scrollen
   ========================================================================== */
(function () {
  "use strict";

  var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var fine = window.matchMedia("(hover: hover) and (pointer: fine)").matches;
  if (reduce || typeof window.gsap === "undefined") return;

  var gsap = window.gsap;
  var hasST = typeof window.ScrollTrigger !== "undefined";
  var hasSplit = typeof window.SplitText !== "undefined";
  if (hasST) gsap.registerPlugin(window.ScrollTrigger);
  if (hasSplit) gsap.registerPlugin(window.SplitText);
  var ST = window.ScrollTrigger;

  var FX = { active: true, lenis: null };
  window.LoggFX = FX;
  document.documentElement.classList.add("fx");

  /* ---------- Lenis: weiches Scrollen (Mausrad/Trackpad, Touch bleibt nativ) ---------- */
  if (typeof window.Lenis !== "undefined") {
    var lenis = new window.Lenis({ autoRaf: false, lerp: 0.11, wheelMultiplier: 1, smoothWheel: true });
    FX.lenis = lenis;
    gsap.ticker.add(function (time) { lenis.raf(time * 1000); });
    gsap.ticker.lagSmoothing(0);
    if (hasST) lenis.on("scroll", ST.update);
  }

  /* ---------- Magnet (React Bits) – Buttons ziehen leicht zur Maus ---------- */
  if (fine) {
    document.querySelectorAll(".btn-primary, .btn-yellow, .nav-cta, .theme-toggle").forEach(function (el) {
      var x = gsap.quickTo(el, "x", { duration: 0.4, ease: "power3" });
      var y = gsap.quickTo(el, "y", { duration: 0.4, ease: "power3" });
      el.addEventListener("pointermove", function (e) {
        var r = el.getBoundingClientRect();
        x((e.clientX - r.left - r.width / 2) / 4);
        y((e.clientY - r.top - r.height / 2) / 4);
      });
      el.addEventListener("pointerleave", function () {
        gsap.to(el, { x: 0, y: 0, duration: 0.6, ease: "elastic.out(1, .45)" });
      });
    });
  }

  /* ---------- SpotlightCard (React Bits) – Lichtkegel folgt der Maus ---------- */
  if (fine) {
    document.querySelectorAll(".spotlight").forEach(function (card) {
      card.addEventListener("pointermove", function (e) {
        var r = card.getBoundingClientRect();
        card.style.setProperty("--mx", (e.clientX - r.left) + "px");
        card.style.setProperty("--my", (e.clientY - r.top) + "px");
      });
    });
  }

  /* ---------- Unterseiten: nur Lenis + Magnet + kurzer Einstieg ---------- */
  if (!document.getElementById("top")) {
    gsap.from(".legal h1, .legal .check, .legal-back", { y: 30, autoAlpha: 0, duration: 0.9, stagger: 0.08, ease: "power3.out" });
    return;
  }
  if (!hasST) return;

  /* ---------- CountUp (React Bits) ---------- */
  function countUp(el, delay) {
    var to = parseFloat(el.getAttribute("data-to"));
    var de = el.getAttribute("data-format") === "de";
    var o = { v: 0 };
    el.textContent = "0";
    gsap.to(o, {
      v: to, duration: 1.8, delay: delay || 0, ease: "power2.out",
      onUpdate: function () { var n = Math.round(o.v); el.textContent = de ? n.toLocaleString("de-DE") : n; }
    });
  }
  document.querySelectorAll(".hero-facts .count").forEach(function (el, i) { countUp(el, 0.6 + i * 0.12); });
  ST.create({
    trigger: ".pricing", start: "top 75%", once: true,
    onEnter: function () { document.querySelectorAll(".pricing .count").forEach(function (el, i) { countUp(el, 0.2 + i * 0.1); }); }
  });

  /* ---------- RotatingText (React Bits) – Wort wechselt Buchstabe für Buchstabe ---------- */
  var rotator = document.getElementById("rotator");
  if (rotator) {
    var words = Array.prototype.slice.call(rotator.querySelectorAll(".rot-word"));
    words.forEach(function (w) {
      var t = w.textContent;
      w.innerHTML = t.split("").map(function (c) { return '<span class="rc">' + c + "</span>"; }).join("");
      w.classList.remove("is-active", "is-out");
    });
    rotator.classList.add("rot-fx");
    gsap.set(words, { autoAlpha: 0 });
    gsap.set(words[0], { autoAlpha: 1 });
    var wi = 0;
    var next = function () {
      var cur = words[wi];
      wi = (wi + 1) % words.length;
      var nxt = words[wi];
      gsap.to(cur.querySelectorAll(".rc"), { yPercent: -110, duration: 0.45, stagger: 0.025, ease: "power3.in",
        onComplete: function () { gsap.set(cur, { autoAlpha: 0 }); } });
      gsap.set(nxt, { autoAlpha: 1 });
      gsap.fromTo(nxt.querySelectorAll(".rc"), { yPercent: 110 }, { yPercent: 0, duration: 0.6, stagger: 0.03, ease: "back.out(1.6)", delay: 0.25 });
    };
    gsap.delayedCall(2.4, function loop() { next(); gsap.delayedCall(2.6, loop); });
  }

  /* ---------- Hero: Parallaxe beim Scrollen ---------- */
  var heroScrub = { trigger: ".hero", start: "top top", end: "bottom top", scrub: true };
  gsap.to(".phone-1", { yPercent: -10, ease: "none", scrollTrigger: heroScrub });
  gsap.to(".phone-2", { yPercent: -22, ease: "none", scrollTrigger: heroScrub });
  gsap.to(".phone-3", { yPercent: -4, ease: "none", scrollTrigger: heroScrub });
  gsap.to(".hero-x", { rotate: 30, ease: "none", scrollTrigger: heroScrub });

  /* ---------- Einblenden: ScrollTrigger.batch (ein Observer für alles) ---------- */
  var fromVars = function (el) {
    if (el.classList.contains("reveal-left")) return { x: -50, y: 0 };
    if (el.classList.contains("reveal-right")) return { x: 50, y: 0 };
    if (el.classList.contains("reveal-zoom")) return { scale: 0.92, y: 0 };
    return { y: 40 };
  };
  var reveals = gsap.utils.toArray(".reveal");
  reveals.forEach(function (el) { gsap.set(el, Object.assign({ autoAlpha: 0 }, fromVars(el))); });
  ST.batch(reveals, {
    start: "top 88%",
    once: true,
    onEnter: function (batch) {
      gsap.to(batch, {
        autoAlpha: 1, x: 0, y: 0, scale: 1, duration: 1, stagger: 0.09, ease: "power3.out", overwrite: true,
        onComplete: function () {
          batch.forEach(function (el) { gsap.set(el, { clearProps: "transform,opacity,visibility" }); el.classList.add("is-done"); });
        }
      });
      batch.forEach(function (el) { el.classList.add("is-visible"); });
    }
  });

  /* ---------- ScrollFloat (React Bits) – Überschriften Buchstabe für Buchstabe ---------- */
  if (hasSplit) {
    document.querySelectorAll(".section-head h2, .process-head h2, .about-copy h2, .contact-title").forEach(function (h) {
      var split = new window.SplitText(h, { type: "words,chars", charsClass: "sf-char", wordsClass: "sf-word" });
      gsap.fromTo(split.chars,
        { yPercent: 120, scaleY: 2.3, scaleX: 0.7, autoAlpha: 0, transformOrigin: "50% 0%" },
        { yPercent: 0, scaleY: 1, scaleX: 1, autoAlpha: 1, stagger: 0.03, ease: "back.inOut(2)",
          scrollTrigger: { trigger: h, start: "top 92%", end: "top 55%", scrub: 0.6 } });
    });
  }

  /* ---------- ScrollReveal (React Bits) – Text füllt sich Wort für Wort ---------- */
  document.querySelectorAll(".about-text").forEach(function (el) {
    var words;
    if (hasSplit) words = new window.SplitText(el, { type: "words", wordsClass: "sr-word" }).words;
    else return;
    gsap.fromTo(el, { rotate: 2, transformOrigin: "0% 50%" },
      { rotate: 0, ease: "none", scrollTrigger: { trigger: el, start: "top bottom", end: "bottom 60%", scrub: true } });
    gsap.fromTo(words, { opacity: 0.12 },
      { opacity: 1, stagger: 0.05, ease: "none", scrollTrigger: { trigger: el, start: "top 85%", end: "bottom 55%", scrub: true } });
  });

  /* ---------- Ablauf: Linie folgt dem Scrollen ---------- */
  var steps = document.querySelector(".steps");
  if (steps) {
    steps.classList.add("is-visible");
    ST.create({
      trigger: steps, start: "top 70%", end: "bottom 60%", scrub: true,
      onUpdate: function (self) { steps.style.setProperty("--p", self.progress.toFixed(3)); }
    });
  }

  /* ---------- Portrait: leichter Zoom beim Scrollen ---------- */
  gsap.fromTo(".portrait-frame > .portrait-initials, .portrait-frame > .portrait-img", { scale: 1.15 },
    { scale: 1, ease: "none", scrollTrigger: { trigger: ".about-portrait", start: "top bottom", end: "bottom 40%", scrub: true } });

  /* Positionen neu berechnen, sobald Bilder & Schriften geladen sind */
  window.addEventListener("load", function () { ST.refresh(); });
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(function () { ST.refresh(); });
})();
