<?php

function cbFoodAvailabilitySql(string $alias): string {
    if (!preg_match('/^[a-z][a-z0-9_]*$/D', $alias)) throw new LogicException('Invalid menu alias.');
    // Use the validation statement's day, even if a transaction waited across midnight.
    return "CASE WHEN {$alias}.is_available=1 AND EXISTS (
        SELECT 1 FROM daily_menu today WHERE today.item_id={$alias}.item_id
        AND today.menu_date=(statement_timestamp() AT TIME ZONE 'Asia/Manila')::date AND today.is_available=1
    ) THEN 1 ELSE 0 END";
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
