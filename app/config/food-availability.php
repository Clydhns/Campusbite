<?php

function cbFoodAvailabilitySql(string $alias): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) throw new LogicException('Invalid menu alias.');
    // Use the validation statement's day, even if a transaction waited across midnight.
    $keepAvailable = cbFoodKeepsAvailabilitySql($alias);
    return "CASE WHEN {$alias}.is_available=1 AND (($keepAvailable)=1 OR EXISTS (
        SELECT 1 FROM daily_menu today WHERE today.item_id={$alias}.item_id
        AND today.menu_date=(statement_timestamp() AT TIME ZONE 'Asia/Manila')::date AND today.is_available=1
    )) THEN 1 ELSE 0 END";
}

function cbFoodKeepsAvailabilitySql(string $alias): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) throw new LogicException('Invalid menu alias.');
    $name = "{$alias}.item_name";
    $fresh = '(^|[^a-z])(juice|iced[ -]?tea|coffee|milk[ -]?tea|shake|smoothie|float|buko|coconut)([^a-z]|$)';
    $packaged = '(^|[^a-z])(water|soft[ -]?drinks?|soda|coca[ -]?cola|coke|sprite|pepsi|royal|7[ -]?up|mountain dew|rc cola|fanta|mirinda|sarsi)([^a-z]|$)';
    $explicit = '(^|[^a-z])((bottled|drinking|mineral|purified|distilled|spring)[ -]+water|soft[ -]?drinks?)([^a-z]|$)';
    // Do not exempt the whole Beverages category: juice and freshly made drinks expire.
    return "CASE WHEN $name !~* '$fresh' AND ($name ~* '$explicit' OR EXISTS (
        SELECT 1 FROM categories availability_category WHERE availability_category.category_id={$alias}.category_id
        AND (availability_category.category_name ~* '^(soft[ -]?drinks?|sodas?|bottled water|drinking water|water)$'
            OR (availability_category.category_name ~* '(beverage|drink)' AND $name ~* '$packaged'))
    )) THEN 1 ELSE 0 END";
}

// Call only after locking/validating the food's store ownership in this transaction.
function cbRecordDailyFoodAvailability(PDO $pdo, int $itemId, int $available): string {
    if (!$pdo->inTransaction()) throw new RuntimeException('Menu changes require a transaction.');
    $stmt = $pdo->prepare("INSERT INTO daily_menu (menu_date,item_id,is_available)
        VALUES ((statement_timestamp() AT TIME ZONE 'Asia/Manila')::date,?,?) ON CONFLICT (menu_date,item_id)
        DO UPDATE SET is_available=EXCLUDED.is_available RETURNING menu_date");
    $stmt->execute([$itemId, $available]);
    return (string) $stmt->fetchColumn();
}
