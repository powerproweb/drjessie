/* =========================================================
   DRJK INTERVIEWS — GRID CONTROLLER (YouTube + Rumble)
   Behavior:
   - Page shows 2 rows x 3 (6 cards) on load.
   - Top 3 are real videos (data-video set in HTML).
   - Remaining slots are placeholders using: images/interviews/interview_coming_soon.jpg
   - "Load More" ALWAYS stays visible and appends 2 more rows (6 placeholders) per click.
   - When you later add data-video="..." to a card, it will auto-wire for playback.
   ========================================================= */

(function () {
  var PLACEHOLDER_IMG = "/images/interviews/img00coming_soon.jpg";

  function isRumble(u){ return /(?:^|\/\/)rumble\.com\//i.test(u || ""); }

  function parseYouTubeId(url) {
    try {
      var u = new URL(url, window.location.href);
      if (/youtube\.com$/i.test(u.hostname) || /www\.youtube\.com$/i.test(u.hostname)) {
        if (u.searchParams.get("v")) return u.searchParams.get("v");
      }
      if (/youtu\.be$/i.test(u.hostname)) {
        var id = (u.pathname || "").replace("/", "");
        return id || "";
      }
      var m = String(url || "").match(/(?:embed\/|shorts\/)([A-Za-z0-9_-]{6,})/);
      return m ? m[1] : "";
    } catch (e) {
      var m2 = String(url || "").match(/(?:v=|youtu\.be\/|embed\/|shorts\/)([A-Za-z0-9_-]{6,})/);
      return m2 ? m2[1] : "";
    }
  }

  function setOpenLink(card, href, label) {
    var a = card.querySelector("[data-open-link]");
    if (!a) return;
    a.href = href || "#";
    a.textContent = label || "Open";
  }

  function makeYouTubeIframe(id) {
    var iframe = document.createElement("iframe");
    iframe.src = "https://www.youtube-nocookie.com/embed/" + encodeURIComponent(id) + "?autoplay=1&rel=0";
    iframe.title = "YouTube video player";
    iframe.loading = "lazy";
    iframe.allow = "accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share";
    iframe.allowFullscreen = true;
    iframe.style.width = "100%";
    iframe.style.aspectRatio = "16 / 9";
    iframe.style.border = "0";
    return iframe;
  }

  function makeRumbleIframeFromHtml(htmlString) {
    var tmp = document.createElement("div");
    tmp.innerHTML = htmlString || "";
    var iframe = tmp.querySelector("iframe");
    if (!iframe) return null;

    iframe.removeAttribute("width");
    iframe.removeAttribute("height");
    iframe.style.width = "100%";
    iframe.style.aspectRatio = "16 / 9";
    iframe.style.border = "0";
    iframe.loading = "lazy";
    iframe.setAttribute("allowfullscreen", "true");
    iframe.setAttribute("allow", "autoplay; fullscreen; picture-in-picture");
    return iframe;
  }

  function rumbleOEmbed(url) {
    var endpoint = "https://wn0.rumble.com/api/Media/oembed.json?url=" + encodeURIComponent(url);
    return fetch(endpoint, { mode:"cors", credentials:"omit", cache:"no-store" })
      .then(function (r) { if (!r.ok) throw new Error("Rumble oEmbed HTTP " + r.status); return r.json(); });
  }

  function showLoading(media) {
    var div = document.createElement("div");
    div.style.width = "100%";
    div.style.aspectRatio = "16 / 9";
    div.style.display = "flex";
    div.style.alignItems = "center";
    div.style.justifyContent = "center";
    div.style.background = "rgba(0,0,0,.35)";
    div.style.color = "rgba(255,255,255,.9)";
    div.style.fontWeight = "800";
    div.textContent = "Loading…";
    media.innerHTML = "";
    media.appendChild(div);
  }

  function markAsPlaceholder(card) {
    card.classList.add("is-placeholder");
    card.removeAttribute("data-video");

    var img = card.querySelector(".ki-video-thumb img");
    if (img) img.src = PLACEHOLDER_IMG;

    var thumbBtn = card.querySelector(".ki-video-thumb");
    if (thumbBtn) {
      thumbBtn.setAttribute("disabled", "disabled");
      thumbBtn.setAttribute("aria-label", "Coming soon");
    }

    var watchBtn = card.querySelector(".ki-watch-btn");
    if (watchBtn) {
      watchBtn.textContent = "Coming Soon";
      watchBtn.setAttribute("disabled", "disabled");
    }

    var a = card.querySelector("[data-open-link]");
    if (a) {
      a.removeAttribute("href");
      a.textContent = "—";
      a.style.textDecoration = "none";
      a.style.opacity = ".7";
      a.setAttribute("aria-hidden", "true");
    }


    // Normalize the meta blurb to match "Interview Coming Soon" placeholders
    var blurb = card.querySelector(".ki-video-blurb");
    if (blurb) {
      blurb.innerHTML = "";
      var s1 = document.createElement("span");
      s1.className = "ki-video-source";
      s1.textContent = "Interview Coming Soon";

      var dot = document.createElement("span");
      dot.className = "ki-dot";
      dot.setAttribute("aria-hidden", "true");
      dot.textContent = "•";

      var s2 = document.createElement("span");
      s2.className = "ki-video-cta";
      s2.textContent = "Name Here";

      blurb.appendChild(s1);
      blurb.appendChild(dot);
      blurb.appendChild(s2);
    }

  }

  function wireVideoCard(card) {
    // If no video URL, force placeholder behavior
    var raw = (card.getAttribute("data-video") || "").trim();
    if (!raw || raw === "#") { markAsPlaceholder(card); return; }

    card.classList.remove("is-placeholder");

    var thumbBtn = card.querySelector(".ki-video-thumb");
    var watchBtn = card.querySelector(".ki-watch-btn");
    if (!thumbBtn || !watchBtn) return;

    thumbBtn.removeAttribute("disabled");
    watchBtn.removeAttribute("disabled");
    watchBtn.textContent = "Watch Now!";

    // Set Open link
    if (isRumble(raw)) {
      setOpenLink(card, raw, "Open on Rumble");
    } else {
      var yid0 = parseYouTubeId(raw);
      if (yid0) setOpenLink(card, "https://www.youtube.com/watch?v=" + encodeURIComponent(yid0), "Open on YouTube");
      else setOpenLink(card, raw, "Open");
    }

    function loadVideo() {
      if (card.getAttribute("data-loaded") === "1") return;
      card.setAttribute("data-loaded", "1");

      var media = card.querySelector(".ki-video-media");
      if (!media) return;

      // RUMBLE (fetch embed on demand so clicking instantly still works)
      if (isRumble(raw)) {
        showLoading(media);
        rumbleOEmbed(raw).then(function (o) {
          var html = (o && o.html) ? o.html : "";
          var rif = makeRumbleIframeFromHtml(html);
          if (rif) {
            media.innerHTML = "";
            media.appendChild(rif);
          } else {
            card.setAttribute("data-loaded", "0");
            window.open(raw, "_blank", "noopener");
          }
        }).catch(function () {
          card.setAttribute("data-loaded", "0");
          window.open(raw, "_blank", "noopener");
        });
        return;
      }

      // YOUTUBE
      var ytid = parseYouTubeId(raw);
      if (!ytid) { card.setAttribute("data-loaded", "0"); return; }
      media.innerHTML = "";
      media.appendChild(makeYouTubeIframe(ytid));
    }

    // Avoid double-binding: remove existing by cloning nodes (simple + safe)
    function rebind(btn, handler) {
      var clone = btn.cloneNode(true);
      btn.parentNode.replaceChild(clone, btn);
      clone.addEventListener("click", handler);
      clone.addEventListener("keydown", function (e) {
        if (e.key === "Enter" || e.key === " ") { e.preventDefault(); handler(); }
      });
      return clone;
    }

    thumbBtn = rebind(thumbBtn, loadVideo);
    watchBtn = rebind(watchBtn, loadVideo);
  }

  function createPlaceholderCard() {
    var article = document.createElement("article");
    article.className = "ki-video-card is-placeholder";
    article.innerHTML =
      '<div class="ki-video-media">' +
        '<button class="ki-video-thumb" type="button" aria-label="Coming soon" disabled>' +
          '<img src="' + PLACEHOLDER_IMG + '" alt="Interview coming soon" loading="lazy">' +
          '<span class="ki-play-badge" aria-hidden="true"></span>' +
        '</button>' +
        '<button class="ki-watch-btn" type="button" disabled>Coming Soon</button>' +
      '</div>' +
      '<div class="ki-video-meta" role="group" aria-label="Video details">' +
        '<h2 class="ki-video-title">Interview Coming Soon</h2>' +
        '<p class="ki-video-blurb">' +
          '<span class="ki-video-source">Interview Coming Soon</span>' +
          '<span class="ki-dot" aria-hidden="true">•</span>' +
          '<span class="ki-video-cta">Name Here</span>' +
        '</p>' +
        '<div class="ki-video-actions">' +
          '<span class="ki-video-link" aria-hidden="true" style="opacity:.7;text-decoration:none;">—</span>' +
        '</div>' +
      '</div>';
    return article;
  }

  function ensureInitialSix(grid) {
    var cards = grid.querySelectorAll(".ki-video-card");
    var count = cards.length;
    for (var i = count; i < 6; i++) grid.appendChild(createPlaceholderCard());
  }

  function appendTwoRows(grid) {
    for (var i = 0; i < 6; i++) grid.appendChild(createPlaceholderCard());
  }

  document.addEventListener("DOMContentLoaded", function () {
    var grid = document.getElementById("kiVideoGrid");
    var btn  = document.getElementById("kiLoadMore");
    if (!grid || !btn) return;

    // Ensure exactly 6 visible slots exist initially
    ensureInitialSix(grid);

    // Wire any existing cards (top 3 real videos included in HTML)
    Array.prototype.slice.call(grid.querySelectorAll(".ki-video-card")).forEach(wireVideoCard);

    // Load More ALWAYS appends placeholders (until you replace data-video)
    btn.addEventListener("click", function () {
      appendTwoRows(grid);
      // Normalize newly appended placeholders (and future real cards if you swap data-video later)
      Array.prototype.slice.call(grid.querySelectorAll(".ki-video-card")).forEach(wireVideoCard);
    });

    // Optional: if you later set data-video via devtools/CMS, you can call:
    // window.DRJK_WIRE_INTERVIEW_CARD(el) to wire it instantly
    window.DRJK_WIRE_INTERVIEW_CARD = wireVideoCard;
  });
})();