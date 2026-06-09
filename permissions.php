<?php
require __DIR__ . '/app/bootstrap.php';

require_permission('security.view');

$matrix = permission_matrix();
$allPermissions = [];
foreach ($matrix as $role => $permissions) {
    foreach ($permissions as $permission) {
        if ($permission !== '*') $allPermissions[$permission] = true;
    }
}
ksort($allPermissions);

render_header('Permission Matrix');
?>

<style>
.permission-table td,.permission-table th{text-align:center}
.permission-table td:first-child,.permission-table th:first-child{text-align:left}
.permission-ok{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:999px;background:#ecfdf5;color:#15803d;border:1px solid #bbf7d0;font-weight:950}
.permission-no{display:inline-grid;place-items:center;width:30px;height:30px;border-radius:999px;background:#f8fafc;color:#94a3b8;border:1px solid #e2e8f0;font-weight:950}
.permission-role{font-weight:950;text-transform:capitalize}
</style>

<div class="hero">
    <div>
        <span class="eyebrow">Internal Security</span>
        <h2>Role Permission Matrix</h2>
        <p>Centralized overview of what each role can access. This is currently code-controlled for safety.</p>
    </div>
    <div class="actions">
        <a class="btn ghost" href="security.php">Security Center</a>
        <a class="btn ghost" href="file_security.php">File Security</a>
    </div>
</div>

<section class="card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Permissions</span>
            <h2>Role access overview</h2>
        </div>
    </div>

    <div class="table-wrap">
        <table class="permission-table">
            <thead>
                <tr>
                    <th>Permission</th>
                    <?php foreach (array_keys($matrix) as $role): ?>
                        <th><?= e(role_label($role)) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_keys($allPermissions) as $permission): ?>
                    <tr>
                        <td><strong><?= e($permission) ?></strong></td>
                        <?php foreach ($matrix as $role => $permissions): ?>
                            <?php $allowed = in_array('*', $permissions, true) || in_array($permission, $permissions, true); ?>
                            <td><?= $allowed ? '<span class="permission-ok">✓</span>' : '<span class="permission-no">–</span>' ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<?php render_footer(); ?>
