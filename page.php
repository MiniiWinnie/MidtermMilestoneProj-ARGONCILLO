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
$recipeId = (int) requestString($_GET, 'id', '0');
$recipe = $recipeId > 0 ? getRecipeById($pdo, $recipeId, $userId) : null;
if ($recipe === null) {
    setFlash('error', 'That recipe could not be found.');
    header('Location: home.php');
    exit;
}

$validator = new Validator();
$errors = [];
$oldComment = '';
$newCommentValue = '';
$editCommentId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = requestString($_POST, 'action');
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        setFlash('error', 'Your form session expired. Please try again.');
        header('Location: page.php?id=' . $recipeId);
        exit;
    }

    if ($action === 'add_comment') {
        $body = requestString($_POST, 'body');
        $oldComment = $body;
        $newCommentValue = $body;
        $errors = $validator->validateComment($body);
        if ($errors === []) {
            createComment($pdo, $recipeId, $userId, $body);
            setFlash('success', 'Your comment was added.');
            header('Location: page.php?id=' . $recipeId . '#comments');
            exit;
        }
    } elseif ($action === 'update_comment') {
        $commentId = (int) requestString($_POST, 'comment_id', '0');
        $comment = $commentId > 0 ? findComment($pdo, $commentId) : null;
        if ($comment === null || (int) $comment['recipe_id'] !== $recipeId || (int) $comment['user_id'] !== $userId) {
            setFlash('error', 'You can only edit your own comments.');
            header('Location: page.php?id=' . $recipeId . '#comments');
            exit;
        }
        $editCommentId = $commentId;
        $body = requestString($_POST, 'body');
        $oldComment = $body;
        $errors = $validator->validateComment($body);
        if ($errors === []) {
            updateComment($pdo, $commentId, $userId, $body);
            setFlash('success', 'Your comment was updated. The Edited marker is now visible.');
            header('Location: page.php?id=' . $recipeId . '#comment-' . $commentId);
            exit;
        }
    } elseif ($action === 'delete_comment') {
        $commentId = (int) requestString($_POST, 'comment_id', '0');
        $comment = $commentId > 0 ? findComment($pdo, $commentId) : null;
        if ($comment !== null && (int) $comment['recipe_id'] === $recipeId && deleteComment($pdo, $commentId, $userId)) {
            setFlash('success', 'Your comment was deleted.');
        } else {
            setFlash('error', 'You can only delete your own comments.');
        }
        header('Location: page.php?id=' . $recipeId . '#comments');
        exit;
    } else {
        setFlash('error', 'That action was not recognized.');
        header('Location: page.php?id=' . $recipeId);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $requestedCommentId = (int) requestString($_GET, 'edit_comment', '0');
    if ($requestedCommentId > 0) {
        $candidate = findComment($pdo, $requestedCommentId);
        if ($candidate === null || (int) $candidate['recipe_id'] !== $recipeId || (int) $candidate['user_id'] !== $userId) {
            setFlash('error', 'You can only edit your own comments.');
            header('Location: page.php?id=' . $recipeId . '#comments');
            exit;
        }
        $editCommentId = $requestedCommentId;
        $oldComment = (string) $candidate['body'];
    }
}

$ingredients = getIngredients($pdo, $recipeId);
$comments = getRecipeComments($pdo, $recipeId);
$flash = pullFlash();
$token = csrfToken();
$isOwner = (int) $recipe['user_id'] === $userId;
$ingredientText = implode("\n", array_map(static function (array $ingredient): string {
    $parts = [];
    if (($ingredient['quantity'] ?? '') !== '') $parts[] = $ingredient['quantity'];
    if (($ingredient['unit'] ?? '') !== '') $parts[] = $ingredient['unit'];
    $parts[] = $ingredient['name'];
    return '- ' . implode(' ', $parts);
}, $ingredients));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($recipe['title']) ?> · Coffee Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/app.js" defer></script>
</head>
<body>
<header class="site-header member-header">
    <a href="home.php" class="brand"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a>
    <nav class="site-nav" aria-label="Member navigation">
        <a href="home.php">Recipe feed</a><a href="home.php?favorites=1">My favorites</a><span class="welcome-user">Hi, <?= e($userName) ?></span>
        <form method="post" action="home.php" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="nav-button">Log out</button></form>
    </nav>
</header>

