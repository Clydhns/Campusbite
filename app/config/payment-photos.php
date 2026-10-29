<?php
require_once __DIR__ . '/food-photos.php';

function cbPaymentQrUrl($path): string {
    return is_string($path) && preg_match('~^uploads/payments/[a-f0-9]{32}\.(jpg|png|webp)$~D', $path) ? $path : '';
}

function cbDeletePaymentQr($path): void {
    $relative = cbPaymentQrUrl($path);
    if (!$relative) return;
    cbStorageDelete($relative);
}

function cbSaveStorePaymentQr(PDO $pdo, int $storeId, int $staffId, array $file, bool $remove): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Payment QR changes require a transaction.');
    $stmt = $pdo->prepare('SELECT gcash_qr_path FROM stores WHERE store_id=? AND staff_user_id=? FOR UPDATE');
    $stmt->execute([$storeId, $staffId]);
    $store = $stmt->fetch();
    if (!$store) throw new RuntimeException('This store does not belong to your account.');
    $hasUpload = ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$hasUpload && !$remove) return ['new' => null, 'old' => null];

    $new = null;
    if ($hasUpload) {
        $extension = cbValidateFoodPhoto($file);
        if (!is_uploaded_file($file['tmp_name'])) throw new RuntimeException('Invalid QR image upload.');
        $new = cbStorageUpload('payments',$file,$extension);
    }
    try {
        $stmt = $pdo->prepare('UPDATE stores SET gcash_qr_path=? WHERE store_id=? AND staff_user_id=?');
        $stmt->execute([$new, $storeId, $staffId]);
    } catch (Throwable $error) {
        cbDeletePaymentQr($new);
        throw $error;
    }
    return ['new' => $new, 'old' => $store['gcash_qr_path']];
}
