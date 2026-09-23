<?php
/* Public menu + ordering rules for the website (GET). */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
}

$cookies = array_map(fn(array $c) => [
    'id' => $c['id'],
    'name' => $c['name'],
    'desc' => $c['description'],
    'type' => $c['type'],
    'img' => $c['image'],
], menu_cookies());

$prices = [];
foreach (box_prices() as $size => $cents) {
    $prices[(string) $size] = $cents;
}

json_response([
    'ok' => true,
    'cookies' => $cookies,
    'prices' => $prices,
    'minDate' => earliest_pickup_date()->format('Y-m-d'),
    'maxDate' => latest_pickup_date()->format('Y-m-d'),
    'leadDays' => lead_days(),
    'unavailableDates' => unavailable_dates(),
    'slots' => pickup_slots(),
    'occasions' => occasions(),
    'accepting' => accepting_orders(),
    'closedMessage' => setting('closed_message'),
    'pickupArea' => setting('pickup_area'),
    'venmo' => ['handle' => setting('venmo_handle'), 'url' => venmo_url()],
    'cashapp' => ['handle' => setting('cashapp_handle'), 'url' => cashapp_url()],
]);
