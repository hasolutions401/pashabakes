# Pashabakess

Bakery website with one-step online ordering and a private admin portal. `dist/` is the public website; `server/` is the PHP ordering system (PHP 8.1+, SQLite or MySQL). Hosted on alwaysdata — see `DEPLOY-ALWAYSDATA.md`.

## Content and ordering

Customers order and pay on one page (`dist/order.html`): box, flavors, pickup date/time, Venmo or Cash App
payment and their payment username. The order is saved by the PHP backend in `server/` and appears in the
admin portal at `/admin/` as **Payment pending**. Pasha checks Venmo/Cash App and clicks **Mark as Paid**;
the customer is then emailed a confirmation with the pickup address.

- Menu, prices, pickup times, unavailable dates and payment handles are managed in **Admin → Menu / Settings**.
- Emails: new-order alert to Pasha, receipt to the customer, confirmation when marked paid.
- Server-side checks: price, box count, one week’s notice, time slots, duplicate submissions, rate limits.
- Setup and hosting: see `DEPLOY-ALWAYSDATA.md`. Backend tests: `php server/tests/run.php`.

Static text (home page, FAQs) still lives in the HTML files in `dist/`; styles in `dist/style.css`.

## Before public launch

- Confirm the final October menu and any larger-order pricing before launch.
- Replace illustrative recipe photographs with owner-supplied photos or secure image reuse permission. Linked credits are included. The hero image is by American Heritage Chocolate on Unsplash.
- Original client-supplied logo is included unchanged as `dist/pashabakess-logo.jpg`, used in the header, footer, and favicon.
- Set up `server/config.php` (SMTP email) and the admin login on alwaysdata — see `DEPLOY-ALWAYSDATA.md`.
- Preferred domain: pashabakess.com. Availability, purchase, and DNS are not yet checked or configured. No canonical URL claims ownership of this domain.
- Product labels and home-bakery approval are in progress per the client; no completed approval or certification is claimed on the site.
- Use cookie photographs only, including in the About section; the client does not want a portrait.
- Configure public hosting deliberately. This delivery is a local design preview; the prior hosting attempt did not complete.

## Verification

Backend: 27 automated checks pass on SQLite and MySQL 8.4 (`php server/tests/run.php`); 200 orders written by 8 parallel processes were all saved with unique order numbers. Checkout, admin (mark paid, baking list, menu edits, photo upload, settings, test email), login lockout and CSRF protection were tested end to end. WebMCP configure_cookie_box sets the visible box and never submits orders.

September 23 update verified: 4-cookie order at $14; Cash App preference; 7pm Eastern pickup; early-date rejection; 36-cookie enquiry review; mobile layout without horizontal overflow. No test emails or payments were sent.

## Hosting on GitHub Pages

`.github/workflows/pages.yml` publishes `dist/` to GitHub Pages on every push to `main`
(site: https://hasolutions401.github.io/pashabakes/). One-time setup: repository
Settings → Pages → Build and deployment → Source: **GitHub Actions**. After that,
pushing to `main` updates the site automatically; the Actions tab shows each deploy.
