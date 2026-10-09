<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
startSession();

if (!empty($_SESSION['user']['id'])) {
    header('Location: home.php');
    exit;
}

$recipes = getLatestRecipePreviews($pdo, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Coffee Hub is a community for sharing coffee, meal, and pastry recipes.">
    <title>Coffee Hub · Recipes from Our Home to Yours</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="landing-body">
<header class="site-header">
    <a href="index.php" class="brand"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a>
    <nav class="site-nav" aria-label="Main navigation">
        <a href="#latest">Latest recipes</a>
        <a href="login.php">Log in</a>
        <a class="button button-small" href="register.php">Join the table</a>
    </nav>
</header>

<main>
    <section class="hero container">
        <div class="hero-copy">
            <p class="eyebrow">A little taste of home</p>
            <h1>Good food tastes even better <span>when shared.</span></h1>
            <p class="hero-lead">A welcoming recipe corner for your favorite coffee, everyday meals, and freshly baked pastries.</p>
            <div class="hero-actions">
                <a class="button" href="register.php">Create your free account <span aria-hidden="true">↗</span></a>
                <a class="text-link" href="login.php">Already a member? Log in</a>
            </div>
            <div class="hero-note"><span class="note-dot"></span> Recipes and little kitchen discoveries from our community.</div>
        </div>
        <div class="hero-art" aria-label="Illustration of coffee and baked goods">
            <div class="art-sun"></div>
            <div class="art-label label-top">made with care</div>
            <div class="coffee-cup"><div class="coffee-liquid"></div><div class="cup-handle"></div></div>
            <div class="pastry pastry-one"></div><div class="pastry pastry-two"></div>
            <div class="art-label label-bottom">share a little joy</div>
        </div>
    </section>

    <section class="category-strip container" aria-label="Recipe categories">
        <div><span class="category-icon">☕</span><strong>Coffee</strong><span>Slow mornings</span></div>
        <div><span class="category-icon">🍲</span><strong>Meal</strong><span>Everyday favorites</span></div>
        <div><span class="category-icon">🥐</span><strong>Pastries</strong><span>Fresh from the oven</span></div>
    </section>

    <section id="latest" class="preview-section container">
        <div class="section-heading">
            <div><p class="eyebrow">Fresh from the community</p><h2>A taste of what's cooking</h2></div>
            <span class="section-aside">A small preview · latest recipes</span>
        </div>
        <?php if ($recipes === []): ?>
            <div class="empty-state preview-empty"><span>🥄</span><h3>The table is waiting.</h3><p>Be one of the first home cooks to share a recipe.</p><a class="button" href="register.php">Join Coffee Hub</a></div>
        <?php else: ?>
            <div class="recipe-grid preview-grid">
                <?php foreach ($recipes as $recipe): ?>
                    <article class="recipe-card preview-card">
                        <a class="card-open" href="register.php?from_preview=1" aria-label="Join Coffee Hub to open <?= e($recipe['title']) ?>">
                            <div class="card-art card-art-<?= strtolower(e($recipe['category_name'])) ?>"><span><?= $recipe['category_name'] === 'Coffee' ? '☕' : ($recipe['category_name'] === 'Meal' ? '🍲' : '🥐') ?></span></div>
                            <div class="card-content">
                                <div class="card-meta"><span class="category-pill"><?= e($recipe['category_name']) ?></span><span><?= e(formatDate($recipe['created_at'])) ?></span></div>
                                <h3><?= e($recipe['title']) ?></h3>
                                <p><?= e($recipe['description']) ?></p>
                                <div class="card-footer"><span>By <?= e($recipe['author']) ?></span><span class="lock-note">Join to read <span aria-hidden="true">↗</span></span></div>
                            </div>
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="join-banner"><div><p class="eyebrow">Pull up a chair</p><h2>Your recipe could be next.</h2><p>Join the community to browse the full collection, share your own recipes, and save favorites.</p></div><a href="register.php" class="button">Join Coffee Hub <span aria-hidden="true">↗</span></a></div>
    </section>
</main>
<footer class="site-footer"><a href="index.php" class="brand brand-footer"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a><p>Made for the recipes that bring us together.</p></footer>
</body>
</html>