<main class="container detail-main">
    <a href="home.php" class="back-link">← Back to recipes</a>
    <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <article class="recipe-detail">
        <div class="detail-hero detail-hero-<?= strtolower(e($recipe['category_name'])) ?>"><span class="detail-emoji"><?= $recipe['category_name'] === 'Coffee' ? '☕' : ($recipe['category_name'] === 'Meal' ? '🍲' : '🥐') ?></span><span class="category-pill"><?= e($recipe['category_name']) ?></span></div>
        <div class="detail-content">
            <div class="detail-topline"><span>Shared by <?= e($recipe['author']) ?></span><span><?= e(formatDate($recipe['created_at'])) ?><?php if ($recipe['edited_at'] !== null): ?> <span class="edited-tag" title="Edited <?= e(formatDate($recipe['edited_at'])) ?>">Edited</span><?php endif; ?></span></div>
            <h1><?= e($recipe['title']) ?></h1>
            <p class="detail-description"><?= e($recipe['description']) ?></p>
            <div class="detail-toolbar">
                <span>♡ <strong data-favorite-count><?= (int) $recipe['favorite_count'] ?></strong> saves</span>
                <span>🥄 <strong><?= count($ingredients) ?></strong> ingredients</span>
                <span>💬 <strong><?= count($comments) ?></strong> comments</span>
                <button type="button" class="favorite-button <?= (int) $recipe['is_favorite'] === 1 ? 'is-favorite' : '' ?>" data-favorite-button data-endpoint="home.php" data-recipe-id="<?= $recipeId ?>" data-favorited="<?= (int) $recipe['is_favorite'] === 1 ? '1' : '0' ?>" data-csrf="<?= e($token) ?>" aria-pressed="<?= (int) $recipe['is_favorite'] === 1 ? 'true' : 'false' ?>"><?= (int) $recipe['is_favorite'] === 1 ? '♥ Saved' : '♡ Save recipe' ?></button>
            </div>
            <?php if ($isOwner): ?>
                <div class="owner-actions detail-owner-actions"><a href="home.php?edit_recipe=<?= $recipeId ?>#recipe-form">Edit recipe</a><form method="post" action="home.php" data-confirm="Delete this recipe? Its ingredients, comments, and favorites will also be removed."><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete_recipe"><input type="hidden" name="recipe_id" value="<?= $recipeId ?>"><button type="submit" class="danger-link">Delete recipe</button></form></div>
            <?php endif; ?>

            <div class="detail-columns">
                <section class="detail-section ingredients-section">
                    <div class="detail-section-heading"><div><p class="eyebrow">Get everything ready</p><h2>Ingredients</h2></div><button type="button" class="button button-outline button-small" data-copy-ingredients data-ingredients="<?= e($ingredientText) ?>">Copy list</button></div>
                    <?php if ($ingredients === []): ?><p class="muted">No ingredients have been added yet.</p><?php else: ?>
                        <ul class="ingredient-list"><?php foreach ($ingredients as $ingredient): ?><li><span class="ingredient-check" aria-hidden="true">✓</span><span><?php if (($ingredient['quantity'] ?? '') !== ''): ?><strong><?= e($ingredient['quantity']) ?></strong> <?php endif; ?><?php if (($ingredient['unit'] ?? '') !== ''): ?><?= e($ingredient['unit']) ?> <?php endif; ?><?= e($ingredient['name']) ?></span></li><?php endforeach; ?></ul>
                    <?php endif; ?><p class="copy-feedback" data-copy-feedback aria-live="polite"></p>
                </section>
                <section class="detail-section steps-section"><p class="eyebrow">Let's make it</p><h2>Cooking steps</h2><div class="recipe-steps"><?= nl2br(e($recipe['steps'])) ?></div></section>
            </div>
        </div>
    </article>

    <section class="comments-section" id="comments">
        <div class="comments-heading"><div><p class="eyebrow">Around the table</p><h2>Kitchen notes <span>(<?= count($comments) ?>)</span></h2></div><p>Questions, tips, and little tweaks are welcome.</p></div>
        <?php if ($comments === []): ?><div class="empty-comments">No comments yet. Start the conversation with a friendly kitchen note.</div><?php endif; ?>
        <div class="comment-list">
            <?php foreach ($comments as $comment): ?>
                <?php $ownsComment = (int) $comment['user_id'] === $userId; $commentId = (int) $comment['id']; ?>
                <article class="comment-card" id="comment-<?= $commentId ?>">
                    <?php if ($ownsComment && $editCommentId === $commentId): ?>
                        <form method="post" action="page.php?id=<?= $recipeId ?>#comment-<?= $commentId ?>" class="form-stack comment-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="update_comment"><input type="hidden" name="comment_id" value="<?= $commentId ?>">
                            <label class="field"><span>Edit your comment</span><textarea name="body" rows="3" maxlength="1000" required><?= e($oldComment) ?></textarea></label>
                            <div class="form-actions"><a class="button button-outline button-small" href="page.php?id=<?= $recipeId ?>#comment-<?= $commentId ?>">Cancel</a><button class="button button-small" type="submit">Save comment</button></div>
                        </form>
                    <?php else: ?>
                        <div class="comment-avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $comment['author'], 0, 1))) ?></div>
                        <div class="comment-body-wrap"><div class="comment-meta"><strong><?= e($comment['author']) ?></strong><span><?= e(formatDate($comment['created_at'])) ?></span><?php if ($comment['edited_at'] !== null): ?><span class="edited-tag" title="Edited <?= e(formatDate($comment['edited_at'])) ?>">Edited</span><?php endif; ?></div><p><?= nl2br(e($comment['body'])) ?></p>
                            <?php if ($ownsComment): ?><div class="owner-actions comment-actions"><a href="page.php?id=<?= $recipeId ?>&edit_comment=<?= $commentId ?>#comment-<?= $commentId ?>">Edit</a><form method="post" action="page.php?id=<?= $recipeId ?>#comments" data-confirm="Delete your comment?"><input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="delete_comment"><input type="hidden" name="comment_id" value="<?= $commentId ?>"><button class="danger-link" type="submit">Delete</button></form></div><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
        <form method="post" action="page.php?id=<?= $recipeId ?>#comments" class="comment-compose">
            <input type="hidden" name="csrf_token" value="<?= e($token) ?>"><input type="hidden" name="action" value="add_comment">
            <label class="field"><span>Add a kitchen note</span><textarea name="body" rows="3" maxlength="1000" placeholder="Share a tip, ask a question, or let the cook know how it turned out..." required><?= e($newCommentValue) ?></textarea><small>Up to 1,000 characters.</small></label>
            <div class="form-actions"><button type="submit" class="button">Post comment <span aria-hidden="true">→</span></button></div>
        </form>
    </section>
</main>
<footer class="site-footer"><a href="home.php" class="brand brand-footer"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a><p>Made for the recipes that bring us together.</p></footer>
<div class="toast" data-toast role="status" aria-live="polite"></div>
</body>
</html>
