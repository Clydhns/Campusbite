<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CAMPUSBITE_LOCAL_TEST') !== '1'
    || getenv('PGHOST') !== '127.0.0.1' || getenv('PGPORT') !== '18758') exit(1);
require dirname(__DIR__, 2) . '/app/config/cloud-database.php';
require dirname(__DIR__, 2) . '/app/config/stores.php';
require dirname(__DIR__, 2) . '/app/config/cart-groups.php';
$first = cbApplicationDatabase();
$second = cbApplicationDatabase();
$sessions = cbCloudDatabase();
$first->beginTransaction();
try {
    $extra = (int) $first->query("INSERT INTO menu_items(category_id,item_name,price,store_id) VALUES (1,'Batch ownership fixture',10,1) RETURNING item_id")->fetchColumn();
    $valid = cbCartStore($first, [1 => ['item_id' => 1], $extra => ['item_id' => $extra]]);
    $multi = [1 => ['item_id'=>1,'store_id'=>2,'quantity'=>1,'price'=>50], 2 => ['item_id'=>2,'store_id'=>1,'quantity'=>1,'price'=>60]];
    $groups = cbCartGroups($first, $multi);
    $selection = cbSelectedCheckoutCart($first, $multi, '1');
    $authoritative = isset($groups[1]['items'][1], $groups[2]['items'][2]) && array_keys($selection['items']) === [1];
    cbRemoveCartItems($multi, $selection['items']);
    $scopedRemoval = array_keys($multi) === [2];
    $selectionRejected = false;
    try { cbSelectedCheckoutCart($first, [1=>['item_id'=>1],2=>['item_id'=>2]], ['bad']); }
    catch (RuntimeException $error) { $selectionRejected = true; }
    $rejects = static function(array $cart) use ($first): bool {
        try { cbCartStore($first, $cart); } catch (RuntimeException $error) { return true; }
        return false;
    };
    $mixedRejected = $rejects([1 => ['item_id' => 1], 2 => ['item_id' => 2]]);
    $missingRejected = $rejects([1 => ['item_id' => 1], 999999 => ['item_id' => 999999]]);
    $first->exec('UPDATE stores SET is_open=0 WHERE store_id=1');
    $closedRejected = $rejects([1 => ['item_id' => 1]]);
} finally {
    $first->rollBack();
}
echo json_encode([
    'application_connection_reused' => $first === $second,
    'session_connection_separate' => $first !== $sessions,
    'application_role' => $first->query('SELECT current_user')->fetchColumn() === 'campusbite_app',
    'batch_store_validation' => (int) $valid['store_id'] === 1 && !isset($valid['cart_item_id'])
        && $mixedRejected && $missingRejected && $closedRejected,
    'multi_cart_authority' => $authoritative && $scopedRemoval && $selectionRejected,
]);
