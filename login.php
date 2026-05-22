<?php
require __DIR__ . '/app/bootstrap.php';
if (is_logged_in()) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user'] = ['id'=>(int)$user['id'],'name'=>$user['name'],'email'=>$user['email'],'role'=>$user['role']];
        session_regenerate_id(true);
        header('Location: index.php'); exit;
    }
    $error = 'Invalid login or inactive user.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login · Pharmastar CRM</title><link rel="stylesheet" href="assets/app.css?v=20260514-newapp"></head><body class="login-page"><form class="login-card" method="post"><div class="brand"><div class="brand-mark">PS</div><div><strong>Pharmastar</strong><span>Medicine Sales CRM</span></div></div><h1>Welcome back</h1><p>Sign in to manage sales reports, schedules, doctors, users, and analytics.</p><?php if($error): ?><div class="alert error"><?= e($error) ?></div><br><?php endif; ?><div class="field"><label>Email</label><input class="input" type="email" name="email" required autocomplete="email"></div><br><div class="field"><label>Password</label><input class="input" type="password" name="password" required autocomplete="current-password"></div><br><button class="btn primary" style="width:100%">Login</button></form></body></html>
