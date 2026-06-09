<?php
require __DIR__ . '/app/bootstrap.php';

require_login();

if (!is_manager()) {
    http_response_code(403);
    exit('Forbidden');
}

verify_csrf();

$hasDistrictManager = column_exists($pdo, 'users', 'district_manager_id');
$hasActive = column_exists($pdo, 'users', 'active');
$hasPasswordHash = column_exists($pdo, 'users', 'password_hash');
$isTopManager = is_top_manager();

function user_active_value(array $user): int
{
    return (int)($user['active'] ?? 1) === 1 ? 1 : 0;
}

function user_status_badge(array $user): string
{
    return user_active_value($user) ? 'approved' : 'needs_changes';
}

function safe_role(string $role): string
{
    return in_array($role, ['employee', 'district_manager', 'manager'], true) ? $role : 'employee';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isTopManager) {
    $action = $_POST['action'] ?? 'save_user';
    $id = (int)($_POST['id'] ?? 0);

    if ($action === 'toggle_user' && $id > 0 && $hasActive) {
        $targetActive = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;

        if ($id === (int)(current_user()['id'] ?? 0) && $targetActive === 0) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            update_dynamic($pdo, 'users', ['active' => $targetActive], 'id = ?', [$id]);
            audit_log($pdo, $targetActive ? 'user_activated' : 'user_deactivated', 'user', $id);
            flash('success', $targetActive ? 'User activated.' : 'User deactivated.');
        }

        header('Location: users.php');
        exit;
    }

    if ($action === 'save_user') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = safe_role((string)($_POST['role'] ?? 'employee'));
        $password = trim((string)($_POST['password'] ?? ''));
        $active = (int)($_POST['active'] ?? 1) === 1 ? 1 : 0;
        $dm = (int)($_POST['district_manager_id'] ?? 0);
        $dm = $dm > 0 ? $dm : null;

        if ($name === '' || $email === '') {
            flash('error', 'Name and email are required.');
            header('Location: users.php' . ($id ? '?edit=' . $id : ''));
            exit;
        }

        $values = [
            'name' => $name,
            'email' => $email,
            'role' => $role,
        ];

        if ($hasDistrictManager) {
            $values['district_manager_id'] = $role === 'employee' ? $dm : null;
        }

        if ($hasActive) {
            if ($id === (int)(current_user()['id'] ?? 0) && $active === 0) {
                $active = 1;
                flash('error', 'Your own account was kept active for safety.');
            }
            $values['active'] = $active;
        }

        if ($hasPasswordHash && $password !== '') {
            $values['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($id > 0) {
            update_dynamic($pdo, 'users', $values, 'id = ?', [$id]);
            audit_log($pdo, 'user_updated', 'user', $id, [
                'name' => $name,
                'email' => $email,
                'role' => $role,
                'password_changed' => $password !== '',
            ]);
            flash('success', 'User updated.');
        } else {
            if ($hasPasswordHash && empty($values['password_hash'])) {
                $values['password_hash'] = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
            }
            $newUserId = insert_dynamic($pdo, 'users', $values);
            audit_log($pdo, 'user_created', 'user', $newUserId, [
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ]);
            flash('success', 'User created.');
        }

        header('Location: users.php');
        exit;
    }
}

$users = fetch_users($pdo, false);
$editId = $isTopManager ? (int)($_GET['edit'] ?? 0) : 0;
$editUser = null;

foreach ($users as $candidate) {
    if ((int)($candidate['id'] ?? 0) === $editId) {
        $editUser = $candidate;
        break;
    }
}

$managerOptions = array_values(array_filter($users, static function (array $user): bool {
    return in_array((string)($user['role'] ?? ''), ['district_manager', 'manager'], true) && user_active_value($user) === 1;
}));

$managerNames = [];
foreach ($users as $user) {
    $managerNames[(int)($user['id'] ?? 0)] = (string)($user['name'] ?? '');
}

$formTitle = $editUser ? 'Edit team member' : 'Add team member';
$formEyebrow = $editUser ? 'Edit User' : 'Create User';
$formButton = $editUser ? 'Save Changes' : 'Create User';

