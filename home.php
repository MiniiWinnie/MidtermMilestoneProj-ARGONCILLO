<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/classes/Validator.php';
startSession();

if (empty($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$userName = (string) ($_SESSION['user']['name'] ?? 'Cook');
$validator = new Validator();
$categories = getCategories($pdo);
$errors = [];
$oldRecipe = ['title' => '', 'description' => '', 'category_id' => '', 'steps' => ''];
$formIngredients = [['name' => '', 'quantity' => '', 'unit' => '']];
$editRecipeId = 0;
$editRecipe = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = requestString($_POST, 'action');
    $isAjaxFavorite = $action === 'toggle_favorite';

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        if ($isAjaxFavorite) {
            http_response_code(419);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => 'Your session expired. Refresh the page and try again.']);
            exit;
        }
        setFlash('error', 'Your form session expired. Please try again.');
        header('Location: home.php');
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'] ?? '/',
                'domain' => $cookie['domain'] ?? '',
                'secure' => (bool) ($cookie['secure'] ?? false),
                'httponly' => (bool) ($cookie['httponly'] ?? true),
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if ($action === 'toggle_favorite') {
        $recipeId = (int) requestString($_POST, 'recipe_id', '0');
        try {
            $saved = $recipeId > 0 ? toggleFavorite($pdo, $userId, $recipeId) : null;
            if ($saved === null) {
                http_response_code(404);
                $payload = ['ok' => false, 'message' => 'That recipe could not be found.'];
            } else {
                $payload = ['ok' => true, 'is_favorite' => $saved, 'message' => $saved ? 'Saved to your favorites.' : 'Removed from your favorites.'];
            }
        } catch (Throwable $exception) {
            error_log('Coffee Hub favorite update failed: ' . $exception->getMessage());
            http_response_code(500);
            $payload = ['ok' => false, 'message' => 'We could not update your favorites. Please try again.'];
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete_recipe') {
        $recipeId = (int) requestString($_POST, 'recipe_id', '0');
        if ($recipeId > 0 && deleteRecipe($pdo, $recipeId, $userId)) {
            setFlash('success', 'Recipe deleted. Its ingredients, comments, and favorites were removed too.');
        } else {
            setFlash('error', 'That recipe was not found, or you do not own it.');
        }
        header('Location: home.php');
        exit;
    }

    if ($action === 'create_recipe' || $action === 'update_recipe') {
        $isUpdate = $action === 'update_recipe';
        $editRecipeId = $isUpdate ? (int) requestString($_POST, 'recipe_id', '0') : 0;
        $oldRecipe = [
            'title' => requestString($_POST, 'title'),
            'description' => requestString($_POST, 'description'),
            'category_id' => requestString($_POST, 'category_id'),
            'steps' => requestString($_POST, 'steps'),
        ];
        $rawIngredients = $_POST['ingredients'] ?? [];
        if (!is_array($rawIngredients)) {
            $rawIngredients = [];
            $errors[] = 'The ingredient list was invalid.';
        }
        $formIngredients = [];
        foreach (array_slice($rawIngredients, 0, 40) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $formIngredients[] = [
                'name' => isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '',
                'quantity' => isset($row['quantity']) && is_string($row['quantity']) ? trim($row['quantity']) : '',
                'unit' => isset($row['unit']) && is_string($row['unit']) ? trim($row['unit']) : '',
            ];
        }
        if ($formIngredients === []) {
            $formIngredients = [['name' => '', 'quantity' => '', 'unit' => '']];
        }

        if ($isUpdate) {
            $editRecipe = $editRecipeId > 0 ? findRecipe($pdo, $editRecipeId) : null;
            if ($editRecipe === null || (int) $editRecipe['user_id'] !== $userId) {
                setFlash('error', 'You can only edit your own recipes.');
                header('Location: home.php');
                exit;
            }
        }

        $categoryId = (int) $oldRecipe['category_id'];
        $categoryExists = false;
        foreach ($categories as $category) {
            if ((int) $category['id'] === $categoryId) {
                $categoryExists = true;
                break;
            }
        }
        $validation = $validator->validateRecipe(
            $oldRecipe['title'],
            $oldRecipe['description'],
            $categoryId,
            $oldRecipe['steps'],
            $rawIngredients,
            $categoryExists
        );
        $errors = array_merge($errors, $validation['errors']);

        if ($errors === []) {
            try {
                if ($isUpdate) {
                    $updated = updateRecipe(
                        $pdo, $editRecipeId, $userId, $categoryId,
                        $oldRecipe['title'], $oldRecipe['description'], $oldRecipe['steps'],
                        $validation['ingredients']
                    );
                    if (!$updated) {
                        setFlash('error', 'You can only edit your own recipes.');
                        header('Location: home.php');
                        exit;
                    }
                    setFlash('success', 'Your recipe was updated. The Edited marker is now visible.');
                    header('Location: page.php?id=' . $editRecipeId);
                    exit;
                }

                $newId = createRecipe(
                    $pdo, $userId, $categoryId,
                    $oldRecipe['title'], $oldRecipe['description'], $oldRecipe['steps'],
                    $validation['ingredients']
                );
                setFlash('success', 'Your recipe is published. Happy cooking!');
                header('Location: page.php?id=' . $newId);
                exit;
            } catch (Throwable $exception) {
                error_log('Coffee Hub recipe save failed: ' . $exception->getMessage());
                $errors[] = 'We could not save the recipe. Please check your database and try again.';
            }
        }
    } else {
        setFlash('error', 'That action was not recognized.');
        header('Location: home.php');
        exit;
    }
}

