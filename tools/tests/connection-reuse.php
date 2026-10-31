<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || getenv('CAMPUSBITE_LOCAL_TEST') !== '1'
    || getenv('PGHOST') !== '127.0.0.1' || getenv('PGPORT') !== '18758') exit(1);
require dirname(__DIR__, 2) . '/app/config/cloud-database.php';
require dirname(__DIR__, 2) . '/app/config/stores.php';
require dirname(__DIR__, 2) . '/app/config/cart-groups.php';
require dirname(__DIR__, 2) . '/app/config/admin-stats.php';
class QueryCountPDO extends PDO {
    public int $queries = 0;
    public function __construct(private PDO $inner) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->queries++;
        return $this->inner->prepare($query, $options);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->queries++;
        return $fetchMode === null ? $this->inner->query($query) : $this->inner->query($query, $fetchMode, ...$fetchModeArgs);
    }
}
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
    $largeCart = [1=>['item_id'=>1,'price'=>0.01],2=>['item_id'=>2,'price'=>0.01]];
    $insert = $first->prepare('INSERT INTO menu_items(category_id,item_name,price,store_id) VALUES (1,?,10,1) RETURNING item_id');
    for ($index=0; $index<18; $index++) {
        $insert->execute(['Performance fixture ' . $index]);
        $id = (int) $insert->fetchColumn();
        $largeCart[$id] = ['item_id'=>$id,'price'=>0.01];
    }
    $counted = new QueryCountPDO($first);
    $catalog = cbCartCatalog($counted, $largeCart);
    $catalogQueries = $counted->queries;
    $emptyCatalog = cbCartCatalog($counted, []);
    $emptyQueries = $counted->queries - $catalogQueries;
    $catalogValid = count($catalog)===20 && (float) $catalog[1]['price']===50.0
        && (int) $catalog[1]['general_available']===1 && (int) $catalog[$id]['general_available']===0;
    $counted->queries = 0;
    foreach (array_keys($largeCart) as $id) {
        $one = $counted->prepare('SELECT item_id FROM menu_items WHERE item_id=?');
        $one->execute([$id]); $one->fetch();
    }
    $legacyQueries = $counted->queries;
    $counted->queries = 0;
    $existingStore = cbEnsureStore($counted, 2);
    $storeQueries = $counted->queries;
    $counted->queries = 0;
    $emptyStats = cbAdminStats($counted);
    $emptyStatsValid = (int) $emptyStats['today_orders']===0 && (float) $emptyStats['total_sales']===0.0;
    $first->exec("INSERT INTO orders(order_number,student_id,store_id,status_id,total_amount,order_datetime) VALUES
        ('PERF-PENDING',4,1,1,10,CURRENT_TIMESTAMP),
        ('PERF-COMPLETE',4,1,4,30,CURRENT_TIMESTAMP),
        ('PERF-YESTERDAY',4,1,4,20,CURRENT_DATE-INTERVAL '1 day')");
    $counted->queries = 0;
    $stats = cbAdminStats($counted);
    $statsQueries = $counted->queries;
    $statsValid = $emptyStatsValid && (int) $stats['total_users']===4 && (int) $stats['active_users']===4
        && (int) $stats['total_staff']===2 && (int) $stats['total_students']===1
        && (int) $stats['pending_orders']===1 && (int) $stats['completed_orders']===2
        && (float) $stats['total_sales']===50.0 && (float) $stats['today_sales']===30.0
        && (int) $stats['today_orders']===2 && (int) $stats['available_menu_items']===2;
    $inactiveStoreRejected = false;
    $first->exec('UPDATE users SET is_active=0 WHERE user_id=3');
    try { cbEnsureStore($counted, 3); }
    catch (RuntimeException $error) { $inactiveStoreRejected = true; }
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
    'batch_catalog_valid' => $catalogValid && $emptyCatalog===[],
    'store_lookup_valid' => (int) $existingStore['store_id']===1 && $inactiveStoreRejected,
    'admin_stats_valid' => $statsValid,
    'query_counts' => ['cart_items'=>count($largeCart),'legacy_cart_queries'=>$legacyQueries,
        'batch_cart_queries'=>$catalogQueries,'empty_cart_queries'=>$emptyQueries,'existing_store_queries'=>$storeQueries,
        'admin_stats_queries'=>$statsQueries],
]);