render_header('Users');
?>
<div class="hero users-hero">
    <div>
        <span class="eyebrow">Team</span>
        <h2>User management</h2>
        <p>Manage users, roles, reporting assignments, and account access from one clean workspace.</p>
    </div>
    <?php if ($isTopManager): ?>
        <div class="actions">
            <a class="btn ghost" href="users.php#user-form">New User</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($isTopManager): ?>
    <form class="card user-editor-card" id="user-form" method="post">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="id" value="<?= (int)($editUser['id'] ?? 0) ?>">

        <div class="section-title">
            <div>
                <span class="eyebrow"><?= e($formEyebrow) ?></span>
                <h2><?= e($formTitle) ?></h2>
                <p><?= $editUser ? 'Update this account without changing the password unless needed.' : 'Create a new user account for sales reporting access.' ?></p>
            </div>
            <?php if ($editUser): ?>
                <a class="btn ghost small" href="users.php">Cancel Edit</a>
            <?php endif; ?>
        </div>

        <div class="form-grid user-form-grid">
            <div class="field">
                <label>Name</label>
                <input class="input" name="name" value="<?= e($editUser['name'] ?? '') ?>" required>
            </div>

            <div class="field">
                <label>Email</label>
                <input class="input" type="email" name="email" value="<?= e($editUser['email'] ?? '') ?>" required>
            </div>

            <div class="field">
                <label>Password</label>
                <input class="input" type="password" name="password" <?= $editUser ? '' : 'required' ?> placeholder="<?= $editUser ? 'Leave blank to keep current password' : 'Set initial password' ?>">
            </div>

            <div class="field">
                <label>Role</label>
                <select name="role">
                    <?php foreach (['employee', 'district_manager', 'manager'] as $role): ?>
                        <option value="<?= e($role) ?>" <?= (($editUser['role'] ?? 'employee') === $role) ? 'selected' : '' ?>>
                            <?= e(role_label($role)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($hasDistrictManager): ?>
                <div class="field">
                    <label>Assigned Manager</label>
                    <select name="district_manager_id">
                        <option value="">No assigned manager</option>
                        <?php foreach ($managerOptions as $manager): ?>
                            <option value="<?= (int)$manager['id'] ?>" <?= ((int)($editUser['district_manager_id'] ?? 0) === (int)$manager['id']) ? 'selected' : '' ?>>
                                <?= e($manager['name'] ?? '') ?> · <?= e(role_label($manager['role'] ?? 'employee')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($hasActive): ?>
                <div class="field">
                    <label>Status</label>
                    <select name="active">
                        <option value="1" <?= user_active_value($editUser ?? ['active' => 1]) ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= !user_active_value($editUser ?? ['active' => 1]) ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
            <?php endif; ?>
        </div>

        <div class="form-actions users-form-actions">
            <button class="btn primary"><?= e($formButton) ?></button>
            <?php if ($editUser): ?>
                <a class="btn ghost" href="users.php">Back to Create Mode</a>
            <?php endif; ?>
        </div>
    </form>
<?php endif; ?>

<div class="card users-list-card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Directory</span>
            <h2>Team accounts</h2>
        </div>
    </div>

    <div class="table-wrap users-table-wrap">
        <table class="users-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <?php if ($hasDistrictManager): ?><th>Assigned Manager</th><?php endif; ?>
                    <th>Status</th>
                    <?php if ($isTopManager): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $u): ?>
                    <?php
                    $userId = (int)($u['id'] ?? 0);
                    $isActive = user_active_value($u);
                    $managerId = (int)($u['district_manager_id'] ?? 0);
                    $canToggle = $hasActive && $userId !== (int)(current_user()['id'] ?? 0);
                    ?>
                    <tr>
                        <td>
                            <div class="user-cell-name">
                                <span class="user-mini-avatar"><?= e(strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1))) ?></span>
                                <strong><?= e($u['name'] ?? '') ?></strong>
                            </div>
                        </td>
                        <td><?= e($u['email'] ?? '') ?></td>
                        <td><span class="role-chip role-<?= e((string)($u['role'] ?? 'employee')) ?>"><?= e(role_label($u['role'] ?? 'employee')) ?></span></td>
                        <?php if ($hasDistrictManager): ?>
                            <td>
                                <?= $managerId > 0 ? e(($managerNames[$managerId] ?? 'User #' . $managerId)) : '<span class="muted">Not assigned</span>' ?>
                            </td>
                        <?php endif; ?>
                        <td>
                            <span class="badge <?= e(user_status_badge($u)) ?>"><?= $isActive ? 'Active' : 'Inactive' ?></span>
                        </td>
                        <?php if ($isTopManager): ?>
                            <td>
                                <div class="user-actions">
                                    <a class="btn small ghost" href="users.php?edit=<?= $userId ?>#user-form">Edit</a>

                                    <?php if ($hasActive): ?>
                                        <form method="post" class="user-toggle-form">
                                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                                            <input type="hidden" name="action" value="toggle_user">
                                            <input type="hidden" name="id" value="<?= $userId ?>">
                                            <input type="hidden" name="active" value="<?= $isActive ? 0 : 1 ?>">
                                            <button class="status-toggle <?= $isActive ? 'is-on' : 'is-off' ?>" type="submit" <?= $canToggle ? '' : 'disabled' ?> title="<?= $isActive ? 'Deactivate user' : 'Activate user' ?>" data-confirm="<?= $isActive ? 'Deactivate this user account? The user will no longer be able to access the system.' : 'Activate this user account? The user will be able to access the system again.' ?>" data-confirm-title="<?= $isActive ? 'Deactivate User' : 'Activate User' ?>" data-confirm-ok="<?= $isActive ? 'Deactivate' : 'Activate' ?>" data-confirm-danger="<?= $isActive ? '1' : '0' ?>">
                                                <span></span>
                                                <strong><?= $isActive ? 'On' : 'Off' ?></strong>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php render_footer(); ?>
