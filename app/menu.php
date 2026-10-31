<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');

ob_start(); require_once __DIR__ . "/config/connection.php"; ob_end_clean();
require_once __DIR__ . "/config/stores.php";

// Student access only
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role_id"]) ||
    (int) $_SESSION["role_id"] !== 3
) {
    header("Location: login.php");
    exit;
}

function h($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

if (empty($_SESSION["menu_csrf"])) {
    $_SESSION["menu_csrf"] = bin2hex(random_bytes(32));
}

$selectedStore = max(0, (int) ($_GET["store"] ?? 0));
$message = "";
$messageType = "";
$storeOrderingSql = cbStoreOrderingSql('s');
$availabilitySql = 'CASE WHEN (' . cbFoodAvailabilitySql('mi') . ')=1 AND (' . $storeOrderingSql . ')=1 THEN 1 ELSE 0 END';

// Add selected food to session cart
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $token = $_POST["csrf_token"] ?? "";
    $itemId = filter_var(
        $_POST["item_id"] ?? null,
        FILTER_VALIDATE_INT
    );
    $quantity = filter_var(
        $_POST["quantity"] ?? null,
        FILTER_VALIDATE_INT
    );

    if (!is_string($token) || !hash_equals($_SESSION["menu_csrf"], $token)) {
        $message = "Invalid request. Please refresh the page.";
        $messageType = "error";
    } elseif (!$itemId || !$quantity || $quantity < 1 || $quantity > 20) {
        $message = "Please select a valid food and quantity.";
        $messageType = "error";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT
            mi.item_id, mi.store_id, mi.image_path, s.store_name,
                    s.opens_at,s.closes_at,$storeOrderingSql AS accepting_orders,
                    mi.item_name,
                    mi.description,
                    mi.price,
                    $availabilitySql AS general_available,
                    c.category_name
                FROM menu_items mi
                JOIN stores s ON s.store_id=mi.store_id
                JOIN users store_owner ON store_owner.user_id=s.staff_user_id
                INNER JOIN categories c
                    ON mi.category_id = c.category_id
                WHERE mi.item_id = ? AND mi.archived_at IS NULL AND s.is_open=1 AND store_owner.is_active=1 AND store_owner.role_id=2
                LIMIT 1
            ");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                $message = "Food item not found.";
                $messageType = "error";
            } else {
                $available = (int) $item["general_available"] === 1;

                $cart = $_SESSION["cart"] ?? [];
                $currentQty = (int) ($cart[$itemId]["quantity"] ?? 0);
                $newQty = $currentQty + $quantity;

                if (!(int) $item['accepting_orders']) {
                    $message = 'This store is closed. Store hours: ' . cbStoreHoursLabel($item) . '. Your cart is saved.';
                    $messageType = 'error';
                } elseif (!$available) {
                    $message = "This food is currently unavailable.";
                    $messageType = "error";
                } elseif ($newQty > 99) {
                    $message = "You can add up to 99 of each food per cart.";
                    $messageType = "error";
                } else {
                    $_SESSION["cart"][$itemId] = [
                        "item_id" => (int) $item["item_id"],
                        "store_id" => (int) $item["store_id"],
                        "store_name" => $item["store_name"],
                        "item_name" => $item["item_name"],
                        "description" => $item["description"],
                        "price" => (float) $item["price"],
                        "category_name" => $item["category_name"],
                        "quantity" => $newQty
                    ];

                    $message = $item["item_name"] . " added to cart!";
                    $messageType = "success";
                }
            }
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $messageType = "error";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $message = "Unable to add food right now.";
            $messageType = "error";
        }
    }
}

// All cart/CSRF writes are complete; don't block another navigation while reading menus.
session_write_close();
$stores = cbVisibleStores($pdo);

// Load categories and food
$categories = [];
$menuItems = [];
$loadError = "";

