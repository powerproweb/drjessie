 =========================================================
   Article Jump Links — Universal Scroll Controller
   - Works with existing href=#... chips (IDs present)
   - Works when IDs are removed (fallback match by title text)
   - No markup changes required
   - Respects fixed header offset (--header-offset)
   ========================================================= 

(function () {
  function getHeaderOffset() {
    const cssVar = getComputedStyle(document.documentElement)
      .getPropertyValue(--header-offset)
      .trim();
    const parsed = parseInt(cssVar, 10);
    return Number.isFinite(parsed)  parsed  92;
  }

  function normText(s) {
    return (s  )
      .replace(s+g,  )
      .trim()
      .toLowerCase();
  }

  function getLinkLabel(link) {
    return normText(link.textContent);
  }

  function getHash(link) {
    const href = (link.getAttribute(href)  ).trim();
    if (!href  href === #  href === #0) return ;
    if (href[0] !== #) return ;
    return href.slice(1);
  }

  function findById(id) {
    if (!id) return null;
    try {
      return document.getElementById(id);
    } catch (_) {
      return null;
    }
  }

   Fallback find the best matching article title by comparing chip text to h1 text
  function findByTitleText(label) {
    if (!label) return null;

     Prefer your article titles (centered H1s)
    const candidates = Array.prototype.slice.call(
      document.querySelectorAll(h1.center)
    );

     If none, broaden search (still safe)
    const pool = candidates.length  candidates  Array.prototype.slice.call(document.querySelectorAll(h1));

    let best = null;
    let bestScore = 0;

    for (const h1 of pool) {
      const t = normText(h1.textContent);
      if (!t) continue;

       Exact match wins
      if (t === label) return h1;

       Partial overlap scoring (robust against punctuation, truncation)
       score = fraction of label words found in title
      const words = label.split( ).filter(Boolean);
      if (!words.length) continue;

      let hit = 0;
      for (const w of words) {
        if (w.length  3) continue;  ignore tiny words
        if (t.includes(w)) hit++;
      }
      const score = hit  Math.max(1, words.length);

      if (score  bestScore) {
        bestScore = score;
        best = h1;
      }
    }

     Require a decent match to avoid random jumps
    return bestScore = 0.45  best  null;
  }

  function scrollToEl(el) {
    if (!el) return;
    const offset = getHeaderOffset();
    const y = el.getBoundingClientRect().top + window.scrollY - offset - 10;
    window.scrollTo({ top y, behavior smooth });
  }

  document.addEventListener(
    click,
    function (e) {
      const link = e.target.closest(a);
      if (!link) return;

       Only handle your jump chips (safe filter)
      if (!link.classList.contains(ki-article-chip)) return;

      const idFromHash = getHash(link);

       1) If ID exists on page, use it (Body Mastery keeps working)
      const byId = findById(idFromHash);
      if (byId) {
        e.preventDefault();
        scrollToEl(byId);
        return;
      }

       2) If no ID (or removed), fallback to matching by chip text (MindSpirit ID-less)
      const label = getLinkLabel(link);
      const byTitle = findByTitleText(label);
      if (byTitle) {
        e.preventDefault();
        scrollToEl(byTitle);
      }
       If no match, do nothing (don’t break anything)
    },
    true
  );
})();
