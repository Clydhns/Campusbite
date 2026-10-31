<?php
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php require __DIR__ . '/components/favicon.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Learn about CampusBite, an independent student-built campus food-ordering project, before creating an account.">
    <title>CampusBite - Independent Student Food-Ordering Project</title>
    <link rel="stylesheet" href="brand.css?v=<?= (int) filemtime(__DIR__ . '/brand.css') ?>">
    <link rel="stylesheet" href="public-home.css?v=<?= (int) filemtime(__DIR__ . '/public-home.css') ?>">
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
    <a class="cb-brand-logo" href="index.php" aria-label="CampusBite project home"><?php require __DIR__ . '/components/brand-mark.php'; ?></a>
    <nav aria-label="Public navigation">
        <a href="#about">About</a>
        <a href="#data-use">Data use</a>
        <a class="button button-outline" href="login.php">Sign in</a>
    </nav>
</header>
<main id="main">
    <section class="intro" aria-labelledby="intro-title">
        <p class="eyebrow">An independent student project</p>
        <h1 id="intro-title">Your next bite.<br><span>A better campus break.</span></h1>
        <p class="intro-description">Explore campus kitchens, order your favorites, and track your pickup. All in one place.</p>
        <div class="actions">
            <a class="button" href="register.php">Create an account</a>
            <a class="text-link" href="#about">How it works <span aria-hidden="true">&darr;</span></a>
        </div>
        <p class="identity-note">Not an official school portal. CampusBite accounts are separate from school, email, and GCash accounts. <a href="#data-use">Before you register &rarr;</a></p>
    </section>
    <section id="about" class="section" aria-labelledby="about-title">
        <h2 id="about-title">A simpler way to take a break.</h2>
        <ol class="steps">
            <li><span class="step-number" aria-hidden="true">01</span><h3>Choose a kitchen</h3><p>Browse food, prices, and availability from campus stores.</p></li>
            <li><span class="step-number" aria-hidden="true">02</span><h3>Place your order</h3><p>Save separate carts and check out one kitchen at a time.</p></li>
            <li><span class="step-number" aria-hidden="true">03</span><h3>Follow your pickup</h3><p>Track your order as the store prepares your food.</p></li>
        </ol>
    </section>
    <section id="data-use" class="section data-use" aria-labelledby="data-title">
        <div><p class="eyebrow">Before you register</p><h2 id="data-title">Know what you share.</h2></div>
        <div class="data-details">
            <h3>Account information</h3>
            <p>Registration asks for an ID number, name, email, and a new CampusBite password. Your ID identifies your account within this project; it does not sign you in to a school system. Contact number and school/course/section are optional.</p>
            <h3>Orders and payment records</h3>
            <p>The project stores your orders and their payment information. If you submit a GCash receipt, the image is stored for payment review by authorized project staff. Submit only the details needed to verify the payment.</p>
            <h3>Account access</h3>
            <p>Project administrators manage accounts. Store staff manage their own menus and store orders. A session cookie keeps you signed in.</p>
            <h3>Use a separate password</h3>
            <p>Never enter your school-account password, email password, GCash password, PIN, or one-time password here. Create a unique password just for CampusBite.</p>
            <p class="data-footnote">This page explains the current project and its account flow. It is not a claim of school affiliation or a guarantee of third-party security approval.</p>
        </div>
    </section>
</main>
<footer><span>CampusBite</span><p>Independent student-built campus food ordering.</p><a href="login.php">Sign in to your CampusBite account</a></footer>
</body>
</html>
