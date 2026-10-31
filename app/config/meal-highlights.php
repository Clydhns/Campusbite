<?php
function cbStoreMealHighlights(array $foods): array {
    $selected = [];
    $scores = [];
    foreach ($foods as $food) {
        $category = strtolower(trim((string) ($food['category_name'] ?? '')));
        $name = strtolower((string) ($food['item_name'] ?? ''));
        if (preg_match('/beverage|drink|coffee|tea|juice|soda|shake/', $category)) continue;
        $mealCategory = preg_match('/rice|meal|main|pasta|noodle|entree/', $category);
        $mealName = preg_match('/\b(carbonara|pasta|spaghetti|lasagna|pancit|rice|adobo|tapa|chicken|burger|pizza|sandwich)\b/', $name);
        if (!$mealCategory && !$mealName) continue;
        $photo = cbFoodPhotoUrl($food['image_path'] ?? null);
        // Prefer available meals on Home, but retain catalog meals as a fallback.
        $score = (!empty($food['dashboard_available']) ? 20 : 0)
            + ($photo ? 10 : 0) + ($mealCategory ? 2 : 1);
        $storeId = (int) $food['store_id'];
        if (!isset($scores[$storeId]) || $score > $scores[$storeId]) {
            $scores[$storeId] = $score;
            $selected[$storeId] = $food;
        }
    }
    return $selected;
}

function cbStoreKitchenHighlights(array $foods): array {
    $selected = [];
    $scores = [];
    foreach (cbStoreMealHighlights($foods) as $id => $meal) {
        if (!cbFoodPhotoUrl($meal['image_path'] ?? null)) continue;
        $selected[$id] = $meal;
        $scores[$id] = 3;
    }
    foreach ($foods as $food) {
        if (!cbFoodPhotoUrl($food['image_path'] ?? null)) continue;
        $id = (int) $food['store_id'];
        $category = strtolower((string) ($food['category_name'] ?? ''));
        $score = preg_match('/beverage|drink|coffee|tea|juice|soda|shake/', $category) ? 1 : 2;
        if (!isset($scores[$id]) || $score > $scores[$id]) {
            $selected[$id] = $food;
            $scores[$id] = $score;
        }
    }
    return $selected;
}
