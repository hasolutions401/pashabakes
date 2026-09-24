<?php
declare(strict_types=1);

/* Cookie flavors, managed in Admin → Menu. */

function menu_cookies(bool $includeHidden = false): array
{
    $sql = 'SELECT id, name, description, type, image, is_available, sort_order, available_from, available_until FROM cookies'
        . ($includeHidden ? '' : ' WHERE is_available = 1')
        . ' ORDER BY sort_order, id';
    return array_map(function (array $c) {
        $c['id'] = (int) $c['id'];
        $c['is_available'] = (int) $c['is_available'];
        $c['sort_order'] = (int) $c['sort_order'];
        return $c;
    }, db_all($sql));
}

/**
 * Flavors shown on the website: available ones, minus any whose pickup dates are
 * already over (e.g. last month's specials).
 */
function public_menu_cookies(): array
{
    $earliest = earliest_pickup_date()->format('Y-m-d');
    return array_values(array_filter(menu_cookies(), fn(array $c) => empty($c['available_until']) || $c['available_until'] >= $earliest));
}

/** True if the flavor can be ordered for pickup on the given YYYY-MM-DD date. */
function cookie_available_on(array $c, string $ymd): bool
{
    return (empty($c['available_from']) || $ymd >= $c['available_from'])
        && (empty($c['available_until']) || $ymd <= $c['available_until']);
}

/** Customer-friendly pickup window, e.g. "October pickups only" or "pickups Dec 1 – Dec 23". */
function cookie_window_text(array $c): string
{
    $from = !empty($c['available_from']) ? parse_date($c['available_from']) : null;
    $until = !empty($c['available_until']) ? parse_date($c['available_until']) : null;
    if ($from && $until) {
        if ($from->format('Y-m') === $until->format('Y-m') && $from->format('j') === '1' && $until->format('j') === $until->format('t')) {
            return $from->format('F') . ' pickups only';
        }
        return 'pickups ' . $from->format('M j') . ' – ' . $until->format('M j');
    }
    if ($from) {
        return 'pickups from ' . $from->format('M j');
    }
    if ($until) {
        return 'pickups until ' . $until->format('M j');
    }
    return '';
}

/**
 * Image URLs for a flavor: full size, card size and thumbnail. Uploaded photos have
 * resized copies (see cookie_store_upload); links to other sites are used as they are.
 */
function cookie_image_variants(string $image): array
{
    $out = ['img' => $image, 'md' => $image, 'sm' => $image];
    if (preg_match('#^((?:uploads|images)/[a-z0-9-]+)\.jpg$#', $image, $m)) {
        $dir = dirname(PB_ROOT) . '/dist/';
        foreach (['md' => '-800', 'sm' => '-160'] as $key => $suffix) {
            if (is_file($dir . $m[1] . $suffix . '.jpg')) {
                $out[$key] = $m[1] . $suffix . '.jpg';
            }
        }
    }
    return $out;
}

function cookie_find(int $id): ?array
{
    return db_one('SELECT * FROM cookies WHERE id = ?', [$id]);
}

/** Validates admin cookie form input. Returns [data, errors]. */
function cookie_validate(array $in): array
{
    $data = [
        'name' => clean_text($in['name'] ?? '', 120),
        'description' => clean_text($in['description'] ?? '', 600),
        'type' => ($in['type'] ?? '') === 'seasonal' ? 'seasonal' : 'signature',
        'image' => clean_text($in['image'] ?? '', 500),
        'is_available' => !empty($in['is_available']) ? 1 : 0,
        'sort_order' => (int) ($in['sort_order'] ?? 0),
        'available_from' => trim((string) ($in['available_from'] ?? '')),
        'available_until' => trim((string) ($in['available_until'] ?? '')),
    ];
    $errors = [];
    if (mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Please enter the cookie name.';
    }
    foreach (['available_from', 'available_until'] as $key) {
        if ($data[$key] === '') {
            $data[$key] = null;
        } elseif (!parse_date($data[$key])) {
            $errors['available'] = 'Use real dates for when this flavor can be picked up.';
        }
    }
    if (!isset($errors['available']) && $data['available_from'] && $data['available_until'] && $data['available_from'] > $data['available_until']) {
        $errors['available'] = 'The first pickup date must be before the last pickup date.';
    }
    if ($data['image'] !== '' && !preg_match('#^(https://|uploads/|images/)#', $data['image'])) {
        $errors['image'] = 'The image must be an uploaded photo or a link starting with https://';
    }
    return [$data, $errors];
}

