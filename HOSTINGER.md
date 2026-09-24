# Moving Pashabakess to Hostinger

Everything in the code is already prepared: the website finds its folders on Hostinger by itself, and the
GitHub auto-deploy has a Hostinger route that switches on as soon as the details below are added.
Nothing on the server is ever deleted by a deploy — photos, orders and settings stay.

**Time needed:** about 45 minutes. Keep alwaysdata running until the last step.

What moves where:

| alwaysdata (now)                         | Hostinger (new)                                     |
| ---------------------------------------- | --------------------------------------------------- |
| `~/pashabakess/dist/`                     | `~/domains/YOUR-DOMAIN/public_html/`                |
| `~/pashabakess/server/`                   | `~/domains/YOUR-DOMAIN/server/` (not public)        |
| `server/config.php` — settings, passwords | copied by hand (step 8)                             |
| `server/data/` — **all orders, inquiries, admin login** | copied by hand (step 8)              |
| `dist/uploads/` — photos uploaded in admin | copied by hand into `public_html/uploads/` (step 8) |

`config.php`, `data/` and `uploads/` are not on GitHub (they hold passwords and customer details),
so they have to be copied once from the old server.

---

## Part A — Hostinger account (hPanel)

1. **Plan:** Premium or Business *Web Hosting* (both include SSH and PHP).
2. **Add the website:** hPanel → Websites → Add website → use your domain (e.g. `pashabakess.com`).
   Skip any website builder / WordPress offer ("Create an empty website").
3. **PHP:** Websites → Manage → Advanced → **PHP Configuration** → PHP **8.2 or newer**.
   Under *PHP extensions*, make sure `pdo_sqlite`, `gd` and `mbstring` are ticked.
4. **SSH:** Advanced → **SSH Access** → Enable. Write down the **IP**, **Port** (usually `65002`) and
   **Username** (looks like `u123456789`). Set an SSH password there if asked.
5. **HTTPS:** Security → **SSL** → install the free SSL certificate, then turn on **Force HTTPS**.

## Part B — Connect GitHub (automatic deploys)

6. GitHub → repository **Settings → Secrets and variables → Actions**:
   - **Secrets** tab → *New repository secret*: `HOSTINGER_SSH_PASSWORD` = the SSH password from step 4.
   - **Variables** tab → *New repository variable*, four times:
     - `HOSTINGER_HOST` = the IP from step 4
     - `HOSTINGER_PORT` = the port from step 4 (e.g. `65002`)
     - `HOSTINGER_USER` = the username from step 4 (e.g. `u123456789`)
     - `HOSTINGER_DOMAIN` = your domain exactly as in hPanel (e.g. `pashabakess.com`)
7. GitHub → **Actions → Deploy website → Run workflow**. This copies the website and ordering system to
   Hostinger. The last check ("live site answers") fails this first time because `config.php` isn't
   there yet — that's expected. From now on every push to `main` deploys to Hostinger (alwaysdata stops
   receiving updates automatically).

## Part C — Copy orders, settings and photos from alwaysdata

8. **On alwaysdata** (admin.alwaysdata.com → Remote access → SSH → *on the Web*), pack everything up:
   ```bash
   cd ~/pashabakess && tar czf ~/pashabakess-move.tar.gz server/config.php server/data dist/uploads && ls -la ~/pashabakess-move.tar.gz
   ```
   **On Hostinger** (log in with SSH: hPanel shows the command, e.g. `ssh -p 65002 u123456789@IP`), fetch
   and unpack it (replace `pashabakess.com` with your domain; it asks for the *alwaysdata* SSH password):
   ```bash
   cd ~/domains/pashabakess.com && scp pashabakess@ssh-pashabakess.alwaysdata.net:pashabakess-move.tar.gz . \
     && mkdir -p move && tar xzf pashabakess-move.tar.gz -C move \
     && cp -a move/server/config.php server/ && cp -a move/server/data server/ \
     && mkdir -p public_html/uploads && cp -a move/dist/uploads/. public_html/uploads/ \
     && echo MOVED
   ```
   (No SSH on one side? Download `pashabakess-move.tar.gz` with FileZilla from alwaysdata and upload it
   to `domains/YOUR-DOMAIN/` on Hostinger, then run the second command without the `scp …` part.)
9. **Update `server/config.php`** for the new address and mail (on Hostinger):
   ```bash
   cd ~/domains/pashabakess.com/server \
     && sed -i "s#https://pashabakess.alwaysdata.net#https://pashabakess.com#" config.php \
     && sed -i "s/'transport'\s*=>\s*'smtp'/'transport' => 'mail'/" config.php \
     && grep -n "site_url\|sqlite\|transport" config.php
   ```
   Check the output: `site_url` shows the new domain, `transport` is `mail`, and the `sqlite` line uses
   `__DIR__ . '/data/pashabakess.sqlite'` (if it shows a `/home/pashabakess/…` path instead, change it to that).
   Emails keep going out through Pasha's Gmail (the app password is stored with the orders, so it moved too).
10. GitHub → **Actions → Deploy website → Run workflow** again. Now every step should be ✓.

## Part D — Check, switch the address, retire alwaysdata

11. Open `https://YOUR-DOMAIN/` and `https://YOUR-DOMAIN/admin/` — log in with the **same** username and
    password as before; the old orders and inquiries are there. Then:
    - **Settings → Send test email** (should say "through Gmail"),
    - place one small test order, check both emails arrive, then cancel and delete it.
12. **Website address:** tell Claude "the domain is YOUR-DOMAIN" — or run
    `php server/tools/set-domain.php https://YOUR-DOMAIN` in the project, commit and push. This updates
    canonical links, the sitemap and robots.txt.
13. **Old address keeps working:** on alwaysdata, send every old link to the new site:
    ```bash
    printf 'RewriteEngine On\nRewriteRule ^(.*)$ https://pashabakess.com/$1 [R=301,L]\n' > ~/pashabakess/dist/.htaccess
    ```
14. Keep alwaysdata for a week as a backup, then cancel it and delete the `ALWAYSDATA_SSH_PASSWORD`
    secret on GitHub. Update the link in Instagram and anywhere else you shared it.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Site shows "Setup needed" | `server/config.php` is missing on Hostinger — redo step 8. |
| "The ordering system is temporarily unavailable" | Check PHP 8.2 and `pdo_sqlite` (step 3). |
| Deploy says "Folder … not found" | `HOSTINGER_DOMAIN` must match the folder name in `~/domains/`. |
| Deploy can't log in | Check `HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER` and the password secret. |
| Emails in spam | Admin → Settings → Email sending: add the Gmail app password. |
| Errors | `~/domains/YOUR-DOMAIN/server/data/logs/php-errors.log` |
