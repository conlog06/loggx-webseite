/* ==========================================================================
   LoggX – main.js
   1) Grundfunktionen (Navigation, Cookie, Vorschau, Anfrage in 3 Schritten, Statistik)
   2) Animationen (GSAP + ScrollTrigger + Lenis, alles lokal in js/vendor)
   ========================================================================== */

(function () {
  "use strict";

  var root = document.documentElement;
  var body = document.body;
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Jahr im Footer ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- Hell/Dunkel-Modus (wird im Browser gespeichert) ---------- */
  var themeBtn = document.getElementById("theme-toggle");
  function applyThemeLabel() {
    var dark = root.getAttribute("data-theme") === "dark";
    if (themeBtn) themeBtn.setAttribute("aria-label", dark ? "Hellen Modus einschalten" : "Dunkelmodus einschalten");
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute("content", dark ? "#0A1628" : "#0F2540");
  }
  if (themeBtn) {
    applyThemeLabel();
    themeBtn.addEventListener("click", function () {
      var next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
      if (!reduceMotion) root.classList.add("theme-anim");
      root.setAttribute("data-theme", next);
      try { localStorage.setItem("loggx-theme", next); } catch (e) {}
      applyThemeLabel();
      setTimeout(function () { root.classList.remove("theme-anim"); }, 500);
    });
  }

  /* Animations-Modul (js/effects.js mit GSAP + Lenis) aktiv? */
  var FX = window.LoggFX && window.LoggFX.active ? window.LoggFX : null;
  function lockScroll(on) {
    body.style.overflow = on ? "hidden" : "";
    if (FX && FX.lenis) { if (on) FX.lenis.stop(); else FX.lenis.start(); }
  }

  /* ---------- Hilfsfunktion: sanft zu einem Ziel scrollen ---------- */
  function scrollToTarget(target) {
    var header = document.getElementById("site-header");
    var offset = header ? -header.offsetHeight + 1 : 0;
    if (target.id === "top") offset = 0;
    if (FX && FX.lenis) { FX.lenis.start(); FX.lenis.scrollTo(target, { offset: 0, duration: 1.3, force: true }); return; } // Lenis berücksichtigt scroll-padding-top bereits
    window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset + offset, behavior: reduceMotion ? "auto" : "smooth" });
  }

  /* Alle internen Links (auch nachträglich erzeugte, z. B. in der Großansicht) */
  document.addEventListener("click", function (e) {
    var a = e.target.closest('a[href^="#"]');
    if (!a || e.defaultPrevented) return;
    var id = a.getAttribute("href");
    if (id.length < 2) return;
    var target = document.querySelector(id);
    if (!target) return;
    e.preventDefault();
    // offenes Handy-Menü zuerst schließen, sonst ist das Scrollen gesperrt
    if (body.classList.contains("nav-open")) setNav(false);
    scrollToTarget(target);
    history.replaceState(null, "", id);
  });

  /* ---------- Mobile Navigation ---------- */
  var toggle = document.getElementById("nav-toggle");
  var nav = document.getElementById("site-nav");
  function setNav(open) {
    var hdr = document.getElementById("site-header");
    if (open && hdr) hdr.classList.remove("is-hidden");
    nav.classList.toggle("is-open", open);
    body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", String(open));
    toggle.setAttribute("aria-label", open ? "Menü schließen" : "Menü öffnen");
    lockScroll(open);
  }
  if (toggle && nav) {
    toggle.addEventListener("click", function () { setNav(!nav.classList.contains("is-open")); });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && nav.classList.contains("is-open")) { setNav(false); toggle.focus(); }
    });
  }

  /* ---------- Aktiver Link (Header + Mobile-Leiste) ---------- */
  var sections = document.querySelectorAll("main section[id]");
  var navLinks = document.querySelectorAll(".site-nav a[href^='#'], .mobile-bar a[href^='#']");
  if ("IntersectionObserver" in window && sections.length && navLinks.length) {
    var navObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var id = entry.target.getAttribute("id");
        if (id === "galerie") id = "beispiele";
        navLinks.forEach(function (link) { link.classList.toggle("is-active", link.getAttribute("href") === "#" + id); });
      });
    }, { rootMargin: "-45% 0px -50% 0px" });
    sections.forEach(function (s) { navObserver.observe(s); });
  }

  /* ---------- Header: ein-/ausblenden beim Scrollen + Fortschritt ---------- */
  var header = document.getElementById("site-header");
  var progress = document.getElementById("scroll-progress");
  var lastY = 0;
  function onScroll() {
    var y = window.pageYOffset;
    if (header) {
      header.classList.toggle("is-scrolled", y > 30);
      var hide = y > lastY && y > 400 && !body.classList.contains("nav-open");
      header.classList.toggle("is-hidden", hide);
    }
    if (progress) {
      var max = document.documentElement.scrollHeight - window.innerHeight;
      progress.style.transform = "scaleX(" + (max > 0 ? y / max : 0) + ")";
    }
    lastY = y;
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- Rotierendes Wort im Hero ---------- */
  var rotator = document.getElementById("rotator");
  if (rotator && !reduceMotion && !FX) {
    var words = rotator.querySelectorAll(".rot-word");
    var wi = 0;
    setInterval(function () {
      var cur = words[wi];
      wi = (wi + 1) % words.length;
      var next = words[wi];
      cur.classList.remove("is-active"); cur.classList.add("is-out");
      next.classList.remove("is-out");
      // Ausgangsposition unten erzwingen, dann einfahren
      next.style.transition = "none"; next.style.transform = "translateY(105%)";
      void next.offsetWidth;
      next.style.transition = ""; next.style.transform = "";
      next.classList.add("is-active");
      setTimeout(function () { cur.classList.remove("is-out"); cur.style.transition = "none"; void cur.offsetWidth; cur.style.transition = ""; }, 800);
    }, 2400);
  }

  /* ---------- Landingpage: fester Handy-Button verschwindet beim Formular ---------- */
  var sticky = document.getElementById("lp-sticky");
  var anfrage = document.getElementById("anfrage");
  if (sticky && anfrage && "IntersectionObserver" in window) {
    new IntersectionObserver(function (entries) {
      sticky.classList.toggle("is-hidden", entries[0].isIntersecting);
    }, { rootMargin: "0px 0px -20% 0px" }).observe(anfrage);
  }

  /* ---------- Referenz-Screenshots: lokales Bild, sonst automatischer Screenshot ---------- */
  document.querySelectorAll(".frame-shot").forEach(function (img) {
    img.addEventListener("error", function () {
      var fallback = img.getAttribute("data-fallback");
      if (fallback && img.src !== fallback) img.src = fallback;
      else img.classList.add("is-missing");
    });
  });

  /* ---------- Portrait: eigenes Foto, sonst Initialen ---------- */
  var portrait = document.getElementById("portrait-img");
  if (portrait && portrait.getAttribute("src")) {
    var markMissing = function () { portrait.classList.add("is-missing"); };
    portrait.addEventListener("error", markMissing);
    portrait.addEventListener("load", function () { portrait.classList.remove("is-missing"); });
    if (portrait.complete) portrait.classList.toggle("is-missing", portrait.naturalWidth === 0);
  }

  /* ---------- Ohne Server-Aufbereitung (z. B. GitHub Pages): Platzhalter ausblenden ---------- */
  if (!document.documentElement.hasAttribute("data-cms-live")) {
    document.querySelectorAll('[data-cms="phone"]').forEach(function (el) {
      if (!/000 000/.test(el.textContent)) return;
      var box = el.closest("[data-phone-line], a[data-cms-href='tel']");
      (box || el).parentNode.removeChild(box || el);
    });
    document.querySelectorAll('[data-cms-if="tax_number"], [data-cms-if="stats_text"], [data-cms-if="stats_on"]').forEach(function (el) { el.parentNode.removeChild(el); });
    if (/\.github\.io$/.test(location.hostname)) {
      document.querySelectorAll('[data-cms="hoster"]').forEach(function (el) {
        el.textContent = "GitHub Inc. (GitHub Pages), 88 Colin P. Kelly Jr. Street, San Francisco, CA 94107, USA";
        el.classList.remove("placeholder");
      });
    }
    if (!document.querySelector(".placeholder")) document.querySelectorAll("[data-placeholder-note]").forEach(function (el) { el.parentNode.removeChild(el); });
  }

  /* ---------- Live-Vorschau (Popup mit Desktop/Handy-Ansicht) ---------- */
  var preview = document.getElementById("preview");
  if (preview) {
    var frame = document.getElementById("preview-frame");
    var loader = document.getElementById("preview-loader");
    var stage = document.getElementById("preview-stage");
    var titleEl = document.getElementById("preview-title");
    var urlEl = document.getElementById("preview-url");
    var openEl = document.getElementById("preview-open");
    var noteEl = document.getElementById("preview-note");
    var shotBox = document.getElementById("preview-shot");
    var shotImg = document.getElementById("preview-shot-img");
    var shotErr = document.getElementById("preview-shot-error");
    var lastFocus = null;
    var current = { url: "", embed: true };
    var loaderTimer;

    var shotSources = function (url, device) {
      var slug = url.replace(/^https?:\/\/(www\.)?/, "").replace(/[^a-z0-9]+/gi, "-").replace(/-$/, "").toLowerCase();
      var local = "img/" + slug + (device === "phone" ? "-full-mobile.jpg" : "-full.jpg");
      var remote = device === "phone"
        ? "https://image.thum.io/get/fullpage/width/390/viewportWidth/390/" + url
        : "https://image.thum.io/get/fullpage/width/1200/" + url;
      return [local, remote];
    };
    var loadShot = function (device) {
      var sources = shotSources(current.url, device);
      var i = 0;
      shotErr.hidden = true; shotImg.hidden = false;
      loader.classList.remove("is-hidden");
      shotImg.onload = function () { loader.classList.add("is-hidden"); };
      shotImg.onerror = function () {
        i += 1;
        if (i < sources.length) shotImg.src = sources[i];
        else { shotImg.hidden = true; shotErr.hidden = false; loader.classList.add("is-hidden"); }
      };
      shotImg.src = sources[0];
      shotBox.scrollTop = 0;
    };
    // Handy-Ansicht auf kleinen Bildschirmen: 390-px-Gerät maßstabsgetreu verkleinern statt abschneiden
    var fitDevice = function () {
      var dev = stage.querySelector(".device");
      dev.style.transform = ""; dev.style.width = ""; dev.style.height = ""; dev.style.maxWidth = ""; dev.style.alignSelf = ""; dev.style.justifySelf = "";
      if (stage.getAttribute("data-device") !== "phone") return;
      var cs = getComputedStyle(stage);
      var availW = stage.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
      var availH = stage.clientHeight - parseFloat(cs.paddingTop) - parseFloat(cs.paddingBottom);
      var full = 406; // 390 px Inhalt + Rahmen
      if (availW >= full) return;
      var sc = availW / full;
      dev.style.maxWidth = "none"; dev.style.width = full + "px"; dev.style.height = (availH / sc) + "px";
      dev.style.alignSelf = "start"; dev.style.justifySelf = "start"; dev.style.transformOrigin = "top left"; dev.style.transform = "scale(" + sc + ")";
    };
    window.addEventListener("resize", function () { if (!preview.hidden) fitDevice(); });
    var phoneBtn = preview.querySelector('.device-btn[data-device="phone"]');
    var deskBtn = preview.querySelector('.device-btn[data-device="desktop"]');
    var openPreview = function (url, title, embed, allowPhone) {
      lastFocus = document.activeElement;
      current = { url: url, embed: embed };
      // Seiten ohne Handy-Vollbild (z. B. fitness4fun.de): nur Desktop-Ansicht
      preview.querySelector(".preview-devices").hidden = !allowPhone;
      // Startansicht: auf dem Handy direkt die Handy-Ansicht (wenn die Seite eine hat)
      var startPhone = allowPhone && window.innerWidth < 700;
      stage.setAttribute("data-device", startPhone ? "phone" : "desktop");
      deskBtn.classList.toggle("is-active", !startPhone); deskBtn.setAttribute("aria-pressed", String(!startPhone));
      phoneBtn.classList.toggle("is-active", startPhone); phoneBtn.setAttribute("aria-pressed", String(startPhone));
      titleEl.textContent = title || "Vorschau";
      urlEl.textContent = url.replace(/^https?:\/\/(www\.)?/, "").replace(/\/$/, "");
      openEl.href = url;
      loader.classList.remove("is-hidden");
      if (embed) {
        shotBox.hidden = true; frame.hidden = false; frame.src = url;
        noteEl.textContent = "Live-Ansicht der Webseite. Sie können darin scrollen und klicken.";
      } else {
        frame.src = "about:blank"; frame.hidden = true; shotBox.hidden = false;
        loadShot(stage.getAttribute("data-device"));
        noteEl.textContent = "Screenshot der Webseite – zum Scrollen. Für die Live-Seite „Neuer Tab“ nutzen.";
      }
      preview.hidden = false;
      fitDevice();
      body.classList.add("preview-open");
      if (FX && FX.lenis) FX.lenis.stop();
      preview.querySelector(".preview-close").focus();
    };
    var closePreview = function () {
      preview.hidden = true;
      body.classList.remove("preview-open");
      if (FX && FX.lenis) FX.lenis.start();
      frame.src = "about:blank";
      shotImg.removeAttribute("src");
      if (lastFocus) lastFocus.focus();
    };

    frame.addEventListener("load", function () {
      if (frame.src !== "about:blank") loader.classList.add("is-hidden");
      clearTimeout(loaderTimer);
    });
    document.querySelectorAll("[data-preview]").forEach(function (trigger) {
      trigger.addEventListener("click", function (e) {
        if (window.innerWidth < 480 && trigger.tagName === "A") return; // Handy: direkt öffnen
        e.preventDefault();
        openPreview(trigger.getAttribute("data-preview"), trigger.getAttribute("data-title"), trigger.getAttribute("data-embed") !== "false", trigger.getAttribute("data-phone") !== "false");
        clearTimeout(loaderTimer);
        loaderTimer = setTimeout(function () { loader.classList.add("is-hidden"); }, 6000);
      });
    });
    preview.querySelectorAll("[data-preview-close]").forEach(function (el) { el.addEventListener("click", closePreview); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !preview.hidden) closePreview(); });
    preview.querySelectorAll(".device-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        preview.querySelectorAll(".device-btn").forEach(function (b) { b.classList.remove("is-active"); b.setAttribute("aria-pressed", "false"); });
        btn.classList.add("is-active"); btn.setAttribute("aria-pressed", "true");
        stage.setAttribute("data-device", btn.getAttribute("data-device"));
        fitDevice();
        if (!current.embed && !preview.hidden) loadShot(btn.getAttribute("data-device"));
      });
    });
  }

  /* ---------- Designbeispiele: Vorschau scrollt beim Hover durch die Seite ---------- */
  var shots = document.querySelectorAll(".shot");
  shots.forEach(function (shot) {
    var img = shot.querySelector(".shot-img img");
    var box = shot.querySelector(".shot-img");
    var setScroll = function () {
      if (!img.naturalHeight) return;
      var h = img.getBoundingClientRect().height, bh = box.getBoundingClientRect().height;
      shot.style.setProperty("--scroll", -Math.max(0, h - bh) + "px");
    };
    shot.addEventListener("mouseenter", setScroll);
    shot.addEventListener("focus", setScroll);
  });

  /* ---------- Großansicht der Designbeispiele (ganze Seite, Desktop/Handy) ---------- */
  if (shots.length) {
    var lb = document.createElement("div");
    lb.className = "lightbox"; lb.hidden = true;
    lb.setAttribute("role", "dialog"); lb.setAttribute("aria-modal", "true"); lb.setAttribute("aria-labelledby", "lb-title");
    lb.innerHTML =
      '<div class="lightbox-backdrop" data-lb-close></div>' +
      '<div class="lightbox-panel">' +
        '<button type="button" class="lightbox-close" data-lb-close aria-label="Schließen"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
        '<div class="lightbox-view" data-device="desktop">' +
          '<div class="lightbox-browser"><span class="shot-bar"><i></i><i></i><i></i><em class="lb-url"></em></span>' +
          '<div class="lightbox-scroll" tabindex="0" aria-label="Seite zum Scrollen" data-lenis-prevent><img class="lb-img" alt=""></div></div>' +
          '<span class="scroll-cue">Scrollen ↓</span>' +
        '</div>' +
        '<aside class="lightbox-side" data-lenis-prevent>' +
          '<h3 id="lb-title"></h3>' +
          '<div class="preview-devices" role="group" aria-label="Ansicht wählen">' +
            '<button type="button" class="device-btn is-active" data-lb-device="desktop" aria-pressed="true"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>Desktop</button>' +
            '<button type="button" class="device-btn" data-lb-device="tablet" aria-pressed="false"><svg viewBox="0 0 24 24"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M11 18h2"/></svg>Tablet</button>' +
            '<button type="button" class="device-btn" data-lb-device="phone" aria-pressed="false"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>Handy</button>' +
          '</div>' +
          '<p class="lb-desc"></p><ul class="lb-features"></ul>' +
          '<p class="lightbox-note">Designbeispiel für einen fiktiven Betrieb. Inhalte, Farben und Funktionen passe ich an Ihren Betrieb an.</p>' +
          '<a href="#kontakt" class="btn btn-primary lb-cta" data-lb-close data-plan="Firmenwebseite"><span class="lb-cta-long">So etwas für meinen Betrieb</span><span class="lb-cta-short">Anfragen</span></a>' +
          '<button type="button" class="lb-close-m" data-lb-close aria-label="Schließen"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg></button>' +
        '</aside>' +
      '</div>';
    body.appendChild(lb);
    var view = lb.querySelector(".lightbox-view"), lbImg = lb.querySelector(".lb-img"), lbScroll = lb.querySelector(".lightbox-scroll"),
        cue = lb.querySelector(".scroll-cue"), current = null, lbFocus = null;
    var setDevice = function (dev) {
      view.setAttribute("data-device", dev);
      lb.querySelectorAll("[data-lb-device]").forEach(function (b) {
        var on = b.getAttribute("data-lb-device") === dev;
        b.classList.toggle("is-active", on); b.setAttribute("aria-pressed", String(on));
      });
      // Desktop: scharfe 2x-Version für hochauflösende Bildschirme, Tablet/Handy: eigene Ansicht
      if (dev === "desktop") { lbImg.srcset = current + ".webp 1200w, " + current + "@2x.webp 2400w"; lbImg.sizes = "(max-width: 900px) 100vw, 900px"; }
      else { lbImg.removeAttribute("srcset"); lbImg.removeAttribute("sizes"); }
      lbImg.src = current + (dev === "phone" ? "-m" : dev === "tablet" ? "-t" : "") + ".webp";
      lbScroll.scrollTop = 0;
      cue.classList.remove("is-gone");
    };
    var openLb = function (shot) {
      lbFocus = shot;
      current = (shot.querySelector(".shot-img img") || shot.querySelector("img")).getAttribute("src").replace(/\.webp$/, ""); // z. B. img/work/full/dental
      lb.querySelector("#lb-title").textContent = shot.querySelector("strong").textContent;
      lb.querySelector(".lb-url").textContent = shot.getAttribute("data-url");
      lb.querySelector(".lb-desc").textContent = shot.getAttribute("data-desc");
      lb.querySelector(".lb-features").innerHTML = shot.getAttribute("data-features").split("|").map(function (f) { return "<li>" + f + "</li>"; }).join("");
      lbImg.alt = "Designbeispiel " + shot.querySelector("strong").textContent + " – komplette Seite";
      setDevice(window.innerWidth < 700 ? "phone" : window.innerWidth < 1100 ? "tablet" : "desktop");
      lb.hidden = false;
      lockScroll(true);
      lb.querySelector(".lightbox-close").focus();
    };
    var closeLb = function () { lb.hidden = true; lockScroll(false); if (lbFocus) lbFocus.focus(); };
    lbScroll.addEventListener("scroll", function () { if (lbScroll.scrollTop > 40) cue.classList.add("is-gone"); }, { passive: true });
    lb.addEventListener("click", function (e) {
      var d = e.target.closest("[data-lb-device]");
      if (d) return setDevice(d.getAttribute("data-lb-device"));
      if (e.target.closest("[data-lb-close]")) closeLb();
    });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !lb.hidden) closeLb(); });
    shots.forEach(function (shot) {
      shot.setAttribute("tabindex", "0");
      shot.setAttribute("role", "button");
      shot.addEventListener("click", function () { openLb(shot); });
      shot.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openLb(shot); } });
    });
  }

  /* ---------- Cookie-Hinweis ---------- */
  var cookieBox = document.getElementById("cookie");
  if (cookieBox) {
    var COOKIE_NAME = "loggx_consent";
    var COOKIE_DAYS = 365;
    var readConsent = function () {
      var m = document.cookie.match(new RegExp("(?:^|; )" + COOKIE_NAME + "=([^;]*)"));
      if (!m) return null;
      try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
    };
    var writeConsent = function (consent) {
      var d = new Date(); d.setTime(d.getTime() + COOKIE_DAYS * 864e5);
      document.cookie = COOKIE_NAME + "=" + encodeURIComponent(JSON.stringify(consent)) +
        "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax" + (location.protocol === "https:" ? "; Secure" : "");
    };
    // Hier Statistik-Tools einhängen, die nur mit Zustimmung laden sollen
    var applyConsent = function (consent) {
      if (consent && consent.stats) {
        // Beispiel: Google Analytics / Matomo hier laden
      }
    };
    var optionsEl = document.getElementById("cookie-options");
    var statsEl = document.getElementById("cookie-stats");
    var settingsBtn = document.getElementById("cookie-settings");
    var showCookie = function () {
      var existing = readConsent();
      if (existing) statsEl.checked = Boolean(existing.stats);
      optionsEl.hidden = true;
      settingsBtn.querySelector("span").textContent = "Einstellungen";
      cookieBox.hidden = false;
    };
    var saveAndClose = function (stats) {
      var consent = { necessary: true, stats: Boolean(stats), date: new Date().toISOString() };
      writeConsent(consent); applyConsent(consent);
      cookieBox.hidden = true;
    };
    document.getElementById("cookie-accept").addEventListener("click", function () { saveAndClose(true); });
    document.getElementById("cookie-necessary").addEventListener("click", function () { saveAndClose(false); });
    settingsBtn.addEventListener("click", function () {
      if (optionsEl.hidden) { optionsEl.hidden = false; settingsBtn.querySelector("span").textContent = "Auswahl speichern"; }
      else saveAndClose(statsEl.checked);
    });
    var reopen = document.getElementById("cookie-reopen");
    if (reopen) reopen.addEventListener("click", function (e) { e.preventDefault(); showCookie(); });
    var saved = readConsent();
    if (saved) applyConsent(saved);
    else setTimeout(showCookie, 600);
  }

  /* ---------- Paket-Berater ---------- */
  var advisor = document.getElementById("advisor");
  if (advisor) {
    var showStep = function (n) {
      advisor.querySelectorAll(".adv-step").forEach(function (s) { s.classList.toggle("is-active", s.getAttribute("data-step") === String(n)); });
    };
    var showResult = function (plan) {
      document.getElementById("adv-result").textContent = plan;
      document.getElementById("adv-cta").setAttribute("data-plan", plan);
      showStep(3);
    };
    advisor.addEventListener("click", function (e) {
      var b = e.target.closest("[data-adv], [data-adv-result]");
      if (!b) return;
      if (b.hasAttribute("data-adv-result")) return showResult(b.getAttribute("data-adv-result"));
      var a = b.getAttribute("data-adv");
      if (a === "complex") showResult("Individuell");
      else if (a === "next") showStep(2);
      else if (a === "reset") showStep(1);
    });
  }

  /* ---------- Statistik ohne Cookies (nur wenn die Seite vom Server kommt) ---------- */
  var LIVE = root.hasAttribute("data-cms-live");
  var API = root.getAttribute("data-api") || "_launch/api.php";
  var PAGE = (document.getElementById("main") || body).getAttribute("data-page") || "start";
  var T = null;
  function track(id) { if (T && id) T.c[id] = 1; }
  var dnt = navigator.doNotTrack === "1" || window.doNotTrack === "1" || navigator.globalPrivacyControl === true;
  if (LIVE && root.hasAttribute("data-stats") && !dnt && navigator.sendBeacon && "IntersectionObserver" in window) {
    T = { s: {}, c: {}, sentS: {}, sentC: {}, vis: Date.now(), acc: 0, engaged: false, sentE: false };
    var secIo = new IntersectionObserver(function (es) {
      es.forEach(function (en) {
        if (!en.isIntersecting) return;
        T.s[(PAGE === "angebot" ? "lp-" : "") + en.target.id] = 1;
        secIo.unobserve(en.target);
      });
    }, { threshold: 0.3 });
    document.querySelectorAll("#main > section[id]").forEach(function (s) { secIo.observe(s); });
    var flush = function () {
      var now = Date.now();
      if (T.vis) { T.acc += (now - T.vis) / 1000; T.vis = 0; }
      if (T.acc >= 15) T.engaged = true;
      var s = Object.keys(T.s).filter(function (x) { return !T.sentS[x]; });
      var c = Object.keys(T.c).filter(function (x) { return !T.sentC[x]; });
      var d = Math.round(T.acc); T.acc -= d;
      var e = T.engaged && !T.sentE;
      if (!s.length && !c.length && !d && !e) return;
      var ok = navigator.sendBeacon(API + "?a=t", JSON.stringify({ s: s, c: c, d: d, e: e ? 1 : 0 }));
      if (ok) { s.forEach(function (x) { T.sentS[x] = 1; }); c.forEach(function (x) { T.sentC[x] = 1; }); if (e) T.sentE = true; }
    };
    document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") flush(); else T.vis = Date.now(); });
    window.addEventListener("pagehide", flush);
    setTimeout(function () {
      if (document.visibilityState !== "visible") return;
      T.acc += (Date.now() - T.vis) / 1000; T.vis = Date.now();
      if (T.acc >= 15) { T.engaged = true; flush(); }
    }, 16000);
  }
  // Wichtige Klicks zählen (nur Name des Buttons, nichts Persönliches)
  var planTrack = { "Visitenkarte": "plan-visit", "Firmenwebseite": "plan-firm", "Individuell": "plan-indiv", "Hosting & Technik": "plan-hosting", "Änderungen": "plan-change" };
  document.addEventListener("click", function (e) {
    var t = e.target.closest("a, button, .shot");
    if (!t) return;
    if (t.hasAttribute("data-track")) track(t.getAttribute("data-track"));
    else if (t.classList.contains("lb-cta")) track("example-cta");
    else if (t.id === "adv-cta") track("advisor");
    else if (t.hasAttribute("data-plan")) track(planTrack[t.getAttribute("data-plan")]);
    else if (/^mailto:/.test(t.getAttribute("href") || "")) track("mail");
    else if (/^tel:/.test(t.getAttribute("href") || "")) track("tel");
    else if (t.classList.contains("shot")) track("example");
    else if (t.hasAttribute("data-preview") || t.closest(".reference")) track("ref");
  }, true);

  /* ---------- Herkunft merken (für Anfragen) ---------- */
  var source = function () {
    try {
      var s = sessionStorage.getItem("lx-src");
      if (s) return s;
      var u = new URLSearchParams(location.search), src = u.get("utm_source") || "";
      if (!src && document.referrer) { try { var h = new URL(document.referrer).hostname; if (h !== location.hostname) src = h.replace(/^www\./, ""); } catch (e) {} }
      if (!src && /Instagram/i.test(navigator.userAgent)) src = "Instagram";
      if (!src && /FBAN|FBAV/.test(navigator.userAgent)) src = "Facebook";
      if (!src && /LinkedInApp/i.test(navigator.userAgent)) src = "LinkedIn";
      sessionStorage.setItem("lx-src", src || "direkt");
      return src || "direkt";
    } catch (e) { return "direkt"; }
  };
  source();

  /* ---------- Anfrage in 3 Schritten ---------- */
  var form = document.getElementById("lead-form");
  if (form) {
    var stepsEl = form.querySelectorAll(".lf-step");
    var labels = ["Ihr Vorhaben", "Umfang & Zeitplan", "Ihre Kontaktdaten"];
    var step = 1, started = 0;
    var val = function (n) { var el = form.elements[n]; return el && el.value ? String(el.value).trim() : ""; };
    var radio = function (n) { var el = form.querySelector('input[name="' + n + '"]:checked'); return el ? el.value : ""; };
    var checks = function (n) { return Array.prototype.map.call(form.querySelectorAll('input[name="' + n + '"]:checked'), function (i) { return i.value; }); };
    var label = function (n) { var el = form.querySelector('input[name="' + n + '"]:checked + span'); return el ? el.textContent.trim() : "–"; };
    var price = function (k) { var el = form.querySelector('.lf-prices [data-cms="' + k + '"]'); return el ? el.textContent.trim() : ""; };
    var eur = function (k) { var n = parseInt(price(k).replace(/\D/g, ""), 10) || 0; return n.toLocaleString("de-DE") + " €"; };
    var markStart = function () { if (!started) { started = Date.now(); track("form-start"); } };

    // Dieselbe Logik wie in _launch/leads.php
    var recommend = function () {
      var p = radio("projekt"), pages = radio("seiten"), x = checks("extras");
      var has = function (a) { return a.some(function (k) { return x.indexOf(k) >= 0; }); };
      var visit = ["Visitenkarte", "Eine schlanke Seite mit allem Wichtigen auf einen Blick – ab " + eur("price_visit") + ", online in " + price("time_visit") + "."];
      if (p === "hosting") return ["Hosting & Technik", "Server in Deutschland, Domain, SSL-Zertifikat und tägliche Backups – ab " + eur("price_hosting") + " im Monat."];
      if (p === "aenderung") return ["Änderungen", "Abrechnung nach Aufwand mit " + eur("price_hourly") + " pro Stunde, in 15-Minuten-Schritten. Kein Abo."];
      if (p === "shop" || pages === "many" || has(["shop", "termin", "sprachen"])) return ["Individuell", "Für Sonderfunktionen wie Shop, Terminbuchung oder mehrere Sprachen. Sie bekommen vorher ein Angebot zum Festpreis."];
      if (pages === "one" || p === "landing") return visit;
      if (pages === "few" || p === "relaunch" || x.length >= 3) return ["Firmenwebseite", "Mehrere Unterseiten für Leistungen, Team und Referenzen – ab " + eur("price_firm") + ", online in " + price("time_firm") + "."];
      return visit;
    };
    var updateRec = function () {
      var any = radio("projekt") && (radio("seiten") || checks("extras").length || radio("start") || ["aenderung", "hosting"].indexOf(radio("projekt")) >= 0);
      var box = document.getElementById("lf-rec");
      box.hidden = !any;
      if (!any) return;
      var r = recommend();
      document.getElementById("lf-rec-name").textContent = r[0];
      document.getElementById("lf-rec-why").textContent = r[1];
    };
    // Felder je nach Auswahl ein-/ausblenden: data-if="projekt=neu|relaunch|" (leerer Wert = noch nichts gewählt)
    var applyIf = function () {
      form.querySelectorAll("[data-if]").forEach(function (el) {
        var m = el.getAttribute("data-if").split("="), v = radio(m[0]);
        el.hidden = m[1].split("|").indexOf(v) < 0;
      });
      var small = ["aenderung", "hosting"].indexOf(radio("projekt")) >= 0;
      var l2 = document.getElementById("lf-l2");
      if (!l2.getAttribute("data-main")) l2.setAttribute("data-main", l2.textContent);
      l2.textContent = small ? l2.getAttribute("data-alt") : l2.getAttribute("data-main");
      document.getElementById("lf-q-start").hidden = small;
    };
    var goStep = function (n, focus) {
      step = n;
      stepsEl.forEach(function (s) { s.hidden = +s.getAttribute("data-step") !== n; });
      document.getElementById("lf-bar").style.width = (n / 3 * 100) + "%";
      document.getElementById("lf-label").textContent = "Schritt " + n + " von 3 · " + labels[n - 1];
      if (n === 2) updateRec();
      if (n >= 2) track("form-step" + n);
      if (focus !== false) {
        var r = form.getBoundingClientRect();
        if (r.top < 60 || r.top > window.innerHeight * 0.4) {
          var y = window.scrollY + r.top - (parseInt(getComputedStyle(root).getPropertyValue("--header-h"), 10) || 76) - 12;
          if (FX && FX.lenis) FX.lenis.scrollTo(y); else window.scrollTo({ top: y, behavior: reduceMotion ? "auto" : "smooth" });
        }
      }
    };
    var showErr = function (msg) { var el = document.getElementById("lf-error"); el.textContent = msg; el.hidden = !msg; };

    form.addEventListener("focusin", markStart);
    form.addEventListener("change", function (e) {
      markStart();
      var t = e.target;
      if (t.name === "projekt") { t.closest(".opts").classList.remove("is-err"); form.querySelector('[data-err="1"]').hidden = true; }
      if (["projekt", "branche"].indexOf(t.name) >= 0) applyIf();
      if (["projekt", "seiten", "extras", "start"].indexOf(t.name) >= 0) updateRec();
      if (t.name === "consent") t.closest(".consent").classList.remove("is-err");
    });
    form.querySelectorAll("[data-next]").forEach(function (b) {
      b.addEventListener("click", function () {
        if (step === 1 && !radio("projekt")) {
          form.querySelector('[data-required="projekt"]').classList.add("is-err");
          form.querySelector('[data-err="1"]').hidden = false;
          return;
        }
        goStep(step + 1);
      });
    });
    form.querySelectorAll("[data-back]").forEach(function (b) { b.addEventListener("click", function () { goStep(step - 1); }); });
    applyIf();
    goStep(1, false);

    // "… anfragen"-Buttons: Paket bzw. Vorhaben im Formular vorauswählen
    var planPreset = {
      "Visitenkarte": ["neu", "one"], "Firmenwebseite": ["neu", "few"], "Individuell": ["shop", ""], "Hosting & Technik": ["hosting", ""],
      "Änderungen": ["aenderung", ""], "Neue Webseite": ["neu", ""], "Relaunch": ["relaunch", ""], "Landingpage": ["landing", "one"]
    };
    document.addEventListener("click", function (e) {
      var b = e.target.closest("[data-plan]");
      if (!b) return;
      var plan = b.getAttribute("data-plan"), pre = planPreset[plan];
      if (!pre) return;
      var setR = function (n, v) { if (!v) return; var r = form.querySelector('input[name="' + n + '"][value="' + v + '"]'); if (r) r.checked = true; };
      setR("projekt", pre[0]); setR("seiten", pre[1]);
      var isPlan = !!planTrack[plan];
      form.elements.paket.value = isPlan ? plan : "";
      var chip = document.getElementById("lf-paket");
      chip.textContent = "Paket: " + plan; chip.hidden = !isPlan;
      form.querySelector('[data-required="projekt"]').classList.remove("is-err");
      form.querySelector('[data-err="1"]').hidden = true;
      applyIf(); updateRec();
      if (step !== 1) goStep(1, false);
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      showErr("");
      var name = form.elements.name, mail = form.elements.email, consent = form.elements.consent;
      var badName = !name.value.trim(), badMail = !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(mail.value.trim());
      name.closest(".field").classList.toggle("is-invalid", badName);
      mail.closest(".field").classList.toggle("is-invalid", badMail);
      consent.closest(".consent").classList.toggle("is-err", !consent.checked);
      if (badName) { showErr("Bitte geben Sie Ihren Namen an."); return name.focus(); }
      if (badMail) { showErr("Bitte geben Sie eine gültige E-Mail-Adresse an."); return mail.focus(); }
      if (!consent.checked) return showErr("Bitte bestätigen Sie noch kurz den Hinweis zum Datenschutz.");

      var data = {
        name: val("name"), email: val("email"), telefon: val("telefon"), firma: val("firma"), ort: val("ort"), webseite: val("webseite"),
        nachricht: val("nachricht"), projekt: radio("projekt"), branche: radio("branche"), branche_text: val("branche_text"),
        seiten: radio("seiten"), extras: checks("extras"), start: radio("start"), budget: radio("budget"), kontakt: radio("kontakt"),
        paket: val("paket"), consent: 1, website: val("website"), t: started ? Date.now() - started : 0, src: source(), page: PAGE
      };
      var rec = recommend();
      var btn = document.getElementById("lf-submit"), btnText = btn.querySelector("span");
      var done = function (recName, recWhy) {
        stepsEl.forEach(function (s) { s.hidden = true; });
        form.querySelector(".lf-progress").hidden = true;
        document.getElementById("lf-paket").hidden = true;
        document.getElementById("lf-label").textContent = "Anfrage gesendet";
        document.getElementById("lf-done-name").textContent = data.name ? ", " + data.name.split(" ")[0] : "";
        var r = document.getElementById("lf-done-rec");
        r.hidden = false; r.querySelector("b").textContent = recName; r.querySelector("span:last-child").textContent = recWhy;
        var d = document.getElementById("lf-done"); d.hidden = false; d.focus({ preventScroll: true });
      };
      if (data.website) return done(rec[0], rec[1]); // Spam-Falle
      if (location.protocol === "file:") return showErr("Vorschau-Modus: Das Formular sendet erst, wenn die Seite online liegt.");

      btn.disabled = true; btn.classList.add("is-loading"); btnText.textContent = "Wird gesendet …";
      var fail = function (msg) {
        showErr((msg || "Senden hat nicht geklappt.") + " Sie können mir auch direkt eine E-Mail schreiben.");
        btn.disabled = false; btn.classList.remove("is-loading"); btnText.textContent = "Anfrage senden";
      };
      if (LIVE) {
        fetch(API + "?a=anfrage", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(data) })
          .then(function (res) { return res.json().catch(function () { return {}; }).then(function (j) { return { ok: res.ok, j: j }; }); })
          .then(function (r) { if (!r.ok || !r.j.ok) throw new Error(r.j.error || ""); done(r.j.rec || rec[0], r.j.why || rec[1]); })
          .catch(function (err) { fail(err.message); });
        return;
      }
      // Ohne Server (z. B. GitHub Pages): Versand über formsubmit.co
      var mailEl = document.querySelector('[data-cms="email"]');
      var to = mailEl ? mailEl.textContent.trim() : "constantinloggen@icloud.com";
      var payload = {
        _subject: "Neue Anfrage: " + (data.firma || data.name) + " – " + rec[0], _template: "table", _captcha: "false",
        Name: data.name, "E-Mail": data.email, Telefon: data.telefon || "–", Firma: data.firma || "–", Ort: data.ort || "–",
        Branche: data.branche === "andere" && data.branche_text ? data.branche_text : label("branche"), Vorhaben: label("projekt"),
        "Jetzige Seite": data.webseite || "–", Umfang: label("seiten"),
        "Dazu gewünscht": Array.prototype.map.call(form.querySelectorAll('input[name="extras"]:checked + span'), function (s) { return s.textContent.trim(); }).join(", ") || "–",
        Start: label("start"), Budget: label("budget"), "Lieber per": label("kontakt"), Paket: data.paket || "–", Empfehlung: rec[0],
        Nachricht: data.nachricht || "–", Herkunft: data.src + (PAGE === "angebot" ? " (Landingpage)" : "")
      };
      fetch("https://formsubmit.co/ajax/" + encodeURIComponent(to), {
        method: "POST", headers: { "Content-Type": "application/json", "Accept": "application/json" }, body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json(); })
        .then(function (j) { if (!(j && (j.success === true || j.success === "true"))) throw new Error(""); done(rec[0], rec[1]); })
        .catch(function () {
          var lines = Object.keys(payload).filter(function (k) { return k.charAt(0) !== "_"; }).map(function (k) { return k + ": " + payload[k]; });
          location.href = "mailto:" + to + "?subject=" + encodeURIComponent(payload._subject) + "&body=" + encodeURIComponent(lines.join("\n"));
          done(rec[0], rec[1]);
        });
    });
  }

  /* ---------- Einblenden ohne Animations-Modul (Fallback) ---------- */
  var reveals = document.querySelectorAll(".reveal");
  if (FX) {
    /* übernimmt js/effects.js */
  } else if ("IntersectionObserver" in window && !reduceMotion) {
    var revealObserver = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        el.classList.add("is-visible");
        revealObserver.unobserve(el);
        setTimeout(function () { el.style.transitionDelay = ""; }, 1200);
      });
    }, { rootMargin: "0px 0px -8% 0px", threshold: 0.08 });
    reveals.forEach(function (el, i) {
      // kleine Staffelung innerhalb einer Gruppe
      var sibs = el.parentElement ? Array.prototype.filter.call(el.parentElement.children, function (c) { return c.classList.contains("reveal"); }) : [];
      var idx = sibs.indexOf(el);
      if (idx > 0) el.style.transitionDelay = Math.min(idx, 5) * 70 + "ms";
      revealObserver.observe(el);
    });
    document.querySelectorAll(".steps").forEach(function (el) { revealObserver.observe(el); });
  } else {
    reveals.forEach(function (el) { el.classList.add("is-visible"); });
    document.querySelectorAll(".steps").forEach(function (el) { el.classList.add("is-visible"); });
  }

  /* ---------- Hero-Zahlen beim Laden hochzählen ---------- */
  if (!reduceMotion && !FX) document.querySelectorAll(".hero-facts .count").forEach(function (el, i) { setTimeout(function () { countUp(el); }, 500 + i * 120); });

  /* ---------- Preise hochzählen (einmalig, wenn sichtbar) ---------- */
  function countUp(el) {
    var to = parseFloat(el.getAttribute("data-to"));
    var de = el.getAttribute("data-format") === "de";
    var start = null, dur = 1200;
    function frame(t) {
      if (!start) start = t;
      var p = Math.min(1, (t - start) / dur);
      var n = Math.round(to * (1 - Math.pow(1 - p, 3)));
      el.textContent = de ? n.toLocaleString("de-DE") : n;
      if (p < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }
  var pricing = document.querySelector(".pricing");
  if (pricing && "IntersectionObserver" in window && !reduceMotion && !FX) {
    var priceObserver = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      pricing.querySelectorAll(".count").forEach(countUp);
      priceObserver.disconnect();
    }, { threshold: 0.3 });
    priceObserver.observe(pricing);
  }
})();
