<?php
require __DIR__ . '/app/bootstrap.php';

require_login();

$user = function_exists('current_user') ? current_user() : ($_SESSION['user'] ?? []);
$userId = (int)($user['id'] ?? ($_SESSION['user_id'] ?? 0));

if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $freshUser = $stmt->fetch();
        if ($freshUser) {
            $user = array_merge($user, $freshUser);
        }
    } catch (Throwable $e) {
        // Keep the session user if the table/columns are different.
    }
}

$name = trim((string)($user['name'] ?? 'User'));
$email = trim((string)($user['email'] ?? 'Not provided'));
$roleRaw = trim((string)($user['role'] ?? 'employee'));
$roleLabel = ucwords(str_replace('_', ' ', $roleRaw));
$isActive = (int)($user['is_active'] ?? 1) === 1;

$initials = '';
foreach (preg_split('/\s+/', $name) as $part) {
    if ($part !== '') {
        $initials .= strtoupper(substr($part, 0, 1));
    }
}
$initials = substr($initials ?: 'U', 0, 2);

$stats = [
    'reports' => 0,
    'pending' => 0,
    'approved' => 0,
    'tasks' => 0,
    'expenses' => 0,
    'expense_total' => 0,
];

if ($userId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ?");
        $stmt->execute([$userId]);
        $stats['reports'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $stats['pending'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status = 'approved'");
        $stmt->execute([$userId]);
        $stats['approved'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Keep defaults.
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE user_id = ? OR assigned_user_id = ?");
        $stmt->execute([$userId, $userId]);
        $stats['tasks'] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE user_id = ?");
            $stmt->execute([$userId]);
            $stats['tasks'] = (int)$stmt->fetchColumn();
        } catch (Throwable $inner) {
            // Keep default.
        }
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total_amount), 0) FROM expense_reports WHERE user_id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        if ($row) {
            $stats['expenses'] = (int)$row[0];
            $stats['expense_total'] = (float)$row[1];
        }
    } catch (Throwable $e) {
        // Expense module may not be installed yet.
    }
}

$recentReports = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, doctor_name, hospital_name, visit_datetime, status
        FROM reports
        WHERE user_id = ?
        ORDER BY COALESCE(visit_datetime, created_at) DESC, id DESC
        LIMIT 5
    ");
    $stmt->execute([$userId]);
    $recentReports = $stmt->fetchAll();
} catch (Throwable $e) {
    $recentReports = [];
}

function profile_safe_date($value)
{
    if (!$value) {
        return 'Not provided';
    }

    $time = strtotime((string)$value);
    return $time ? date('M d, Y g:i A', $time) : (string)$value;
}

render_header('Profile');
?>

