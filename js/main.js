/* ==========================================================================
   LoggX – main.js
   1) Grundfunktionen (Navigation, Cookie, Vorschau, Formular, Paket-Berater)
   2) Animationen (GSAP + ScrollTrigger + Lenis, alles lokal in js/vendor)
   ========================================================================== */

(function () {
  "use strict";

  /* ==========================================================
     EINSTELLUNGEN – nur hier die E-Mail-Adresse eintragen!
     Versand über formsubmit.co. Beim allerersten Absenden kommt eine
     Aktivierungs-Mail – einmal auf "Activate" klicken, fertig.
     Eigenes Server-Skript: formEndpoint auf "kontakt.php" setzen.
     ========================================================== */
  var CONFIG = {
    contactEmail: "constantinloggen@icloud.com",
    formEndpoint: null   // null = automatisch formsubmit.co
  };
  if (!CONFIG.formEndpoint) CONFIG.formEndpoint = "https://formsubmit.co/ajax/" + CONFIG.contactEmail;

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

  /* ---------- Hilfsfunktion: sanft zu einem Ziel scrollen ---------- */
  function scrollToTarget(target) {
    var header = document.getElementById("site-header");
    var offset = header ? -header.offsetHeight + 1 : 0;
    if (target.id === "top") offset = 0;
    window.scrollTo({ top: target.getBoundingClientRect().top + window.pageYOffset + offset, behavior: reduceMotion ? "auto" : "smooth" });
  }

  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener("click", function (e) {
      var id = a.getAttribute("href");
      if (id.length < 2) return;
      var target = document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      scrollToTarget(target);
      history.replaceState(null, "", id);
    });
  });

  /* ---------- Mobile Navigation ---------- */
  var toggle = document.getElementById("nav-toggle");
  var nav = document.getElementById("site-nav");
  function setNav(open) {
    nav.classList.toggle("is-open", open);
    body.classList.toggle("nav-open", open);
    toggle.setAttribute("aria-expanded", String(open));
    toggle.setAttribute("aria-label", open ? "Menü schließen" : "Menü öffnen");
    body.style.overflow = open ? "hidden" : "";
  }
  if (toggle && nav) {
    toggle.addEventListener("click", function () { setNav(!nav.classList.contains("is-open")); });
    nav.querySelectorAll("a").forEach(function (link) { link.addEventListener("click", function () { setNav(false); }); });
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
  if (rotator && !reduceMotion) {
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
  if (portrait) {
    var markMissing = function () { portrait.classList.add("is-missing"); };
    portrait.addEventListener("error", markMissing);
    if (portrait.complete && portrait.naturalWidth === 0) markMissing();
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
    var phoneBtn = preview.querySelector('.device-btn[data-device="phone"]');
    var deskBtn = preview.querySelector('.device-btn[data-device="desktop"]');
    var openPreview = function (url, title, embed, allowPhone) {
      lastFocus = document.activeElement;
      current = { url: url, embed: embed };
      // Seiten ohne Handy-Vollbild (z. B. fitness4fun.de): nur Desktop-Ansicht
      preview.querySelector(".preview-devices").hidden = !allowPhone;
      if (!allowPhone) {
        stage.setAttribute("data-device", "desktop");
        deskBtn.classList.add("is-active"); deskBtn.setAttribute("aria-pressed", "true");
        phoneBtn.classList.remove("is-active"); phoneBtn.setAttribute("aria-pressed", "false");
      }
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
      body.classList.add("preview-open");
      preview.querySelector(".preview-close").focus();
    };
    var closePreview = function () {
      preview.hidden = true;
      body.classList.remove("preview-open");
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
          '<div class="lightbox-scroll" tabindex="0" aria-label="Seite zum Scrollen"><img class="lb-img" alt=""></div></div>' +
          '<span class="scroll-cue">Scrollen ↓</span>' +
        '</div>' +
        '<aside class="lightbox-side">' +
          '<h3 id="lb-title"></h3>' +
          '<div class="preview-devices" role="group" aria-label="Ansicht wählen">' +
            '<button type="button" class="device-btn is-active" data-lb-device="desktop" aria-pressed="true"><svg viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/></svg>Desktop</button>' +
            '<button type="button" class="device-btn" data-lb-device="phone" aria-pressed="false"><svg viewBox="0 0 24 24"><rect x="7" y="2" width="10" height="20" rx="2"/><path d="M11 18h2"/></svg>Handy</button>' +
          '</div>' +
          '<p class="lb-desc"></p><ul class="lb-features"></ul>' +
          '<p class="lightbox-note">Designbeispiel für einen fiktiven Betrieb. Inhalte, Farben und Funktionen passe ich an Ihren Betrieb an.</p>' +
          '<a href="#kontakt" class="btn btn-primary" data-lb-close data-plan="Firmenwebseite"><span>So etwas für meinen Betrieb</span></a>' +
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
      lbImg.src = "img/work/full/" + current + (dev === "phone" ? "-m" : "") + ".webp";
      lbScroll.scrollTop = 0;
      cue.classList.remove("is-gone");
    };
    var openLb = function (shot) {
      lbFocus = shot;
      current = shot.getAttribute("data-id");
      lb.querySelector("#lb-title").textContent = shot.querySelector("strong").textContent;
      lb.querySelector(".lb-url").textContent = shot.getAttribute("data-url");
      lb.querySelector(".lb-desc").textContent = shot.getAttribute("data-desc");
      lb.querySelector(".lb-features").innerHTML = shot.getAttribute("data-features").split("|").map(function (f) { return "<li>" + f + "</li>"; }).join("");
      lbImg.alt = "Designbeispiel " + shot.querySelector("strong").textContent + " – komplette Seite";
      setDevice(window.innerWidth < 700 ? "phone" : "desktop");
      lb.hidden = false;
      body.style.overflow = "hidden";
      lb.querySelector(".lightbox-close").focus();
    };
    var closeLb = function () { lb.hidden = true; body.style.overflow = ""; if (lbFocus) lbFocus.focus(); };
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

  /* ---------- Paket ins Formular übernehmen ---------- */
  var planSelect = document.getElementById("plan");
  function selectPlan(value) {
    if (!planSelect) return;
    var match = Array.prototype.find.call(planSelect.options, function (o) { return o.value === value || o.text === value; });
    if (match) planSelect.value = match.value;
  }
  document.addEventListener("click", function (e) {
    var btn = e.target.closest("[data-plan]");
    if (btn) selectPlan(btn.getAttribute("data-plan"));
  });

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

  /* ---------- Kontaktformular ---------- */
  var form = document.getElementById("contact-form");
  var status = document.getElementById("form-status");
  if (form) {
    var fields = {
      name: { el: form.elements.name, msg: "Bitte geben Sie Ihren Namen an." },
      email: { el: form.elements.email, msg: "Bitte geben Sie eine gültige E-Mail-Adresse an." },
      message: { el: form.elements.message, msg: "Bitte beschreiben Sie kurz Ihr Anliegen." },
      privacy: { el: form.elements.privacy, msg: "Bitte bestätigen Sie die Datenschutzerklärung." }
    };
    var setError = function (field, message) {
      var wrapper = field.el.closest(".field");
      var errorEl = wrapper ? wrapper.querySelector(".field-error") : null;
      if (wrapper) wrapper.classList.toggle("is-invalid", Boolean(message));
      if (errorEl) errorEl.textContent = message || "";
      field.el.setAttribute("aria-invalid", message ? "true" : "false");
    };
    var validate = function (field, key) {
      var el = field.el, valid;
      if (key === "privacy") valid = el.checked;
      else if (key === "email") valid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(el.value.trim());
      else valid = el.value.trim().length > 1;
      setError(field, valid ? "" : field.msg);
      return valid;
    };
    Object.keys(fields).forEach(function (key) {
      var field = fields[key];
      field.el.addEventListener(key === "privacy" ? "change" : "input", function () {
        if (field.el.closest(".field").classList.contains("is-invalid")) validate(field, key);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var allValid = true, firstInvalid = null;
      Object.keys(fields).forEach(function (key) {
        if (!validate(fields[key], key)) { allValid = false; if (!firstInvalid) firstInvalid = fields[key].el; }
      });
      if (!allValid) {
        status.className = "form-status is-error";
        status.textContent = "Bitte prüfen Sie die markierten Felder.";
        firstInvalid.focus();
        return;
      }
      if (form.elements._honey && form.elements._honey.value) return; // Spam-Falle

      var submitBtn = document.getElementById("form-submit");
      if (location.protocol === "file:") {
        status.className = "form-status is-error";
        status.textContent = "Vorschau-Modus: Das Formular sendet erst, wenn die Seite online bei einem Hoster liegt.";
        return;
      }
      var showSuccess = function () {
        form.innerHTML = '<div class="form-success"><span class="check"><svg viewBox="0 0 24 24"><path d="M5 12l5 5L19 7"/></svg></span>' +
          '<h3>Anfrage gesendet</h3><p>Vielen Dank! Ich melde mich innerhalb von 24 Stunden bei Ihnen.</p></div>';
      };
      var nativeSubmit = function () {
        var next = document.getElementById("form-next");
        if (next && /^https?:/.test(location.protocol)) next.value = location.href.replace(/#.*$/, "").replace(/[^\/]*$/, "") + "danke.html";
        else if (next) next.remove();
        form.submit();
      };
      submitBtn.classList.add("is-loading");
      submitBtn.querySelector("span").textContent = "Wird gesendet …";
      status.className = "form-status"; status.textContent = "";

      var payload = {};
      new FormData(form).forEach(function (v, k) { if (k !== "_next") payload[k] = v; });
      fetch(CONFIG.formEndpoint, {
        method: "POST",
        headers: { "Content-Type": "application/json", "Accept": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (res) { return res.json(); })
        .then(function (json) {
          var ok = json && (json.ok === true || json.success === "true" || json.success === true);
          if (!ok) throw new Error("Fehler");
          showSuccess();
        })
        .catch(nativeSubmit);
    });
  }

  /* ---------- Sanftes Einblenden beim Scrollen (leichtgewichtig) ---------- */
  var reveals = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window && !reduceMotion) {
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
  if (!reduceMotion) document.querySelectorAll(".hero-facts .count").forEach(function (el, i) { setTimeout(function () { countUp(el); }, 500 + i * 120); });

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
  if (pricing && "IntersectionObserver" in window && !reduceMotion) {
    var priceObserver = new IntersectionObserver(function (entries) {
      if (!entries[0].isIntersecting) return;
      pricing.querySelectorAll(".count").forEach(countUp);
      priceObserver.disconnect();
    }, { threshold: 0.3 });
    priceObserver.observe(pricing);
  }
})();
