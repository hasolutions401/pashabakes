# Meta (Facebook/Instagram) ads measurement

Built in, **switched off**. While `server/config.php` has no `meta.pixel_id`, visitors see no cookie banner,
no Meta script is loaded and nothing is sent anywhere.

## What it does once switched on

- A small cookie banner: **Allow** / **No thanks** (plus "Cookie settings" in the footer to change it later).
  Nothing from Meta loads until the visitor clicks Allow.
- Browser (Meta Pixel), only after Allow:

  | Event | When |
  | --- | --- |
  | PageView | every page |
  | ViewContent | menu and order page |
  | AddToCart | first cookie added to the box |
  | InitiateCheckout | "Place order" pressed with a valid form |
  | Lead | order saved (event id = order number) |

- Server (Conversions API, `server/lib/meta.php`), only for customers who clicked Allow:
  - **Lead** when the order is saved — same event id as the browser, so Meta counts it once.
  - **Purchase** when Pasha clicks **Mark as Paid** in admin — the only moment payment is really confirmed
    (payment happens in Venmo/Cash App, so the website never "sees" a purchase).
- Sent to Meta: order number, box size, total, SHA-256-hashed email and phone, Meta's own browser ids
  (`_fbp`/`_fbc` cookies) and, for the Lead only, the customer's IP address and browser type.
  **Never** sent: name, notes, payer name, pickup address, payment details.

## Switching it on (in this order)

1. **Approve the privacy text below** (and ideally have it looked at by someone qualified).
2. Create the Meta Business account and a dataset/pixel (Meta Events Manager → Connect data → Web),
   then **Settings → Conversions API → Generate access token**.
3. In the repository (one commit, merged only with "approve merge"):
   - add the approved section to `dist/privacy.html` (id `advertising`) and update its date;
   - in `dist/.htaccess`, allow Meta in the Content-Security-Policy:
     `script-src 'self' https://connect.facebook.net;` and `connect-src 'self' https://www.facebook.com https://connect.facebook.net;`
4. On the server, in `server/config.php`, fill the `meta` section (see `config.sample.php`):
   `pixel_id`, `capi_token`, and for testing `test_event_code` from Events Manager → Test events.
5. Test with your own phone: Allow → view menu → add a cookie → place a small order → check Events Manager →
   Test events shows PageView, ViewContent, AddToCart, InitiateCheckout, Lead (browser **and** server, deduplicated).
   Mark the test order as paid → Purchase (server). Then cancel and delete the test order.
6. Empty `test_event_code` again.
7. Domain: verify the website domain in Meta Business settings (meta-tag or DNS) — ideally on the final domain
   (pashabakess.com) so it doesn't have to be redone after the move.

## Switching it off

Empty `meta.pixel_id` in `server/config.php`. The banner and pixel disappear at once; nothing else changes.

## Draft privacy text (for approval — NOT live)

> ### Advertising and ads measurement
> If you click **Allow** in the cookie banner, we use Meta's tools (the Meta Pixel and the Conversions API) to see
> whether our Facebook and Instagram ads lead to orders. Meta then receives: which pages of this site you view and
> when you add cookies to a box or place an order; your order number, box size and total; and, when you place an
> order or when Pasha confirms your payment, your email address and phone number in a scrambled (hashed) form, your
> IP address and browser type (when you place the order), so Meta can match the order to an ad. Meta uses this
> under its own data policy (facebook.com/privacy/policy). We never send your name, your notes, your pickup
> address or your payment details. If you choose **No thanks**, or don't choose, none of this happens. You can
> change your choice at any time with **Cookie settings** at the bottom of every page, and manage ads in your
> Facebook or Instagram ad settings.

Also update, when switching on:
- "How we use it": replace "we don't use it for advertising" with "With your permission (see *Advertising and ads
  measurement*), we use Meta's tools to measure our ads. We don't sell or rent your information and don't send
  marketing emails."
- "Cookies and storage in your browser": "If you allow ads measurement, Meta sets its own cookies (`_fbp`, and `_fbc`
  when you arrive from an ad). Your cookie choice is remembered in your browser's local storage."
- "Where it's kept and who can see it": add Meta as a recipient (only with your permission).

Questions for a qualified reviewer: whether any state privacy law applies at the business's size, and whether
the banner wording meets Meta's Business Tools Terms for the audiences the ads target.