// A GET request can open the edit form. Ownership is checked before any recipe details are shown.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $requestedEditId = (int) requestString($_GET, 'edit_recipe', '0');
    if ($requestedEditId > 0) {
        $candidate = findRecipe($pdo, $requestedEditId);
        if ($candidate === null || (int) $candidate['user_id'] !== $userId) {
            setFlash('error', 'You can only edit your own recipes.');
            header('Location: home.php');
            exit;
        }
        $editRecipeId = $requestedEditId;
        $editRecipe = $candidate;
        $oldRecipe = [
            'title' => (string) $candidate['title'],
            'description' => (string) $candidate['description'],
            'category_id' => (string) $candidate['category_id'],
            'steps' => (string) $candidate['steps'],
        ];
        $formIngredients = getIngredients($pdo, $editRecipeId);
        if ($formIngredients === []) {
            $formIngredients = [['name' => '', 'quantity' => '', 'unit' => '']];
        }
    }
}

$keyword = mb_substr(requestString($_GET, 'q'), 0, 100);
$categoryFilter = (int) requestString($_GET, 'category', '0');
$onlyFavorites = requestString($_GET, 'favorites') === '1';
$recipes = getRecipes($pdo, $userId, $keyword, $categoryFilter, $onlyFavorites);
$flash = pullFlash();
$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $onlyFavorites ? 'Your favorites' : 'Recipe feed' ?> · Coffee Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/app.js" defer></script>
</head>
<body>
<header class="site-header member-header">
    <a href="home.php" class="brand"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a>
    <nav class="site-nav" aria-label="Member navigation">
        <a href="home.php" class="active">Recipe feed</a>
        <a href="home.php?favorites=1">My favorites</a>
        <span class="welcome-user">Hi, <?= e($userName) ?></span>
        <form method="post" action="home.php" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="nav-button">Log out</button></form>
    </nav>
</header>

