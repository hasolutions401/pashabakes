<?php
/* Public menu + ordering rules for the website (GET). */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$cookies = array_map(function (array $c) {
    $img = cookie_image_variants($c['image']);
    return [
        'id' => $c['id'],
        'name' => $c['name'],
        'desc' => $c['description'],
        'type' => $c['type'],
        'img' => $img['img'],
        'imgMd' => $img['md'],
        'imgSm' => $img['sm'],
        // Pickup dates this flavor can be ordered for (null = no limit).
        'from' => $c['available_from'] ?: null,
        'until' => $c['available_until'] ?: null,
    ];
}, public_menu_cookies());

$prices = [];
foreach (box_prices() as $size => $cents) {
    $prices[(string) $size] = $cents;
}

json_response([
    'ok' => true,
    'cookies' => $cookies,
    'prices' => $prices,
    // "Other amount": any number of cookies in this range at this price each (0 = option off).
    'cookiePrice' => cookie_price(),
    'customMin' => PB_CUSTOM_MIN,
    'customMax' => PB_MAX_COOKIES,
    'minDate' => earliest_pickup_date()->format('Y-m-d'),
    'maxDate' => latest_pickup_date()->format('Y-m-d'),
    'leadDays' => lead_days(),
    'unavailableDates' => unavailable_dates(),
    'maxCookiesPerDay' => max_cookies_per_day(),
    'payWithinHours' => payment_hours(),
    'paymentHoldText' => payment_hold_text(),
    'dayRemaining' => (object) days_remaining(),
    'slots' => pickup_slots(),
    'occasions' => occasions(),
    'accepting' => accepting_orders(),
    'closedMessage' => setting('closed_message'),
    'pickupArea' => setting('pickup_area'),
    'venmo' => ['handle' => setting('venmo_handle'), 'url' => venmo_url()],
    'cashapp' => ['handle' => setting('cashapp_handle'), 'url' => cashapp_url()],
    // Ads measurement: null = switched off (no cookie banner, no pixel). See META-ADS.md.
    'tracking' => meta_enabled() ? ['metaPixelId' => meta_pixel_id()] : null,
]);
