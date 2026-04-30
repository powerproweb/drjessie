/* =========================================================
   DrJessie.life — Shared site-wide scripts
   Consolidates inline JS that was duplicated on every page.
   ========================================================= */

// Header shadow / color shift on scroll
document.addEventListener("scroll", function () {
  var header = document.querySelector("header");
  if (!header) return;
  if (window.scrollY > 10) {
    header.classList.add("is-scrolled");
  } else {
    header.classList.remove("is-scrolled");
  }
});

// Auto-offset main content so the fixed header never overlaps the hero
(function () {
  function setHeaderOffset() {
    var header = document.querySelector("header");
    if (!header) return;
    var h = Math.ceil(header.getBoundingClientRect().height || 0);
    document.documentElement.style.setProperty("--header-offset", h ? (h + "px") : "92px");
  }
  window.addEventListener("load", setHeaderOffset, { once: true });
  window.addEventListener("resize", setHeaderOffset);
  setHeaderOffset();
})();

// Dynamic year in footer
(function () {
  var el = document.getElementById("year");
  if (el) el.textContent = new Date().getFullYear();
})();

// Global dark/light theme toggle (persisted)
(function () {
  var STORAGE_KEY = "drjessie-theme";
  var THEME_TOGGLE_ENABLED = false; // Set to true to re-enable after light-mode styling is finalized.
  var root = document.documentElement;

  if (!THEME_TOGGLE_ENABLED) {
    root.setAttribute("data-theme", "dark");
    try {
      localStorage.setItem(STORAGE_KEY, "dark");
    } catch (err) {}
    return;
  }

  function getStoredTheme() {
    try {
      var saved = localStorage.getItem(STORAGE_KEY);
      if (saved === "light" || saved === "dark") return saved;
    } catch (err) {}
    return null;
  }

  function saveTheme(theme) {
    try {
      localStorage.setItem(STORAGE_KEY, theme);
    } catch (err) {}
  }

  function getPreferredTheme() {
    return (window.matchMedia && window.matchMedia("(prefers-color-scheme: light)").matches)
      ? "light"
      : "dark";
  }

  function setToggleUI(theme) {
    var btn = document.querySelector(".theme-toggle");
    if (!btn) return;

    var icon = btn.querySelector(".theme-toggle-icon");
    var text = btn.querySelector(".theme-toggle-text");
    var isLight = theme === "light";

    if (icon) icon.textContent = isLight ? "☀" : "☾";
    if (text) text.textContent = isLight ? "Light" : "Dark";

    btn.setAttribute("aria-pressed", String(isLight));
    btn.setAttribute("title", isLight ? "Switch to dark mode" : "Switch to light mode");
  }

  function applyTheme(theme) {
    root.setAttribute("data-theme", theme);
    setToggleUI(theme);
  }

  function installThemeToggle() {
    var nav = document.querySelector(".main-nav");
    if (!nav || nav.querySelector(".theme-toggle")) return;

    var btn = document.createElement("button");
    btn.type = "button";
    btn.className = "theme-toggle";
    btn.setAttribute("aria-label", "Toggle light and dark mode");

    var icon = document.createElement("span");
    icon.className = "theme-toggle-icon";
    icon.setAttribute("aria-hidden", "true");

    var text = document.createElement("span");
    text.className = "theme-toggle-text";

    btn.appendChild(icon);
    btn.appendChild(text);
    nav.appendChild(btn);

    btn.addEventListener("click", function () {
      var current = root.getAttribute("data-theme") === "light" ? "light" : "dark";
      var next = current === "light" ? "dark" : "light";
      applyTheme(next);
      saveTheme(next);
    });

    setToggleUI(root.getAttribute("data-theme") || "dark");
  }

  var initialTheme = getStoredTheme() || getPreferredTheme();
  applyTheme(initialTheme);

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", installThemeToggle, { once: true });
  } else {
    installThemeToggle();
  }
})();

// Format buttons: auto-disable when href is missing/placeholder + block clicks when disabled
(function () {
  function isPlaceholderHref(hrefRaw) {
    var href = (hrefRaw || "").trim();
    if (!href) return true;
    var low = href.toLowerCase();
    return (href === "#" || href === "#0" || low.startsWith("javascript"));
  }

  var buttons = document.querySelectorAll('.kdp-links a.buy-btn');
  buttons.forEach(function (btn) {
    var href = btn.getAttribute('href');
    var placeholder = isPlaceholderHref(href);
    var forceDisabled = btn.getAttribute('data-force-disabled') === 'true';

    if (placeholder || forceDisabled) {
      btn.classList.add('is-disabled');
      btn.setAttribute('aria-disabled', 'true');
      btn.setAttribute('tabindex', '-1');
    } else {
      btn.classList.remove('is-disabled');
      btn.removeAttribute('aria-disabled');
      btn.removeAttribute('tabindex');
    }
  });

  // Keep hover/animation alive, but prevent navigation when disabled.
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a.buy-btn');
    if (!a) return;
    var disabled = a.classList.contains('is-disabled') || a.getAttribute('aria-disabled') === 'true';
    if (disabled) {
      e.preventDefault();
      e.stopPropagation();
    }
  }, true);
})();
