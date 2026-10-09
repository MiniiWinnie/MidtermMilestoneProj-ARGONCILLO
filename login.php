<?php
declare(strict_types=1);

require __DIR__ . '/db.php';
require __DIR__ . '/classes/Validator.php';
startSession();

if (!empty($_SESSION['user']['id'])) {
    header('Location: home.php');
    exit;
}

$validator = new Validator();
$errors = [];
$email = '';
$flash = pullFlash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Please try again.';
    } else {
        $email = strtolower(requestString($_POST, 'email'));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $errors = $validator->validateLogin($email, $password);

        if ($errors === []) {
            $user = findUserByEmail($pdo, $email);
            if ($user !== null && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = ['id' => (int) $user['id'], 'name' => $user['name'], 'email' => $user['email']];
                $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: home.php');
                exit;
            }
            $errors[] = 'Invalid email or password.';
        }
    }
}
$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log in · Coffee Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-body">
<header class="site-header auth-header"><a href="index.php" class="brand"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a><nav class="site-nav"><a href="index.php">Explore</a><a href="register.php">Create account</a></nav></header>
<main class="auth-main container">
    <section class="auth-panel">
        <div class="auth-intro"><p class="eyebrow">Welcome back</p><h1>Come on in.</h1><p>Your next favorite recipe is waiting at the table.</p><div class="auth-doodle">☕</div></div>
        <div class="auth-form-wrap">
            <h2>Log in to Coffee Hub</h2><p class="muted">Use your account to browse, cook, and share.</p>
            <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
            <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" action="login.php" class="form-stack">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <label class="field"><span>Email address</span><input type="email" name="email" value="<?= e($email) ?>" maxlength="255" autocomplete="email" required></label>
                <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
                <button type="submit" class="button button-wide">Log in <span aria-hidden="true">→</span></button>
            </form>
            <p class="auth-switch">New to the table? <a href="register.php">Create an account</a></p>
        </div>
    </section>
</main>
<footer class="simple-footer">Coffee Hub · Good food tastes better when shared.</footer>
</body>
</html>
