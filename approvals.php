<?php
require __DIR__ . '/app/bootstrap.php';

require_login();
verify_csrf();

if (!is_manager()) {
    http_response_code(403);
    exit('Approvals are only available to managers and district managers.');
}

function approvals_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM approval_records LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function approvals_expenses_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT 1 FROM expense_reports LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function approval_status_label_local(string $status): string
{
    return ['pending' => 'Pending', 'approved' => 'Approved', 'needs_changes' => 'Needs Changes'][$status] ?? $status;
}

function approval_entity_label(string $type): string
{
    return $type === 'expense' ? 'Expense Report' : 'Sales Report';
}

function approval_final_status(array $approval): string
{
    $manager = $approval['manager_status'] ?? 'pending';
    $district = $approval['district_status'] ?? 'pending';

    if ($manager === 'needs_changes' || $district === 'needs_changes') {
        return 'needs_changes';
    }

    if ($manager === 'approved' && $district === 'approved') {
        return 'approved';
    }

    return 'pending';
}

function approval_safe_date($value): string
{
    if (!$value) return 'Not provided';
    $time = strtotime((string)$value);
    return $time ? date('M d, Y g:i A', $time) : (string)$value;
}

function approval_money($value): string
{
    return '₱' . number_format((float)$value, 2);
}

