<?php
require_once __DIR__ . '/food-photos.php';
require_once __DIR__ . '/payment-photos.php';
require_once __DIR__ . '/payment-receipts.php';
require_once __DIR__ . '/food-availability.php';
function cbEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function cbEnsureStore(PDO $pdo, int $staffId): array {
    $stmt = $pdo->prepare("SELECT LEFT(CONCAT(first_name,' ',last_name,' Kitchen'),100) AS default_store_name FROM users WHERE user_id=? AND role_id=2 AND is_active=1");
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch();
    if (!$staff) throw new RuntimeException('An active staff account is required.');
    $stmt = $pdo->prepare('INSERT INTO stores (staff_user_id,store_name) VALUES (?,?) ON CONFLICT (staff_user_id) DO NOTHING');
    $stmt->execute([$staffId, $staff['default_store_name']]);
    $stmt = $pdo->prepare('SELECT * FROM stores WHERE staff_user_id=?');
    $stmt->execute([$staffId]);
    return $stmt->fetch();
}
function cbStaffStore(PDO $pdo): array {
    if (!isset($_SESSION['user_id']) || (int) ($_SESSION['role_id'] ?? 0) !== 2) {
        header('Location: login.php');
        exit;
    }
    try {
        return cbEnsureStore($pdo, (int) $_SESSION['user_id']);
    } catch (RuntimeException $error) {
        header('Location: login.php');
        exit;
    }
}
function cbCsrf(string $key): string {
    if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(32));
    return $_SESSION[$key];
}
function cbCheckCsrf(string $key): bool {
    $token = $_POST['csrf_token'] ?? null;
    return is_string($token) && hash_equals(cbCsrf($key), $token);
}
function cbVisibleStores(PDO $pdo): array {
    return $pdo->query("SELECT s.* FROM stores s JOIN users u ON u.user_id=s.staff_user_id
        WHERE s.is_open=1 AND u.is_active=1 AND u.role_id=2 ORDER BY s.store_name")->fetchAll();
}
// Always resolve ownership from the database, not from cached session fields.
function cbCartStore(PDO $pdo, array $cart): ?array {
    if (!$cart) return null;
    $ids = array_values(array_unique(array_map(static fn($key) => (int) ($cart[$key]['item_id'] ?? $key), array_keys($cart))));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $store = null;
    $stmt = $pdo->prepare("SELECT m.item_id AS cart_item_id,s.*,u.is_active AS staff_active,u.role_id AS staff_role FROM menu_items m
        JOIN stores s ON s.store_id=m.store_id JOIN users u ON u.user_id=s.staff_user_id
        WHERE m.item_id IN ($placeholders) AND m.archived_at IS NULL ORDER BY m.item_id" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
    $stmt->execute($ids);
    $foundItems = [];
    foreach ($stmt->fetchAll() as $found) $foundItems[(int) $found['cart_item_id']] = $found;
    foreach ($ids as $id) {
        $found = $foundItems[$id] ?? null;
        if (!$found || !(int) $found['is_open'] || !(int) $found['staff_active'] || (int) $found['staff_role'] !== 2) {
            throw new RuntimeException('An item belongs to a closed or unavailable store. Remove it from your cart.');
        }
        if ($store && (int) $store['store_id'] !== (int) $found['store_id']) {
            throw new RuntimeException('One store per checkout. Choose the kitchen you want to check out from your carts.');
        }
        $store = $found;
    }
    unset($store['cart_item_id']);
    return $store;
}
function cbStoreTiles(array $stores): void {
    echo '<section class="cb-store-section" aria-labelledby="stores-title"><h2 id="stores-title">Your campus kitchens</h2><p>Save picks from several kitchens and check out each store separately.</p><div class="cb-store-grid">';
    foreach ($stores as $store) {
        echo '<a class="cb-store-tile" href="menu.php?store=' . (int) $store['store_id'] . '"><span class="cb-store-kicker">CAMPUS STORE</span><h3>' . cbEscape($store['store_name']) . '</h3><p>' . cbEscape($store['description'] ?: 'Fresh picks from your campus kitchen.') . '</p><span>Browse menu &rarr;</span></a>';
    }
    if (!$stores) echo '<p>No stores are open right now.</p>';
    echo '</div></section>';
}
function cbDeleteFood(PDO $pdo, int $storeId, int $itemId): ?string {
    if (!$pdo->inTransaction()) throw new RuntimeException('Food deletion requires a transaction.');
    if ($itemId <= 0) throw new RuntimeException('Choose a valid food to remove.');
    $stmt = $pdo->prepare('SELECT image_path FROM menu_items WHERE item_id=? AND store_id=? AND archived_at IS NULL FOR UPDATE');
    $stmt->execute([$itemId,$storeId]);
    $food = $stmt->fetch();
    if (!$food) throw new RuntimeException('This food no longer exists or does not belong to your store.');
    // Keep the menu row locked so a checkout cannot reference it during deletion.
    $stmt = $pdo->prepare('SELECT order_item_id FROM order_items WHERE item_id=? LIMIT 1 FOR UPDATE');
    $stmt->execute([$itemId]);
    if ($stmt->fetch()) {
        // Archive ordered food instead of breaking historical order references.
        $pdo->prepare('UPDATE menu_items SET archived_at=NOW(),is_available=0 WHERE item_id=? AND store_id=?')->execute([$itemId,$storeId]);
        $pdo->prepare('UPDATE daily_menu SET is_available=0 WHERE item_id=? AND menu_date>=CURRENT_DATE')->execute([$itemId]);
        return null;
    }
    $stmt = $pdo->prepare('DELETE FROM menu_items WHERE item_id=? AND store_id=?');
    $stmt->execute([$itemId,$storeId]);
    return $food['image_path'];
}
function cbSaveFood(PDO $pdo, int $storeId, array $input): int {
    $name = trim((string) ($input['item_name'] ?? ''));
    $description = trim((string) ($input['description'] ?? ''));
    $price = (string) ($input['price'] ?? '');
    $category = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT);
    $itemId = (int) ($input['item_id'] ?? 0);
    $available = isset($input['is_available']) ? 1 : 0;
    if (!preg_match('/^.{1,100}$/us', $name) || !preg_match('/^.{0,255}$/us', $description)) throw new RuntimeException('Enter a food name (up to 100 characters) and description (up to 255).');
    if (!preg_match('/^\d{1,6}(\.\d{1,2})?$/', $price) || (float) $price <= 0) throw new RuntimeException('Enter a positive price with at most two decimal places.');
    if (!$category) throw new RuntimeException('Choose a valid category.');
    $check = $pdo->prepare('SELECT category_id FROM categories WHERE category_id=?');
    $check->execute([$category]);
    if (!$check->fetch()) throw new RuntimeException('Category not found.');
    if (!$pdo->inTransaction()) throw new RuntimeException('Menu changes require a transaction.');
    if ($itemId) {
        $check = $pdo->prepare('SELECT item_id FROM menu_items WHERE item_id=? AND store_id=? AND archived_at IS NULL FOR UPDATE');
        $check->execute([$itemId,$storeId]);
        if (!$check->fetch()) throw new RuntimeException('This food does not belong to your store.');
        $stmt = $pdo->prepare('UPDATE menu_items SET category_id=?,item_name=?,description=?,price=?,is_available=? WHERE item_id=? AND store_id=?');
        $stmt->execute([$category,$name,$description ?: null,$price,$available,$itemId,$storeId]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO menu_items (store_id,category_id,item_name,description,price,is_available) VALUES (?,?,?,?,?,?) RETURNING item_id');
        $stmt->execute([$storeId,$category,$name,$description ?: null,$price,$available]);
        $itemId = (int) $stmt->fetchColumn();
    }
    cbRecordDailyFoodAvailability($pdo, $itemId, $available);
    return $itemId;
}

function cbSetFoodAvailability(PDO $pdo, int $storeId, int $itemId, int $available): array {
    if (!$pdo->inTransaction()) throw new RuntimeException('Menu changes require a transaction.');
    if ($itemId <= 0 || !in_array($available, [0, 1], true)) throw new RuntimeException('Choose a valid food and availability.');
    $stmt = $pdo->prepare('UPDATE menu_items SET is_available=? WHERE item_id=? AND store_id=? AND archived_at IS NULL RETURNING item_id,item_name,is_available,' . cbFoodKeepsAvailabilitySql('menu_items') . ' AS keeps_availability');
    $stmt->execute([$available,$itemId,$storeId]);
    $food = $stmt->fetch();
    if (!$food) throw new RuntimeException('This food no longer exists or does not belong to your store.');
    $food['availability_date'] = cbRecordDailyFoodAvailability($pdo, $itemId, $available);
    return $food;
}
