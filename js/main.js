/* ==========================================================================
   LoggX – main.js
   Navigation, aktive Links, Preisauswahl, Formularprüfung
   ========================================================================== */

(function () {
  "use strict";

  /* ==========================================================
     EINSTELLUNGEN – nur hier die E-Mail-Adresse eintragen!
     contactEmail: An diese Adresse gehen die Anfragen.
     Der Versand läuft über formsubmit.co (kostenlos, keine Anmeldung).
     Beim allerersten Absenden kommt eine Aktivierungs-Mail an diese
     Adresse – einmal auf "Activate" klicken, danach läuft alles.
     Wer lieber das eigene Server-Skript nutzt: formEndpoint auf
     "kontakt.php" setzen (dann dort ebenfalls die Adresse eintragen).
     ========================================================== */
  var CONFIG = {
    contactEmail: "constantinloggen@icloud.com",
    formEndpoint: null   // null = automatisch formsubmit.co
  };
  if (!CONFIG.formEndpoint) CONFIG.formEndpoint = "https://formsubmit.co/ajax/" + CONFIG.contactEmail;

  /* ---------- Aktuelles Jahr im Footer ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- Mobile Navigation ---------- */
  var toggle = document.getElementById("nav-toggle");
  var nav = document.getElementById("site-nav");

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", String(open));
      toggle.setAttribute("aria-label", open ? "Menü schließen" : "Menü öffnen");
    });

    // Menü nach Klick auf einen Link schließen
    nav.querySelectorAll("a").forEach(function (link) {
      link.addEventListener("click", function () {
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.setAttribute("aria-label", "Menü öffnen");
      });
    });

    // Escape schließt das Menü
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && nav.classList.contains("is-open")) {
        nav.classList.remove("is-open");
        toggle.setAttribute("aria-expanded", "false");
        toggle.focus();
      }
    });
  }

  /* ---------- Aktiver Navigationslink beim Scrollen (Header + Mobile-Leiste) ---------- */
  var sections = document.querySelectorAll("main section[id]");
  var navLinks = document.querySelectorAll(".site-nav a[href^='#'], .mobile-bar a[href^='#']");

  if ("IntersectionObserver" in window && sections.length && navLinks.length) {
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        var id = entry.target.getAttribute("id");
        navLinks.forEach(function (link) {
          link.classList.toggle("is-active", link.getAttribute("href") === "#" + id);
        });
      });
    }, { rootMargin: "-40% 0px -55% 0px" });

    sections.forEach(function (s) { observer.observe(s); });
  }


  /* ---------- Fortschrittsbalken im Header ---------- */
  var progress = document.getElementById("scroll-progress");
  if (progress) {
    var updateProgress = function () {
      var doc = document.documentElement;
      var max = doc.scrollHeight - window.innerHeight;
      progress.style.width = (max > 0 ? (window.scrollY / max) * 100 : 0) + "%";
    };
    window.addEventListener("scroll", updateProgress, { passive: true });
    updateProgress();
  }

  /* ---------- Rotierendes Wort im Hero ---------- */
  var rotator = document.getElementById("rotator");
  var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (rotator && !reduceMotion) {
    var words = ["Praxen", "Studios", "Handwerk", "Gastronomie", "Dienstleister"];
    var idx = 0;
    setInterval(function () {
      rotator.classList.add("is-out");
      setTimeout(function () {
        idx = (idx + 1) % words.length;
        rotator.textContent = words[idx];
        rotator.classList.remove("is-out");
        rotator.classList.add("is-in");
        setTimeout(function () { rotator.classList.remove("is-in"); }, 340);
      }, 260);
    }, 2600);
  }

  /* ---------- Scroll-Reveal ---------- */
  var revealTargets = document.querySelectorAll(
    ".section-head, .service, .reference, .step, .plan, .pricing-note, .about-portrait, .about-copy, .faq, .contact-copy, .contact-form"
  );
  if (revealTargets.length) {
    // Stagger: Geschwister innerhalb eines Elternelements bekommen aufsteigende Verzögerung
    var groups = new Map();
    revealTargets.forEach(function (el) {
      el.classList.add("reveal");
      var parent = el.parentElement;
      var n = groups.get(parent) || 0;
      el.style.setProperty("--i", n);
      groups.set(parent, n + 1);
    });

    if ("IntersectionObserver" in window && !reduceMotion) {
      var revealObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: "0px 0px -8% 0px" });
      revealTargets.forEach(function (el) { revealObserver.observe(el); });
    } else {
      revealTargets.forEach(function (el) { el.classList.add("is-visible"); });
    }
  }


  /* ---------- Referenz-Screenshots: lokales Bild, sonst automatischer Screenshot ---------- */
  document.querySelectorAll(".frame-shot").forEach(function (img) {
    img.addEventListener("error", function onError() {
      var fallback = img.getAttribute("data-fallback");
      if (fallback && img.src !== fallback) {
        img.src = fallback;
      } else {
        img.classList.add("is-missing");
      }
    });
  });

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

    // Screenshot-Quelle: lokales Bild, sonst automatischer Ganzseiten-Screenshot
    function shotSources(url, device) {
      var slug = url.replace(/^https?:\/\/(www\.)?/, "").replace(/[^a-z0-9]+/gi, "-").replace(/-$/, "").toLowerCase();
      var local = "img/" + slug + (device === "phone" ? "-full-mobile.jpg" : "-full.jpg");
      var remote = device === "phone"
        ? "https://image.thum.io/get/fullpage/width/390/viewportWidth/390/" + url
        : "https://image.thum.io/get/fullpage/width/1200/" + url;
      return [local, remote];
    }

    function loadShot(device) {
      var sources = shotSources(current.url, device);
      var i = 0;
      shotErr.hidden = true;
      shotImg.hidden = false;
      loader.classList.remove("is-hidden");
      shotImg.onload = function () { loader.classList.add("is-hidden"); };
      shotImg.onerror = function () {
        i += 1;
        if (i < sources.length) { shotImg.src = sources[i]; }
        else { shotImg.hidden = true; shotErr.hidden = false; loader.classList.add("is-hidden"); }
      };
      shotImg.src = sources[0];
      shotBox.scrollTop = 0;
    }

    function openPreview(url, title, embed) {
      lastFocus = document.activeElement;
      current = { url: url, embed: embed };
      titleEl.textContent = title || "Vorschau";
      urlEl.textContent = url.replace(/^https?:\/\/(www\.)?/, "").replace(/\/$/, "");
      openEl.href = url;
      loader.classList.remove("is-hidden");

      if (embed) {
        shotBox.hidden = true;
        frame.hidden = false;
        frame.src = url;
        noteEl.textContent = "Live-Ansicht der Webseite. Sie können darin scrollen und klicken.";
      } else {
        frame.src = "about:blank";
        frame.hidden = true;
        shotBox.hidden = false;
        loadShot(stage.getAttribute("data-device"));
        noteEl.textContent = "Screenshot der Webseite – zum Scrollen. Für die Live-Seite „Neuer Tab“ nutzen.";
      }

      preview.hidden = false;
      document.body.classList.add("preview-open");
      preview.querySelector(".preview-close").focus();
    }

    function closePreview() {
      preview.hidden = true;
      document.body.classList.remove("preview-open");
      frame.src = "about:blank";
      shotImg.removeAttribute("src");
      if (lastFocus) lastFocus.focus();
    }

    frame.addEventListener("load", function () {
      if (frame.src !== "about:blank") loader.classList.add("is-hidden");
    });
    // Sicherheitsnetz: Spinner spätestens nach 6 s ausblenden
    var loaderTimer;
    frame.addEventListener("load", function () { clearTimeout(loaderTimer); });

    document.querySelectorAll("[data-preview]").forEach(function (trigger) {
      trigger.addEventListener("click", function (e) {
        // Auf sehr kleinen Geräten direkt die Seite öffnen (Popup wäre zu eng)
        if (window.innerWidth < 480 && trigger.tagName === "A") return;
        e.preventDefault();
        openPreview(
          trigger.getAttribute("data-preview"),
          trigger.getAttribute("data-title"),
          trigger.getAttribute("data-embed") !== "false"
        );
        clearTimeout(loaderTimer);
        loaderTimer = setTimeout(function () { loader.classList.add("is-hidden"); }, 6000);
      });
    });

    preview.querySelectorAll("[data-preview-close]").forEach(function (el) {
      el.addEventListener("click", closePreview);
    });
    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && !preview.hidden) closePreview();
    });

    preview.querySelectorAll(".device-btn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        preview.querySelectorAll(".device-btn").forEach(function (b) {
          b.classList.remove("is-active");
          b.setAttribute("aria-pressed", "false");
        });
        btn.classList.add("is-active");
        btn.setAttribute("aria-pressed", "true");
        stage.setAttribute("data-device", btn.getAttribute("data-device"));
        if (!current.embed && !preview.hidden) loadShot(btn.getAttribute("data-device"));
      });
    });
  }


  /* ---------- Cookie-Hinweis ---------- */
  // Auswahl wird als Cookie gespeichert. Löscht der Besucher seine Cookies,
  // erscheint der Hinweis automatisch wieder.
  var cookieBox = document.getElementById("cookie");
  if (cookieBox) {
    var COOKIE_NAME = "loggx_consent";
    var COOKIE_DAYS = 365;

    function readConsent() {
      var m = document.cookie.match(new RegExp("(?:^|; )" + COOKIE_NAME + "=([^;]*)"));
      if (!m) return null;
      try { return JSON.parse(decodeURIComponent(m[1])); } catch (e) { return null; }
    }
    function writeConsent(consent) {
      var d = new Date(); d.setTime(d.getTime() + COOKIE_DAYS * 864e5);
      document.cookie = COOKIE_NAME + "=" + encodeURIComponent(JSON.stringify(consent)) +
        "; expires=" + d.toUTCString() + "; path=/; SameSite=Lax" +
        (location.protocol === "https:" ? "; Secure" : "");
    }

    // Hier Statistik-Tools einhängen, die nur mit Zustimmung laden sollen
    function applyConsent(consent) {
      if (consent && consent.stats) {
        // Beispiel: Google Analytics / Matomo hier laden
        // var s = document.createElement("script"); s.src = "…"; document.head.appendChild(s);
      }
    }

    var optionsEl = document.getElementById("cookie-options");
    var statsEl = document.getElementById("cookie-stats");

    function showCookie() {
      var existing = readConsent();
      if (existing) statsEl.checked = Boolean(existing.stats);
      optionsEl.hidden = true;
      cookieBox.hidden = false;
    }
    function saveAndClose(stats) {
      var consent = { necessary: true, stats: Boolean(stats), date: new Date().toISOString() };
      writeConsent(consent);
      applyConsent(consent);
      cookieBox.hidden = true;
    }

    document.getElementById("cookie-accept").addEventListener("click", function () { saveAndClose(true); });
    document.getElementById("cookie-necessary").addEventListener("click", function () { saveAndClose(false); });
    document.getElementById("cookie-settings").addEventListener("click", function () {
      if (optionsEl.hidden) {
        optionsEl.hidden = false;
        this.textContent = "Auswahl speichern";
      } else {
        saveAndClose(statsEl.checked);
        this.textContent = "Einstellungen";
      }
    });
    var reopen = document.getElementById("cookie-reopen");
    if (reopen) reopen.addEventListener("click", function (e) { e.preventDefault(); showCookie(); });

    var saved = readConsent();
    if (saved) applyConsent(saved); else showCookie();
  }

  /* ---------- Paket aus Preistabelle ins Formular übernehmen ---------- */
  var planSelect = document.getElementById("plan");
  document.querySelectorAll("[data-plan]").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (!planSelect) return;
      var value = btn.getAttribute("data-plan");
      var match = Array.prototype.find.call(planSelect.options, function (o) {
        return o.value === value || o.text === value;
      });
      if (match) planSelect.value = match.value;
    });
  });

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

    function setError(field, message) {
      var wrapper = field.el.closest(".field");
      var errorEl = wrapper ? wrapper.querySelector(".field-error") : null;
      if (wrapper) wrapper.classList.toggle("is-invalid", Boolean(message));
      if (errorEl) errorEl.textContent = message || "";
      field.el.setAttribute("aria-invalid", message ? "true" : "false");
    }

    function validate(field, key) {
      var el = field.el;
      var valid = true;

      if (key === "privacy") valid = el.checked;
      else if (key === "email") valid = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(el.value.trim());
      else valid = el.value.trim().length > 1;

      setError(field, valid ? "" : field.msg);
      return valid;
    }

    // Fehler beim Tippen wieder entfernen
    Object.keys(fields).forEach(function (key) {
      var field = fields[key];
      var evt = key === "privacy" ? "change" : "input";
      field.el.addEventListener(evt, function () {
        if (field.el.closest(".field").classList.contains("is-invalid")) validate(field, key);
      });
    });

    form.addEventListener("submit", function (e) {
      e.preventDefault();

      var allValid = true;
      var firstInvalid = null;

      Object.keys(fields).forEach(function (key) {
        var ok = validate(fields[key], key);
        if (!ok) {
          allValid = false;
          if (!firstInvalid) firstInvalid = fields[key].el;
        }
      });

      if (!allValid) {
        status.className = "form-status is-error";
        status.textContent = "Bitte prüfen Sie die markierten Felder.";
        firstInvalid.focus();
        return;
      }

      // Spam-Falle: unsichtbares Feld ausgefüllt → still abbrechen
      if (form.elements._honey && form.elements._honey.value) return;

      var submitBtn = document.getElementById("form-submit");

      // Lokal geöffnete Datei (file://): Browser erlauben hier keinen Versand.
      if (location.protocol === "file:") {
        status.className = "form-status is-error";
        status.textContent = "Vorschau-Modus: Das Formular sendet erst, wenn die Seite online bei einem Hoster liegt. " +
          "Lokal geöffnete Dateien dürfen aus Sicherheitsgründen keine Anfragen verschicken.";
        return;
      }

      function showSuccess() {
        form.innerHTML =
          '<div class="form-success">' +
          '<span class="check"><svg viewBox="0 0 24 24"><path d="M5 12l5 5L19 7"/></svg></span>' +
          '<h3>Anfrage gesendet</h3>' +
          '<p>Vielen Dank! Ich melde mich innerhalb von 24 Stunden bei Ihnen.</p>' +
          '</div>';
      }

      // Plan B: klassisches Absenden an formsubmit.co (funktioniert immer, auch ohne
      // Freigabe im Browser). Danach leitet formsubmit.co auf danke.html zurück.
      function nativeSubmit() {
        var next = document.getElementById("form-next");
        if (next && /^https?:/.test(location.protocol)) {
          next.value = location.href.replace(/[^\/]*$/, "").replace(/#.*$/, "") + "danke.html";
        } else if (next) {
          next.remove(); // lokal (file://) gibt es keine gültige Rücksprung-Adresse
        }
        form.submit();
      }

      submitBtn.classList.add("is-loading");
      submitBtn.textContent = "Wird gesendet …";
      status.className = "form-status";
      status.textContent = "";

      // Plan A: Versand im Hintergrund, Erfolg wird direkt auf der Seite angezeigt
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
          if (!ok) throw new Error((json && (json.message || json.error)) || "Fehler");
          showSuccess();
        })
        .catch(function () {
          nativeSubmit();
        });
    });
  }
})();


