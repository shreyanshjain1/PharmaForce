<?php
require __DIR__ . '/app/bootstrap.php'; require_login(); if(!is_manager()){ http_response_code(403); exit('Forbidden'); } verify_csrf();

$hasDistrictManager = column_exists($pdo, 'users', 'district_manager_id');
$hasActive = column_exists($pdo, 'users', 'active');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_top_manager()) {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = $_POST['role'] ?? 'employee';
    $active = isset($_POST['active']) ? 1 : 0;
    $dm = (int)($_POST['district_manager_id'] ?? 0);
    $dm = $dm > 0 ? $dm : null;

    $values = [
        'name' => $name,
        'email' => $email,
        'role' => $role,
        'district_manager_id' => $dm,
        'active' => $active,
    ];
    if (trim($_POST['password'] ?? '') !== '') {
        $values['password_hash'] = password_hash($_POST['password'], PASSWORD_DEFAULT);
    }

    if ($id) {
        update_dynamic($pdo, 'users', $values, 'id = ?', [$id]);
        flash('success', 'User updated.');
    } else {
        if (empty($values['password_hash'])) $values['password_hash'] = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
        insert_dynamic($pdo, 'users', $values);
        flash('success', 'User created.');
    }
    header('Location: users.php');
    exit;
}

$users = fetch_users($pdo, false);
render_header('Users');
?>
<div class="hero"><div><span class="eyebrow">Team</span><h2>User management</h2><p>Manage active users and roles. District manager assignment appears only if your database has that column.</p></div></div>
<?php if(is_top_manager()): ?>
<form class="card" method="post" style="margin-bottom:18px">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <div class="section-title"><div><span class="eyebrow">Create User</span><h2>Add team member</h2></div></div>
  <div class="form-grid">
    <div class="field"><label>Name</label><input class="input" name="name" required></div>
    <div class="field"><label>Email</label><input class="input" type="email" name="email" required></div>
    <div class="field"><label>Password</label><input class="input" type="password" name="password" required></div>
    <div class="field"><label>Role</label><select name="role"><option value="employee">Employee</option><option value="district_manager">District Manager</option><option value="manager">Manager</option></select></div>
    <?php if($hasDistrictManager): ?><div class="field"><label>District Manager ID</label><input class="input" type="number" name="district_manager_id"></div><?php endif; ?>
    <?php if($hasActive): ?><div class="field"><label>Active</label><select name="active"><option value="1">Active</option></select></div><?php endif; ?>
  </div>
  <br><button class="btn primary">Create User</button>
</form>
<?php endif; ?>
<div class="card"><div class="table-wrap"><table><thead><tr><th>Name</th><th>Email</th><th>Role</th><?php if($hasDistrictManager): ?><th>Manager ID</th><?php endif; ?><th>Status</th></tr></thead><tbody><?php foreach($users as $u): ?><tr><td><strong><?= e($u['name'] ?? '') ?></strong></td><td><?= e($u['email'] ?? '') ?></td><td><?= e(role_label($u['role'] ?? 'employee')) ?></td><?php if($hasDistrictManager): ?><td><?= e((string)($u['district_manager_id'] ?? '')) ?></td><?php endif; ?><td><span class="badge <?= ($u['active'] ?? 1) ? 'approved' : 'needs_changes' ?>"><?= ($u['active'] ?? 1) ? 'Active' : 'Inactive' ?></span></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php render_footer(); ?>
