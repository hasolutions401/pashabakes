<?php
/* Add or edit one cookie flavor. */
require __DIR__ . '/_init.php';
$user = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$cookie = $id ? cookie_find($id) : null;
if ($id && !$cookie) {
    flash('That flavor could not be found.', 'error');
    redirect('menu.php');
}

$values = $cookie ?? ['name' => '', 'description' => '', 'type' => 'signature', 'image' => '', 'is_available' => 1,
    'sort_order' => ((int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM cookies')) + 10,
    'available_from' => null, 'available_until' => null];
[$monthFrom, $monthUntil] = seasonal_window_default(db());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$data, $errors] = cookie_validate($_POST);
    if (!empty($_FILES['photo']['name'])) {
        try {
            $data['image'] = cookie_store_upload($_FILES['photo']);
        } catch (RuntimeException $ex) {
            $errors['image'] = $ex->getMessage();
        }
    } elseif (!empty($_POST['remove_photo'])) {
        $data['image'] = '';
    }
    if (!$errors) {
        cookie_save($id ?: null, $data);
        flash($id ? "“{$data['name']}” updated." : "“{$data['name']}” added to the menu.");
        redirect('menu.php');
    }
    $values = $data + $values;
}

$preview = $values['image'] !== '' ? (cookie_image_is_local($values['image']) ? '../' . $values['image'] : $values['image']) : '';
admin_header($cookie ? 'Edit ' . $cookie['name'] : 'Add a flavor', 'menu', $user);
?>
<a class="back" href="menu.php">← Menu</a>
<section class="card narrow-wide">
  <h1><?= $cookie ? 'Edit ' . e($cookie['name']) : 'Add a flavor' ?></h1>

  <form method="post" enctype="multipart/form-data" class="form">
    <?= csrf_field() ?>
    <label>Cookie name
      <input name="name" value="<?= e($values['name']) ?>" required maxlength="120">
      <?php if (isset($errors['name'])): ?><span class="error"><?= e($errors['name']) ?></span><?php endif; ?>
    </label>

    <label>Description
      <textarea name="description" rows="3" maxlength="600"><?= e($values['description']) ?></textarea>
    </label>

    <fieldset class="choice-row">
      <legend>Type</legend>
      <label class="choice"><input type="radio" name="type" value="signature"<?= $values['type'] !== 'seasonal' ? ' checked' : '' ?>> Signature (all year)</label>
      <label class="choice"><input type="radio" name="type" value="seasonal"<?= $values['type'] === 'seasonal' ? ' checked' : '' ?>> Monthly special</label>
    </fieldset>

    <fieldset class="window-fields">
      <legend>Pickup dates it can be ordered for <small>(optional)</small></legend>
      <p class="muted">Leave both empty for a flavor you offer every day. For a monthly special, set the first and last day of its month
        (for example <?= e(short_date($monthFrom)) ?> – <?= e(short_date($monthUntil)) ?>). Customers can’t pick it for other dates,
        and it disappears from the menu once its last date has passed.</p>
      <div class="two-col">
        <label>First pickup date
          <input type="date" name="available_from" value="<?= e((string) ($values['available_from'] ?? '')) ?>">
        </label>
        <label>Last pickup date
          <input type="date" name="available_until" value="<?= e((string) ($values['available_until'] ?? '')) ?>">
        </label>
      </div>
      <?php if (isset($errors['available'])): ?><span class="error"><?= e($errors['available']) ?></span><?php endif; ?>
    </fieldset>

    <div class="photo-field">
      <p class="label">Photo</p>
      <?php if ($preview !== ''): ?><img class="photo-preview" src="<?= e($preview) ?>" alt="Current photo" width="160" height="160"><?php endif; ?>
      <label>Upload a new photo <small>(JPG, PNG or WebP, up to 8 MB)</small>
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
      </label>
      <details>
        <summary>Or use a photo link</summary>
        <label>Photo link (https://…)
          <input name="image" value="<?= e($values['image']) ?>" maxlength="500" inputmode="url">
        </label>
      </details>
      <?php if ($preview !== ''): ?><label class="choice"><input type="checkbox" name="remove_photo" value="1"> Remove the photo</label><?php endif; ?>
      <?php if (isset($errors['image'])): ?><span class="error"><?= e($errors['image']) ?></span><?php endif; ?>
    </div>

    <label>Position on the menu <small>(lower numbers show first)</small>
      <input type="number" name="sort_order" value="<?= (int) $values['sort_order'] ?>" step="1" inputmode="numeric">
    </label>

    <label class="choice"><input type="checkbox" name="is_available" value="1"<?= (int) $values['is_available'] ? ' checked' : '' ?>> Show on the website (customers can order it)</label>

    <div class="action-row">
      <button class="btn btn-primary btn-lg" type="submit"><?= $cookie ? 'Save changes' : 'Add flavor' ?></button>
      <a class="btn btn-ghost" href="menu.php">Cancel</a>
    </div>
  </form>
</section>
<?php admin_footer();
