# Deploying Pashabakess on alwaysdata

The website (`dist/`) and the ordering system (`server/`) run together on one
alwaysdata PHP site. Nothing needs to be built or installed on the server — you
upload the files, create one config file, and set up the admin login.

**Time needed:** about 20 minutes.

---

## 1. Create the site on alwaysdata

1. Log in to https://admin.alwaysdata.com
2. **Web → Sites → Add a site**
   - **Type:** PHP
   - **Addresses:** your address, e.g. `pashabakess.alwaysdata.net` (or your own domain later)
   - **Root directory:** `pashabakess/dist/` (i.e. `~/pashabakess/dist`)  ← important: point it at the **dist** folder
   - **PHP version:** 8.2 or newer (the live site runs 8.4; the automatic tests run on 8.2 and 8.4)
3. In the site’s **SSL** tab, turn on **Force HTTPS**.
4. Save.

## 2. Upload the files

Upload the whole project folder so that on the server you have:

```
/home/ACCOUNT/pashabakess/
    dist/        ← the website (public)
    server/      ← ordering system (private, not public)
```

Choose one way:

- **SFTP/FTP (easiest):** use FileZilla with the details from alwaysdata → *Remote access → SSH / FTP*.
  Upload the `dist` and `server` folders into `pashabakess/`.
- **Git over SSH:**
  ```bash
  cd ~ && git clone https://github.com/hasolutions401/pashabakes.git pashabakess
  ```
  Later updates happen automatically (see *Updating the site*).

## 3. Create an email address for sending orders

alwaysdata → **Emails → Addresses → Add an address**, e.g. `orders@pashabakess.alwaysdata.net`,
and choose a password. This mailbox sends the order emails (replies still go to pashabakess@gmail.com).

Your SMTP server name is shown under **Emails → Addresses**; it looks like `smtp-ACCOUNT.alwaysdata.net`.

## 4. Create `server/config.php`

1. In the `server/` folder, copy `config.sample.php` to `config.php`.
2. Edit `config.php`:
   - `site_url` → `https://pashabakess.alwaysdata.net` (your real address, no slash at the end)
   - `setup_key` → any long random phrase (you only need it once, in step 5)
   - `db` → leave `'driver' => 'sqlite'` (no database setup needed).
     *Optional:* for MariaDB, create a database under **Databases → MySQL**, set `'driver' => 'mysql'`
     and fill in host, name, user and password.
   - `mail` → `transport` `smtp`, and fill in:
     - `from_email` and `smtp_user`: the address from step 3
     - `smtp_pass`: its password
     - `smtp_host`: from step 3, `smtp_port` `587`, `smtp_secure` `tls`

`config.php` holds passwords — it is never uploaded to GitHub and is not reachable from the web.

## 5. Create the admin login

1. Open `https://YOUR-ADDRESS/admin/`
2. The setup page shows six checks — all should be green ✓.
3. Enter the **setup key** from `config.php`, choose a username and password, and click
   **Create admin account**. The setup page then disables itself.

## 6. Finish in the admin portal

1. **Settings → Exact pickup address** — add Pasha’s pickup address (only sent in confirmation emails).
2. **Settings → Send test email** — check it arrives at pashabakess@gmail.com (look in spam too;
   mark it “Not spam” once).
3. Place one test order on the website, then **Mark as Paid** in admin and check the emails.
   Cancel the test order afterwards.

---

## Everyday use (for Pasha)

- **New order:** you get an email “New order PB1005…”. The customer has been told to pay with
  **PB1005 in the payment note**. When that payment shows up in Venmo/Cash App, tap
  **Open order in admin → Mark as Paid**. The customer is emailed their confirmation with the pickup
  address automatically.
- **Can’t fill an order** (date fully booked, etc.)? Email the customer, refund them in full if they paid,
  then **Cancel order**.
- **Enquiries:** messages from the Contact and Celebrations pages arrive by email (just reply) and are
  listed under **Enquiries** — mark them answered there.
- **Baking list:** the dashboard shows how many of each flavor to bake per pickup day.
- **After pickup:** tap **Mark as Picked up**.
- **Menu:** add flavors, upload photos, hide sold-out flavors, and set the pickup dates for each
  monthly special (e.g. 1–31 October) so it can’t be ordered for other months.
- **Settings:** prices, pickup times, days you’re not available, pausing online orders, and the most
  cookies you can bake per pickup day (full days close automatically).

## Updating the site

Updates go live automatically: each push to the `main` branch is tested and then pulled onto
alwaysdata by GitHub Actions (`.github/workflows/deploy.yml`, needs the `ALWAYSDATA_SSH_PASSWORD` secret in the GitHub **environment** `production` — Settings → Environments → production — which only `main` can use).
The deploy then checks that the live site serves exactly the new files, and fails loudly if not.
If you change the SSH password on alwaysdata, update that environment secret too (there is deliberately no repository-wide copy). By hand: `cd ~/pashabakess && git checkout main && git pull`.

**No other update mechanism may touch `~/pashabakess`.** An old scheduled task (alwaysdata → Advanced → Scheduled
tasks, "Pashabakess: install/update from GitHub") reset the folder to the retired `checkout-backend` branch every
10 minutes, silently undoing every deploy from `main`. It must stay paused or deleted.

## Pickup reminder emails (scheduled task)

The day before pickup, every customer with a paid order gets a reminder email, and Pasha gets the list of
tomorrow's pickups at the same time (from 9 AM Eastern). One scheduled task sends them — it only **runs a
script**, it never updates the code:

- alwaysdata → **Advanced → Scheduled tasks → Add**
- Type: **Execute the command**, command: `php ~/pashabakess/server/tools/send-reminders.php`
- Frequency: **every hour**, name: `Pashabakess: pickup reminders`

Each reminder goes out once, however often the task runs. Without the task, reminders still go out when someone
visits the website or Pasha opens the admin (checked at most every 30 minutes), just less punctually.
Test by hand: `php ~/pashabakess/server/tools/send-reminders.php --now`.

The database upgrades itself on the first visit after an update (a copy of the database is saved first in
`server/data/backups/`, newest five kept) —
for example, the September 2026 update added enquiries and gave the existing monthly specials the pickup
dates of the month customers can next order for. Check **Admin → Menu** afterwards.

## Backups

All orders live in `server/data/pashabakess.sqlite` (or your MariaDB database).
- alwaysdata keeps automatic daily backups: admin.alwaysdata.com → **Backup recovery**. A restore puts the *whole
  account* back to that day (orders placed since then would be lost), so it's the last resort.
- Before every database upgrade the site saves a copy in `server/data/backups/` (newest five kept) — use these to
  recover just the database after a bad update.
- Now and then (e.g. monthly) download `server/data/pashabakess.sqlite` via SFTP and keep it somewhere private; it
  contains customers' names, emails and phone numbers.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| “Setup needed” message | `server/config.php` is missing or has a typo. |
| Test email fails | Check the `mail` section of `config.php` (address, password, SMTP host). |
| Emails land in spam | Mark as “Not spam” in Gmail once; consider a custom domain with SPF/DKIM (alwaysdata → Emails). |
| Website loads but ordering says “temporarily unavailable” | The site’s root directory must be `.../dist`, and `server/` must sit next to it. |
| Errors | See `server/data/logs/php-errors.log` (older lines in `php-errors.1.log`). |
| Site shows an older version a few minutes after a deploy | Something else is updating `~/pashabakess` — check Scheduled tasks (see *Updating the site*). |

## Self-test

Via SSH: `php server/tests/run.php` — runs all backend checks (100+) on a throwaway database in a temporary
folder; it never touches the real orders or `server/data/`.