try {
    $categories = $pdo->query("
        SELECT category_id, category_name
        FROM categories
        ORDER BY CASE
            WHEN category_name ~* '(^|[^a-z])(beverages?|drinks?|soft[ -]?drinks?|juices?|coffees?|teas?|sodas?|water|shakes?|smoothies?)([^a-z]|$)' THEN 3
            WHEN category_name ~* '(rice|meal|main|pasta|noodle|entree)' THEN 0
            WHEN category_name ~* '(snack|bread|sandwich)' THEN 1
            ELSE 2 END, category_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    $menuQuery = $pdo->prepare("
        SELECT
            mi.item_id, mi.store_id, mi.image_path, s.store_name,
            s.opens_at,s.closes_at,$storeOrderingSql AS accepting_orders,
            mi.item_name,
            mi.description,
            mi.price,
            $availabilitySql AS general_available,
            c.category_name
        FROM menu_items mi
                JOIN stores s ON s.store_id=mi.store_id
                JOIN users store_owner ON store_owner.user_id=s.staff_user_id
        INNER JOIN categories c
            ON mi.category_id = c.category_id
        WHERE mi.archived_at IS NULL AND s.is_open=1 AND store_owner.is_active=1 AND store_owner.role_id=2 AND (?=0 OR mi.store_id=?)
        ORDER BY s.store_name, CASE
            WHEN c.category_name ~* '(^|[^a-z])(beverages?|drinks?|soft[ -]?drinks?|juices?|coffees?|teas?|sodas?|water|shakes?|smoothies?)([^a-z]|$)' THEN 3
            WHEN c.category_name ~* '(rice|meal|main|pasta|noodle|entree)' THEN 0
            WHEN c.category_name ~* '(snack|bread|sandwich)' THEN 1
            ELSE 2 END, c.category_name, mi.item_name, mi.item_id
    ");
    $menuQuery->execute([$selectedStore,$selectedStore]);
    $menuItems = $menuQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log($e->getMessage());
    $loadError = "Unable to load the menu. Check the database connection and tables.";
}

$cartCount = 0;
foreach ($_SESSION["cart"] ?? [] as $cartItem) {
    $cartCount += (int) ($cartItem["quantity"] ?? 0);
}

$firstName = $_SESSION["first_name"] ?? "Student";

require_once __DIR__ . '/config/meal-highlights.php';
$kitchenMeals = [];
try {
    // Kitchen identity photos should not disappear when today's food is switched off.
    $highlightFoods = $selectedStore === 0 ? $menuItems : $pdo->query("SELECT mi.store_id, mi.item_name, mi.image_path, c.category_name
        FROM menu_items mi
        JOIN categories c ON c.category_id=mi.category_id
        JOIN stores s ON s.store_id=mi.store_id
        JOIN users u ON u.user_id=s.staff_user_id
        WHERE mi.archived_at IS NULL AND mi.image_path IS NOT NULL
        AND s.is_open=1 AND u.is_active=1 AND u.role_id=2
        ORDER BY s.store_name, c.category_name, mi.item_name")->fetchAll(PDO::FETCH_ASSOC);
    $kitchenMeals = cbStoreKitchenHighlights($highlightFoods);
} catch (PDOException $e) {
    error_log($e->getMessage());
}
$selectedKitchen = null;
foreach ($stores as $store) {
    if ((int) $store['store_id'] === $selectedStore) $selectedKitchen = $store;
}
$availableCount = count(array_filter($menuItems, static fn($item) => (int) $item['general_available'] === 1));

function foodEmoji($category) {
    $category = strtolower($category);

    if (str_contains($category, "rice")) return "🍚";
    if (str_contains($category, "drink") ||
        str_contains($category, "beverage")) return "🥤";
    if (str_contains($category, "snack")) return "🍟";
    if (str_contains($category, "dessert")) return "🍰";
    if (str_contains($category, "bread")) return "🥪";

    return "🍱";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Food Menu | Campusbite</title>

    <link rel="stylesheet" href="menu-food.css?v=2">
    <link rel="stylesheet" href="student-pages.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
<link rel="stylesheet" href="stores.css?v=<?= (int) filemtime(__DIR__ . '/stores.css') ?>">
    <link rel="stylesheet" href="store-switch.css?v=<?= (int) filemtime(__DIR__ . '/store-switch.css') ?>">
    <script src="store-switch.js?v=<?= (int) filemtime(__DIR__ . '/store-switch.js') ?>" defer></script>
    <link rel="stylesheet" href="menu-refresh.css?v=<?= (int) filemtime(__DIR__ . '/menu-refresh.css') ?>">
</head>

<body class="cb-menu cb-student" data-menu-user="<?= (int) $_SESSION['user_id'] ?>" data-menu-error="<?= h($loadError) ?>">

<?php require __DIR__ . "/components/student-navbar.php"; ?>

<main class="container">

    <section class="menu-page-header">
        <span class="menu-eyebrow">FRESH PICKS. HAPPY BREAKS.</span>
        <h1>What&apos;s for <span>lunch?</span></h1>
        <p>
            Hello, <?= h($firstName) ?>! 👋
            Hungry? Browse our delicious meals and
            order your favorites ahead of time.
        </p>
    </section>

    <?php if ($message !== ""): ?>
        <div class="message <?= h($messageType) ?>" role="status" aria-live="polite">
            <?= h($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($loadError !== ""): ?>
        <div class="message error" role="alert"><?= h($loadError) ?></div>
    <?php endif; ?>

    <section class="cb-store-section cb-menu-kitchens" aria-labelledby="kitchens-heading">
        <div class="kitchens-heading"><h2 id="kitchens-heading">Choose your kitchen</h2><span>Save several carts. Check out each kitchen separately.</span></div>
        <nav class="kitchen-options" aria-label="Choose a kitchen">
            <a href="menu.php" class="cb-store-tile kitchen-tab kitchen-all <?= $selectedStore === 0 ? 'is-selected' : '' ?>" data-store-name="All kitchens" <?= $selectedStore === 0 ? 'aria-current="true"' : '' ?>>
                <span class="kitchen-all-icon" aria-hidden="true">&#9670;</span>
                <span class="kitchen-tab-copy"><strong>All kitchens</strong><small>Find your next favorite</small></span>
                <span class="kitchen-tab-check" aria-hidden="true">&#10003;</span>
            </a>
            <?php foreach ($stores as $store): $id = (int) $store['store_id']; $meal = $kitchenMeals[$id] ?? null; $photo = cbFoodPhotoUrl($meal['image_path'] ?? null); ?>
                <a href="menu.php?store=<?= $id ?>" class="cb-store-tile kitchen-tab <?= $selectedStore === $id ? 'is-selected' : '' ?>" data-store-name="<?= h($store['store_name']) ?>" <?= $selectedStore === $id ? 'aria-current="true"' : '' ?>>
                    <?php if ($photo): ?><img src="<?= h($photo) ?>" alt="" width="76" height="76" loading="lazy" decoding="async"><?php else: ?><span class="kitchen-all-icon" aria-hidden="true">&#127869;</span><?php endif; ?>
                    <span class="kitchen-tab-copy"><strong><?= h($store['store_name']) ?></strong><small><?= h(cbStoreStatusLabel($store)) ?></small></span>
                    <span class="kitchen-tab-check" aria-hidden="true">&#10003;</span>
                </a>
            <?php endforeach; ?>
        </nav>
    </section>
    <p id="store-switch-status" class="cb-store-switch-status" role="status" aria-live="polite" hidden></p>
    <a id="store-switch-login" href="login.php" hidden>Sign in again</a>
    <div class="toolbar">
        <input
            type="search"
            id="searchFood"
            class="search-box"
            placeholder="Search for food..."
            aria-label="Search for food"
        >

        <a href="cart.php" class="cart-button">
            🛒 View Cart (<?= $cartCount ?>)
        </a>
    </div>

    <div class="categories" role="group" aria-label="Food categories">
        <button class="category-btn active"
                data-category="all" aria-pressed="true">
            All Food
        </button>

        <?php foreach ($categories as $category): ?>
            <button
                class="category-btn"
                aria-pressed="false"
                data-category="<?= h($category["category_name"]) ?>"
            >
                <?= h($category["category_name"]) ?>
            </button>
        <?php endforeach; ?>
    </div>

    <div id="store-menu-results" data-store="<?= (int) $selectedStore ?>" aria-busy="false">
    <div class="menu-results-heading">
        <div><span class="menu-eyebrow">FRESH FROM THE KITCHEN</span><h2><?= h($selectedKitchen['store_name'] ?? ($selectedStore ? 'Kitchen unavailable' : 'Something for every craving')) ?></h2>
        <p><?= h($selectedKitchen['description'] ?? ($selectedStore ? 'Choose another kitchen to explore available food.' : 'Discover meals, snacks, and little treats from your campus kitchens.')) ?></p></div>
        <span class="menu-available-count"><?= $availableCount ?> available now</span>
    </div>
    <div class="menu-grid" id="menuGrid">

        <?php foreach ($menuItems as $item): ?>

            <?php
                $available = (int) $item["general_available"] === 1;
                $maxQty = 20;
            ?>

            <article
                class="food-card"
                data-category="<?= h($item["category_name"]) ?>"
                data-name="<?= h($item["item_name"]) ?>"
            >
                <div class="food-image">
                    <?php if ($photo = cbFoodPhotoUrl($item['image_path'])): ?>
                        <img class="cb-food-photo" src="<?= h($photo) ?>" alt="<?= h($item['item_name']) ?>" loading="lazy" width="640" height="400">
                    <?php else: ?>
                        <?= foodEmoji($item["category_name"]) ?>
                    <?php endif; ?>
                </div>

                <div class="food-info">
                    <span class="food-category">
                        <?= h($item["category_name"]) ?>
                    </span>

                    <span class="cb-food-store"><?= h($item["store_name"]) ?></span>
                    <small class="cb-store-hours"><?= h(cbStoreHoursLabel($item)) ?></small>
                    <h3><?= h($item["item_name"]) ?></h3>

                    <p class="description">
                        <?= h($item["description"] ?: "Freshly prepared food.") ?>
                    </p>

                    <div class="price">
                        ₱<?= number_format((float) $item["price"], 2) ?>
                    </div>

                    <?php if ($available): ?>
                        <div class="stock">
                            ● Available
                        </div>
                    <?php else: ?>
                        <div class="stock unavailable">
                            ● <?= (int) $item['accepting_orders'] ? 'Unavailable' : 'Store closed' ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" class="add-form">
                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= h($_SESSION["menu_csrf"]) ?>"
                        >

                        <input
                            type="hidden"
                            name="item_id"
                            value="<?= (int) $item["item_id"] ?>"
                        >

                        <input
                            class="qty-input"
                            type="number"
                            name="quantity"
                            value="1"
                            min="1"
                            max="<?= $maxQty ?>"
                            aria-label="Quantity for <?= h($item["item_name"]) ?>"
                            required
                            <?= !$available ? "disabled" : "" ?>
                        >

                        <button
                            class="add-btn"
                            type="submit"
                            <?= !$available ? "disabled" : "" ?>
                        >
                            <?= $available ? "🛒 Add to Cart" : "Unavailable" ?>
                        </button>
                    </form>
                </div>
            </article>

        <?php endforeach; ?>

    </div>

    <p id="noResults" class="empty" role="status" aria-live="polite" hidden>
        No matching food items found in this selection.
    </p>

    <?php if (!$menuItems && $loadError === ""): ?>
        <div class="empty">
            No food items have been added yet.
        </div>
    <?php endif; ?>

    </div>
</main>

<footer class="footer">
    © <?= date("Y") ?> Campusbite |
    Your Campus Food Ordering System
</footer>

<script>
    const searchInput = document.getElementById("searchFood");
    const categoryButtons = document.querySelectorAll(".category-btn");

    let selectedCategory = "all";

    function filterFoods() {
        const foodCards = document.querySelectorAll("#store-menu-results .food-card");
        const noResults = document.getElementById("noResults");
        const search = searchInput.value.toLowerCase().trim();
        let visibleCount = 0;

        foodCards.forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const category = card.dataset.category;

            const matchesSearch =
                name.includes(search) ||
                category.toLowerCase().includes(search);

            const matchesCategory =
                selectedCategory === "all" ||
                selectedCategory === category;

            const visible = matchesSearch && matchesCategory;
            card.style.display = visible ? "" : "none";

            if (visible) visibleCount++;
        });

        noResults.hidden =
            visibleCount > 0 || foodCards.length === 0;
    }

    searchInput.addEventListener("input", filterFoods);
    document.addEventListener("campusbite:store-menu-updated", filterFoods);

    categoryButtons.forEach(button => {
        button.addEventListener("click", () => {
            categoryButtons.forEach(btn => {
                btn.classList.remove("active");
                btn.setAttribute("aria-pressed", "false");
            });

            button.classList.add("active");
            button.setAttribute("aria-pressed", "true");
            selectedCategory = button.dataset.category;
            filterFoods();
        });
    });
</script>

</body>
</html>
