<?php
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
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
        <div class="intro-copy">
        <p class="eyebrow">An independent student project</p>
        <h1 id="intro-title">Your next bite.<br><span>A better campus break.</span></h1>
        <p class="intro-description">Explore campus kitchens, order your favorites, and follow your pickup. A little less queue. A little more time for you.</p>
        <div class="actions">
            <a class="button" href="register.php">Create an account <span aria-hidden="true">&rarr;</span></a>
            <a class="text-link" href="login.php">Sign in</a>
        </div>
        <p class="account-hint">New here? Create your own CampusBite account to get started.</p>
        <p class="identity-note">Not an official school portal. CampusBite accounts are separate from school, email, and GCash accounts. <a href="#data-use">Before you register &rarr;</a></p>
        </div>
        <aside class="flow-preview" aria-labelledby="flow-title">
            <div class="flow-heading">
                <p class="eyebrow">How it works</p>
                <span class="flow-mark" aria-hidden="true">CB</span>
            </div>
            <h2 id="flow-title">Good food.<br>Simple pickup.</h2>
            <ol class="flow-steps">
                <li><span class="flow-number" aria-hidden="true">01</span><div><h3>Find your kitchen</h3><p>Explore food, prices, and availability.</p></div></li>
                <li><span class="flow-number" aria-hidden="true">02</span><div><h3>Make it your order</h3><p>Check out one kitchen at a time.</p></div></li>
                <li><span class="flow-number" aria-hidden="true">03</span><div><h3>Follow your pickup</h3><p>Track your order as the store prepares it.</p></div></li>
            </ol>
            <a class="flow-link" href="#about">Get to know CampusBite <span aria-hidden="true">&darr;</span></a>
        </aside>
    </section>
    <section id="about" class="section" aria-labelledby="about-title">
        <p class="eyebrow">What this website does</p>
        <h2 id="about-title">One store. One order. One pickup.</h2>
        <div class="steps">
            <article><span class="step-number" aria-hidden="true">01</span><h3>Browse a store</h3><p>View food items, prices, and availability from participating stores.</p></article>
            <article><span class="step-number" aria-hidden="true">02</span><h3>Place your order</h3><p>Keep separate carts for different stores. Check out one kitchen at a time and review that store's payment details.</p></article>
            <article><span class="step-number" aria-hidden="true">03</span><h3>Track your pickup</h3><p>Follow your order status as the store prepares your food.</p></article>
        </div>
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
