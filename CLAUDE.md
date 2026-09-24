# Notes for Claude sessions on this repository

Pashabakess — cookie bakery website with online ordering and an admin portal (PHP 8.2 + SQLite).
Owner of the project: Hamza (developer). Client: Pasha (the baker). Customers are in Massachusetts (US English).

## Hosting — read before touching deployment
- Live now: **alwaysdata** (`https://pashabakess.alwaysdata.net`, files in `~/pashabakess`).
- **Planned move to Hostinger.** Everything is prepared; the steps are in `HOSTINGER.md`.
- **Never delete** deployment or hosting files (`.github/workflows/deploy.yml`, `DEPLOY-ALWAYSDATA.md`,
  `HOSTINGER.md`, `server/tools/`, any `.htaccess`) without asking Hamza first.
- These exist **only on the server, never in git** — never delete, overwrite or reset them on a server:
  `server/config.php` (settings, passwords), `server/data/` (all orders, inquiries, admin login),
  `dist/uploads/` or `public_html/uploads/` (photos uploaded in admin).
- `main` is the only branch. Every push to `main` runs `php server/tests/run.php` and, if it passes,
  deploys (`.github/workflows/deploy.yml`): to Hostinger once the `HOSTINGER_HOST` variable is set,
  otherwise to alwaysdata. Deploys only add/overwrite files, never delete.
- The public folder is found by `public_dir()` (`dist/` next to `server/`, or `public_html/` on Hostinger).
- Website address in canonical links / sitemap / robots.txt: change with `php server/tools/set-domain.php https://NEW-DOMAIN`.

## Working rules
- Run `php server/tests/run.php` before every push (all checks must pass).
- Database changes go through a new `PB_SCHEMA_VERSION` step in `server/lib/db.php` (runs on the first
  request after deploy); never assume a fresh database.
- Pasha's confirmed policies (order page, FAQ, `refund_policy_text()` in `server/lib/emails.php` must match):
  pay right after ordering; max 60 cookies per pickup day; cancel/reschedule by email ≥ 2 calendar days before
  pickup → full refund within 3–5 business days; less notice → no refund; not picked up within 2 days → no
  refund; full refund if Pasha cancels.
- Pasha's own photos: originals in `photos/`, web copies in `dist/images/`. Pumpkin Chocolate Chip and Maple
  Pecan still use sample photos from recipe sites (credited on the menu) until Pasha sends hers.
- After editing `dist/app.js` or `dist/style.css`, bump their `?v=` number in every HTML page.
