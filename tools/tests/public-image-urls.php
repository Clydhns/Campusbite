<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/config/food-photos.php';
require dirname(__DIR__, 2) . '/app/config/payment-photos.php';
$original = getenv('SUPABASE_URL');
function expect(bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
    echo 'PASS: ' . $message . PHP_EOL;
}
try {
    $food = 'uploads/foods/' . str_repeat('a', 32) . '.webp';
    $qr = 'uploads/payments/' . str_repeat('b', 32) . '.png';
    $receipt = 'uploads/receipts/' . str_repeat('c', 32) . '.jpg';
    putenv('SUPABASE_URL=https://fixture.supabase.co');
    $prefix = 'https://fixture.supabase.co/storage/v1/object/public/campusbite-images/';
    expect(cbFoodPhotoUrl($food) === $prefix . substr($food,8), 'Food images use the public storage URL directly');
    expect(cbPaymentQrUrl($qr) === $prefix . substr($qr,8), 'Store QR images use the public storage URL directly');
    expect(cbPublicImageUrl($receipt) === '' && cbFoodPhotoUrl($receipt) === '' && cbPaymentQrUrl($receipt) === '', 'Private receipts cannot become public image URLs');
    expect(cbPublicImageUrl('uploads/foods/../../config/connection.php') === '' && cbPublicImageUrl('https://other.invalid/image.png') === '', 'Traversal and arbitrary external image paths are rejected');
    expect(cbStoragePath($food) === $food && cbStoragePath($qr) === $qr, 'Storage mutation paths stay relative and valid');
    putenv('SUPABASE_URL');
    expect(cbFoodPhotoUrl($food) === $food && cbPaymentQrUrl($qr) === $qr, 'Unconfigured local tests retain relative URL compatibility');
    putenv('SUPABASE_URL=https://fixture.supabase.co.other.invalid');
    expect(cbFoodPhotoUrl($food) === $food, 'An untrusted storage host cannot become a direct image destination');
} finally {
    putenv($original === false ? 'SUPABASE_URL' : 'SUPABASE_URL=' . $original);
}
