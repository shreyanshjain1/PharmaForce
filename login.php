<?php
require __DIR__ . '/app/bootstrap.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error = '';
$expired = isset($_GET['expired']);

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

function login_attempt_key(string $email): string
{
    return strtolower(trim($email)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function login_is_limited(string $email): bool
{
    $key = login_attempt_key($email);
    $attempts = $_SESSION['login_attempts'][$key] ?? [];
    $recent = array_filter($attempts, static fn($time) => time() - (int)$time < 900);
    $_SESSION['login_attempts'][$key] = $recent;
    return count($recent) >= 6;
}

function login_record_failure(string $email): void
{
    $key = login_attempt_key($email);
    $_SESSION['login_attempts'][$key][] = time();
}

function login_clear_failures(string $email): void
{
    unset($_SESSION['login_attempts'][login_attempt_key($email)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if (login_is_limited($email)) {
        $error = 'Too many login attempts. Please wait 15 minutes and try again.';
    } else {
        $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, (string)$user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ];
            $_SESSION['created_at'] = time();
            $_SESSION['last_activity'] = time();
            $_SESSION['fingerprint'] = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 7));
            $_SESSION['csrf'] = bin2hex(random_bytes(32));

            login_clear_failures($email);
            audit_log($pdo, 'login_success', 'user', (int)$user['id'], ['email' => $email]);

            header('Location: index.php');
            exit;
        }

        login_record_failure($email);
        audit_log($pdo, 'login_failed', 'user', null, ['email' => $email]);
        $error = 'Invalid login or inactive user.';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login · Pharmastar CRM</title>
  <link rel="stylesheet" href="assets/app.css?v=20260609-security-hardening">
</head>
<body class="login-page">
<form class="login-card" method="post" autocomplete="on">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="brand"><div class="brand-mark">PS</div><div><strong>Pharmastar</strong><span>Medicine Sales CRM</span></div></div>
  <h1>Welcome back</h1>
  <p>Sign in to manage sales reports, schedules, doctors, users, and analytics.</p>
  <?php if ($expired): ?><div class="alert warning">Your session expired for security. Please log in again.</div><br><?php endif; ?>
  <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><br><?php endif; ?>
  <div class="field"><label>Email</label><input class="input" type="email" name="email" required autocomplete="email"></div><br>
  <div class="field"><label>Password</label><input class="input" type="password" name="password" required autocomplete="current-password"></div><br>
  <button class="btn primary" style="width:100%">Login</button>
</form>
</body>
</html>