function cookie_save(?int $id, array $data): int
{
    $now = now_str();
    if ($id) {
        db_exec('UPDATE cookies SET name = ?, description = ?, type = ?, image = ?, is_available = ?, sort_order = ?, available_from = ?, available_until = ?, updated_at = ? WHERE id = ?', [
            $data['name'], $data['description'], $data['type'], $data['image'], $data['is_available'], $data['sort_order'],
            $data['available_from'] ?? null, $data['available_until'] ?? null, $now, $id,
        ]);
        return $id;
    }
    db_exec('INSERT INTO cookies (name, description, type, image, is_available, sort_order, available_from, available_until, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [
        $data['name'], $data['description'], $data['type'], $data['image'], $data['is_available'], $data['sort_order'],
        $data['available_from'] ?? null, $data['available_until'] ?? null, $now, $now,
    ]);
    return (int) db()->lastInsertId();
}

function cookie_delete(int $id): void
{
    // Past orders keep their own copy of the cookie name, so deleting is safe.
    db_transaction(function (PDO $pdo) use ($id) {
        $pdo->prepare('UPDATE order_items SET cookie_id = NULL WHERE cookie_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM cookies WHERE id = ?')->execute([$id]);
    });
}

/**
 * Saves an uploaded cookie photo into dist/uploads/ (resized when GD is available).
 * Also saves an 800px copy for menu cards and a 160px thumbnail for the order form.
 * Returns the relative path, e.g. "uploads/cookie-1a2b3c.jpg".
 */
function cookie_store_upload(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'That photo is too large. Please use one under 8 MB.'
            : 'The photo could not be uploaded. Please try again.');
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('That photo is too large. Please use one under 8 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        throw new RuntimeException('Please upload a JPG, PNG or WebP photo.');
    }

    $dir = dirname(PB_ROOT) . '/dist/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException('The uploads folder is not writable.');
    }
    $name = 'cookie-' . bin2hex(random_bytes(6));

    // Resize large photos to max 1400px wide and save as JPEG to keep pages fast.
    if (extension_loaded('gd') && function_exists('imagecreatefromstring')) {
        $src = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
        if ($src) {
            $src = cookie_photo_orient($src, $file['tmp_name']);
            foreach (['' => 1400, '-800' => 800, '-160' => 160] as $suffix => $maxWidth) {
                if (!cookie_photo_save($src, "{$dir}/{$name}{$suffix}.jpg", $maxWidth)) {
                    throw new RuntimeException('The photo could not be saved.');
                }
            }
            return "uploads/{$name}.jpg";
        }
    }

    $ext = $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}.{$ext}")) {
        throw new RuntimeException('The photo could not be saved.');
    }
    return "uploads/{$name}.{$ext}";
}

/** Writes a JPEG no wider than $maxWidth (never enlarged), on white for transparent PNGs. */
function cookie_photo_save(GdImage $src, string $path, int $maxWidth): bool
{
    [$w, $h] = [imagesx($src), imagesy($src)];
    $scale = min(1, $maxWidth / max($w, 1));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    return imagejpeg($dst, $path, $maxWidth <= 160 ? 80 : 84);
}

/** Phone photos store their rotation in EXIF; apply it so the saved copy is upright. */
function cookie_photo_orient(GdImage $src, string $file): GdImage
{
    if (!function_exists('exif_read_data')) {
        return $src;
    }
    $exif = @exif_read_data($file);
    $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
    if ($angle === 0) {
        return $src;
    }
    $rotated = imagerotate($src, $angle, 0);
    return $rotated === false ? $src : $rotated;
}

/** True for photos stored on this website (uploads/ from admin, images/ shipped with the site). */
function cookie_image_is_local(string $image): bool
{
    return str_starts_with($image, 'uploads/') || str_starts_with($image, 'images/');
}
