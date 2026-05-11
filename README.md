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

