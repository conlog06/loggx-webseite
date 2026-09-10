/* ==========================================================================
   LoggX – main.js
   Navigation, aktive Links, Preisauswahl, Formularprüfung
   ========================================================================== */

(function () {
  "use strict";

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

  /* ---------- Aktiver Navigationslink beim Scrollen ---------- */
  var sections = document.querySelectorAll("main section[id]");
  var navLinks = document.querySelectorAll(".site-nav a[href^='#']");

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

      // Ohne Backend: Anfrage als E-Mail im Mailprogramm öffnen.
      // Für ein echtes Backend hier fetch() an ein Formular-Skript anbinden.
      var data = new FormData(form);
      var subject = "Anfrage über loggx.de" + (data.get("plan") ? " – " + data.get("plan") : "");
      var body = [
        "Name: " + data.get("name"),
        "E-Mail: " + data.get("email"),
        "Firma: " + (data.get("company") || "–"),
        "Interesse an: " + (data.get("plan") || "Noch unentschieden"),
        "",
        data.get("message")
      ].join("\n");

      window.location.href = "mailto:kontakt@loggx.de?subject=" +
        encodeURIComponent(subject) + "&body=" + encodeURIComponent(body);

      status.className = "form-status is-success";
      status.textContent = "Ihr E-Mail-Programm öffnet sich mit der Anfrage. Vielen Dank!";
      form.reset();
    });
  }
})();
