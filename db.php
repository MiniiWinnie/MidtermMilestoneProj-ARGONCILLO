<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'coffee_hub';
const DB_USER = 'root';
const DB_PASS = '';

date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $exception) {
    http_response_code(500);
    exit('The database is unavailable. Check db.php and import coffee_hub.sql.');
}

/* ---------- shared helpers ---------- */

function startSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function requestString(array $source, string $key, string $default = ''): string
{
    return isset($source[$key]) && is_string($source[$key]) ? trim($source[$key]) : $default;
}

function csrfToken(): string
{
    if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function verifyCsrfToken(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && is_string($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pullFlash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function formatDate(string $date): string
{
    return date('M j, Y · g:i A', strtotime($date));
}

/** Run dependent writes as one unit. Roll back on any exception. */
function runInTransaction(PDO $pdo, callable $work): mixed
{
    if ($pdo->inTransaction()) {
        return $work();
    }

    $pdo->beginTransaction();
    try {
        $result = $work();
        $pdo->commit();
        return $result;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/* ---------- users ---------- */

function findUserByEmail(PDO $pdo, string $email): ?array
{
    $statement = $pdo->prepare('SELECT id, name, email, password FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);
    $user = $statement->fetch();
    return $user ?: null;
}

function createUser(PDO $pdo, string $name, string $email, string $passwordHash): int
{
    $statement = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
    $statement->execute([$name, $email, $passwordHash]);
    return (int) $pdo->lastInsertId();
}

/* ---------- categories and public previews ---------- */

function getCategories(PDO $pdo): array
{
    return $pdo->query('SELECT id, name FROM categories ORDER BY id ASC')->fetchAll();
}

function getLatestRecipePreviews(PDO $pdo, int $limit = 2): array
{
    $limit = max(1, min($limit, 2));
    $statement = $pdo->query(
        'SELECT r.id, r.title, r.description, r.created_at, r.edited_at,
                c.name AS category_name, u.name AS author,
                (SELECT COUNT(*) FROM ingredients i WHERE i.recipe_id = r.id) AS ingredient_count
         FROM recipes r
         JOIN categories c ON c.id = r.category_id
         JOIN users u ON u.id = r.user_id
         ORDER BY r.created_at DESC, r.id DESC
         LIMIT ' . $limit
    );
    return $statement->fetchAll();
}

/* ---------- recipe feed and recipe details ---------- */

function getRecipes(PDO $pdo, int $userId, string $keyword = '', int $categoryId = 0, bool $favoritesOnly = false): array
{
    $where = [];
    // The first parameter belongs to the computed favorite flag in SELECT.
    $parameters = [$userId];

    if ($keyword !== '') {
        $where[] = '(r.title LIKE ? OR r.description LIKE ? OR r.steps LIKE ? OR EXISTS (
            SELECT 1 FROM ingredients si WHERE si.recipe_id = r.id AND si.name LIKE ?
        ))';
        $term = '%' . $keyword . '%';
        array_push($parameters, $term, $term, $term, $term);
    }

    if ($categoryId > 0) {
        $where[] = 'r.category_id = ?';
        $parameters[] = $categoryId;
    }

    if ($favoritesOnly) {
        $where[] = 'EXISTS (SELECT 1 FROM favorites filter_f WHERE filter_f.recipe_id = r.id AND filter_f.user_id = ?)';
        $parameters[] = $userId;
    }

    $sql = 'SELECT r.id, r.user_id, r.category_id, r.title, r.description, r.created_at, r.edited_at,
                   c.name AS category_name, u.name AS author,
                   (SELECT COUNT(*) FROM ingredients i WHERE i.recipe_id = r.id) AS ingredient_count,
                   (SELECT COUNT(*) FROM comments cm WHERE cm.recipe_id = r.id) AS comment_count,
                   (SELECT COUNT(*) FROM favorites fc WHERE fc.recipe_id = r.id) AS favorite_count,
                   EXISTS (SELECT 1 FROM favorites own_f WHERE own_f.recipe_id = r.id AND own_f.user_id = ?) AS is_favorite
            FROM recipes r
            JOIN categories c ON c.id = r.category_id
            JOIN users u ON u.id = r.user_id';

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.created_at DESC, r.id DESC';

    // The is_favorite placeholder is last in SQL only if it occurs after FROM.
    // It actually appears in SELECT, so its parameter must be first.
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function getRecipeById(PDO $pdo, int $recipeId, ?int $currentUserId = null): ?array
{
    $sql = 'SELECT r.id, r.user_id, r.category_id, r.title, r.description, r.steps,
                   r.created_at, r.edited_at, c.name AS category_name, u.name AS author,
                   (SELECT COUNT(*) FROM favorites f WHERE f.recipe_id = r.id) AS favorite_count';
    $parameters = [];
    if ($currentUserId !== null) {
        $sql .= ', EXISTS (SELECT 1 FROM favorites own_f WHERE own_f.recipe_id = r.id AND own_f.user_id = ?) AS is_favorite';
        $parameters[] = $currentUserId;
    } else {
        $sql .= ', 0 AS is_favorite';
    }
    $sql .= ' FROM recipes r
              JOIN categories c ON c.id = r.category_id
              JOIN users u ON u.id = r.user_id
              WHERE r.id = ? LIMIT 1';
    $parameters[] = $recipeId;

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $recipe = $statement->fetch();
    return $recipe ?: null;
}

function getIngredients(PDO $pdo, int $recipeId): array
{
    $statement = $pdo->prepare(
        'SELECT id, recipe_id, quantity, unit, name
         FROM ingredients WHERE recipe_id = ? ORDER BY id ASC'
    );
    $statement->execute([$recipeId]);
    return $statement->fetchAll();
}

function getRecipeComments(PDO $pdo, int $recipeId): array
{
    $statement = $pdo->prepare(
        'SELECT c.id, c.recipe_id, c.user_id, c.body, c.created_at, c.edited_at, u.name AS author
         FROM comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.recipe_id = ?
         ORDER BY c.created_at ASC, c.id ASC'
    );
    $statement->execute([$recipeId]);
    return $statement->fetchAll();
}

function findRecipe(PDO $pdo, int $recipeId): ?array
{
    $statement = $pdo->prepare('SELECT id, user_id, category_id, title, description, steps FROM recipes WHERE id = ?');
    $statement->execute([$recipeId]);
    $recipe = $statement->fetch();
    return $recipe ?: null;
}

/* ---------- recipe CRUD ---------- */

function createRecipe(PDO $pdo, int $userId, int $categoryId, string $title, string $description, string $steps, array $ingredients): int
{
    return runInTransaction($pdo, function () use ($pdo, $userId, $categoryId, $title, $description, $steps, $ingredients): int {
        $statement = $pdo->prepare(
            'INSERT INTO recipes (user_id, category_id, title, description, steps) VALUES (?, ?, ?, ?, ?)'
        );
        $statement->execute([$userId, $categoryId, $title, $description, $steps]);
        $recipeId = (int) $pdo->lastInsertId();
        insertIngredients($pdo, $recipeId, $ingredients);
        return $recipeId;
    });
}

function updateRecipe(PDO $pdo, int $recipeId, int $userId, int $categoryId, string $title, string $description, string $steps, array $ingredients): bool
{
    return runInTransaction($pdo, function () use ($pdo, $recipeId, $userId, $categoryId, $title, $description, $steps, $ingredients): bool {
        $ownership = $pdo->prepare('SELECT id FROM recipes WHERE id = ? AND user_id = ? FOR UPDATE');
        $ownership->execute([$recipeId, $userId]);
        if (!$ownership->fetch()) {
            return false;
        }

        $statement = $pdo->prepare(
            'UPDATE recipes SET category_id = ?, title = ?, description = ?, steps = ?, edited_at = CURRENT_TIMESTAMP(6)
             WHERE id = ? AND user_id = ?'
        );
        $statement->execute([$categoryId, $title, $description, $steps, $recipeId, $userId]);

        $deleteIngredients = $pdo->prepare('DELETE FROM ingredients WHERE recipe_id = ?');
        $deleteIngredients->execute([$recipeId]);
        insertIngredients($pdo, $recipeId, $ingredients);
        return true;
    });
}

function insertIngredients(PDO $pdo, int $recipeId, array $ingredients): void
{
    $statement = $pdo->prepare(
        'INSERT INTO ingredients (recipe_id, quantity, unit, name) VALUES (?, ?, ?, ?)'
    );
    foreach ($ingredients as $ingredient) {
        $statement->execute([
            $recipeId,
            $ingredient['quantity'],
            $ingredient['unit'] !== '' ? $ingredient['unit'] : null,
            $ingredient['name'],
        ]);
    }
}

function deleteRecipe(PDO $pdo, int $recipeId, int $userId): bool
{
    $statement = $pdo->prepare('DELETE FROM recipes WHERE id = ? AND user_id = ?');
    $statement->execute([$recipeId, $userId]);
    return $statement->rowCount() > 0;
}

/* ---------- favorites ---------- */

function toggleFavorite(PDO $pdo, int $userId, int $recipeId): ?bool
{
    // true means saved, false means removed, null means the recipe does not exist.
    return runInTransaction($pdo, function () use ($pdo, $userId, $recipeId): ?bool {
        $recipeCheck = $pdo->prepare('SELECT id FROM recipes WHERE id = ?');
        $recipeCheck->execute([$recipeId]);
        if (!$recipeCheck->fetch()) {
            return null;
        }

        $check = $pdo->prepare('SELECT id FROM favorites WHERE user_id = ? AND recipe_id = ? FOR UPDATE');
        $check->execute([$userId, $recipeId]);
        if ($check->fetch()) {
            $delete = $pdo->prepare('DELETE FROM favorites WHERE user_id = ? AND recipe_id = ?');
            $delete->execute([$userId, $recipeId]);
            return false;
        }

        $insert = $pdo->prepare('INSERT INTO favorites (user_id, recipe_id) VALUES (?, ?)');
        $insert->execute([$userId, $recipeId]);
        return true;
    });
}

/* ---------- comments CRUD ---------- */

function findComment(PDO $pdo, int $commentId): ?array
{
    $statement = $pdo->prepare('SELECT id, recipe_id, user_id, body FROM comments WHERE id = ?');
    $statement->execute([$commentId]);
    $comment = $statement->fetch();
    return $comment ?: null;
}

function createComment(PDO $pdo, int $recipeId, int $userId, string $body): int
{
    $statement = $pdo->prepare('INSERT INTO comments (recipe_id, user_id, body) VALUES (?, ?, ?)');
    $statement->execute([$recipeId, $userId, $body]);
    return (int) $pdo->lastInsertId();
}

function updateComment(PDO $pdo, int $commentId, int $userId, string $body): bool
{
    $statement = $pdo->prepare(
        'UPDATE comments SET body = ?, edited_at = CURRENT_TIMESTAMP(6) WHERE id = ? AND user_id = ?'
    );
    $statement->execute([$body, $commentId, $userId]);
    return $statement->rowCount() > 0;
}

function deleteComment(PDO $pdo, int $commentId, int $userId): bool
{
    $statement = $pdo->prepare('DELETE FROM comments WHERE id = ? AND user_id = ?');
    $statement->execute([$commentId, $userId]);
    return $statement->rowCount() > 0;
}