/* --- NEUE FEATURES LOGIK (SYSTEM DARK MODE) --- */

document.addEventListener('DOMContentLoaded', () => {
    // 1. Systemerkennung & Dark Mode Logik
    const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
    
    // Ermittle das aktuelle Theme (Nutzer-Auswahl geht vor System-Einstellung)
    const getCurrentTheme = () => {
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme) {
            return savedTheme;
        }
        return prefersDarkScheme.matches ? 'dark' : 'light';
    };

    // Theme setzen (HTML Attribut)
    const applyTheme = (theme) => {
        if (theme === 'dark') {
            document.documentElement.setAttribute('data-theme', 'dark');
        } else {
            document.documentElement.removeAttribute('data-theme');
        }
    };

    let currentTheme = getCurrentTheme();
    applyTheme(currentTheme);

    // Floating Button erstellen
    const themeToggle = document.createElement('button');
    themeToggle.className = 'theme-btn-floating';
    themeToggle.innerHTML = currentTheme === 'dark' ? '<span class="theme-icon">☀️</span><span class="theme-text">Hell</span>' : '<span class="theme-icon">🌓</span><span class="theme-text">Dunkel</span>';
    document.body.appendChild(themeToggle);

    // Manueller Wechsel durch Klick
    themeToggle.addEventListener('click', () => {
        currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
        applyTheme(currentTheme);
        localStorage.setItem('theme', currentTheme); // Präferenz speichern
        themeToggle.innerHTML = currentTheme === 'dark' ? '<span class="theme-icon">☀️</span><span class="theme-text">Hell</span>' : '<span class="theme-icon">🌓</span><span class="theme-text">Dunkel</span>';
    });

    // Lauschen, ob sich die Systemeinstellung ändert (nur anwenden, wenn der Nutzer nichts manuell überschrieben hat)
    prefersDarkScheme.addEventListener('change', (e) => {
        if (!localStorage.getItem('theme')) {
            currentTheme = e.matches ? 'dark' : 'light';
            applyTheme(currentTheme);
            themeToggle.innerHTML = currentTheme === 'dark' ? '<span class="theme-icon">☀️</span><span class="theme-text">Hell</span>' : '<span class="theme-icon">🌓</span><span class="theme-text">Dunkel</span>';
        }
    });

    // 2. Scroll Animationen (Intersection Observer)
    const observerOptions = {
        root: null,
        rootMargin: '0px',
        threshold: 0.1
    };
    const observer = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('visible');
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Animation auf Sektionen anwenden
    document.querySelectorAll('section, article, .footer-inner').forEach(element => {
        element.classList.add('fade-in');
        observer.observe(element);
    });
});


