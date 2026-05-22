<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

$config = require __DIR__ . '/../config/database.php';

try {
    $dsn = "mysql:host={$config['host']};dbname={$config['database']};charset={$config['charset']}";
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Database connection failed</h1><p>Edit <code>config/database.php</code> and confirm MySQL is running.</p><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    exit;
}

function e(?string $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function app_url(string $path = ''): string { return $path === '' ? './' : $path; }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function is_logged_in(): bool { return current_user() !== null; }
function is_manager(): bool { $u = current_user(); return $u && in_array($u['role'], ['manager','district_manager'], true); }
function is_top_manager(): bool { $u = current_user(); return $u && $u['role'] === 'manager'; }
function require_login(): void { if (!is_logged_in()) { header('Location: login.php'); exit; } }
function csrf_token(): string { if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); } return $_SESSION['csrf']; }
function verify_csrf(): void { if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['_csrf'] ?? '')) { http_response_code(419); exit('Invalid session token. Please go back and reload.'); } }
function flash(string $type, string $message): void { $_SESSION['flash'][] = ['type'=>$type, 'message'=>$message]; }
function flashes(): array { $items = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $items; }
function active_nav(string $file): string { return basename($_SERVER['SCRIPT_NAME']) === $file ? 'active' : ''; }
function normalize_status(?string $status): string { return in_array($status, ['pending','approved','needs_changes'], true) ? $status : 'pending'; }
function status_label(string $status): string { return ['pending'=>'Pending','approved'=>'Approved','needs_changes'=>'Needs Changes'][$status] ?? $status; }
function role_label(string $role): string { return ['manager'=>'Manager','district_manager'=>'District Manager','employee'=>'Employee'][$role] ?? $role; }
function row_value(array $row, string $key, mixed $fallback = ''): mixed { return array_key_exists($key, $row) ? $row[$key] : $fallback; }

function table_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
        $cache[$table] = array_map(static fn($row) => $row['Field'], $stmt->fetchAll());
    } catch (Throwable $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

function column_exists(PDO $pdo, string $table, string $column): bool {
    return in_array($column, table_columns($pdo, $table), true);
}

function pick_columns(PDO $pdo, string $table, array $preferred): array {
    $existing = table_columns($pdo, $table);
    return array_values(array_filter($preferred, static fn($column) => in_array($column, $existing, true)));
}

function insert_dynamic(PDO $pdo, string $table, array $values): int {
    $cols = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (column_exists($pdo, $table, $column)) {
            $cols[] = $column;
            $params[] = $value;
        }
    }
    if (!$cols) throw new RuntimeException("No valid columns found for insert into {$table}.");
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

function update_dynamic(PDO $pdo, string $table, array $values, string $where, array $whereParams): void {
    $sets = [];
    $params = [];
    foreach ($values as $column => $value) {
        if (column_exists($pdo, $table, $column)) {
            $sets[] = '`' . $column . '` = ?';
            $params[] = $value;
        }
    }
    if (!$sets) return;
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;
    $pdo->prepare($sql)->execute(array_merge($params, $whereParams));
}

function visible_user_ids(PDO $pdo): array {
    $u = current_user();
    if (!$u) return [];
    if (($u['role'] ?? '') === 'manager') {
        return array_map('intval', $pdo->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN));
    }
    if (($u['role'] ?? '') === 'district_manager') {
        if (column_exists($pdo, 'users', 'district_manager_id')) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ? OR district_manager_id = ?');
            $stmt->execute([(int)$u['id'], (int)$u['id']]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }
        return [(int)$u['id']];
    }
    return [(int)$u['id']];
}

function scope_clause(PDO $pdo, string $alias = ''): array {
    $ids = visible_user_ids($pdo);
    if (!$ids) return ['1=0', []];
    $prefix = $alias ? $alias . '.' : '';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return ["{$prefix}user_id IN ($placeholders)", $ids];
}

function fetch_users(PDO $pdo, bool $activeOnly = false): array {
    $columns = pick_columns($pdo, 'users', ['id', 'name', 'email', 'role', 'district_manager_id', 'active', 'created_at']);
    if (!$columns) return [];
    $sql = 'SELECT `' . implode('`,`', $columns) . '` FROM users';
    if ($activeOnly && column_exists($pdo, 'users', 'active')) $sql .= ' WHERE active = 1';
    $sql .= column_exists($pdo, 'users', 'active') ? ' ORDER BY active DESC, name ASC' : ' ORDER BY name ASC';
    $users = $pdo->query($sql)->fetchAll();
    foreach ($users as &$user) {
        $user['district_manager_id'] = $user['district_manager_id'] ?? null;
        $user['active'] = $user['active'] ?? 1;
        $user['role'] = $user['role'] ?? 'employee';
    }
    unset($user);
    return $users;
}

