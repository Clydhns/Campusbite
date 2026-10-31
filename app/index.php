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
        <div class="intro-copy">
        <p class="eyebrow">An independent student project</p>
        <h1 id="intro-title">Your next bite.<br><span>A better campus break.</span></h1>
        <p class="intro-description">Your campus kitchens, in one place. Find a favorite, place your order, and follow it all the way to pickup.</p>
        <div class="actions">
            <a class="button" href="register.php">Create an account <span aria-hidden="true">&rarr;</span></a>
            <a class="text-link" href="#about">See how it works <span aria-hidden="true">&darr;</span></a>
        </div>
        <p class="returning-user">Already part of CampusBite? <a href="login.php">Sign in</a></p>
        <p class="identity-note">Not an official school portal. CampusBite accounts are separate from school, email, and GCash accounts. <a href="#data-use">Before you register &rarr;</a></p>
        </div>
        <figure class="break-illustration">
            <p class="illustration-label">The campus kitchen</p>
            <svg class="pickup-bag" viewBox="0 0 360 330" fill="none" aria-hidden="true" focusable="false">
                <ellipse cx="184" cy="299" rx="120" ry="13" fill="#edcbb0" opacity=".45"/>
                <g transform="rotate(10 254 88)">
                    <rect x="221" y="33" width="70" height="108" rx="8" fill="#fffdf9" stroke="#e9c5a7" stroke-width="2"/>
                    <path d="M237 54H275M237 66H265M237 94H275M237 106H259" stroke="#e5bfa0" stroke-width="3" stroke-linecap="round"/>
                    <path d="m239 79 5 5 10-11" stroke="#bf2634" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <path d="m101 110 151-12 31 174c2 12-5 20-17 21l-161 8c-13 1-21-8-20-20z" fill="#a61f2e"/>
                <path d="m90 109 155-7 8 171c1 13-6 21-19 22l-137 5c-13 1-21-8-20-21z" fill="#d53843"/>
                <path d="m245 102 7-4 31 174c2 12-5 20-17 21l-32 2c13-1 20-9 19-22z" fill="#b52231"/>
                <path d="m98 109 5-10 12 8 10-10 12 9 11-10 12 9 12-10 12 9 12-10 12 9 11-10 12 9 10-10 10 10" stroke="#ed6269" stroke-width="3" stroke-linejoin="round"/>
                <path d="M128 139v-39c0-48 70-51 72-3l2 39" stroke="#7e1925" stroke-width="10" stroke-linecap="round"/>
                <path d="M128 132v-33c0-48 70-51 72-3l2 32" stroke="#ffdfbd" stroke-width="7" stroke-linecap="round"/>
                <rect x="117" y="171" width="90" height="70" rx="22" transform="rotate(-3 117 171)" fill="#fff0e1"/>
                <g transform="translate(131 173) scale(.94)" stroke="#bf2634" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M29 24C26 20 18 20 15 25C11 31 12 39 18 43C22 46 27 44 30 41"/>
                    <path d="M38 21V44M38 21H44C52 21 52 32 44 32H38M44 32C54 32 54 44 44 44H38"/>
                </g>
                <path d="m66 176-10-3m18-19-6-8m220 60 9-5m-12 24 12 1" stroke="#c68c62" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <figcaption>A little less queue.<br><span>A little more you.</span></figcaption>
        </figure>
    </section>
    <section id="about" class="section" aria-labelledby="about-title">
        <div class="section-heading"><div><p class="eyebrow">From browsing to pickup</p><h2 id="about-title">Your break, in three simple steps.</h2></div><span class="section-aside">One kitchen. One checkout. One pickup.</span></div>
        <ol class="steps">
            <li><span class="step-number" aria-hidden="true">01</span><h3>Choose a kitchen</h3><p>Browse food, prices, and availability from campus stores.</p></li>
            <li><span class="step-number" aria-hidden="true">02</span><h3>Place your order</h3><p>Save separate carts and check out one kitchen at a time.</p></li>
            <li><span class="step-number" aria-hidden="true">03</span><h3>Follow your pickup</h3><p>Track your order as the store prepares your food.</p></li>
        </ol>
    </section>
    <section id="data-use" class="section data-use" aria-labelledby="data-title">
        <div><p class="eyebrow">Before you register</p><h2 id="data-title">Your details.<br>No surprises.</h2><p class="data-intro">A separate account for your campus orders. Here is what CampusBite asks for and why.</p></div>
        <div class="data-details">
            <h3>Account information</h3>
            <p>Registration asks for an ID number, name, email, and a new CampusBite password. Your ID identifies your account within this project; it does not sign you in to a school system. Contact number and school/course/section are optional.</p>
            <h3>Orders and payment records</h3>
            <p>The project stores your orders and their payment information. If you submit a GCash receipt, the image is stored for payment review by authorized project staff. Submit only the details needed to verify the payment.</p>
            <h3>Account access</h3>
            <p>Project administrators manage accounts. Store staff manage their own menus and store orders. A session cookie keeps you signed in.</p>
            <div class="password-note"><h3>Use a separate password</h3>
            <p>Never enter your school-account password, email password, GCash password, PIN, or one-time password here. Create a unique password just for CampusBite.</p></div>
            <p class="data-footnote">This page explains the current project and its account flow. It is not a claim of school affiliation or a guarantee of third-party security approval.</p>
        </div>
    </section>
</main>
<footer><span>CampusBite</span><p>Independent student-built campus food ordering.</p><a href="login.php">Sign in to your CampusBite account</a></footer>
</body>
</html>
