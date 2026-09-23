<?php
declare(strict_types=1);

/* Cookie flavors, managed in Admin → Menu. */

function menu_cookies(bool $includeHidden = false): array
{
    $sql = 'SELECT id, name, description, type, image, is_available, sort_order FROM cookies'
        . ($includeHidden ? '' : ' WHERE is_available = 1')
        . ' ORDER BY sort_order, id';
    return array_map(function (array $c) {
        $c['id'] = (int) $c['id'];
        $c['is_available'] = (int) $c['is_available'];
        $c['sort_order'] = (int) $c['sort_order'];
        return $c;
    }, db_all($sql));
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
    ];
    $errors = [];
    if (mb_strlen($data['name']) < 2) {
        $errors['name'] = 'Please enter the cookie name.';
    }
    if ($data['image'] !== '' && !preg_match('#^(https://|uploads/)#', $data['image'])) {
        $errors['image'] = 'The image must be an uploaded photo or a link starting with https://';
    }
    return [$data, $errors];
}

function cookie_save(?int $id, array $data): int
{
    $now = now_str();
    if ($id) {
        db_exec('UPDATE cookies SET name = ?, description = ?, type = ?, image = ?, is_available = ?, sort_order = ?, updated_at = ? WHERE id = ?', [
            $data['name'], $data['description'], $data['type'], $data['image'], $data['is_available'], $data['sort_order'], $now, $id,
        ]);
        return $id;
    }
    db_exec('INSERT INTO cookies (name, description, type, image, is_available, sort_order, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
        $data['name'], $data['description'], $data['type'], $data['image'], $data['is_available'], $data['sort_order'], $now, $now,
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
            [$w, $h] = [imagesx($src), imagesy($src)];
            $scale = min(1, 1400 / max($w, 1));
            $nw = (int) round($w * $scale);
            $nh = (int) round($h * $scale);
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $path = "{$dir}/{$name}.jpg";
            if (!imagejpeg($dst, $path, 84)) {
                throw new RuntimeException('The photo could not be saved.');
            }
            imagedestroy($src);
            imagedestroy($dst);
            return "uploads/{$name}.jpg";
        }
    }

    $ext = $types[$info[2]];
    if (!move_uploaded_file($file['tmp_name'], "{$dir}/{$name}.{$ext}")) {
        throw new RuntimeException('The photo could not be saved.');
    }
    return "uploads/{$name}.{$ext}";
}
