# Pashabakess

Responsive static bakery website. Serve `dist` with any static web server.

## Content and ordering

Edit the six cookie entries in `dist/app.js`. Prices live in `prices` in `dist/app.js` and are also shown in `dist/index.html` (hero note, pricing strip, box-size cards, FAQ) — update them together. Styles are in `dist/style.css` (brand tokens at the top).

After editing `style.css` or `app.js`, bump the `?v=` number on their links in `index.html` so returning visitors get the new files.

Orders and enquiries prepare reviewable email drafts addressed to pashabakess@gmail.com, with copy-to-clipboard fallback. They are not sent automatically. No orders are stored, no payment is processed, and pickup times are preferences, not reservations. The client currently chooses Venmo and Cash App; customers are told to wait for confirmation before paying. Automatic email delivery requires a configured server-side email service; do not put email credentials in frontend files.

Confirmed rules: pickup only in Tyngsboro, normally 10am–7pm Eastern time; address sent on pickup day; seven days’ advance notice; minimum four cookies; orders of 24 and 36 cookies available through the form at the existing $38-per-dozen rate; orders above 36 arranged by email in whole dozens. Larger quantities are not assigned unconfirmed prices. Two seasonal flavors rotate monthly. Storage, cancellation, and allergy wording follows the client’s supplied text; cancellation requires two days’ notice by email, with no invented refund guarantee.

The Instagram QR code points to https://www.instagram.com/pashabakess/ rather than a private preview URL. Regenerate it with the final public ordering URL if desired.

## Before public launch

- Confirm the final October menu and any larger-order pricing before launch.
- Replace illustrative recipe photographs with owner-supplied photos or secure image reuse permission. Linked credits are included. The hero image is by American Heritage Chocolate on Unsplash.
- Original client-supplied logo is included unchanged as `dist/pashabakess-logo.jpg`, used in the header, footer, and favicon.
- Connect an email delivery service for automatic form submission if required; current forms open an email draft.
- Preferred domain: pashabakess.com. Availability, purchase, and DNS are not yet checked or configured. No canonical URL claims ownership of this domain.
- Product labels and home-bakery approval are in progress per the client; no completed approval or certification is claimed on the site.
- Use cookie photographs only, including in the About section; the client does not want a portrait.
- Configure public hosting deliberately. This delivery is a local design preview; the prior hosting attempt did not complete.

## Verification

Checked JavaScript syntax, six menu photographs loading, responsive desktop/mobile layout, flavor filters, a six-flavor box at $20, over-capacity tool rejection, and the email order review with sample data without sending it. WebMCP configure_cookie_box shares the visible state and never submits orders.

September 23 update verified: 4-cookie order at $14; Cash App preference; 7pm Eastern pickup; early-date rejection; 36-cookie enquiry review; mobile layout without horizontal overflow. No test emails or payments were sent.