function approval_scope_clause_for(PDO $pdo, string $alias): array
{
    $ids = visible_user_ids($pdo);
    if (!$ids) return ['1=0', []];
    return [$alias . '.user_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
}

function ensure_approval_record(PDO $pdo, string $type, int $entityId, ?int $userId): void
{
    $stmt = $pdo->prepare('INSERT IGNORE INTO approval_records (entity_type, entity_id, submitted_by_user_id, final_status) VALUES (?, ?, ?, ?)');
    $stmt->execute([$type, $entityId, $userId, 'pending']);
}

function update_entity_approval_status(PDO $pdo, string $type, int $entityId, string $finalStatus): void
{
    if ($type === 'report') {
        $pdo->prepare('UPDATE reports SET status = ? WHERE id = ?')->execute([$finalStatus, $entityId]);
        return;
    }

    if ($type === 'expense') {
        $pdo->prepare('UPDATE expense_reports SET status = ?, updated_at = NOW() WHERE id = ?')->execute([$finalStatus, $entityId]);
    }
}

function sync_entity_comment(PDO $pdo, string $type, int $entityId, array $approval): void
{
    $parts = [];
    if (trim((string)($approval['manager_comment'] ?? '')) !== '') {
        $parts[] = 'Manager: ' . trim((string)$approval['manager_comment']);
    }
    if (trim((string)($approval['district_comment'] ?? '')) !== '') {
        $parts[] = 'District Manager: ' . trim((string)$approval['district_comment']);
    }
    if (!$parts) return;
    $comment = implode("\n\n", $parts);

    if ($type === 'report') {
        $pdo->prepare('UPDATE reports SET manager_comment = ? WHERE id = ?')->execute([$comment, $entityId]);
        return;
    }

    if ($type === 'expense') {
        $pdo->prepare('UPDATE expense_reports SET manager_comment = ?, updated_at = NOW() WHERE id = ?')->execute([$comment, $entityId]);
    }
}

$ready = approvals_ready($pdo);
if (!$ready) {
    render_header('Approval Center');
    ?>
    <div class="hero">
        <div>
            <span class="eyebrow">Setup Needed</span>
            <h2>Approval Center tables are not installed yet.</h2>
            <p>Import the approval migration in phpMyAdmin, then reload this page.</p>
        </div>
    </div>
    <div class="card">
        <div class="section-title">
            <div><span class="eyebrow">Migration</span><h2>Required SQL file</h2></div>
        </div>
        <p class="muted">Import this file into the <strong>pharmastar_reports</strong> database:</p>
        <pre style="white-space:pre-wrap;background:#0f172a;color:#e2e8f0;padding:18px;border-radius:18px;overflow:auto">database/migrations/2026_05_26_create_approval_records.sql</pre>
    </div>
    <?php
    render_footer();
    exit;
}

$current = current_user();
$currentRole = $current['role'] ?? 'employee';
$currentUserId = (int)($current['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['approval_action'] ?? '') === 'review') {
    $type = $_POST['entity_type'] === 'expense' ? 'expense' : 'report';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $decision = normalize_status($_POST['decision'] ?? 'pending');
    $comment = trim((string)($_POST['comment'] ?? ''));

    if ($entityId <= 0) {
        flash('error', 'Invalid approval item.');
        header('Location: approvals.php');
        exit;
    }

    if ($type === 'report') {
        [$scopeSql, $scopeParams] = approval_scope_clause_for($pdo, 'r');
        $stmt = $pdo->prepare("SELECT r.id, r.user_id FROM reports r WHERE r.id = ? AND $scopeSql LIMIT 1");
        $stmt->execute(array_merge([$entityId], $scopeParams));
    } else {
        [$scopeSql, $scopeParams] = approval_scope_clause_for($pdo, 'er');
        $stmt = $pdo->prepare("SELECT er.id, er.user_id FROM expense_reports er WHERE er.id = ? AND $scopeSql LIMIT 1");
        $stmt->execute(array_merge([$entityId], $scopeParams));
    }

    $entity = $stmt->fetch();
    if (!$entity) {
        http_response_code(403);
        exit('Approval item not found or not allowed.');
    }

    ensure_approval_record($pdo, $type, $entityId, (int)$entity['user_id']);

    if ($currentRole === 'manager') {
        $update = $pdo->prepare('UPDATE approval_records SET manager_status = ?, manager_comment = ?, manager_user_id = ?, manager_reviewed_at = NOW(), updated_at = NOW() WHERE entity_type = ? AND entity_id = ?');
        $update->execute([$decision, $comment, $currentUserId, $type, $entityId]);
    } elseif ($currentRole === 'district_manager') {
        $update = $pdo->prepare('UPDATE approval_records SET district_status = ?, district_comment = ?, district_user_id = ?, district_reviewed_at = NOW(), updated_at = NOW() WHERE entity_type = ? AND entity_id = ?');
        $update->execute([$decision, $comment, $currentUserId, $type, $entityId]);
    }

    $stmt = $pdo->prepare('SELECT * FROM approval_records WHERE entity_type = ? AND entity_id = ? LIMIT 1');
    $stmt->execute([$type, $entityId]);
    $approval = $stmt->fetch() ?: [];
    $final = approval_final_status($approval);

    $pdo->prepare('UPDATE approval_records SET final_status = ?, updated_at = NOW() WHERE entity_type = ? AND entity_id = ?')->execute([$final, $type, $entityId]);
    update_entity_approval_status($pdo, $type, $entityId, $final);

    $approval['final_status'] = $final;
    sync_entity_comment($pdo, $type, $entityId, $approval);

    flash('success', approval_entity_label($type) . ' review saved. Final status: ' . approval_status_label_local($final) . '.');
    header('Location: approvals.php?tab=' . urlencode($_POST['return_tab'] ?? 'pending'));
    exit;
}

$items = [];

[$reportScopeSql, $reportScopeParams] = approval_scope_clause_for($pdo, 'r');
$stmt = $pdo->prepare("\n    SELECT\n        'report' AS entity_type,\n        r.id AS entity_id,\n        r.user_id,\n        r.doctor_name AS title,\n        r.hospital_name AS subtitle,\n        r.visit_datetime AS item_date,\n        r.status AS source_status,\n        NULL AS amount,\n        u.name AS employee_name,\n        ar.manager_status, ar.manager_comment, ar.manager_reviewed_at,\n        ar.district_status, ar.district_comment, ar.district_reviewed_at,\n        ar.final_status\n    FROM reports r\n    JOIN users u ON u.id = r.user_id\n    LEFT JOIN approval_records ar ON ar.entity_type = 'report' AND ar.entity_id = r.id\n    WHERE $reportScopeSql\n");
$stmt->execute($reportScopeParams);
$items = array_merge($items, $stmt->fetchAll());

if (approvals_expenses_ready($pdo)) {
    [$expenseScopeSql, $expenseScopeParams] = approval_scope_clause_for($pdo, 'er');
    $stmt = $pdo->prepare("\n        SELECT\n            'expense' AS entity_type,\n            er.id AS entity_id,\n            er.user_id,\n            er.title AS title,\n            DATE_FORMAT(er.report_month, '%M %Y') AS subtitle,\n            er.created_at AS item_date,\n            er.status AS source_status,\n            er.total_amount AS amount,\n            u.name AS employee_name,\n            ar.manager_status, ar.manager_comment, ar.manager_reviewed_at,\n            ar.district_status, ar.district_comment, ar.district_reviewed_at,\n            ar.final_status\n        FROM expense_reports er\n        JOIN users u ON u.id = er.user_id\n        LEFT JOIN approval_records ar ON ar.entity_type = 'expense' AND ar.entity_id = er.id\n        WHERE $expenseScopeSql\n    ");
    $stmt->execute($expenseScopeParams);
    $items = array_merge($items, $stmt->fetchAll());
}

foreach ($items as &$item) {
    $item['manager_status'] = $item['manager_status'] ?: 'pending';
    $item['district_status'] = $item['district_status'] ?: 'pending';
    $item['final_status'] = $item['final_status'] ?: approval_final_status($item);
}
unset($item);

usort($items, static function ($a, $b) {
    return strtotime((string)($b['item_date'] ?? '')) <=> strtotime((string)($a['item_date'] ?? ''));
});

$tab = $_GET['tab'] ?? 'pending';
$allowedTabs = ['pending', 'my_step', 'needs_changes', 'approved', 'all'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'pending';

$filtered = array_values(array_filter($items, static function ($item) use ($tab, $currentRole) {
    if ($tab === 'all') return true;
    if ($tab === 'approved') return ($item['final_status'] ?? 'pending') === 'approved';
    if ($tab === 'needs_changes') return ($item['final_status'] ?? 'pending') === 'needs_changes';
    if ($tab === 'my_step') {
        return $currentRole === 'manager'
            ? (($item['manager_status'] ?? 'pending') === 'pending')
            : (($item['district_status'] ?? 'pending') === 'pending');
    }
    return ($item['final_status'] ?? 'pending') === 'pending';
}));

$countPending = count(array_filter($items, static fn($i) => ($i['final_status'] ?? 'pending') === 'pending'));
$countNeeds = count(array_filter($items, static fn($i) => ($i['final_status'] ?? 'pending') === 'needs_changes'));
$countApproved = count(array_filter($items, static fn($i) => ($i['final_status'] ?? 'pending') === 'approved'));
$countMyStep = count(array_filter($items, static function ($i) use ($currentRole) {
    return $currentRole === 'manager'
        ? (($i['manager_status'] ?? 'pending') === 'pending')
        : (($i['district_status'] ?? 'pending') === 'pending');
}));

render_header('Approval Center');
?>

<style>
.approval-shell{display:grid;gap:20px}.approval-hero{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:space-between;gap:18px;padding:30px 34px;border:1px solid rgba(15,118,110,.16);border-radius:34px;background:radial-gradient(circle at 88% 16%,rgba(250,204,21,.20),transparent 30%),radial-gradient(circle at 14% 0%,rgba(20,184,166,.18),transparent 34%),linear-gradient(135deg,#fff 0%,#ecfffb 78%);box-shadow:0 22px 54px rgba(15,118,110,.09)}.approval-hero:before{content:"";position:absolute;inset:0 auto 0 0;width:7px;background:linear-gradient(180deg,#0f766e,#14b8a6,#facc15);border-radius:999px}.approval-hero h2{margin:4px 0 8px;font-size:clamp(30px,3vw,44px);letter-spacing:-.06em;color:#061f1c}.approval-hero p{margin:0;color:#59736d;font-weight:700;line-height:1.55}.approval-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}.approval-kpi{position:relative;overflow:hidden;min-height:118px;padding:22px;border:1px solid rgba(15,118,110,.14);border-radius:30px;background:linear-gradient(145deg,rgba(255,255,255,.98),rgba(250,255,253,.94));box-shadow:0 14px 34px rgba(15,118,110,.065)}.approval-kpi:before{content:"";position:absolute;top:0;left:18px;right:18px;height:5px;border-radius:999px;background:linear-gradient(90deg,#0f766e,#14b8a6,#facc15)}.approval-kpi span{display:block;margin-top:8px;color:#607872;font-size:12px;text-transform:uppercase;letter-spacing:.09em;font-weight:950}.approval-kpi strong{display:block;margin-top:10px;color:#061f1c;font-size:32px;line-height:1;letter-spacing:-.05em}.approval-tabs{display:flex;gap:10px;flex-wrap:wrap;padding:12px;border:1px solid rgba(15,118,110,.13);border-radius:28px;background:rgba(255,255,255,.78);box-shadow:0 12px 28px rgba(15,118,110,.055)}.approval-tab{display:inline-flex;align-items:center;gap:8px;min-height:42px;padding:10px 14px;border-radius:18px;border:1px solid rgba(15,118,110,.13);background:#fff;color:#0b4f48;font-weight:950}.approval-tab.active{background:linear-gradient(135deg,#0f766e,#14b8a6);color:#fff;border-color:#0f766e}.approval-list{display:grid;gap:14px}.approval-card{display:grid;gap:16px;padding:20px;border:1px solid rgba(15,118,110,.14);border-radius:32px;background:radial-gradient(circle at right top,rgba(20,184,166,.08),transparent 26%),linear-gradient(145deg,#fff,#fbfffe);box-shadow:0 14px 34px rgba(15,118,110,.065)}.approval-card-head{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:start}.approval-card h3{margin:4px 0 5px;font-size:22px;letter-spacing:-.04em;color:#061f1c}.approval-card p{margin:0;color:#607872;font-weight:750;line-height:1.5}.approval-type{display:inline-flex;align-items:center;min-height:30px;padding:6px 10px;border-radius:999px;background:#ecfdf5;border:1px solid #99f6e4;color:#0f766e;font-size:12px;font-weight:950}.approval-status-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.approval-step{padding:14px;border:1px solid rgba(15,118,110,.12);border-radius:22px;background:rgba(255,255,255,.78)}.approval-step span{display:block;color:#607872;font-size:11px;text-transform:uppercase;letter-spacing:.09em;font-weight:950}.approval-step strong{display:flex;align-items:center;gap:8px;margin-top:7px;color:#082f2b;font-size:15px}.approval-dot{width:10px;height:10px;border-radius:999px;background:#f59e0b}.approval-dot.approved{background:#16a34a}.approval-dot.needs_changes{background:#dc2626}.approval-actions{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:end}.approval-actions textarea{min-height:54px}.approval-action-buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.approval-link-row{display:flex;gap:8px;flex-wrap:wrap}.approval-empty{padding:30px;border:1px dashed rgba(15,118,110,.24);border-radius:30px;background:#f8fffd;text-align:center;color:#607872;font-weight:850}@media(max-width:1120px){.approval-kpis,.approval-status-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.approval-actions{grid-template-columns:1fr}.approval-action-buttons{justify-content:flex-start}.approval-hero{display:grid}}@media(max-width:720px){.approval-kpis,.approval-status-grid{grid-template-columns:1fr}.approval-card-head{grid-template-columns:1fr}.approval-action-buttons .btn,.approval-link-row .btn{width:100%}.approval-tabs{display:grid}.approval-tab{justify-content:center}}
</style>

<div class="approval-shell">
    <section class="approval-hero">
        <div>
            <span class="eyebrow">Two-Step Review</span>
            <h2>Approval Center</h2>
            <p>Review sales reports and expense reports through both Manager and District Manager approval steps.</p>
        </div>
        <div class="actions"><a class="btn ghost" href="reports.php">Reports</a><a class="btn ghost" href="expenses.php">Expenses</a></div>
    </section>

    <section class="approval-kpis">
        <div class="approval-kpi"><span>Pending Final</span><strong><?= number_format($countPending) ?></strong></div>
        <div class="approval-kpi"><span>My Step Pending</span><strong><?= number_format($countMyStep) ?></strong></div>
        <div class="approval-kpi"><span>Needs Changes</span><strong><?= number_format($countNeeds) ?></strong></div>
        <div class="approval-kpi"><span>Fully Approved</span><strong><?= number_format($countApproved) ?></strong></div>
    </section>

    <nav class="approval-tabs">
        <a class="approval-tab <?= $tab === 'pending' ? 'active' : '' ?>" href="approvals.php?tab=pending">Pending Final <span><?= number_format($countPending) ?></span></a>
        <a class="approval-tab <?= $tab === 'my_step' ? 'active' : '' ?>" href="approvals.php?tab=my_step">My Step <span><?= number_format($countMyStep) ?></span></a>
        <a class="approval-tab <?= $tab === 'needs_changes' ? 'active' : '' ?>" href="approvals.php?tab=needs_changes">Needs Changes <span><?= number_format($countNeeds) ?></span></a>
        <a class="approval-tab <?= $tab === 'approved' ? 'active' : '' ?>" href="approvals.php?tab=approved">Approved <span><?= number_format($countApproved) ?></span></a>
        <a class="approval-tab <?= $tab === 'all' ? 'active' : '' ?>" href="approvals.php?tab=all">All <span><?= number_format(count($items)) ?></span></a>
    </nav>

    <section class="approval-list">
        <?php if (!$filtered): ?>
            <div class="approval-empty">No approval items found for this view.</div>
        <?php endif; ?>

        <?php foreach ($filtered as $item): ?>
            <?php
            $type = $item['entity_type'];
            $viewUrl = $type === 'expense'
                ? 'expenses.php?action=view&id=' . (int)$item['entity_id']
                : 'report_view.php?id=' . (int)$item['entity_id'];
            $amountText = $type === 'expense' ? ' · ' . approval_money($item['amount'] ?? 0) : '';
            ?>
            <article class="approval-card">
                <div class="approval-card-head">
                    <div>
                        <span class="approval-type"><?= e(approval_entity_label($type)) ?> #<?= (int)$item['entity_id'] ?></span>
                        <h3><?= e($item['title'] ?: 'Untitled') ?></h3>
                        <p><?= e($item['subtitle'] ?: 'No details') ?><?= e($amountText) ?></p>
                        <p><?= e($item['employee_name']) ?> · <?= e(approval_safe_date($item['item_date'])) ?></p>
                    </div>
                    <span class="badge <?= e($item['final_status']) ?>"><?= e(approval_status_label_local($item['final_status'])) ?></span>
                </div>

                <div class="approval-status-grid">
                    <div class="approval-step">
                        <span>Manager Step</span>
                        <strong><i class="approval-dot <?= e($item['manager_status']) ?>"></i><?= e(approval_status_label_local($item['manager_status'])) ?></strong>
                        <?php if (!empty($item['manager_comment'])): ?><p><?= nl2br(e($item['manager_comment'])) ?></p><?php endif; ?>
                    </div>
                    <div class="approval-step">
                        <span>District Manager Step</span>
                        <strong><i class="approval-dot <?= e($item['district_status']) ?>"></i><?= e(approval_status_label_local($item['district_status'])) ?></strong>
                        <?php if (!empty($item['district_comment'])): ?><p><?= nl2br(e($item['district_comment'])) ?></p><?php endif; ?>
                    </div>
                    <div class="approval-step">
                        <span>Final Result</span>
                        <strong><i class="approval-dot <?= e($item['final_status']) ?>"></i><?= e(approval_status_label_local($item['final_status'])) ?></strong>
                        <p>Both steps must be approved before final approval is complete.</p>
                    </div>
                </div>

                <div class="approval-link-row">
                    <a class="btn small ghost" href="<?= e($viewUrl) ?>">Open Details</a>
                </div>

                <form class="approval-actions" method="post">
                    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                    <input type="hidden" name="approval_action" value="review">
                    <input type="hidden" name="entity_type" value="<?= e($type) ?>">
                    <input type="hidden" name="entity_id" value="<?= (int)$item['entity_id'] ?>">
                    <input type="hidden" name="return_tab" value="<?= e($tab) ?>">
                    <div class="field">
                        <label><?= $currentRole === 'manager' ? 'Manager Comment' : 'District Manager Comment' ?></label>
                        <textarea name="comment" placeholder="Optional review note"><?= e($currentRole === 'manager' ? ($item['manager_comment'] ?? '') : ($item['district_comment'] ?? '')) ?></textarea>
                    </div>
                    <div class="approval-action-buttons">
                        <button class="btn ghost" name="decision" value="pending">Keep Pending</button>
                        <button class="btn danger" name="decision" value="needs_changes">Needs Changes</button>
                        <button class="btn primary" name="decision" value="approved">Approve My Step</button>
                    </div>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
</div>

<?php render_footer(); ?>
