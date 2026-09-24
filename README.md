# Pashabakess

Bakery website with online ordering (order first, then pay with Venmo or Cash App) and a private admin portal. `dist/` is the public website; `server/` is the PHP ordering system (PHP 8.1+, SQLite or MySQL). Hosted on alwaysdata — see `DEPLOY-ALWAYSDATA.md`.

## Content and ordering

Customers order first and pay second (`dist/order.html`): box, flavors, pickup date/time and their choice of
Venmo or Cash App. The PHP backend in `server/` saves the order as **Payment pending** and gives it an order
number (e.g. PB1005). The next screen, and the receipt email, then show how to pay: the amount, Pasha's
handle, a QR code, and "write PB1005 in the payment note". Nothing is paid before the order exists, so a failed
submission can never leave Pasha with money and no order. Pasha checks Venmo/Cash App, then clicks
**Mark as Paid** in `/admin/`; the customer is emailed a confirmation with the pickup address.

- The checkout form is saved in the browser tab (sessionStorage) so a refresh doesn't lose it, and the payment
  screen comes back if the page reloads after ordering. Both are cleared when the order is placed or the tab closes.
- **Monthly specials:** each flavor can have "first/last pickup date" in **Admin → Menu → Edit**. Customers can't
  choose it for other pickup dates (checked in the browser and on the server), and it drops off the menu once
  its last date has passed. Pages show the month name from these dates ("October specials").
- **Enquiries** from the Contact and Celebrations pages are saved on the server, emailed to Pasha (reply goes
  straight to the customer), and listed in **Admin → Enquiries**. If the server can't be reached, the form offers
  the message as an email instead. Without JavaScript the form still posts normally.
- Menu, prices, pickup times, unavailable dates and payment handles are managed in **Admin → Menu / Settings**.
- Emails: new-order alert to Pasha, receipt with payment instructions to the customer, confirmation when marked
  paid, and new-enquiry alerts.
- Server-side checks: price, box count, flavor pickup dates, one week's notice, time slots (Eastern time),
  the daily cookie limit, duplicate submissions, rate limits.
- **Daily limit:** Admin → Settings → "Most cookies you can bake for one pickup day" (0 = no limit). Unpaid
  orders hold their place; cancelling frees it. It is re-checked while saving, so two customers ordering at the
  same moment can't both take the last spot.
- Uploaded flavor photos are saved at 1400px plus an 800px copy for menu cards and a 160px thumbnail for the
  order form.
- Setup and hosting: see `DEPLOY-ALWAYSDATA.md`. Backend tests: `php server/tests/run.php`. The database
  upgrades itself on the first request after an update.

Text that lives in several places — keep it in step when it changes:

- **Cancellation and refund policy:** `dist/order.html`, `dist/faq.html` (`#faq-cancel`) and
  `refund_policy_text()` in `server/lib/emails.php`.
- **Menu cards without JavaScript:** the static cards in `dist/index.html` and `dist/menu.html` match
  `FALLBACK_COOKIES` in `dist/app.js`. The live menu from Admin replaces them as soon as the page loads.
- **Prices** written in page text (home, menu, FAQs, Celebrations).

Static text (home page, FAQs, privacy notice) lives in the HTML files in `dist/`; styles in `dist/style.css`.
After editing `style.css` or `app.js`, bump the `?v=` number on their links in every HTML page.

## Search and sharing

Every page has a canonical URL, Open Graph tags and a social preview image (`dist/og-image.jpg`, 1200×630).
`dist/sitemap.xml` and `dist/robots.txt` list the pages and keep `/admin/` and `/api/` out of search.
They all use **https://pashabakess.alwaysdata.net/**. If the site moves to its own domain, replace that
address everywhere: `grep -rl pashabakess.alwaysdata.net dist | xargs sed -i 's#pashabakess.alwaysdata.net#NEW-DOMAIN#g'`

## Before public launch

- **Photos:** Pasha's own photos are in `dist/images/` (originals in `photos/`). No borrowed photos are left:
  Pumpkin Chocolate Chip and Maple Pecan show a branded "photo coming soon" image until Pasha uploads hers
  (Admin → Menu → Edit). M&M is in Admin → Menu as a
  **hidden** flavor with its photo, ready for a future rotation: set its pickup dates and click Show.
- **Payment deadline:** Admin → Settings → Payments → "Ask customers to pay within (hours)". 0 (default) states
  no deadline; customers are told their pickup date is held while Pasha waits for payment.
- **Refund policy:** the published wording (full refund with at least two days' notice, none after, full
  refund if Pasha cancels) is awaiting Pasha's confirmation, including how quickly refunds are sent.
- **Privacy notice** (`dist/privacy.html`): ask Pasha to review it, especially how long records are kept.
- **Venmo:** payments go to the personal profile @Palosha-Rashid, which shows Pasha's full name. Venmo expects
  sales to use a business profile; confirm with Pasha.
- Confirm the monthly specials and any larger-order pricing each month.
- Original client-supplied logo is included unchanged as `dist/pashabakess-logo.jpg`, used in the header, footer, and favicon.
- Product labels and home-bakery approval are in progress per the client; no completed approval or certification is claimed on the site.
- The client previously asked for cookie photographs only (no portrait) in the About section; a baking photo of
  Pasha would suit "Meet Pasha" better if she changes her mind.

## Verification

Backend: 62 automated checks pass on SQLite (`php server/tests/run.php`), including pay-after-order, flavor pickup
dates, enquiries and the upgrade of an existing database. Browser checks (Chromium, desktop and 390px mobile)
covered: menu filters and their screen-reader announcements, Add → order page, checkout draft kept after refresh,
an October special removed for a November pickup (and rejected by the server), placing an order and seeing the
payment screen again after a reload, contact/celebration enquiries (with and without JavaScript, and the email
fallback when the server is down), admin enquiries, flavor dates and photo resizing. No real emails or payments
were sent.

## Hosting

The live site is **https://pashabakess.alwaysdata.net/** only (see `DEPLOY-ALWAYSDATA.md`).
**Deploying is automatic:** every push to `main` runs the backend tests and, if they pass,
`.github/workflows/deploy.yml` logs in to alwaysdata and runs `git pull` in `~/pashabakess`, then checks the
live site answers. It needs the repository secret `ALWAYSDATA_SSH_PASSWORD` (Settings → Secrets and variables →
Actions). Progress and any errors show in the repository's **Actions** tab. The old GitHub
Pages copy at hasolutions401.github.io/pashabakes was an early version whose "order" button only opened an
email draft, so orders placed there never reached the admin portal. Its workflow has been removed and the
site unpublished (repository Settings → Pages). Don't re-enable GitHub Pages: it can't run the ordering system.
