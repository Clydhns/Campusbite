<?php
// Students' favourite kitchens (the heart button on Home and the Favourites page).
function cbFavoriteStoreIds(PDO $pdo, int $studentId): array {
    $stmt = $pdo->prepare('SELECT store_id FROM favorite_stores WHERE student_id=?');
    $stmt->execute([$studentId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

// Returns whether the store is a favourite afterwards. Only kitchens run by active staff can be added.
function cbSetFavoriteStore(PDO $pdo, int $studentId, int $storeId, bool $favorite): bool {
    if ($favorite) {
        $stmt = $pdo->prepare('INSERT INTO favorite_stores (student_id,store_id)
            SELECT ?,s.store_id FROM stores s JOIN users u ON u.user_id=s.staff_user_id
            WHERE s.store_id=? AND u.is_active=1 AND u.role_id=2
            ON CONFLICT (student_id,store_id) DO NOTHING');
        $stmt->execute([$studentId, $storeId]);
    } else {
        $pdo->prepare('DELETE FROM favorite_stores WHERE student_id=? AND store_id=?')->execute([$studentId, $storeId]);
    }
    return in_array($storeId, cbFavoriteStoreIds($pdo, $studentId), true);
}

// Photo per kitchen, chosen exactly as Home does (same food order, availability and meal scoring),
// so a kitchen shows the same picture on Home and on Favourites.
function cbFavoriteStorePhotos(PDO $pdo, array $storeIds): array {
    if (!$storeIds) return [];
    require_once __DIR__ . '/meal-highlights.php';
    $placeholders = implode(',', array_fill(0, count($storeIds), '?'));
    $availability = 'CASE WHEN (' . cbFoodAvailabilitySql('mi') . ')=1 AND (' . cbStoreOrderingSql('s') . ')=1 THEN 1 ELSE 0 END';
    $stmt = $pdo->prepare("SELECT mi.item_id, mi.store_id, mi.item_name, mi.image_path, c.category_name, $availability AS dashboard_available
        FROM menu_items mi JOIN stores s ON s.store_id=mi.store_id JOIN categories c ON c.category_id=mi.category_id
        WHERE mi.archived_at IS NULL AND mi.store_id IN ($placeholders)
        ORDER BY CASE
            WHEN c.category_name ~* '(^|[^a-z])(beverages?|drinks?|soft[ -]?drinks?|juices?|coffees?|teas?|sodas?|water|shakes?|smoothies?)([^a-z]|$)' THEN 3
            WHEN c.category_name ~* '(^|[^a-z])(desserts?|sweets?|cakes?|pastries|ice[ -]?cream)([^a-z]|$)' THEN 2
            WHEN c.category_name ~* '(rice|meal|main|pasta|noodle|entree)' THEN 0
            ELSE 1 END,
            c.category_name ASC, mi.item_name ASC, mi.item_id ASC");
    $stmt->execute(array_values(array_map('intval', $storeIds)));
    $foods = $stmt->fetchAll();
    foreach ($foods as &$food) $food['dashboard_available'] = (int) $food['dashboard_available'] === 1;
    unset($food);
    $photos = [];
    foreach (cbStoreMealHighlights($foods) as $storeId => $meal) {
        if ($photo = cbFoodPhotoUrl($meal['image_path'] ?? null)) $photos[(int) $storeId] = $photo;
    }
    return $photos;
}

function cbFavoriteHeartIcon(): string {
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 20.5s-7.5-4.6-9.3-9.4C1.4 7.6 3.6 4 7.2 4c2 0 3.6 1.1 4.8 2.8C13.2 5.1 14.8 4 16.8 4c3.6 0 5.8 3.6 4.5 7.1-1.8 4.8-9.3 9.4-9.3 9.4z"/></svg>';
}

// The heart toggle form. Works as a normal POST without JavaScript; home-favorites.js submits it in place.
function cbFavoriteButton(array $store, bool $favorite, string $token, string $return): string {
    $name = cbEscape($store['store_name']);
    return '<form method="POST" action="favorites.php" class="cb-favorite-form">'
        . '<input type="hidden" name="csrf_token" value="' . cbEscape($token) . '">'
        . '<input type="hidden" name="store_id" value="' . (int) $store['store_id'] . '">'
        . '<input type="hidden" name="favorite" value="' . ($favorite ? '0' : '1') . '">'
        . '<input type="hidden" name="return" value="' . cbEscape($return) . '">'
        . '<button type="submit" class="cb-heart-button" aria-pressed="' . ($favorite ? 'true' : 'false') . '"'
        . ' aria-label="' . ($favorite ? 'Remove ' . $name . ' from favourites' : 'Add ' . $name . ' to favourites') . '"'
        . ' data-store-name="' . $name . '">' . cbFavoriteHeartIcon() . '</button></form>';
}
