<?php
/* QR code (SVG) for the Venmo or Cash App profile set in Admin → Settings. */
require dirname(__DIR__, 2) . '/server/bootstrap.php';

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

$url = ($_GET['m'] ?? '') === 'cashapp' ? cashapp_url() : venmo_url();

$options = new QROptions([
    'outputType' => QROutputInterface::MARKUP_SVG,
    'outputBase64' => false,
    'svgAddXmlHeader' => true,
    'addQuietzone' => true,
    'quietzoneSize' => 2,
    'drawLightModules' => false,
]);

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo (new QRCode($options))->render($url);
