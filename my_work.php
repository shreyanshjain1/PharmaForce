<?php
require __DIR__ . '/app/bootstrap.php';

require_login();
verify_csrf();

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$userRole = (string)($u['role'] ?? 'employee');
$todayStart = date('Y-m-d 00:00:00');
$tomorrowStart = date('Y-m-d 00:00:00', strtotime('+1 day'));
$next14 = date('Y-m-d 23:59:59', strtotime('+14 days'));
$monthStart = date('Y-m-01 00:00:00');
$nextMonth = date('Y-m-01 00:00:00', strtotime('+1 month'));

function my_work_date(?string $value): string {
    if (!$value) return 'Not scheduled';
    $time = strtotime((string)$value);
    return $time ? date('M d, Y g:i A', $time) : (string)$value;
}

function my_work_money(float|int|string|null $value): string {
    return '₱' . number_format((float)$value, 2);
}

function my_work_short(?string $value, int $length = 110): string {
    $value = trim((string)$value);
    if ($value === '') return 'No details provided.';
    if (function_exists('mb_strlen') && mb_strlen($value) > $length) {
        return mb_substr($value, 0, $length - 3) . '...';
    }
    if (strlen($value) > $length) return substr($value, 0, $length - 3) . '...';
    return $value;
}

$stats = [
    'today_tasks' => 0,
    'pending_reports' => 0,
    'needs_changes' => 0,
    'pending_expenses' => 0,
    'month_reports' => 0,
    'approval_queue' => 0,
];

