<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store, max-age=0');
ob_start(); require_once __DIR__ . '/config/connection.php'; ob_end_clean();
require_once __DIR__ . '/config/stores.php';
$store = cbStaffStore($pdo);
$token = cbCsrf('store_csrf');
$error = '';
$photoChange = ['new'=>null,'old'=>null];
$qrChange = ['new'=>null,'old'=>null];
$saveMessage = 'Your store has been updated.';
$toggleJson = ($_POST['action'] ?? '') === 'set_food_availability'
    && str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
$success = $_SESSION['store_flash'] ?? '';
unset($_SESSION['store_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST)) throw new RuntimeException('Upload too large. Choose a photo up to 3 MB and try again.');
        if (!cbCheckCsrf('store_csrf')) throw new RuntimeException('Invalid request. Refresh and try again.');
        $pdo->beginTransaction();
        if (($_POST['action'] ?? '') === 'save_store') {
            $name = trim((string) ($_POST['store_name'] ?? ''));
            $description = trim((string) ($_POST['store_description'] ?? ''));
            [$opensAt, $closesAt] = cbValidateStoreHours($_POST['opens_at'] ?? substr($store['opens_at'],0,5), $_POST['closes_at'] ?? substr($store['closes_at'],0,5));
            $acceptGcash = isset($_POST['payment_options_present'])
                ? isset($_POST['accept_gcash'])
                : (is_string($_POST['gcash_number'] ?? null) && trim($_POST['gcash_number']) !== '');
            $gcash = $acceptGcash && is_string($_POST['gcash_number'] ?? null) ? trim($_POST['gcash_number']) : '';
            if (!preg_match('/^.{1,100}$/us', $name) || !preg_match('/^.{0,255}$/us', $description)) throw new RuntimeException('Enter a store name (up to 100 characters) and description (up to 255).');
            if ($acceptGcash && !preg_match('/^09[0-9]{9}$/', $gcash)) throw new RuntimeException('To accept GCash, enter an 11-digit receiving number starting with 09.');
            $qrFile = $_FILES['gcash_qr'] ?? [];
            $hasQrUpload = ($qrFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            $removeQr = isset($_POST['remove_gcash_qr']);
            if ($acceptGcash && $gcash !== ($store['gcash_number'] ?? '') && cbPaymentQrUrl($store['gcash_qr_path'] ?? null) && !$hasQrUpload && !$removeQr) {
                throw new RuntimeException('Replace or remove your existing QR image when changing the GCash receiving number.');
            }
            $stmt = $pdo->prepare('UPDATE stores SET store_name=?,description=?,gcash_number=?,is_open=?,opens_at=?,closes_at=? WHERE store_id=? AND staff_user_id=?');
            $stmt->execute([$name,$description ?: null,$gcash ?: null,isset($_POST['is_open']) ? 1 : 0,$opensAt,$closesAt,$store['store_id'],$_SESSION['user_id']]);
            if (!$acceptGcash && $hasQrUpload) {
                throw new RuntimeException('Turn on GCash payments before uploading a QR image.');
            }
            $qrChange = cbSaveStorePaymentQr($pdo, (int) $store['store_id'], (int) $_SESSION['user_id'], $qrFile, $removeQr);
        } elseif (($_POST['action'] ?? '') === 'save_food') {
            $itemId = cbSaveFood($pdo,(int) $store['store_id'],$_POST);
            $photoChange = cbSaveFoodPhoto($pdo,(int) $store['store_id'],$itemId,$_FILES['food_photo'] ?? [],isset($_POST['remove_photo']));
            $saveMessage = 'Your food has been saved.';
        } elseif (($_POST['action'] ?? '') === 'set_food_availability') {
            $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
            $available = $_POST['available'] ?? null;
            if (!in_array($available, ['0', '1'], true)) throw new RuntimeException('Choose a valid availability.');
            $changedFood = cbSetFoodAvailability($pdo, (int) $store['store_id'], (int) $itemId, (int) $available);
            $saveMessage = $changedFood['item_name'] . ' is now ' . ((int) $changedFood['is_available'] === 1
                ? ((int) $changedFood['keeps_availability'] === 1 ? 'available until you switch it off.' : 'available for today.') : 'unavailable.');
        } elseif (($_POST['action'] ?? '') === 'delete_food') {
            $itemId = filter_var($_POST['item_id'] ?? null,FILTER_VALIDATE_INT);
            $photoChange['old'] = cbDeleteFood($pdo,(int) $store['store_id'],(int) $itemId);
            $saveMessage = 'Food removed from your store.';
        } else {
            throw new RuntimeException('Unknown menu action.');
        }
        $pdo->commit();
        cbDeleteFoodPhoto($photoChange['old']);
        cbDeletePaymentQr($qrChange['old']);
        if ($toggleJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok'=>true, 'workspace'=>$_SESSION['user_id'] . '-' . $store['store_id'],
                'item_id'=>(int) $changedFood['item_id'], 'available'=>(int) $changedFood['is_available'] === 1,
                'keeps_availability'=>(int) $changedFood['keeps_availability'] === 1,
                'available_until'=>(int) $changedFood['keeps_availability'] === 1 ? null : strtotime($changedFood['availability_date'] . ' +1 day') * 1000, 'message'=>$saveMessage]);
            exit;
        }
        $_SESSION['store_flash'] = $saveMessage;
        header('Location: staff_menu.php' . (($_POST['action'] ?? '') === 'save_store' ? '?view=settings' : ''));
        exit;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cbDeleteFoodPhoto($photoChange['new']);
        cbDeletePaymentQr($qrChange['new']);
        error_log($exception->getMessage());
        $error = ($_POST['action'] ?? '') === 'delete_food'
            ? 'Unable to remove this food right now. Refresh and try again.'
            : (($_POST['action'] ?? '') === 'set_food_availability'
                ? 'Unable to change food availability. Please try again.'
                : (($_POST['action'] ?? '') === 'save_store'
                ? 'Unable to save your store settings. Please try again.'
                : 'Unable to save. Food names must be unique within your store.'));
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cbDeleteFoodPhoto($photoChange['new']);
        cbDeletePaymentQr($qrChange['new']);
        $error = $exception->getMessage();
    }
}
if ($toggleJson) {
    http_response_code(422);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false, 'message'=>$error]);
    exit;
}
session_write_close();
$categories = $pdo->query('SELECT * FROM categories ORDER BY category_name')->fetchAll();
$stmt = $pdo->prepare('SELECT m.*,c.category_name,' . cbFoodAvailabilitySql('m') . ' AS available_today,' . cbFoodKeepsAvailabilitySql('m') . ' AS keeps_availability FROM menu_items m JOIN categories c ON c.category_id=m.category_id WHERE m.store_id=? AND m.archived_at IS NULL ORDER BY m.item_name');
$stmt->execute([$store['store_id']]);
$foods = $stmt->fetchAll();
$edit = ['item_id'=>0,'item_name'=>'','description'=>'','price'=>'','category_id'=>'','is_available'=>0,'keeps_availability'=>0];
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId) {
    $found = false;
    foreach ($foods as $food) if ((int) $food['item_id'] === $editId) { $edit = $food; $edit['is_available'] = $food['available_today']; $found = true; }
    if (!$found) $error = 'That food does not belong to your store.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_food' && $error !== '') {
    foreach (['item_id','item_name','description','price','category_id'] as $field) $edit[$field] = is_scalar($_POST[$field] ?? null) ? $_POST[$field] : '';
    $edit['is_available'] = isset($_POST['is_available']);
}
$acceptGcash = !empty($store['gcash_number']);
$gcashNumber = (string) ($store['gcash_number'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_store' && $error !== '') {
    $acceptGcash = isset($_POST['accept_gcash']);
    $gcashNumber = is_string($_POST['gcash_number'] ?? null) ? $_POST['gcash_number'] : '';
}
$settingsView = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['action'] ?? '') === 'save_store'
    : (($_GET['view'] ?? '') === 'settings' && !$editId && ($_GET['add'] ?? '') !== '1');
