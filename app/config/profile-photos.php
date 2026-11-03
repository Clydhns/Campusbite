<?php
require_once __DIR__ . '/food-photos.php';

// Student profile photos. Stored privately; staff see them only on orders placed at their own kitchen.
function cbProfilePhotoPath($path): string {
    $relative = cbStoragePath($path);
    return str_starts_with($relative, 'uploads/avatars/') ? $relative : '';
}

// A versioned URL so a new photo is not hidden by the browser cache. Without an order it is the viewer's own photo.
function cbProfilePhotoUrl($path, ?int $orderId = null): string {
    $relative = cbProfilePhotoPath($path);
    if (!$relative) return '';
    return 'profile_photo.php?' . ($orderId ? 'order_id=' . $orderId . '&' : '') . 'v=' . substr(sha1($relative), 0, 10);
}

// Replaces or removes the student's photo. Returns the old path, to delete from storage after the save is committed.
function cbSaveProfilePhoto(PDO $pdo, int $studentId, array $file, bool $remove): ?string {
    $hasUpload = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasUpload && !$remove) throw new RuntimeException('Choose a photo to upload.');
    $new = null;
    if ($hasUpload) {
        $extension = cbValidateFoodPhoto($file);
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid photo upload.');
        $new = cbStorageUpload('avatars', $file, $extension);
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT avatar_path FROM users WHERE user_id=? AND role_id=3 AND is_active=1 FOR UPDATE');
        $stmt->execute([$studentId]);
        $old = $stmt->fetchColumn();
        if ($old === false) throw new RuntimeException('Your account is no longer active.');
        $pdo->prepare('UPDATE users SET avatar_path=? WHERE user_id=?')->execute([$new, $studentId]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($new) cbStorageDelete($new);
        throw $error;
    }
    return cbProfilePhotoPath($old) ?: null;
}
