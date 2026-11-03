<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start(); require_once __DIR__ . '/config/connection.php'; ob_end_clean();
require_once __DIR__ . '/config/stores.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role_name'] ?? '') !== 'Administrator') {
    header('Location: login.php'); exit;
}
$check = $pdo->prepare('SELECT user_id FROM users WHERE user_id=? AND role_id=1 AND is_active=1');
$check->execute([(int) $_SESSION['user_id']]);
if (!$check->fetch()) { header('Location: login.php'); exit; }
$token = cbCsrf('admin_categories_csrf');

function cbCategoryInput(array $input): array {
    $name = is_string($input['category_name'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $input['category_name'])) : '';
    $description = is_string($input['description'] ?? null) ? trim($input['description']) : '';
    if (!preg_match('/^.{1,50}$/u', $name)) throw new RuntimeException('Enter a category name of up to 50 characters.');
    if (!preg_match('/^.{0,150}$/us', $description)) throw new RuntimeException('Keep the description to 150 characters or fewer.');
    return [$name, $description === '' ? null : $description];
}

$error = '';
$success = $_SESSION['admin_categories_success'] ?? '';
unset($_SESSION['admin_categories_success']);
$form = ['category_name' => '', 'description' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if (!cbCheckCsrf('admin_categories_csrf')) throw new RuntimeException('Invalid request. Refresh and try again.');
        if ($action === 'add_category') {
            $form = ['category_name' => (string) ($_POST['category_name'] ?? ''), 'description' => (string) ($_POST['description'] ?? '')];
            [$name, $description] = cbCategoryInput($_POST);
            $stmt = $pdo->prepare('INSERT INTO categories (category_name,description) VALUES (?,?)');
            $stmt->execute([$name, $description]);
            $message = "Category \"$name\" added.";
        } elseif ($action === 'update_category') {
            $id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) throw new RuntimeException('Choose a category to update.');
            [$name, $description] = cbCategoryInput($_POST);
            $stmt = $pdo->prepare('UPDATE categories SET category_name=?,description=? WHERE category_id=?');
            $stmt->execute([$name, $description, $id]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('That category no longer exists.');
            $message = "Category \"$name\" saved.";
        } elseif ($action === 'delete_category') {
            $id = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$id) throw new RuntimeException('Choose a category to delete.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT category_name FROM categories WHERE category_id=? FOR UPDATE');
            $stmt->execute([$id]);
            $name = $stmt->fetchColumn();
            if ($name === false) throw new RuntimeException('That category no longer exists.');
            // Removed foods keep their category for order history, so they also block deletion.
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM menu_items WHERE category_id=?');
            $stmt->execute([$id]);
            if ((int) $stmt->fetchColumn() > 0) throw new RuntimeException("\"$name\" is used by foods, including removed ones, so it can't be deleted. Rename it instead.");
            $pdo->prepare('DELETE FROM categories WHERE category_id=?')->execute([$id]);
            $pdo->commit();
            $message = "Category \"$name\" deleted.";
        } else {
            throw new RuntimeException('Unknown action.');
        }
        $_SESSION['admin_categories_success'] = $message;
        header('Location: admin_categories.php'); exit;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($exception instanceof PDOException) {
            $error = match ($exception->getCode()) {
                '23505' => 'A category with that name already exists.',
                '23503' => "This category is used by foods, so it can't be deleted. Rename it instead.",
                default => 'Unable to save categories. Please try again.',
            };
            if (!in_array($exception->getCode(), ['23505', '23503'], true)) error_log('CampusBite category change failed: ' . $exception->getMessage());
        } else {
            $error = $exception->getMessage();
        }
    }
}
$categories = $pdo->query('SELECT c.category_id,c.category_name,c.description,
    COUNT(m.item_id) FILTER (WHERE m.archived_at IS NULL) AS food_count,
    COUNT(m.item_id) AS total_count
    FROM categories c LEFT JOIN menu_items m ON m.category_id=c.category_id
    GROUP BY c.category_id ORDER BY lower(c.category_name)')->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Categories - CampusBite Admin</title>
<?php require __DIR__ . '/components/favicon.php'; ?>
<link rel="stylesheet" href="student-pages.css?v=1"><link rel="stylesheet" href="student-navbar.css?v=1"><link rel="stylesheet" href="stores.css?v=<?= (int) filemtime(__DIR__ . '/stores.css') ?>">
<link rel="stylesheet" href="admin-navbar.css?v=<?= (int) filemtime(__DIR__ . '/admin-navbar.css') ?>">
<link rel="stylesheet" href="admin-categories.css?v=<?= (int) filemtime(__DIR__ . '/admin-categories.css') ?>">
</head><body class="cb-student cb-admin-categories">
<?php require __DIR__ . '/components/admin-navbar.php'; ?>
<main class="container"><header class="page-title"><span class="cb-page-eyebrow">ONE MENU. EVERY KITCHEN.</span><h1>Food categories.</h1><p>Staff choose from these categories when they add food. Students use them to filter the menu.</p></header>
<?php if ($success !== ''): ?><div class="success-message" role="status"><?= cbEscape($success) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="error-message" role="alert"><?= cbEscape($error) ?></div><?php endif; ?>
<div class="cb-category-layout">
<section class="cb-store-panel cb-category-add" aria-labelledby="add-category-title">
<h2 id="add-category-title">Add category</h2>
<form method="POST" action="admin_categories.php"><input type="hidden" name="action" value="add_category"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>">
<label for="new-category-name">Name</label><input id="new-category-name" name="category_name" maxlength="50" required value="<?= cbEscape($form['category_name']) ?>">
<label for="new-category-description">Description <span class="cb-category-optional">(optional)</span></label><input id="new-category-description" name="description" maxlength="150" value="<?= cbEscape($form['description']) ?>">
<p class="cb-store-help">Drinks that stay available overnight (water, soft drinks) are recognised in categories named like Beverages or Drinks.</p>
<button class="btn" type="submit">Add category</button>
</form>
</section>
<section class="cb-category-list" aria-labelledby="category-list-title">
<h2 id="category-list-title"><?= count($categories) ?> <?= count($categories) === 1 ? 'category' : 'categories' ?></h2>
<?php if (!$categories): ?><p class="cb-category-empty">No categories yet. Add one so staff can list food.</p><?php endif; ?>
<?php foreach ($categories as $category): $id = (int) $category['category_id']; ?>
<article class="cb-store-panel cb-category-item">
<form method="POST" action="admin_categories.php" class="cb-category-edit"><input type="hidden" name="action" value="update_category"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>"><input type="hidden" name="category_id" value="<?= $id ?>">
<div class="cb-category-fields">
<div><label for="category-name-<?= $id ?>">Name</label><input id="category-name-<?= $id ?>" name="category_name" maxlength="50" required value="<?= cbEscape($category['category_name']) ?>"></div>
<div><label for="category-description-<?= $id ?>">Description</label><input id="category-description-<?= $id ?>" name="description" maxlength="150" value="<?= cbEscape($category['description']) ?>"></div>
</div>
<p class="cb-category-count"><?= (int) $category['food_count'] ?> <?= (int) $category['food_count'] === 1 ? 'food' : 'foods' ?> listed</p>
<div class="cb-store-actions"><button class="btn btn-secondary" type="submit">Save</button>
<?php if ((int) $category['total_count'] === 0): ?><button class="btn cb-food-delete" type="submit" form="category-delete-<?= $id ?>" aria-label="Delete <?= cbEscape($category['category_name']) ?>">Delete</button><?php endif; ?>
</div>
<?php if ((int) $category['total_count'] > 0): ?><p class="cb-store-help">In use, so it can't be deleted. Rename it instead.</p><?php endif; ?>
</form>
<?php if ((int) $category['total_count'] === 0): ?>
<form method="POST" action="admin_categories.php" id="category-delete-<?= $id ?>"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="csrf_token" value="<?= cbEscape($token) ?>"><input type="hidden" name="category_id" value="<?= $id ?>"></form>
<?php endif; ?>
</article>
<?php endforeach; ?>
</section>
</div>
</main></body></html>
