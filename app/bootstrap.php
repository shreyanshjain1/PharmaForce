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
    global $pdo;

    $u = current_user();
    $flashes = flashes();

    $navBadges = [
        'my_work' => 0,
        'reports' => 0,
        'tasks' => 0,
        'expenses' => 0,
        'approvals' => 0,
    ];

    if ($u && isset($pdo) && $pdo instanceof PDO) {
        $safeCount = static function (string $sql, array $params = []) use ($pdo): int {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return (int)$stmt->fetchColumn();
            } catch (Throwable $e) {
                return 0;
            }
        };

        $role = (string)($u['role'] ?? 'employee');
        $userId = (int)($u['id'] ?? 0);
        $isManagerRole = in_array($role, ['manager', 'district_manager'], true);

        try {
            if (table_columns($pdo, 'reports')) {
                if ($isManagerRole) {
                    $navBadges['reports'] = $safeCount("SELECT COUNT(*) FROM reports WHERE status IN ('pending','needs_changes')");
                } else {
                    $navBadges['reports'] = $safeCount("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status IN ('pending','needs_changes')", [$userId]);
                }
            }
        } catch (Throwable $e) {}

        try {
            if (table_columns($pdo, 'expense_reports')) {
                if ($isManagerRole) {
                    $navBadges['expenses'] = $safeCount("SELECT COUNT(*) FROM expense_reports WHERE status IN ('pending','needs_changes')");
                } else {
                    $navBadges['expenses'] = $safeCount("SELECT COUNT(*) FROM expense_reports WHERE user_id = ? AND status IN ('pending','needs_changes')", [$userId]);
                }
            }
        } catch (Throwable $e) {}

        try {
            if (table_columns($pdo, 'events')) {
                $eventCols = table_columns($pdo, 'events');
                $eventStartColumn = in_array('start', $eventCols, true)
                    ? 'start'
                    : (in_array('start_datetime', $eventCols, true)
                        ? 'start_datetime'
                        : (in_array('visit_datetime', $eventCols, true) ? 'visit_datetime' : 'created_at'));

                $ownerSql = 'user_id = ?';
                $ownerParams = [$userId];

                if ($isManagerRole) {
                    $ownerSql = '1=1';
                    $ownerParams = [];
                } elseif (in_array('assigned_user_id', $eventCols, true)) {
                    $ownerSql = '(assigned_user_id = ? OR user_id = ?)';
                    $ownerParams = [$userId, $userId];
                }

                $statusSql = in_array('status', $eventCols, true)
                    ? " AND (status IS NULL OR status NOT IN ('done','completed','cancelled'))"
                    : '';

                $navBadges['tasks'] = $safeCount(
                    "SELECT COUNT(*) FROM events WHERE {$ownerSql} AND DATE(`{$eventStartColumn}`) = CURDATE() {$statusSql}",
                    $ownerParams
                );
            }
        } catch (Throwable $e) {}

        try {
            if ($isManagerRole && table_columns($pdo, 'approval_records')) {
                if ($role === 'manager') {
                    $navBadges['approvals'] = $safeCount("SELECT COUNT(*) FROM approval_records WHERE manager_status = 'pending' AND final_status != 'approved'");
                } else {
                    $navBadges['approvals'] = $safeCount("SELECT COUNT(*) FROM approval_records WHERE district_status = 'pending' AND final_status != 'approved'");
                }
            } elseif ($isManagerRole) {
                $navBadges['approvals'] = $navBadges['reports'] + $navBadges['expenses'];
            }
        } catch (Throwable $e) {}

        $navBadges['my_work'] = $isManagerRole
            ? ($navBadges['approvals'] + $navBadges['tasks'])
            : ($navBadges['reports'] + $navBadges['expenses'] + $navBadges['tasks']);

        foreach ($navBadges as $key => $value) {
            $navBadges[$key] = max(0, min(99, (int)$value));
        }
    }
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
  <script>
    try {
      if (localStorage.getItem('pharmaforce_sidebar_collapsed') === '1') {
        document.documentElement.classList.add('sidebar-collapsed');
      }
    } catch (e) {}
  </script>
  <link rel="stylesheet" href="assets/app.css?v=20260526-sidebar-final">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">PS</div>
      <div class="brand-copy"><strong>Pharmastar</strong><span>Sales Reports</span></div>
      <button type="button" class="sidebar-collapse-btn" data-collapse-sidebar aria-label="Collapse sidebar" title="Collapse sidebar">
        <span class="collapse-icon">‹</span>
      </button>
    </div>
    <nav class="nav">
      <a class="<?= active_nav('index.php') ?>" href="index.php"><span class="nav-icon">D</span><span class="nav-label">Dashboard</span></a>
      <a class="<?= active_nav('my_work.php') ?>" href="my_work.php"><span class="nav-icon">W</span><span class="nav-label">My Work</span><?php if (($navBadges['my_work'] ?? 0) > 0): ?><span class="nav-badge"><?= (int)$navBadges['my_work'] ?></span><?php endif; ?></a>
      <a class="<?= active_nav('reports.php') ?>" href="reports.php"><span class="nav-icon">R</span><span class="nav-label">Reports</span><?php if (($navBadges['reports'] ?? 0) > 0): ?><span class="nav-badge"><?= (int)$navBadges['reports'] ?></span><?php endif; ?></a>
      <a class="<?= active_nav('report_form.php') ?>" href="report_form.php"><span class="nav-icon">N</span><span class="nav-label">New Report</span></a>
      <a class="<?= active_nav('tasks.php') ?>" href="tasks.php"><span class="nav-icon">T</span><span class="nav-label">Tasks</span><?php if (($navBadges['tasks'] ?? 0) > 0): ?><span class="nav-badge"><?= (int)$navBadges['tasks'] ?></span><?php endif; ?></a>
      <a class="<?= active_nav('expenses.php') ?>" href="expenses.php"><span class="nav-icon">E</span><span class="nav-label">Expenses</span><?php if (($navBadges['expenses'] ?? 0) > 0): ?><span class="nav-badge"><?= (int)$navBadges['expenses'] ?></span><?php endif; ?></a>
      <?php if (is_manager()): ?><a class="<?= active_nav('approvals.php') ?>" href="approvals.php"><span class="nav-icon">A</span><span class="nav-label">Approvals</span><?php if (($navBadges['approvals'] ?? 0) > 0): ?><span class="nav-badge urgent"><?= (int)$navBadges['approvals'] ?></span><?php endif; ?></a><?php endif; ?>
      <a class="<?= active_nav('analytics.php') ?>" href="analytics.php"><span class="nav-icon">K</span><span class="nav-label">Analytics</span></a>
      <a class="<?= active_nav('doctors.php') ?>" href="doctors.php"><span class="nav-icon">Dr</span><span class="nav-label">Doctors</span></a>
      <?php if (is_manager()): ?><a class="<?= active_nav('users.php') ?>" href="users.php"><span class="nav-icon">U</span><span class="nav-label">Users</span></a><?php endif; ?>
      <a class="<?= active_nav('profile.php') ?>" href="profile.php"><span class="nav-icon">P</span><span class="nav-label">Profile</span></a>
    </nav>
    <div class="sidebar-user">
      <div class="avatar"><?= e(strtoupper(substr($u['name'] ?? 'U', 0, 1))) ?></div>
      <div class="sidebar-user-copy"><strong><?= e($u['name'] ?? '') ?></strong><span><?= e(role_label($u['role'] ?? 'employee')) ?></span></div>
      <a href="logout.php" class="logout" data-confirm="Are you sure you want to log out?"><span class="logout-label">Logout</span><span class="logout-icon">↪</span></a>
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
<script>
(function () {
  const root = document.documentElement;
  const body = document.body;
  const sidebar = document.getElementById('sidebar');
  const collapseToggle = document.querySelector('[data-collapse-sidebar]');
  const mobileToggleSelector = '[data-toggle-sidebar]';
  const mobileBreakpoint = 1180;

  function isMobileSidebarMode() {
    return window.innerWidth <= mobileBreakpoint;
  }

  function applyCollapsedState(collapsed) {
    root.classList.toggle('sidebar-collapsed', collapsed);
    if (collapseToggle) {
      collapseToggle.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      collapseToggle.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    }
  }

  function openMobileSidebar() {
    if (!sidebar) return;
    sidebar.classList.add('open');
    body.classList.add('sidebar-backdrop-active');
  }

  function closeMobileSidebar() {
    if (!sidebar) return;
    sidebar.classList.remove('open');
    body.classList.remove('sidebar-backdrop-active');
  }

  function toggleMobileSidebar() {
    if (!sidebar) return;
    sidebar.classList.contains('open') ? closeMobileSidebar() : openMobileSidebar();
  }

  try {
    applyCollapsedState(localStorage.getItem('pharmaforce_sidebar_collapsed') === '1');
  } catch (e) {
    applyCollapsedState(false);
  }

  if (collapseToggle) {
    collapseToggle.addEventListener('click', function () {
      if (isMobileSidebarMode()) return;
      const collapsed = !root.classList.contains('sidebar-collapsed');
      applyCollapsedState(collapsed);
      try {
        localStorage.setItem('pharmaforce_sidebar_collapsed', collapsed ? '1' : '0');
      } catch (e) {}
    });
  }

  document.addEventListener('click', function (event) {
    const mobileToggle = event.target.closest(mobileToggleSelector);

    if (mobileToggle) {
      if (isMobileSidebarMode()) {
        event.preventDefault();
        toggleMobileSidebar();
      }
      return;
    }

    if (!isMobileSidebarMode() || !sidebar || !sidebar.classList.contains('open')) return;

    const clickedInsideSidebar = event.target.closest('#sidebar');
    const clickedNavLink = event.target.closest('#sidebar a');

    if (clickedNavLink || !clickedInsideSidebar) {
      closeMobileSidebar();
    }
  }, true);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && isMobileSidebarMode()) closeMobileSidebar();
  });

  window.addEventListener('resize', function () {
    if (!isMobileSidebarMode()) closeMobileSidebar();
  });
})();
</script>
<script src="assets/app.js?v=20260526-sidebar-final"></script>
</body>
</html>
<?php }