function fetch_doctors(PDO $pdo): array {
    $columns = pick_columns($pdo, 'doctors_masterlist', ['id','dr_name','speciality','hospital_address','place','email','contact_no','class']);
    if (!$columns) return [];
    $sql = 'SELECT `' . implode('`,`', $columns) . '` FROM doctors_masterlist ORDER BY dr_name ASC';
    return $pdo->query($sql)->fetchAll();
}

function fetch_doctor_cities(PDO $pdo): array {
    if (!column_exists($pdo, 'doctors_masterlist', 'place')) return [];
    return $pdo->query("SELECT DISTINCT place FROM doctors_masterlist WHERE place IS NOT NULL AND place <> '' ORDER BY place ASC")->fetchAll(PDO::FETCH_COLUMN);
}

function save_uploaded_file(string $field, string $dir): ?string {
    if (empty($_FILES[$field]['name']) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    $root = __DIR__ . '/../' . trim($dir, '/');
    if (!is_dir($root)) mkdir($root, 0775, true);
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    $safeExt = preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    $name = $field . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $safeExt;
    $target = $root . '/' . $name;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $target)) return null;
    return trim($dir, '/') . '/' . $name;
}

function save_base64_image(?string $dataUrl, string $dir, string $prefix = 'signature'): ?string {
    $dataUrl = trim((string)$dataUrl);
    if ($dataUrl === '' || !str_starts_with($dataUrl, 'data:image/')) return null;
    if (!preg_match('/^data:image\/(png|jpeg|jpg);base64,(.+)$/', $dataUrl, $matches)) return null;
    $binary = base64_decode($matches[2], true);
    if ($binary === false || strlen($binary) < 50) return null;
    $root = __DIR__ . '/../' . trim($dir, '/');
    if (!is_dir($root)) mkdir($root, 0775, true);
    $name = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.png';
    $path = $root . '/' . $name;
    file_put_contents($path, $binary);
    return trim($dir, '/') . '/' . $name;
}

function render_header(string $title, string $eyebrow = 'Pharmastar CRM'): void {
    $u = current_user();
    $flashes = flashes();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> · Pharmastar CRM</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/app.css?v=20260514-dashboard-modal-font-animations">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">PS</div>
      <div><strong>Pharmastar</strong><span>Sales Reports</span></div>
    </div>
    <nav class="nav">
      <a class="<?= active_nav('index.php') ?>" href="index.php">Dashboard</a>
      <a class="<?= active_nav('reports.php') ?>" href="reports.php">Reports</a>
      <a class="<?= active_nav('report_form.php') ?>" href="report_form.php">New Report</a>
      <a class="<?= active_nav('tasks.php') ?>" href="tasks.php">Tasks</a>
      <a class="<?= active_nav('analytics.php') ?>" href="analytics.php">Analytics</a>
      <a class="<?= active_nav('doctors.php') ?>" href="doctors.php">Doctors</a>
      <?php if (is_manager()): ?><a class="<?= active_nav('users.php') ?>" href="users.php">Users</a><?php endif; ?>
      <a class="<?= active_nav('profile.php') ?>" href="profile.php">Profile</a>
    </nav>
    <div class="sidebar-user">
      <div class="avatar"><?= e(strtoupper(substr($u['name'] ?? 'U', 0, 1))) ?></div>
      <div><strong><?= e($u['name'] ?? '') ?></strong><span><?= e(role_label($u['role'] ?? 'employee')) ?></span></div>
      <a href="logout.php" class="logout">Logout</a>
    </div>
  </aside>
  <main class="main">
    <header class="topbar">
      <button class="menu-btn" data-toggle-sidebar>☰</button>
      <div><span class="eyebrow"><?= e($eyebrow) ?></span><h1><?= e($title) ?></h1></div>
      <div class="top-actions"><a class="btn ghost" href="reports.php">Reports</a><a class="btn primary" href="report_form.php">New Report</a></div>
    </header>
    <?php if ($flashes): ?><div class="flash-wrap"><?php foreach ($flashes as $f): ?><div class="alert <?= e($f['type']) ?>"><?= e($f['message']) ?></div><?php endforeach; ?></div><?php endif; ?>
    <section class="content">
    <?php
}

function render_footer(): void { ?>
    </section>
  </main>
</div>
<script src="assets/app.js?v=20260514-dashboard-modal-font-animations"></script>
</body>
</html>
<?php }
