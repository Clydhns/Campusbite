<?php
require_once __DIR__ . '/cloud-storage.php';
function cbFoodPhotoUrl($path): string {
    return is_string($path) && preg_match('~^uploads/foods/[a-f0-9]{32}\.(jpg|png|webp)$~D', $path) ? cbPublicImageUrl($path) : '';
}
function cbValidateFoodPhoto(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Photo upload failed. Choose a JPEG, PNG, or WebP image up to 3 MB.');
    $tmp = $file['tmp_name'] ?? '';
    if (!is_string($tmp) || !is_file($tmp) || filesize($tmp) > 3 * 1024 * 1024 || filesize($tmp) === 0) throw new RuntimeException('Choose a photo up to 3 MB.');
    $info = @getimagesize($tmp);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $types = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if (!$info || !isset($types[$mime]) || ($info['mime'] ?? '') !== $mime) throw new RuntimeException('Only actual JPEG, PNG, and WebP images are allowed.');
    if ($info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 20000000) throw new RuntimeException('Photo dimensions are too large. Use an image up to 6000 pixels per side and 20 megapixels.');
    return $types[$mime];
}
function cbDeleteFoodPhoto($path): void {
    $relative = cbStoragePath($path);
    if (!str_starts_with($relative, 'uploads/foods/')) return;
    cbStorageDelete($relative);
}
function cbSaveFoodPhoto(PDO $pdo, int $storeId, int $itemId, array $file, bool $remove): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Photo changes require a transaction.');
    $stmt = $pdo->prepare('SELECT image_path FROM menu_items WHERE item_id=? AND store_id=? FOR UPDATE');
    $stmt->execute([$itemId,$storeId]);
    $food = $stmt->fetch();
    if (!$food) throw new RuntimeException('This food does not belong to your store.');
    $hasUpload = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasUpload && !$remove) return ['new'=>null,'old'=>null];
    $new = null;
    if ($hasUpload) {
        $extension = cbValidateFoodPhoto($file);
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid photo upload.');
        $new = cbStorageUpload('foods',$file,$extension);
    }
    try {
        $stmt = $pdo->prepare('UPDATE menu_items SET image_path=? WHERE item_id=? AND store_id=?');
        $stmt->execute([$new,$itemId,$storeId]);
    } catch (Throwable $error) {
        cbDeleteFoodPhoto($new);
        throw $error;
    }
    return ['new'=>$new,'old'=>$food['image_path']];
}