$tasks = [];
$reportsNeedingChanges = [];
$pendingReports = [];
$pendingExpenses = [];
$approvalItems = [];
$recentReports = [];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND visit_datetime >= ? AND visit_datetime < ?");
    $stmt->execute([$userId, $monthStart, $nextMonth]);
    $stats['month_reports'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status = 'pending'");
    $stmt->execute([$userId]);
    $stats['pending_reports'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE user_id = ? AND status = 'needs_changes'");
    $stmt->execute([$userId]);
    $stats['needs_changes'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT id, doctor_name, hospital_name, visit_datetime, purpose, medicine_name, status FROM reports WHERE user_id = ? AND status = 'needs_changes' ORDER BY COALESCE(visit_datetime, created_at) DESC LIMIT 5");
    $stmt->execute([$userId]);
    $reportsNeedingChanges = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, doctor_name, hospital_name, visit_datetime, purpose, medicine_name, status FROM reports WHERE user_id = ? AND status = 'pending' ORDER BY COALESCE(visit_datetime, created_at) DESC LIMIT 5");
    $stmt->execute([$userId]);
    $pendingReports = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, doctor_name, hospital_name, visit_datetime, status FROM reports WHERE user_id = ? ORDER BY COALESCE(visit_datetime, created_at) DESC, id DESC LIMIT 5");
    $stmt->execute([$userId]);
    $recentReports = $stmt->fetchAll();
} catch (Throwable $e) {
    // Keep page usable even if optional report columns differ.
}

try {
    if (table_columns($pdo, 'events')) {
        $eventCols = table_columns($pdo, 'events');
        $eventStartColumn = in_array('start', $eventCols, true) ? 'start' : (in_array('start_datetime', $eventCols, true) ? 'start_datetime' : (in_array('visit_datetime', $eventCols, true) ? 'visit_datetime' : 'created_at'));
        $eventEndColumn = in_array('end', $eventCols, true) ? 'end' : (in_array('end_datetime', $eventCols, true) ? 'end_datetime' : null);
        $eventDescriptionColumn = in_array('description', $eventCols, true) ? 'description' : (in_array('notes', $eventCols, true) ? 'notes' : null);
        $taskOwnerSql = in_array('assigned_user_id', $eventCols, true) ? '(e.assigned_user_id = ? OR e.user_id = ?)' : 'e.user_id = ?';
        $taskOwnerParams = in_array('assigned_user_id', $eventCols, true) ? [$userId, $userId] : [$userId];
        $statusFilter = in_array('status', $eventCols, true) ? " AND (e.status IS NULL OR e.status NOT IN ('done','completed','cancelled'))" : '';
        $selectEnd = $eventEndColumn ? "e.`$eventEndColumn` AS task_end" : "NULL AS task_end";
        $selectDescription = $eventDescriptionColumn ? "e.`$eventDescriptionColumn` AS task_notes" : "'' AS task_notes";
        $joinDoctor = in_array('doctor_id', $eventCols, true) && table_columns($pdo, 'doctors_masterlist');
        $sql = "SELECT e.*, e.`$eventStartColumn` AS task_start, $selectEnd, $selectDescription";
        $sql .= $joinDoctor ? ", d.dr_name, d.speciality, d.hospital_address, d.place" : ", NULL AS dr_name, NULL AS speciality, NULL AS hospital_address, NULL AS place";
        $sql .= " FROM events e ";
        if ($joinDoctor) $sql .= "LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id ";
        $sql .= "WHERE $taskOwnerSql AND e.`$eventStartColumn` >= ? AND e.`$eventStartColumn` <= ? $statusFilter ORDER BY e.`$eventStartColumn` ASC LIMIT 8";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($taskOwnerParams, [$todayStart, $next14]));
        $tasks = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events e WHERE $taskOwnerSql AND e.`$eventStartColumn` >= ? AND e.`$eventStartColumn` < ? $statusFilter");
        $stmt->execute(array_merge($taskOwnerParams, [$todayStart, $tomorrowStart]));
        $stats['today_tasks'] = (int)$stmt->fetchColumn();
    }
} catch (Throwable $e) {
    $tasks = [];
}

try {
    if (table_columns($pdo, 'expense_reports')) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM expense_reports WHERE user_id = ? AND status = 'pending'");
        $stmt->execute([$userId]);
        $stats['pending_expenses'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT id, report_month, title, status, total_amount, created_at FROM expense_reports WHERE user_id = ? AND status IN ('pending','needs_changes') ORDER BY created_at DESC LIMIT 5");
        $stmt->execute([$userId]);
        $pendingExpenses = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $pendingExpenses = [];
}

if (is_manager()) {
    try {
        if (table_columns($pdo, 'approval_records')) {
            $stepColumn = $userRole === 'district_manager' ? 'district_status' : 'manager_status';
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM approval_records WHERE final_status = 'pending' AND `$stepColumn` = 'pending'");
            $stmt->execute();
            $stats['approval_queue'] = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("\n                SELECT ar.*, u.name AS submitted_by_name\n                FROM approval_records ar\n                LEFT JOIN users u ON u.id = ar.submitted_by_user_id\n                WHERE ar.final_status = 'pending' AND ar.`$stepColumn` = 'pending'\n                ORDER BY ar.created_at ASC\n                LIMIT 6\n            ");
            $stmt->execute();
            $approvalItems = $stmt->fetchAll();
        }
    } catch (Throwable $e) {
        $approvalItems = [];
    }
}

$priorityCount = count($reportsNeedingChanges) + count($pendingExpenses) + count($approvalItems);

render_header('My Work Center');
?>
<style>
.my-work-shell{display:grid;gap:20px}.work-hero{position:relative;overflow:hidden;display:flex;justify-content:space-between;align-items:center;gap:20px;padding:30px 34px;border:1px solid rgba(15,118,110,.16);border-radius:34px;background:radial-gradient(circle at 88% 12%,rgba(250,204,21,.18),transparent 30%),radial-gradient(circle at 18% -8%,rgba(20,184,166,.18),transparent 34%),linear-gradient(135deg,#fff,#ecfffb 78%);box-shadow:0 22px 54px rgba(15,118,110,.09)}.work-hero:before{content:"";position:absolute;inset:0 auto 0 0;width:7px;background:linear-gradient(180deg,#0f766e,#14b8a6,#facc15);border-radius:999px}.work-hero h2{margin:5px 0 8px;font-size:clamp(32px,3.3vw,48px);line-height:1;letter-spacing:-.06em;color:#061f1c}.work-hero p{margin:0;color:#59736d;font-size:16px;font-weight:700;line-height:1.55}.work-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.work-kpis{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px}.work-kpi{position:relative;overflow:hidden;min-height:124px;padding:22px;border:1px solid rgba(15,118,110,.14);border-radius:30px;background:linear-gradient(145deg,rgba(255,255,255,.98),rgba(250,255,253,.94));box-shadow:0 14px 34px rgba(15,118,110,.065)}.work-kpi:before{content:"";position:absolute;top:0;left:18px;right:18px;height:5px;border-radius:999px;background:linear-gradient(90deg,#0f766e,#14b8a6,#facc15)}.work-kpi span{display:block;margin-top:8px;color:#607872;font-size:12px;text-transform:uppercase;letter-spacing:.09em;font-weight:950}.work-kpi strong{display:block;margin-top:10px;color:#061f1c;font-size:32px;line-height:1;letter-spacing:-.05em}.work-grid{display:grid;grid-template-columns:minmax(0,1.18fr) minmax(360px,.82fr);gap:18px;align-items:start}.work-panel{padding:24px;border:1px solid rgba(15,118,110,.14);border-radius:32px;background:radial-gradient(circle at right top,rgba(20,184,166,.09),transparent 26%),linear-gradient(145deg,#fff,#fbfffe);box-shadow:0 14px 34px rgba(15,118,110,.065)}.work-panel-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding-bottom:14px;margin-bottom:14px;border-bottom:1px solid rgba(15,118,110,.10)}.work-panel-head h3{margin:3px 0 0;color:#061f1c;font-size:22px;letter-spacing:-.04em}.work-list{display:grid;gap:12px}.work-item{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:14px;align-items:center;padding:16px;border:1px solid rgba(15,118,110,.12);border-radius:24px;background:linear-gradient(145deg,#fff,#fbfffe);transition:transform .18s ease,box-shadow .18s ease,border-color .18s ease}.work-item:hover{transform:translateY(-1px);border-color:rgba(20,184,166,.34);box-shadow:0 14px 28px rgba(15,118,110,.07)}.work-item strong{display:block;color:#082f2b;font-weight:950}.work-item p{margin:5px 0 0;color:#607872;font-size:13px;font-weight:750;line-height:1.45}.work-meta{display:flex;gap:7px;flex-wrap:wrap;margin-top:9px}.work-chip{display:inline-flex;align-items:center;border:1px solid rgba(15,118,110,.14);background:#f8fffd;color:#0b4f48;border-radius:999px;padding:6px 9px;font-size:11px;font-weight:950}.work-chip.warn{background:#fff7ed;border-color:#fed7aa;color:#b45309}.work-chip.bad{background:#fff1f2;border-color:#fecdd3;color:#b91c1c}.work-item-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.quick-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.quick-card{padding:18px;border:1px solid rgba(15,118,110,.12);border-radius:26px;background:linear-gradient(145deg,#fff,#fbfffe)}.quick-card span{display:block;color:#607872;font-size:11px;text-transform:uppercase;letter-spacing:.09em;font-weight:950}.quick-card strong{display:block;margin-top:8px;color:#082f2b;font-size:15px;line-height:1.35}.work-empty{padding:24px;text-align:center;border:1px dashed rgba(15,118,110,.24);border-radius:24px;background:#f8fffd;color:#607872;font-weight:850}.priority-dot{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:999px;background:#fff7ed;color:#b45309;border:1px solid #fed7aa;font-size:13px;font-weight:950}@media(max-width:1280px){.work-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}.work-grid{grid-template-columns:1fr}}@media(max-width:760px){.work-hero{display:grid;padding:24px}.work-actions,.work-actions .btn{width:100%}.work-kpis,.quick-grid{grid-template-columns:1fr}.work-item{grid-template-columns:1fr}.work-item-actions,.work-item-actions .btn{width:100%;justify-content:stretch}}
</style>

<div class="my-work-shell">
  <section class="work-hero">
    <div>
      <span class="eyebrow">Daily Field Workspace</span>
      <h2>My Work Center</h2>
      <p>One focused place for today’s tasks, pending reports, expense follow-ups, and review actions.</p>
    </div>
    <div class="work-actions">
      <a class="btn ghost" href="tasks.php">Open Tasks</a>
      <a class="btn ghost" href="expenses.php">Expenses</a>
      <a class="btn primary" href="report_form.php">New Report</a>
    </div>
  </section>

  <section class="work-kpis">
    <div class="work-kpi"><span>Today Tasks</span><strong><?= number_format($stats['today_tasks']) ?></strong></div>
    <div class="work-kpi"><span>Pending Reports</span><strong><?= number_format($stats['pending_reports']) ?></strong></div>
    <div class="work-kpi"><span>Needs Changes</span><strong><?= number_format($stats['needs_changes']) ?></strong></div>
    <div class="work-kpi"><span>Pending Expenses</span><strong><?= number_format($stats['pending_expenses']) ?></strong></div>
    <div class="work-kpi"><span>This Month</span><strong><?= number_format($stats['month_reports']) ?></strong></div>
  </section>

  <section class="work-grid">
    <div class="work-panel">
      <div class="work-panel-head">
        <div><span class="eyebrow">Schedule</span><h3>Upcoming tasks</h3></div>
        <a class="btn small ghost" href="tasks.php">Task Center</a>
      </div>
      <?php if (!$tasks): ?>
        <div class="work-empty">No upcoming tasks assigned to you for the next 14 days.</div>
      <?php else: ?>
        <div class="work-list">
          <?php foreach ($tasks as $task):
            $doctor = trim((string)($task['dr_name'] ?? '')) ?: preg_replace('/^visit:\s*/i', '', (string)($task['title'] ?? 'Task'));
            $hospital = trim((string)($task['hospital_address'] ?? ($task['hospital_name'] ?? '')));
            $taskStart = (string)($task['task_start'] ?? '');
            $taskDate = $taskStart ? date('Y-m-d', strtotime($taskStart)) : '';
          ?>
            <div class="work-item">
              <div>
                <strong><?= e($task['title'] ?? 'Scheduled Task') ?></strong>
                <p><?= e($doctor ?: 'Doctor not assigned') ?><?= $hospital ? ' · ' . e($hospital) : '' ?></p>
                <div class="work-meta">
                  <span class="work-chip <?= $taskDate === date('Y-m-d') ? 'warn' : '' ?>"><?= e(my_work_date($taskStart)) ?></span>
                  <?php if (!empty($task['place'])): ?><span class="work-chip"><?= e((string)$task['place']) ?></span><?php endif; ?>
                </div>
              </div>
              <div class="work-item-actions">
                <a class="btn small ghost" href="tasks.php?view=<?= (int)$task['id'] ?>">View</a>
                <a class="btn small primary" href="report_form.php?task=<?= (int)$task['id'] ?>">Generate Report</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <aside class="work-panel">
      <div class="work-panel-head">
        <div><span class="eyebrow">Priority</span><h3>Needs action</h3></div>
        <span class="priority-dot"><?= number_format($priorityCount) ?></span>
      </div>
      <div class="work-list">
        <?php if (!$reportsNeedingChanges && !$pendingExpenses && !$approvalItems): ?>
          <div class="work-empty">No urgent follow-ups right now.</div>
        <?php endif; ?>

        <?php foreach ($reportsNeedingChanges as $report): ?>
          <div class="work-item">
            <div>
              <strong>Report needs changes</strong>
              <p><?= e($report['doctor_name'] ?? 'Doctor') ?> · <?= e($report['hospital_name'] ?? 'Hospital') ?></p>
              <div class="work-meta"><span class="work-chip bad">Needs Changes</span><span class="work-chip"><?= e(my_work_date($report['visit_datetime'] ?? null)) ?></span></div>
            </div>
            <div class="work-item-actions"><a class="btn small primary" href="report_form.php?id=<?= (int)$report['id'] ?>">Fix Report</a></div>
          </div>
        <?php endforeach; ?>

        <?php foreach ($pendingExpenses as $expense): ?>
          <div class="work-item">
            <div>
              <strong><?= e($expense['status'] === 'needs_changes' ? 'Expense needs changes' : 'Expense pending review') ?></strong>
              <p><?= e($expense['title'] ?? 'Liquidation of Expenses') ?> · <?= e(my_work_money($expense['total_amount'] ?? 0)) ?></p>
              <div class="work-meta"><span class="work-chip <?= $expense['status'] === 'needs_changes' ? 'bad' : 'warn' ?>"><?= e(status_label((string)$expense['status'])) ?></span><span class="work-chip"><?= e(date('M Y', strtotime((string)$expense['report_month']))) ?></span></div>
            </div>
            <div class="work-item-actions"><a class="btn small ghost" href="expenses.php?action=view&id=<?= (int)$expense['id'] ?>">View</a></div>
          </div>
        <?php endforeach; ?>

        <?php foreach ($approvalItems as $approval): ?>
          <div class="work-item">
            <div>
              <strong><?= e(ucfirst((string)$approval['entity_type'])) ?> awaiting your approval</strong>
              <p>Submitted by <?= e($approval['submitted_by_name'] ?? 'Unknown user') ?></p>
              <div class="work-meta"><span class="work-chip warn">Approval Step</span><span class="work-chip"><?= e(my_work_date($approval['created_at'] ?? null)) ?></span></div>
            </div>
            <div class="work-item-actions"><a class="btn small primary" href="approvals.php">Review</a></div>
          </div>
        <?php endforeach; ?>
      </div>
    </aside>
  </section>

  <section class="work-grid">
    <div class="work-panel">
      <div class="work-panel-head">
        <div><span class="eyebrow">Follow-up</span><h3>Pending reports</h3></div>
        <a class="btn small ghost" href="reports.php">Reports</a>
      </div>
      <?php if (!$pendingReports): ?>
        <div class="work-empty">No pending reports submitted by you.</div>
      <?php else: ?>
        <div class="work-list">
          <?php foreach ($pendingReports as $report): ?>
            <a class="work-item" href="report_view.php?id=<?= (int)$report['id'] ?>">
              <div><strong><?= e($report['doctor_name'] ?? 'Doctor') ?></strong><p><?= e($report['hospital_name'] ?? 'Hospital') ?> · <?= e(my_work_date($report['visit_datetime'] ?? null)) ?></p></div>
              <span class="badge pending">Pending</span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="work-panel">
      <div class="work-panel-head"><div><span class="eyebrow">Shortcuts</span><h3>Quick access</h3></div></div>
      <div class="quick-grid">
        <a class="quick-card" href="report_form.php"><span>Report</span><strong>Create a field report with signature and attachment support.</strong></a>
        <a class="quick-card" href="tasks.php"><span>Task</span><strong>Open scheduled work and generate reports from assigned visits.</strong></a>
        <a class="quick-card" href="expenses.php?action=new"><span>Expense</span><strong>Submit liquidation of expenses for review.</strong></a>
        <a class="quick-card" href="doctors.php"><span>Doctors</span><strong>Find doctors and create prefilled reports.</strong></a>
      </div>
    </div>
  </section>
</div>
<?php render_footer(); ?>