$editorOpen = $editId > 0 || ($_GET['add'] ?? '') === '1'
    || ($error !== '' && ($_POST['action'] ?? '') === 'save_food');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<?php require __DIR__ . '/components/favicon.php'; ?>
<title><?= $settingsView ? 'Store Settings' : 'Menu' ?> - CampusBite</title>
<link rel="stylesheet" href="student-pages.css?v=1"><link rel="stylesheet" href="student-navbar.css?v=1"><link rel="stylesheet" href="stores.css?v=<?= (int) filemtime(__DIR__ . '/stores.css') ?>">
<link rel="stylesheet" href="staff-navbar.css?v=<?= (int) filemtime(__DIR__ . '/staff-navbar.css') ?>">
</head><body class="cb-student" data-staff-workspace="<?= (int) $_SESSION['user_id'] ?>-<?= (int) $store['store_id'] ?>" data-availability-until="<?= strtotime('tomorrow') * 1000 ?>">
<?php require __DIR__ . '/components/staff-navbar.php'; ?>
<?php if ($error): ?><div class="error-message cb-staff-notice" data-staff-notice data-auto-dismiss role="alert"><span><?= cbEscape($error) ?></span><button type="button" data-dismiss-notice aria-label="Dismiss error message">&times;</button></div><?php endif; ?>
<?php if ($success): ?><div class="success-message cb-staff-notice" data-staff-notice data-auto-dismiss role="status" aria-live="polite"><span><?= cbEscape($success) ?></span><button type="button" data-dismiss-notice aria-label="Dismiss success message">&times;</button></div><?php endif; ?>
<div id="food-availability-notice" class="success-message cb-staff-notice" role="status" aria-live="polite" hidden><span></span><button type="button" aria-label="Dismiss availability message">&times;</button></div>
<main class="container">
<header class="page-title"><span class="cb-page-eyebrow">YOUR KITCHEN. YOUR MENU.</span><h1><?= cbEscape($store['store_name']) ?></h1><p><?= cbEscape(cbStoreStatusLabel($store)) ?></p></header>
<?php if ($settingsView): ?>
<section class="cb-store-settings" aria-labelledby="store-settings-title"><div class="cb-store-profile-heading"><h2 id="store-settings-title">Store Settings</h2></div><p>Update your kitchen details, opening hours, and payment options in one place.</p>
<form class="cb-store-profile-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="save_store"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>">
<section class="cb-store-panel cb-store-profile-column" aria-labelledby="store-details-title"><h3 id="store-details-title">Store details</h3>
<label for="store-name">Store name</label><input id="store-name" name="store_name" maxlength="100" required value="<?= cbEscape($store['store_name']) ?>">
<label for="store-description">Description</label><textarea id="store-description" name="store_description" maxlength="255"><?= cbEscape($store['description']) ?></textarea>
<section class="cb-store-payments" aria-labelledby="store-hours-title"><h4 id="store-hours-title">Store hours</h4>
<p class="cb-store-help" role="status"><?= cbEscape(cbStoreStatusLabel($store)) ?></p>
<div class="cb-store-fields"><div><label for="store-opens">Opening time</label><input id="store-opens" name="opens_at" type="time" step="60" required value="<?= cbEscape(is_string($_POST['opens_at'] ?? null) && $error ? $_POST['opens_at'] : substr($store['opens_at'],0,5)) ?>"></div>
<div><label for="store-closes">Closing time</label><input id="store-closes" name="closes_at" type="time" step="60" required value="<?= cbEscape(is_string($_POST['closes_at'] ?? null) && $error ? $_POST['closes_at'] : substr($store['closes_at'],0,5)) ?>"></div></div>
<p class="cb-store-help">Daily, in Philippine time. No new orders outside these hours, including water and soft drinks. A closing time earlier than opening means the next morning. Existing orders can still be completed.</p></section>
<label class="cb-store-check"><input type="checkbox" name="is_open" <?= $store['is_open'] ? 'checked' : '' ?>> Accept new orders during store hours</label><p class="cb-store-help">Switch this off to pause orders even during opening hours. Switching it on does not override your closing time.</p>
</section>
<section class="cb-store-panel cb-store-payments cb-store-profile-column" id="store-payments" aria-labelledby="payment-methods-title">
<h3 id="payment-methods-title">Payment methods</h3>
<input type="hidden" name="payment_options_present" value="1">
<p class="cb-store-help">Choose which payment options customers can use at checkout.</p>
<div class="cb-payment-method"><div><strong>Cash</strong><p>Customers pay at the canteen.</p></div><span class="cb-payment-accepted">Always accepted</span></div>
<label class="cb-store-check cb-payment-gcash" for="accept-gcash"><input type="checkbox" id="accept-gcash" name="accept_gcash" value="1" <?= $acceptGcash ? 'checked' : '' ?> aria-controls="store-gcash-details"><span><strong>Accept GCash payments</strong><span class="cb-payment-caption">Let customers choose Cash or GCash.</span></span></label>
<div id="store-gcash-details">
<label for="gcash-number">GCash receiving number</label><input id="gcash-number" name="gcash_number" type="tel" inputmode="numeric" maxlength="11" pattern="09[0-9]{9}" placeholder="09XXXXXXXXX" aria-describedby="store-gcash-help" <?= $acceptGcash ? 'required' : '' ?> value="<?= cbEscape($gcashNumber) ?>">
<p id="store-gcash-help" class="cb-store-help">Use an 11-digit number starting with 09. Customers pay this number using their GCash app. Verify each payment in your GCash account before confirming it.</p>
<label for="gcash-qr">GCash QR image (optional)</label>
<input id="gcash-qr" name="gcash_qr" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="gcash-qr-help gcash-qr-message">
<p id="gcash-qr-help" class="cb-store-help">Upload your GCash QR as a JPEG, PNG, or WebP image, up to 3 MB. Use the QR for the receiving number above. Replace or remove the QR if you change that number. Customers can scan or save it at checkout.</p>
<?php $currentQr = cbPaymentQrUrl($store['gcash_qr_path'] ?? null); ?>
<img id="gcash-qr-preview" class="cb-gcash-qr-preview" src="<?= cbEscape($currentQr ?: 'data:,') ?>" alt="Your store's GCash QR image" width="280" height="280" <?= $currentQr ? '' : 'hidden' ?>>
<p id="gcash-qr-message" class="cb-store-help" role="status"></p>
<?php if ($currentQr): ?><label class="cb-store-check"><input id="remove-gcash-qr" type="checkbox" name="remove_gcash_qr"> Remove current QR image</label><?php endif; ?>
</div>
<p class="cb-store-help" id="store-payment-summary" role="status"><?= $acceptGcash ? 'Cash and GCash are available after saving these settings.' : 'Cash is available. Turn on GCash and enter your receiving number to offer both.' ?></p>
</section>
<div class="cb-store-profile-actions"><p>Save to apply changes to your store.</p><button class="btn" type="submit">Save store</button></div>
</form></section>
<?php else: ?>
<details class="cb-store-panel cb-food-editor" id="food-editor" <?= $editorOpen ? 'open' : '' ?>>
<summary><span><strong><?= $edit['item_id'] ? 'Edit food' : 'Add food' ?></strong><small>Name, photo, price, and availability</small></span><span class="cb-editor-expand" aria-hidden="true">+</span></summary>
<form class="cb-food-editor-form" method="POST" enctype="multipart/form-data"><input type="hidden" name="action" value="save_food"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>"><input type="hidden" name="item_id" value="<?= (int) $edit['item_id'] ?>">
<div>
<label for="food-name">Food name</label><input id="food-name" name="item_name" maxlength="100" required value="<?= cbEscape($edit['item_name']) ?>">
<label for="food-description">Description</label><textarea id="food-description" name="description" maxlength="255"><?= cbEscape($edit['description']) ?></textarea>
<div class="cb-store-fields"><div><label for="food-category">Category</label><select id="food-category" name="category_id" required><option value="">Choose category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['category_id'] ?>" <?= (int) $edit['category_id'] === (int) $category['category_id'] ? 'selected' : '' ?>><?= cbEscape($category['category_name']) ?></option><?php endforeach; ?></select></div>
<div><label for="food-price">Price (PHP)</label><input id="food-price" type="number" name="price" min="0.01" max="999999.99" step="0.01" required value="<?= cbEscape($edit['price']) ?>"></div></div>
<label class="cb-food-switch-label" for="food-availability"><input id="food-availability" type="checkbox" name="is_available" value="1" role="switch" data-availability-reset="<?= $edit['keeps_availability'] ? 'never' : 'daily' ?>" aria-label="Food available for ordering" aria-describedby="food-availability-help" <?= $edit['is_available'] ? 'checked' : '' ?>><span class="cb-switch-track" aria-hidden="true"></span><span class="cb-switch-state"><span class="cb-switch-on">Available</span><span class="cb-switch-off">Unavailable</span></span></label>
<p id="food-availability-help" class="cb-store-help">Fresh food and cups of juice reset to unavailable at midnight Philippine time. Soft drinks and bottled water stay available across days unless you switch them off. Categorize drinks under Beverages or a matching drinks category.</p>
</div>
<div>
<label for="food-photo">Food photo (optional)</label>
<input id="food-photo" name="food_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-describedby="food-photo-help">
<p id="food-photo-help" class="cb-store-help">Upload from your computer: JPEG, PNG, or WebP, up to 3 MB. Leave blank to keep the existing photo.</p>
<?php $currentPhoto = cbFoodPhotoUrl($edit['image_path'] ?? null); ?>
<img id="food-photo-preview" class="cb-food-photo cb-food-photo-preview" src="<?= cbEscape($currentPhoto ?: 'data:,') ?>" alt="Food photo preview" <?= $currentPhoto ? '' : 'hidden' ?>>
<p id="food-photo-message" class="cb-store-help" role="status"></p>
<?php if ($currentPhoto): ?><label class="cb-store-check"><input type="checkbox" name="remove_photo"> Remove current photo (a new upload replaces it)</label><?php endif; ?>
</div>
<div class="cb-store-actions cb-food-editor-actions"><button class="btn" type="submit"><?= $edit['item_id'] ? 'Save food' : 'Add food' ?></button><a class="btn btn-secondary" href="staff_menu.php">Cancel</a></div>
</form></details>
<section class="cb-store-section"><h2>Your menu</h2><p>Manage your food here. Fresh food and juice reset at midnight; water and soft drinks keep their availability.</p><div class="cb-store-grid">
<?php foreach ($foods as $food): ?><article class="cb-store-panel">
<?php if ($photo = cbFoodPhotoUrl($food['image_path'])): ?><img class="cb-food-photo" src="<?= cbEscape($photo) ?>" alt="<?= cbEscape($food['item_name']) ?>" loading="lazy" width="640" height="400"><?php endif; ?>
<span class="cb-store-kicker"><?= cbEscape($food['category_name']) ?></span><h3><?= cbEscape($food['item_name']) ?></h3><p><?= cbEscape($food['description']) ?></p><strong>PHP <?= number_format((float) $food['price'],2) ?></strong>
<p class="cb-store-help"><?= $food['keeps_availability'] ? 'No daily reset' : 'Resets at midnight (Philippine time)' ?></p>
<form method="POST" action="staff_menu.php" data-food-availability data-availability-reset="<?= $food['keeps_availability'] ? 'never' : 'daily' ?>" data-item-id="<?= (int) $food['item_id'] ?>">
<input type="hidden" name="action" value="set_food_availability"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>"><input type="hidden" name="item_id" value="<?= (int) $food['item_id'] ?>"><input type="hidden" name="available" value="<?= $food['available_today'] ? '0' : '1' ?>">
<button type="submit" class="cb-food-switch" role="switch" aria-checked="<?= $food['available_today'] ? 'true' : 'false' ?>" aria-label="Availability for <?= cbEscape($food['item_name']) ?>"><span class="cb-switch-track" aria-hidden="true"></span><span data-availability-label><?= $food['available_today'] ? 'Available' : 'Unavailable' ?></span></button>
</form>
<div class="cb-store-actions"><a class="btn btn-secondary" href="staff_menu.php?edit=<?= (int) $food['item_id'] ?>">Edit food</a>
<form method="POST" action="staff_menu.php" data-delete-food data-food-name="<?= cbEscape($food['item_name']) ?>">
<input type="hidden" name="action" value="delete_food"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>"><input type="hidden" name="item_id" value="<?= (int) $food['item_id'] ?>">
<button class="btn cb-food-delete" type="submit" data-delete-food-button disabled aria-label="Remove <?= cbEscape($food['item_name']) ?>">Remove food</button>
</form></div></article><?php endforeach; ?>
<?php if (!$foods): ?><p>Your store is ready. Add your first food above.</p><?php endif; ?>
</div></section>
<?php endif; ?>
</main>
<dialog id="food-delete-dialog" class="cb-food-delete-dialog" aria-labelledby="food-delete-title" aria-describedby="food-delete-message">
<h2 id="food-delete-title">Remove this food?</h2>
<p id="food-delete-message">Remove <strong id="food-delete-name"></strong> from your menu? It will no longer appear for sale. This cannot be undone here.</p>
<p class="cb-store-help">Food linked to orders is archived to keep order history safe. Food with no orders is deleted with its photo.</p>
<div class="cb-store-actions"><button type="button" class="btn btn-secondary" id="food-delete-cancel" autofocus>Keep food</button><button type="button" class="btn cb-food-delete" id="food-delete-confirm">Remove food</button></div>
</dialog>
<script src="food-photo-preview.js?v=<?= (int) filemtime(__DIR__ . '/food-photo-preview.js') ?>" defer></script><script src="food-delete.js?v=<?= (int) filemtime(__DIR__ . '/food-delete.js') ?>" defer></script><script src="food-availability.js?v=<?= (int) filemtime(__DIR__ . '/food-availability.js') ?>" defer></script><script src="staff-menu-ux.js?v=<?= (int) filemtime(__DIR__ . '/staff-menu-ux.js') ?>" defer></script><script src="staff-payments.js?v=<?= (int) filemtime(__DIR__ . '/staff-payments.js') ?>" defer></script></body></html>