<main class="container member-main">
    <section class="member-welcome"><div><p class="eyebrow">The community table</p><h1><?= $onlyFavorites ? 'Your saved recipes' : 'What’s cooking today?' ?></h1><p><?= $onlyFavorites ? 'The recipes you saved for another day.' : 'Find a new favorite or share something delicious with your neighbors.' ?></p></div><a class="button" href="#recipe-form">＋ Share a recipe</a></section>

    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <section class="search-panel" aria-label="Search and filter recipes">
        <form method="get" action="home.php" class="search-form">
            <label class="search-box"><span class="visually-hidden">Search recipes</span><span class="search-icon" aria-hidden="true">⌕</span><input type="search" name="q" value="<?= e($keyword) ?>" maxlength="100" placeholder="Search recipes, steps, ingredients..."></label>
            <label class="filter-select"><span class="visually-hidden">Filter category</span><select name="category"><option value="0">All categories</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= $categoryFilter === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            <?php if ($onlyFavorites): ?><input type="hidden" name="favorites" value="1"><?php endif; ?>
            <button class="button" type="submit">Find recipes</button>
            <?php if ($keyword !== '' || $categoryFilter > 0 || $onlyFavorites): ?><a class="clear-filters" href="home.php">Clear</a><?php endif; ?>
        </form>
        <div class="feed-tabs"><a href="home.php" class="<?= !$onlyFavorites ? 'selected' : '' ?>">Latest recipes</a><a href="home.php?favorites=1" class="<?= $onlyFavorites ? 'selected' : '' ?>">♡ My favorites</a><span><span data-recipe-total><?= count($recipes) ?></span> <?= count($recipes) === 1 ? 'recipe' : 'recipes' ?></span></div>
    </section>

    <section class="recipe-grid member-grid" aria-label="Recipes">
        <?php if ($recipes === []): ?>
            <?php if ($onlyFavorites): ?>
                <div class="empty-state grid-empty"><span>♡</span><h2>No saved recipes yet.</h2><p>Save a recipe from the latest feed and it will appear here.</p><a class="button button-outline" href="home.php">Browse recipes</a></div>
            <?php else: ?>
                <div class="empty-state grid-empty"><span>🍽️</span><h2>No recipes found yet.</h2><p>Try a different search or category, or share the first recipe that fits.</p><a class="button button-outline" href="home.php">Show all recipes</a></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php foreach ($recipes as $recipe): ?>
            <?php $isOwner = (int) $recipe['user_id'] === $userId; $kind = strtolower((string) $recipe['category_name']); ?>
            <article class="recipe-card member-card" data-favorites-only="<?= $onlyFavorites ? '1' : '0' ?>">
                <div class="card-art card-art-<?= e($kind) ?>"><span><?= $kind === 'coffee' ? '☕' : ($kind === 'meal' ? '🍲' : '🥐') ?></span><span class="art-category"><?= e($recipe['category_name']) ?></span></div>
                <div class="card-content">
                    <div class="card-meta"><span><?= e(formatDate($recipe['created_at'])) ?></span><?php if ($recipe['edited_at'] !== null): ?><span class="edited-tag" title="Edited <?= e(formatDate($recipe['edited_at'])) ?>">Edited</span><?php endif; ?></div>
                    <h2><a href="page.php?id=<?= (int) $recipe['id'] ?>"><?= e($recipe['title']) ?></a></h2>
                    <p><?= e($recipe['description']) ?></p>
                    <div class="recipe-stats"><span>🥄 <?= (int) $recipe['ingredient_count'] ?> ingredients</span><span>💬 <?= (int) $recipe['comment_count'] ?></span><span>♡ <span data-favorite-count><?= (int) $recipe['favorite_count'] ?></span></span></div>
                    <div class="card-byline">Shared by <strong><?= e($recipe['author']) ?></strong><?= $isOwner ? ' · Your recipe' : '' ?></div>
                    <div class="card-actions">
                        <a class="text-link" href="page.php?id=<?= (int) $recipe['id'] ?>">View recipe <span aria-hidden="true">↗</span></a>
                        <button type="button" class="favorite-button <?= (int) $recipe['is_favorite'] === 1 ? 'is-favorite' : '' ?>" data-favorite-button data-endpoint="home.php" data-recipe-id="<?= (int) $recipe['id'] ?>" data-favorited="<?= (int) $recipe['is_favorite'] === 1 ? '1' : '0' ?>" data-csrf="<?= e($token) ?>" aria-pressed="<?= (int) $recipe['is_favorite'] === 1 ? 'true' : 'false' ?>" title="Save or remove favorite"><?= (int) $recipe['is_favorite'] === 1 ? '♥ Saved' : '♡ Save' ?></button>
                    </div>
                    <?php if ($isOwner): ?>
                        <div class="owner-actions"><a href="home.php?edit_recipe=<?= (int) $recipe['id'] ?>#recipe-form">Edit</a><form method="post" action="home.php" data-confirm="Delete this recipe? Its ingredients, comments, and favorites will also be removed."><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete_recipe"><input type="hidden" name="recipe_id" value="<?= (int) $recipe['id'] ?>"><button type="submit" class="danger-link">Delete</button></form></div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
        <?php if ($onlyFavorites && $recipes !== []): ?>
            <div class="empty-state grid-empty" data-favorites-empty hidden><span>♡</span><h2>No saved recipes left.</h2><p>Save a recipe from the latest feed and it will appear here.</p><a class="button button-outline" href="home.php">Browse recipes</a></div>
        <?php endif; ?>
    </section>

    <section class="recipe-editor" id="recipe-form">
        <div class="editor-heading"><p class="eyebrow"><?= $editRecipeId > 0 ? 'Make it just right' : 'From your kitchen' ?></p><h2><?= $editRecipeId > 0 ? 'Edit your recipe' : 'Share a recipe' ?></h2><p><?= $editRecipeId > 0 ? 'Update your recipe and its ingredient list.' : 'Pass along a favorite dish to the Coffee Hub community.' ?></p></div>
        <form method="post" action="home.php<?= $editRecipeId > 0 ? '?edit_recipe=' . $editRecipeId : '' ?>#recipe-form" class="recipe-form" id="recipe-entry-form">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="<?= $editRecipeId > 0 ? 'update_recipe' : 'create_recipe' ?>">
            <?php if ($editRecipeId > 0): ?><input type="hidden" name="recipe_id" value="<?= $editRecipeId ?>"><?php endif; ?>
            <div class="form-grid-two">
                <label class="field"><span>Recipe title</span><input name="title" type="text" maxlength="150" value="<?= e($oldRecipe['title']) ?>" placeholder="e.g. Sunday morning kapeng barako" required></label>
                <label class="field"><span>Category</span><select name="category_id" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= (int) $category['id'] ?>" <?= (string) $oldRecipe['category_id'] === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select></label>
            </div>
            <label class="field"><span>Short description</span><textarea name="description" rows="2" maxlength="500" placeholder="What makes this recipe special?" required><?= e($oldRecipe['description']) ?></textarea><small>Up to 500 characters.</small></label>
            <div class="ingredients-editor">
                <div class="ingredients-heading"><div><h3>Ingredients</h3><p>Add one row for each ingredient.</p></div><button type="button" class="button button-outline button-small" data-add-ingredient>＋ Add ingredient</button></div>
                <div class="ingredient-rows" data-ingredient-rows>
                    <?php foreach ($formIngredients as $index => $ingredient): ?>
                        <div class="ingredient-row" data-ingredient-row data-index="<?= (int) $index ?>">
                            <label><span class="visually-hidden">Ingredient name</span><input type="text" name="ingredients[<?= (int) $index ?>][name]" value="<?= e($ingredient['name'] ?? '') ?>" maxlength="100" placeholder="Ingredient name" required></label>
                            <label><span class="visually-hidden">Quantity</span><input type="text" name="ingredients[<?= (int) $index ?>][quantity]" value="<?= e($ingredient['quantity'] ?? '') ?>" maxlength="20" placeholder="Quantity"></label>
                            <label><span class="visually-hidden">Unit</span><input type="text" name="ingredients[<?= (int) $index ?>][unit]" value="<?= e($ingredient['unit'] ?? '') ?>" maxlength="30" placeholder="Unit"></label>
                            <button type="button" class="remove-ingredient" data-remove-ingredient aria-label="Remove ingredient row">×</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <p class="form-hint">Example: 2 · tablespoons · brown sugar. You can leave quantity and unit blank.</p>
            </div>
            <label class="field"><span>Cooking steps</span><textarea name="steps" rows="6" maxlength="20000" placeholder="Write the steps in the order someone should follow them..." required><?= e($oldRecipe['steps']) ?></textarea><small>Use a new line for each step if that helps readability.</small></label>
            <div class="form-actions"><a class="button button-outline" href="home.php">Cancel</a><button type="submit" class="button"><?= $editRecipeId > 0 ? 'Save changes' : 'Publish recipe' ?> <span aria-hidden="true">→</span></button></div>
        </form>
    </section>
</main>
<footer class="site-footer"><a href="home.php" class="brand brand-footer"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a><p>Made for the recipes that bring us together.</p></footer>
<div class="toast" data-toast role="status" aria-live="polite"></div>
</body>
</html>