<style>
.profile-center { display: grid; gap: 20px; }
.profile-hero { position: relative; overflow: hidden; display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 24px; align-items: center; padding: 30px; border: 1px solid rgba(15,118,110,.16); border-radius: 34px; background: radial-gradient(circle at 88% 16%, rgba(250,204,21,.20), transparent 30%), radial-gradient(circle at 14% 0%, rgba(20,184,166,.18), transparent 34%), linear-gradient(135deg,#fff 0%,#ecfffb 78%); box-shadow: 0 22px 54px rgba(15,118,110,.09); }
.profile-hero::before { content: ""; position: absolute; inset: 0 auto 0 0; width: 7px; background: linear-gradient(180deg,#0f766e,#14b8a6,#facc15); border-radius: 999px; }
.profile-identity { display: flex; align-items: center; gap: 18px; min-width: 0; }
.profile-avatar-xl { width: 86px; height: 86px; flex: 0 0 86px; display: grid; place-items: center; border-radius: 28px; color: #0f766e; background: radial-gradient(circle at top left, rgba(20,184,166,.22), transparent 40%), linear-gradient(135deg,#fff,#ecfdf5); border: 1px solid rgba(15,118,110,.18); box-shadow: 0 16px 34px rgba(15,118,110,.12); font-size: 30px; font-weight: 950; letter-spacing: -.04em; }
.profile-title-block { min-width: 0; }
.profile-title-block h2 { margin: 5px 0 6px; font-size: clamp(30px,3vw,46px); line-height: 1; letter-spacing: -.06em; color: #061f1c; }
.profile-title-block p { margin: 0; color: #59736d; font-size: 16px; font-weight: 700; }
.profile-chip-row { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.profile-chip { display: inline-flex; align-items: center; gap: 7px; min-height: 34px; padding: 7px 11px; border-radius: 999px; border: 1px solid rgba(15,118,110,.15); background: rgba(255,255,255,.76); color: #0b4f48; font-size: 12px; font-weight: 950; }
.profile-chip.active { color: #15803d; background: #ecfdf5; border-color: #bbf7d0; }
.profile-chip.inactive { color: #b91c1c; background: #fff1f2; border-color: #fecdd3; }
.profile-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
.profile-actions .btn { border-radius: 18px; }
.profile-dashboard-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 16px; }
.profile-stat-card { position: relative; overflow: hidden; min-height: 126px; padding: 22px; border: 1px solid rgba(15,118,110,.14); border-radius: 30px; background: linear-gradient(145deg,rgba(255,255,255,.98),rgba(250,255,253,.94)); box-shadow: 0 14px 34px rgba(15,118,110,.065); }
.profile-stat-card::before { content: ""; position: absolute; top: 0; left: 18px; right: 18px; height: 5px; border-radius: 999px; background: linear-gradient(90deg,#0f766e,#14b8a6,#facc15); }
.profile-stat-card span { display: block; margin-top: 8px; color: #607872; font-size: 12px; text-transform: uppercase; letter-spacing: .09em; font-weight: 950; }
.profile-stat-card strong { display: block; margin-top: 10px; color: #061f1c; font-size: 32px; line-height: 1; letter-spacing: -.05em; }
.profile-layout { display: grid; grid-template-columns: minmax(0,.95fr) minmax(0,1.25fr); gap: 18px; align-items: start; }
.profile-panel { padding: 24px; border: 1px solid rgba(15,118,110,.14); border-radius: 32px; background: radial-gradient(circle at right top, rgba(20,184,166,.09), transparent 26%), linear-gradient(145deg,#fff,#fbfffe); box-shadow: 0 14px 34px rgba(15,118,110,.065); }
.profile-panel-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; padding-bottom: 14px; margin-bottom: 14px; border-bottom: 1px solid rgba(15,118,110,.10); }
.profile-panel-head h3 { margin: 3px 0 0; color: #061f1c; font-size: 22px; letter-spacing: -.04em; }
.profile-info-list { display: grid; gap: 12px; }
.profile-info-item { display: grid; grid-template-columns: 150px minmax(0,1fr); gap: 12px; align-items: center; padding: 14px; border: 1px solid rgba(15,118,110,.11); border-radius: 22px; background: rgba(255,255,255,.74); }
.profile-info-item span { color: #607872; font-size: 11px; text-transform: uppercase; letter-spacing: .09em; font-weight: 950; }
.profile-info-item strong { color: #082f2b; font-weight: 900; overflow-wrap: anywhere; }
.profile-note { margin-top: 14px; padding: 15px; border-radius: 22px; border: 1px dashed rgba(15,118,110,.23); background: #f8fffd; color: #607872; font-weight: 750; line-height: 1.55; }
.profile-activity-list { display: grid; gap: 12px; }
.profile-activity-item { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 14px; align-items: center; padding: 15px; border: 1px solid rgba(15,118,110,.12); border-radius: 24px; background: linear-gradient(145deg,#fff,#fbfffe); transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease; }
.profile-activity-item:hover { transform: translateY(-1px); border-color: rgba(20,184,166,.34); box-shadow: 0 14px 28px rgba(15,118,110,.07); }
.profile-activity-item strong { display: block; color: #082f2b; font-weight: 950; }
.profile-activity-item p { margin: 4px 0 0; color: #607872; font-size: 13px; font-weight: 750; }
.profile-system-grid { display: grid; grid-template-columns: repeat(3,minmax(0,1fr)); gap: 14px; }
.profile-system-card { padding: 18px; border: 1px solid rgba(15,118,110,.12); border-radius: 26px; background: linear-gradient(145deg,#fff,#fbfffe); }
.profile-system-card span { display: block; color: #607872; font-size: 11px; text-transform: uppercase; letter-spacing: .09em; font-weight: 950; }
.profile-system-card strong { display: block; margin-top: 8px; color: #082f2b; font-size: 15px; line-height: 1.35; }
@media (max-width:1120px) { .profile-dashboard-grid,.profile-system-grid { grid-template-columns: repeat(2,minmax(0,1fr)); } .profile-layout { grid-template-columns: 1fr; } }
@media (max-width:720px) { .profile-hero { grid-template-columns: 1fr; padding: 24px; } .profile-identity { align-items: flex-start; } .profile-avatar-xl { width: 68px; height: 68px; flex-basis: 68px; border-radius: 22px; font-size: 24px; } .profile-actions,.profile-actions .btn { width: 100%; } .profile-dashboard-grid,.profile-system-grid { grid-template-columns: 1fr; } .profile-info-item { grid-template-columns: 1fr; gap: 6px; } .profile-activity-item { grid-template-columns: 1fr; } }
</style>

<div class="profile-center">
    <section class="profile-hero">
        <div class="profile-identity">
            <div class="profile-avatar-xl"><?= e($initials) ?></div>
            <div class="profile-title-block">
                <span class="eyebrow">Account Center</span>
                <h2><?= e($name) ?></h2>
                <p><?= e($email) ?> &middot; <?= e($roleLabel) ?></p>
                <div class="profile-chip-row">
                    <span class="profile-chip"><?= e($roleLabel) ?></span>
                    <span class="profile-chip <?= $isActive ? 'active' : 'inactive' ?>"><?= $isActive ? 'Active Account' : 'Inactive Account' ?></span>
                    <span class="profile-chip">User ID #<?= (int)$userId ?></span>
                </div>
            </div>
        </div>
        <div class="profile-actions">
            <a class="btn ghost" href="reports.php">Reports</a>
            <a class="btn primary" href="report_form.php">New Report</a>
            <a class="btn danger" href="logout.php">Logout</a>
        </div>
    </section>

    <section class="profile-dashboard-grid">
        <div class="profile-stat-card"><span>Total Reports</span><strong><?= number_format($stats['reports']) ?></strong></div>
        <div class="profile-stat-card"><span>Approved</span><strong><?= number_format($stats['approved']) ?></strong></div>
        <div class="profile-stat-card"><span>Pending</span><strong><?= number_format($stats['pending']) ?></strong></div>
        <div class="profile-stat-card"><span>Tasks</span><strong><?= number_format($stats['tasks']) ?></strong></div>
    </section>

    <section class="profile-layout">
        <div class="profile-panel">
            <div class="profile-panel-head"><div><span class="eyebrow">Profile Details</span><h3>Account information</h3></div></div>
            <div class="profile-info-list">
                <div class="profile-info-item"><span>Name</span><strong><?= e($name) ?></strong></div>
                <div class="profile-info-item"><span>Email</span><strong><?= e($email) ?></strong></div>
                <div class="profile-info-item"><span>Role</span><strong><?= e($roleLabel) ?></strong></div>
                <div class="profile-info-item"><span>Status</span><strong><?= $isActive ? 'Active' : 'Inactive' ?></strong></div>
            </div>
            <div class="profile-note">This profile reflects the logged-in account used for reports, tasks, manager review, and expense workflows.</div>
        </div>

        <div class="profile-panel">
            <div class="profile-panel-head"><div><span class="eyebrow">Recent Work</span><h3>Latest reports</h3></div><a class="btn small ghost" href="reports.php">View All</a></div>
            <?php if ($recentReports): ?>
                <div class="profile-activity-list">
                    <?php foreach ($recentReports as $report): ?>
                        <a class="profile-activity-item" href="report_view.php?id=<?= (int)$report['id'] ?>">
                            <div>
                                <strong><?= e($report['doctor_name'] ?: 'Unnamed Doctor') ?></strong>
                                <p><?= e($report['hospital_name'] ?: 'Hospital not provided') ?> &middot; <?= e(profile_safe_date($report['visit_datetime'] ?? null)) ?></p>
                            </div>
                            <span class="badge <?= e($report['status']) ?>"><?= e(status_label($report['status'])) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No recent reports yet.</div>
            <?php endif; ?>
        </div>
    </section>

    <section class="profile-panel">
        <div class="profile-panel-head"><div><span class="eyebrow">Workspace</span><h3>Quick access</h3></div></div>
        <div class="profile-system-grid">
            <a class="profile-system-card" href="reports.php"><span>Reports</span><strong>Open submitted field reports and manager review records.</strong></a>
            <a class="profile-system-card" href="tasks.php"><span>Tasks</span><strong>View scheduled field work and generate reports from tasks.</strong></a>
            <a class="profile-system-card" href="expenses.php"><span>Expenses</span><strong>Manage liquidation of expenses and reimbursement records.</strong></a>
        </div>
    </section>
</div>

<?php render_footer(); ?>
