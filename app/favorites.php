<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Cache-Control: private, no-store');
ob_start(); require_once __DIR__ . '/config/connection.php'; ob_end_clean();
require_once __DIR__ . '/config/stores.php';
require_once __DIR__ . '/config/favorites.php';

$isFetch = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
if (!isset($_SESSION['user_id']) || (int) ($_SESSION['role_id'] ?? 0) !== 3) {
    if ($isFetch && $_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => 'Sign in again to save favourites.']);
        exit;
    }
    header('Location: login.php');
    exit;
}
$studentId = (int) $_SESSION['user_id'];
$token = cbCsrf('favorites_csrf');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $storeId = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $return = in_array($_POST['return'] ?? '', ['dashboard.php', 'favorites.php'], true) ? $_POST['return'] : 'favorites.php';
    $result = ['ok' => false, 'message' => 'Unable to update favourites. Please try again.'];
    $status = 400;
    if (!cbCheckCsrf('favorites_csrf')) {
        $result['message'] = 'Your session changed. Refresh the page and try again.';
        $status = 403;
    } elseif ($storeId) {
        try {
            $favorite = cbSetFavoriteStore($pdo, $studentId, $storeId, ($_POST['favorite'] ?? '') === '1');
            $result = ['ok' => true, 'favorite' => $favorite, 'count' => count(cbFavoriteStoreIds($pdo, $studentId))];
            $status = 200;
        } catch (PDOException $exception) {
            error_log('CampusBite favourite update failed.');
            $status = 500;
        }
    }
    if ($isFetch) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
    header('Location: ' . $return . ($return === 'dashboard.php' ? '#campus-kitchens' : ''), true, 303);
    exit;
}

$stmt = $pdo->prepare("SELECT s.*, u.is_active AS staff_active
    FROM favorite_stores f
    JOIN stores s ON s.store_id=f.store_id
    JOIN users u ON u.user_id=s.staff_user_id
    WHERE f.student_id=? AND u.is_active=1 AND u.role_id=2
    ORDER BY f.created_at DESC");
$stmt->execute([$studentId]);
$favorites = $stmt->fetchAll();
$favoritePhotos = cbFavoriteStorePhotos($pdo, array_column($favorites, 'store_id'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Favourites - CampusBite</title>
    <link rel="stylesheet" href="student-pages.css?v=1">
    <link rel="stylesheet" href="student-navbar.css?v=1">
    <link rel="stylesheet" href="favorites.css?v=<?= (int) filemtime(__DIR__ . '/favorites.css') ?>">
    <script src="home-favorites.js?v=<?= (int) filemtime(__DIR__ . '/home-favorites.js') ?>" defer></script>
</head>
<body class="cb-student cb-favorites">
<?php require __DIR__ . '/components/student-navbar.php'; ?>
<main class="container cb-favorites-page">
    <header class="cb-favorites-head">
        <a class="cb-round-button" href="dashboard.php" aria-label="Back to Home"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7"/></svg></a>
        <h1>Favourites</h1>
    </header>
    <p class="cb-favorites-status" id="favorites-status" role="status" aria-live="polite"></p>
    <?php if ($favorites): ?>
    <div class="cb-favorites-list">
        <?php foreach ($favorites as $store): $photo = $favoritePhotos[(int) $store['store_id']] ?? ''; ?>
        <article class="cb-favorite-item">
            <a class="cb-favorite-link" href="menu.php?store=<?= (int) $store['store_id'] ?>">
                <?php if ($photo): ?>
                <img src="<?= cbEscape($photo) ?>" alt="" loading="lazy" width="640" height="300">
                <?php else: ?>
                <span class="cb-favorite-placeholder" aria-hidden="true"><?= cbEscape(strtoupper(substr($store['store_name'], 0, 1))) ?></span>
                <?php endif; ?>
                <span class="cb-favorite-info">
                    <strong><?= cbEscape($store['store_name']) ?></strong>
                    <span class="cb-kitchen-meta"><?= cbStoreStatusPill($store) ?></span>
                    <?php if ($store['description']): ?><span><?= cbEscape($store['description']) ?></span><?php endif; ?>
                </span>
            </a>
            <?= cbFavoriteButton($store, true, $token, 'favorites.php') ?>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div class="cb-favorites-empty"<?= $favorites ? ' hidden' : '' ?>>
        <span class="cb-favorites-empty-icon"><?= cbFavoriteHeartIcon() ?></span>
        <h2>No favourites yet</h2>
        <p>Tap the heart on a kitchen to save it here.</p>
        <a class="btn" href="dashboard.php#campus-kitchens">Browse kitchens</a>
    </div>
</main>
</body>
</html>
