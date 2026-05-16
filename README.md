# DrJessie.life

Professional website for **Jessie Keener, N.D.** — Naturopathic Doctor and Human Design Specialist.

The site covers holistic naturopathic care, longevity, breathwork, detox, integrative wellness, books, courses, interviews, and a newsletter.

## Tech Stack

- Static HTML + CSS + vanilla JS (no framework, no build step)
- PHP + MySQL for contact form API and admin inbox
- MailerLite for newsletter signup
- Hosted on Apache shared hosting (BlueHost/cPanel)

## Structure

- HTML pages at project root
- `css/` — Stylesheets (style.css + custom.css)
- `js/` — Scripts
- `images/` — Organized by content type (headers, books, courses, articles, etc.)

## Deployment

This repo auto-deploys to BlueHost with GitHub Actions on every push to `master` (and `main`), via `.github/workflows/deploy.yml`.

Required repository secrets (`Settings` → `Secrets and variables` → `Actions`):

- `BLUEHOST_HOST` — BlueHost host (for example, `drjessie.life` or your server hostname)
- `BLUEHOST_USER` — cPanel SSH/SFTP username
- `BLUEHOST_SSH_KEY` — private SSH key (raw key text or base64-encoded key)
- `BLUEHOST_REMOTE_DIR` — remote publish directory (optional; defaults to `/home/<BLUEHOST_USER>/public_html`)

Legacy fallback names are also supported by the workflow: `SFTP_HOST`, `SFTP_USER`, `SFTP_KEY`, `SFTP_REMOTE_DIR`.

PowerShell command to base64-encode an existing private key (if you store `BLUEHOST_SSH_KEY` encoded):

```powershell
[Convert]::ToBase64String([System.IO.File]::ReadAllBytes("$env:USERPROFILE\.ssh\drjessie_deploy"))
```

Manual upload is still possible, but the expected path is deployment by git push.

### Final deployment workflow (cache-safe)

Use this sequence for every production release so browser caches do not serve stale assets.

1. Pick a release version token in UTC format: `YYYYMMDDHHMM`
2. Run the cache-bust updater from project root:
   - `python bump_asset_version.py --version YYYYMMDDHHMM`
3. Review file changes and verify only intended asset URL bumps were updated.
4. Commit and push to `master` (or `main`) to trigger GitHub Actions deploy.
5. Verify live headers:
   - HTML should return `Cache-Control: no-store, no-cache, must-revalidate, max-age=0`
   - Versioned assets should return `Cache-Control: public, max-age=31536000, immutable`
6. Smoke test on high-risk clients first:
   - iPad Safari
   - iPhone Safari
   - iOS in-app browsers
   - Android WebView / Samsung Internet

