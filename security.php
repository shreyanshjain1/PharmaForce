<?php
require __DIR__ . '/app/bootstrap.php';

require_top_manager();

function security_check_label(bool $ok): string
{
    return $ok ? '<span class="badge approved">OK</span>' : '<span class="badge needs_changes">Needs Attention</span>';
}

function security_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        error_log('Security table check failed: ' . $e->getMessage());
        return false;
    }
}

function security_current_database(PDO $pdo): string
{
    try {
        return (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    } catch (Throwable $e) {
        return 'Unknown database';
    }
}

$currentDatabase = security_current_database($pdo);
$hasActivityLogs = security_table_exists($pdo, 'activity_logs');

$checks = [];

$checks[] = [
    'label' => 'HTTPS connection',
    'ok' => is_https_request() || is_local_request(),
    'detail' => is_https_request() ? 'Running over HTTPS.' : 'Localhost is allowed for development only.',
];

$checks[] = [
    'label' => 'Secure session cookie',
    'ok' => is_https_request() ? (bool)session_get_cookie_params()['secure'] : true,
    'detail' => is_https_request() ? 'Session cookie is marked secure.' : 'Secure cookie is disabled on localhost only.',
];

$checks[] = [
    'label' => 'HTTP only session cookie',
    'ok' => (bool)session_get_cookie_params()['httponly'],
    'detail' => 'Session cookie is protected from JavaScript access.',
];

$checks[] = [
    'label' => 'Activity log table',
    'ok' => $hasActivityLogs,
    'detail' => $hasActivityLogs ? 'activity_logs table exists in database: ' . $currentDatabase . '.' : 'activity_logs table was not found in database: ' . $currentDatabase . '. Import database/migrations/2026_06_09_internal_security_hardening.sql into this exact database.',
];

$checks[] = [
    'label' => 'Upload protection file',
    'ok' => file_exists(__DIR__ . '/uploads/.htaccess') || file_exists(__DIR__ . '/uploads/attachments/.htaccess'),
    'detail' => 'Upload folders should block executable files and directory listing.',
];

$checks[] = [
    'label' => 'PHP error display',
    'ok' => ini_get('display_errors') === '0' || ini_get('display_errors') === '',
    'detail' => 'Production should not display PHP errors publicly.',
];

$logAction = trim((string)($_GET['action_filter'] ?? ''));
$recentLogs = [];
$availableActions = [];

try {
    if ($hasActivityLogs) {
        $availableActions = $pdo->query("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "SELECT a.*, u.name user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id";
        $params = [];
        if ($logAction !== '') {
            $sql .= " WHERE a.action = ?";
            $params[] = $logAction;
        }
        $sql .= " ORDER BY a.created_at DESC, a.id DESC LIMIT 75";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $recentLogs = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $recentLogs = [];
}

render_header('Security Center');
?>

<style>
.security-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.security-check{padding:18px;border:1px solid rgba(15,118,110,.13);border-radius:26px;background:linear-gradient(145deg,#fff,#fbfffe);box-shadow:0 14px 30px rgba(15,118,110,.055)}
.security-check-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.security-check h3{margin:0;color:#082f2b}
.security-check p{margin:.6rem 0 0;color:#607872;font-weight:750;line-height:1.5}
.security-log-table td{vertical-align:top}
@media(max-width:900px){.security-grid{grid-template-columns:1fr}}
</style>

<div class="hero">
    <div>
        <span class="eyebrow">Internal Security</span>
        <h2>Security Center</h2>
        <p>Quick checks for the hosted PharmaForce install. Current database: <strong><?= e($currentDatabase) ?></strong></p>
    </div>
</div>

<section class="security-grid">
    <?php foreach ($checks as $check): ?>
        <article class="security-check">
            <div class="security-check-head">
                <h3><?= e($check['label']) ?></h3>
                <?= security_check_label((bool)$check['ok']) ?>
            </div>
            <p><?= e($check['detail']) ?></p>
        </article>
    <?php endforeach; ?>
</section>

<br>

<section class="card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Audit Trail</span>
            <h2>Recent security activity</h2>
        </div>
    </div>

    <?php if ($availableActions): ?>
        <form method="get" class="filters" style="margin-bottom:1rem">
            <div class="field">
                <label>Action Filter</label>
                <select name="action_filter">
                    <option value="">All actions</option>
                    <?php foreach ($availableActions as $actionOption): ?>
                        <option value="<?= e($actionOption) ?>" <?= $logAction === $actionOption ? 'selected' : '' ?>><?= e($actionOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn primary">Filter</button>
            <a class="btn ghost" href="security.php">Reset</a>
        </form>
    <?php endif; ?>

    <?php if (!$recentLogs): ?>
        <div class="empty">No activity logs found yet. Import the migration, then log in again to start recording events.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="security-log-table">
                <thead>
                    <tr><th>Date</th><th>User</th><th>Action</th><th>IP</th><th>Details</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td><?= e(date('M d, Y g:i A', strtotime($log['created_at']))) ?></td>
                            <td><?= e($log['user_name'] ?: 'System / Unknown') ?></td>
                            <td><strong><?= e($log['action']) ?></strong></td>
                            <td><?= e($log['ip_address'] ?? '') ?></td>
                            <td><span class="muted"><?= e($log['details'] ?? '') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
