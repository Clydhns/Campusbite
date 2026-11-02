<?php $faviconVersion = (int) filemtime(__DIR__ . '/../favicon.svg'); ?>
<?php $homeIconVersion = (int) filemtime(__DIR__ . '/../apple-touch-icon.png'); ?>
<link rel="icon" href="favicon.ico?v=<?= $faviconVersion ?>" sizes="32x32">
<link rel="icon" href="favicon.svg?v=<?= $faviconVersion ?>" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=<?= $homeIconVersion ?>" sizes="180x180">
<link rel="manifest" href="manifest.webmanifest?v=<?= (int) filemtime(__DIR__ . '/../manifest.webmanifest') ?>">
<meta name="apple-mobile-web-app-title" content="CampusBite">