/* --- 5 NEUE FEATURE-SKRIPTE --- */
document.addEventListener('DOMContentLoaded', () => {
    // Feature 1 Neu: Paket-Berater
    window.nextAdv = function(needsComplex) {
        if(needsComplex) {
            showResult('Individuell');
        } else {
            document.getElementById('adv-q1').style.display = 'none';
            document.getElementById('adv-q2').style.display = 'block';
        }
    };
    window.showResult = function(plan) {
        document.getElementById('adv-q1').style.display = 'none';
        document.getElementById('adv-q2').style.display = 'none';
        document.getElementById('adv-res').style.display = 'block';
        document.getElementById('adv-result-title').textContent = 'Paket: ' + plan;
        
        // Übernimmt das Paket auch direkt ins Formular, falls vorhanden
        const planSelect = document.getElementById('plan');
        if(planSelect) {
            const match = Array.from(planSelect.options).find(o => o.value === plan || o.text === plan);
            if (match) planSelect.value = match.value;
        }
    };
    window.resetAdv = function() {
        document.getElementById('adv-q1').style.display = 'block';
        document.getElementById('adv-q2').style.display = 'none';
        document.getElementById('adv-res').style.display = 'none';
    };

    // Feature 2: Farb-Akzent-Umschalter
    const accentDots = document.querySelectorAll('.accent-dot');
    const savedAccent = localStorage.getItem('loggx_accent');
    if (savedAccent) {
        document.documentElement.style.setProperty('--accent', savedAccent);
    }

    accentDots.forEach(dot => {
        dot.addEventListener('click', () => {
            const color = dot.getAttribute('data-color');
            if (color) {
                document.documentElement.style.setProperty('--accent', color);
                localStorage.setItem('loggx_accent', color);
            }
        });
    });

    // Feature 3: Text-to-Speech Vorlese-Funktion
    const ttsBtn = document.getElementById('tts-speak-btn');
    if (ttsBtn && 'speechSynthesis' in window) {
        ttsBtn.addEventListener('click', () => {
            window.speechSynthesis.cancel();
            const textToSpeak = "LoggX aus Osnabrück entwickelt schnelle, klare Webseiten für Praxen, Studios, Handwerk und Dienstleister. Ein Ansprechpartner, ein Festpreis, ein fertiges Ergebnis.";
            const utterance = new SpeechSynthesisUtterance(textToSpeak);
            utterance.lang = 'de-DE';
            utterance.rate = 1.0;
            
            ttsBtn.style.opacity = '0.7';
            ttsBtn.querySelector('span').textContent = 'Liest vor …';
            
            utterance.onend = () => {
                ttsBtn.style.opacity = '1';
                ttsBtn.querySelector('span').textContent = 'Einleitung vorlesen lassen (Audio)';
            };
            window.speechSynthesis.speak(utterance);
        });
    } else if (ttsBtn) {
        ttsBtn.style.display = 'none'; // Verstecken, falls Browser es nicht unterstützt
    }
});
