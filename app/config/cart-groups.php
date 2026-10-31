<?php
require_once __DIR__ . '/stores.php';

function cbCartCatalog(PDO $pdo, array $cart): array {
    if (!$cart) return [];
    $ids = array_values(array_unique(array_map('intval', array_keys($cart))));
    $stmt = $pdo->prepare('SELECT mi.item_id,mi.item_name,mi.description,mi.image_path,mi.price,c.category_name,
        ' . cbFoodAvailabilitySql('mi') . ' AS general_available,s.is_open,s.opens_at,s.closes_at,
        ' . cbStoreOrderingSql('s') . ' AS accepting_orders,
        owner.is_active AS owner_active,owner.role_id AS owner_role
        FROM menu_items mi JOIN categories c ON c.category_id=mi.category_id
        JOIN stores s ON s.store_id=mi.store_id JOIN users owner ON owner.user_id=s.staff_user_id
        WHERE mi.archived_at IS NULL AND mi.item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
    $stmt->execute($ids);
    $catalog = [];
    foreach ($stmt->fetchAll() as $item) $catalog[(int) $item['item_id']] = $item;
    return $catalog;
}

// Resolve store ownership from current database rows, never from session store labels.
function cbCartGroups(PDO $pdo, array $cart): array {
    if (!$cart) return [];
    $ids = [];
    foreach ($cart as $key => $entry) {
        if (is_array($entry)) $ids[] = (int) ($entry['item_id'] ?? $key);
    }
    $ids = array_values(array_unique($ids));
    $owners = [];
    if ($ids) {
        $query = $pdo->prepare('SELECT m.item_id AS cart_item_id,s.*,u.is_active AS staff_active,u.role_id AS staff_role
            FROM menu_items m JOIN stores s ON s.store_id=m.store_id
            JOIN users u ON u.user_id=s.staff_user_id
            WHERE m.item_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $query->execute($ids);
        foreach ($query->fetchAll() as $row) $owners[(int) $row['cart_item_id']] = $row;
    }
    $groups = [];
    foreach ($cart as $key => $entry) {
        $entry = is_array($entry) ? $entry : ['quantity' => 0, 'unavailable' => true];
        $id = (int) ($entry['item_id'] ?? $key);
        $store = $owners[$id] ?? null;
        $storeId = $store ? (int) $store['store_id'] : 0;
        if (!isset($groups[$storeId])) {
            $groups[$storeId] = ['store' => $store, 'items' => [], 'quantity' => 0, 'subtotal' => 0,
                'can_checkout' => $store && cbStoreAcceptingOrders($store)
                    && (int) $store['staff_active'] === 1 && (int) $store['staff_role'] === 2];
        }
        if ($store) {
            $entry['store_id'] = $storeId;
            $entry['store_name'] = $store['store_name'];
        }
        $groups[$storeId]['items'][$key] = $entry;
        $quantity = max(0, (int) ($entry['quantity'] ?? 0));
        $groups[$storeId]['quantity'] += $quantity;
        $groups[$storeId]['subtotal'] += $quantity * (float) ($entry['price'] ?? 0);
        if (!$store || !$quantity || !empty($entry['unavailable'])) $groups[$storeId]['can_checkout'] = false;
    }
    return $groups;
}

function cbSelectedCheckoutCart(PDO $pdo, array $cart, $requestedStore): array {
    $groups = cbCartGroups($pdo, $cart);
    if (!$groups) return ['store_id' => 0, 'items' => []];
    if ($requestedStore === null && count($groups) === 1) $requestedStore = array_key_first($groups);
    $storeId = filter_var($requestedStore, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($storeId === false || !isset($groups[$storeId])) {
        throw new RuntimeException('Choose a store cart below and check out one kitchen at a time.');
    }
    $group = $groups[$storeId];
    // Availability and current prices are revalidated under locks during order placement.
    if (!$group['store'] || !cbStoreAcceptingOrders($group['store']) || !(int) $group['store']['staff_active']
        || (int) $group['store']['staff_role'] !== 2) {
        throw new RuntimeException('This kitchen is closed or unavailable. ' . ($group['store'] ? 'Store hours: ' . cbStoreHoursLabel($group['store']) . '. ' : '') . 'Your saved carts are unchanged.');
    }
    return ['store_id' => (int) $storeId, 'items' => $group['items']];
}

function cbRemoveCartItems(array &$allItems, array $selectedItems): void {
    foreach (array_keys($selectedItems) as $key) unset($allItems[$key]);
}
