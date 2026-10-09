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
$old = ['name' => '', 'email' => ''];
$flash = pullFlash();
$fromPreview = requestString($_GET, 'from_preview') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Your form session expired. Please try again.';
    } else {
        $name = requestString($_POST, 'name');
        $email = strtolower(requestString($_POST, 'email'));
        $password = isset($_POST['password']) && is_string($_POST['password']) ? $_POST['password'] : '';
        $confirmation = isset($_POST['confirm_password']) && is_string($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
        $old = ['name' => $name, 'email' => $email];
        $errors = $validator->validateRegistration($name, $email, $password, $confirmation);

        if ($errors === [] && findUserByEmail($pdo, $email) !== null) {
            $errors[] = 'That email address is already registered.';
        }

        if ($errors === []) {
            try {
                createUser($pdo, $name, $email, password_hash($password, PASSWORD_DEFAULT));
                setFlash('success', 'Your account is ready. Please log in.');
                header('Location: login.php');
                exit;
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000') {
                    $errors[] = 'That email address is already registered.';
                } else {
                    error_log('Coffee Hub registration failed: ' . $exception->getMessage());
                    $errors[] = 'We could not create your account right now. Please try again.';
                }
            }
        }
    }
}
$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create account · Coffee Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="js/app.js" defer></script>
</head>
<body class="auth-body">
<header class="site-header auth-header"><a href="index.php" class="brand"><span class="brand-mark">☕</span><span>Coffee <em>Hub</em></span></a><nav class="site-nav"><a href="index.php">Explore</a><a href="login.php">Log in</a></nav></header>
<main class="auth-main container">
    <section class="auth-panel register-panel">
        <div class="auth-intro"><p class="eyebrow">You're invited</p><h1>There's room at the table.</h1><p>Save recipes, trade tips, and share the dishes that feel like home.</p><div class="auth-doodle">🥐</div></div>
        <div class="auth-form-wrap">
            <h2>Create your account</h2><p class="muted">A few details and you're in.</p>
            <?php if ($fromPreview): ?><div class="alert alert-info">Create an account to open recipes and explore the full community collection.</div><?php endif; ?>
            <?php if ($flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
            <?php if ($errors !== []): ?><div class="alert alert-error" role="alert"><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
            <form method="post" action="register.php" class="form-stack" id="register-form">
                <input type="hidden" name="csrf_token" value="<?= e($token) ?>">
                <label class="field"><span>Your name</span><input type="text" name="name" value="<?= e($old['name']) ?>" minlength="2" maxlength="100" autocomplete="name" required></label>
                <label class="field"><span>Email address</span><input type="email" name="email" value="<?= e($old['email']) ?>" maxlength="255" autocomplete="email" required></label>
                <label class="field"><span>Password <small>(8–72 bytes)</small></span><input type="password" name="password" id="register-password" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                <label class="field"><span>Confirm password</span><input type="password" name="confirm_password" id="register-confirm" minlength="8" maxlength="72" autocomplete="new-password" required></label>
                <button type="submit" class="button button-wide">Create account <span aria-hidden="true">→</span></button>
            </form>
            <p class="auth-switch">Already have an account? <a href="login.php">Log in</a></p>
        </div>
    </section>
</main>
<footer class="simple-footer">Coffee Hub · Good food tastes better when shared.</footer>
</body>
</html>
