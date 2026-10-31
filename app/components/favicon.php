<?php $faviconVersion = (int) filemtime(__DIR__ . '/../favicon.svg'); ?>
<link rel="icon" href="favicon.ico?v=<?= $faviconVersion ?>" sizes="32x32">
<link rel="icon" href="favicon.svg?v=<?= $faviconVersion ?>" type="image/svg+xml" sizes="any">
<link rel="apple-touch-icon" href="apple-touch-icon.png?v=<?= $faviconVersion ?>" sizes="180x180">
