<?php
require __DIR__ . '/app/bootstrap.php'; require_login(); verify_csrf();

$visibleIds = visible_user_ids($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $id) {
        if ($visibleIds) {
            $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
            $pdo->prepare("DELETE FROM events WHERE id = ? AND user_id IN ($placeholders)")->execute(array_merge([$id], $visibleIds));
            audit_log($pdo, 'task_deleted', 'task', $id);
        }
        flash('success', 'Task deleted.');
        header('Location: tasks.php');
        exit;
    }

    $doctorId = (int)($_POST['doctor_id'] ?? 0);
    $doctorId = $doctorId > 0 ? $doctorId : null;
    $title = trim($_POST['title'] ?? '');
    if ($title === '') $title = 'Visit / Task';
    $start = str_replace('T', ' ', ($_POST['start'] ?? date('Y-m-d\TH:i'))) . ':00';
    $end = trim($_POST['end'] ?? '') !== '' ? str_replace('T', ' ', $_POST['end']) . ':00' : null;
    $city = trim($_POST['city'] ?? '');
    $notes = trim($_POST['notes'] ?? ($_POST['description'] ?? ''));

    $eventValues = [
        'user_id' => current_user()['id'],
        'title' => $title,
        'description' => $notes,
        'notes' => $notes,
        'city' => $city,
        'doctor_id' => $doctorId,
        'hospital_name' => trim($_POST['hospital_name'] ?? ''),
        'purpose' => trim($_POST['purpose'] ?? ''),
        'medicine_name' => trim($_POST['medicine_name'] ?? ''),
        'summary' => trim($_POST['summary'] ?? ''),
        'remarks' => trim($_POST['remarks'] ?? ''),
        'start' => $start,
        'end' => $end,
        'start_datetime' => $start,
        'end_datetime' => $end,
        'visit_datetime' => $start,
        'all_day' => isset($_POST['all_day']) ? 1 : 0,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($id) {
        unset($eventValues['user_id'], $eventValues['created_at']);
        update_dynamic($pdo, 'events', $eventValues, 'id = ?', [$id]);
        audit_log($pdo, 'task_updated', 'task', $id, [
            'title' => $title,
            'doctor_id' => $doctorId,
            'start' => $start,
        ]);
        flash('success', 'Task updated.');
    } else {
        $newTaskId = insert_dynamic($pdo, 'events', $eventValues);
        audit_log($pdo, 'task_created', 'task', $newTaskId, [
            'title' => $title,
            'doctor_id' => $doctorId,
            'start' => $start,
        ]);
        flash('success', 'Task created.');
    }
    header('Location: tasks.php');
    exit;
}

[$scopeSql, $scopeParams] = scope_clause($pdo, 'e');
$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');

$eventCols = table_columns($pdo, 'events');
$startColumn = in_array('start', $eventCols, true) ? 'start' : (in_array('start_datetime', $eventCols, true) ? 'start_datetime' : 'visit_datetime');
$selectCols = 'e.*';
$joinDoctor = column_exists($pdo, 'events', 'doctor_id') && table_columns($pdo, 'doctors_masterlist');
$sql = "SELECT $selectCols, u.name rep" . ($joinDoctor ? ', d.dr_name' : ', NULL dr_name') . " FROM events e JOIN users u ON u.id = e.user_id ";
if ($joinDoctor) $sql .= 'LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id ';
$sql .= "WHERE $scopeSql AND DATE(e.`$startColumn`) BETWEEN ? AND ? ORDER BY e.`$startColumn` ASC LIMIT 250";
$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge($scopeParams, [$from, $to]));
$tasks = $stmt->fetchAll();

$doctors = fetch_doctors($pdo);
$cities = fetch_doctor_cities($pdo);

$prefill = [
    'title' => '',
    'city' => '',
    'doctor_id' => 0,
    'hospital_name' => '',
    'purpose' => '',
    'medicine_name' => '',
    'notes' => '',
    'start' => date('Y-m-d\TH:i'),
    'end' => '',
];

$followupDays = (int)($_GET['days'] ?? 7);
if (!in_array($followupDays, [7, 14, 30], true)) {
    $followupDays = 7;
}

$prefillDoctorId = (int)($_GET['doctor'] ?? $_GET['doctor_id'] ?? 0);
$sourceReportId = (int)($_GET['report'] ?? $_GET['report_id'] ?? 0);

if (($_GET['followup'] ?? '') === '1' || $prefillDoctorId > 0 || $sourceReportId > 0) {
    $prefill['start'] = date('Y-m-d\T09:00', strtotime('+' . $followupDays . ' days'));
    $prefill['end'] = date('Y-m-d\T10:00', strtotime('+' . $followupDays . ' days'));
    $prefill['purpose'] = 'Follow-up Visit';

    $sourceReport = null;

    if ($sourceReportId > 0) {
        try {
            [$reportScopeSql, $reportScopeParams] = scope_clause($pdo, 'r');
            $stmt = $pdo->prepare("SELECT r.* FROM reports r WHERE r.id = ? AND $reportScopeSql LIMIT 1");
            $stmt->execute(array_merge([$sourceReportId], $reportScopeParams));
            $sourceReport = $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            $sourceReport = null;
        }

        if ($sourceReport) {
            if (column_exists($pdo, 'reports', 'doctor_id')) {
                $prefillDoctorId = (int)($sourceReport['doctor_id'] ?? 0);
            }

            $prefill['hospital_name'] = trim((string)($sourceReport['hospital_name'] ?? ''));
            $prefill['medicine_name'] = trim((string)($sourceReport['medicine_name'] ?? ''));
            $prefill['purpose'] = 'Follow-up: ' . trim((string)($sourceReport['purpose'] ?? 'Visit'));
            $summary = trim((string)($sourceReport['summary'] ?? ''));
            $remarks = trim((string)($sourceReport['remarks'] ?? ''));
            $prefill['notes'] = trim("Follow-up from Report #{$sourceReportId}.\n\nPrevious summary: {$summary}\n\nPrevious remarks: {$remarks}");
        }
    }

    $prefillDoctor = null;

    if ($prefillDoctorId > 0) {
        foreach ($doctors as $doctorOption) {
            if ((int)($doctorOption['id'] ?? 0) === $prefillDoctorId) {
                $prefillDoctor = $doctorOption;
                break;
            }
        }
    }

    if (!$prefillDoctor && $sourceReport) {
        $reportDoctorName = trim((string)($sourceReport['doctor_name'] ?? ''));
        $reportHospital = trim((string)($sourceReport['hospital_name'] ?? ''));

        foreach ($doctors as $doctorOption) {
            $optionName = trim((string)($doctorOption['dr_name'] ?? ''));
            $optionHospital = trim((string)($doctorOption['hospital_address'] ?? ''));

            if ($reportDoctorName !== '' && strcasecmp($optionName, $reportDoctorName) === 0) {
                $prefillDoctor = $doctorOption;
                $prefillDoctorId = (int)($doctorOption['id'] ?? 0);
                break;
            }

            if ($reportDoctorName !== '' && $reportHospital !== '' && stripos($optionName, $reportDoctorName) !== false && stripos($reportHospital, $optionHospital) !== false) {
                $prefillDoctor = $doctorOption;
                $prefillDoctorId = (int)($doctorOption['id'] ?? 0);
                break;
            }
        }
    }

    if ($prefillDoctor) {
        $doctorName = trim((string)($prefillDoctor['dr_name'] ?? 'Doctor'));
        $prefill['doctor_id'] = (int)($prefillDoctor['id'] ?? 0);
        $prefill['city'] = trim((string)($prefillDoctor['place'] ?? ''));
        $prefill['hospital_name'] = $prefill['hospital_name'] !== '' ? $prefill['hospital_name'] : trim((string)($prefillDoctor['hospital_address'] ?? ''));
        $prefill['title'] = 'Follow-up Visit - ' . $doctorName;

        if ($prefill['notes'] === '') {
            $prefill['notes'] = 'Follow-up meeting with ' . $doctorName . '. Review previous visit history and update next steps after the visit.';
        }
    } elseif ($sourceReport) {
        $doctorName = trim((string)($sourceReport['doctor_name'] ?? 'Doctor'));
        $prefill['title'] = 'Follow-up Visit - ' . $doctorName;
    }

    if ($prefill['title'] === '') {
        $prefill['title'] = 'Follow-up Visit';
    }
}

render_header('Task Center');
?>
<div class="hero"><div><span class="eyebrow">Tasks</span><h2>Team schedule and field tasks</h2><p>Create tasks here with proper city-first doctor filtering.</p></div></div>
<div class="grid grid-2">
  <form class="card" method="post">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <div class="section-title"><div><span class="eyebrow">Create Task</span><h2>New schedule item</h2></div></div>
    <div class="form-grid">
      <div class="field full"><label>Title</label><input class="input" name="title" value="<?= e($prefill['title']) ?>" placeholder="Visit, meeting, delivery, follow-up" required></div>
      <div class="field"><label>City / Area</label><select name="city" data-task-city required><option value="">Select city first</option><?php foreach($cities as $city): ?><option value="<?= e($city) ?>" <?= $prefill['city'] === $city ? 'selected' : '' ?>><?= e($city) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Doctor</label><select name="doctor_id" data-task-doctor <?= $prefill['city'] !== '' ? '' : 'disabled' ?>><option value=""><?= $prefill['city'] !== '' ? 'Select doctor' : 'Select city first' ?></option><?php foreach($doctors as $d): ?><option value="<?= (int)$d['id'] ?>" data-city="<?= e($d['place'] ?? '') ?>" data-hospital="<?= e($d['hospital_address'] ?? '') ?>" <?= (int)$prefill['doctor_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e(($d['dr_name'] ?? 'Doctor') . ' · ' . ($d['place'] ?? '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label>Hospital / Clinic</label><input class="input" name="hospital_name" value="<?= e($prefill['hospital_name']) ?>" data-task-hospital placeholder="Auto-fills from selected doctor"></div>
      <div class="field"><label>Purpose</label><input class="input" name="purpose" value="<?= e($prefill['purpose']) ?>" placeholder="Follow-up, demo, collection, visit"></div>
      <div class="field"><label>Start</label><input class="input" type="datetime-local" name="start" required value="<?= e($prefill['start']) ?>"></div>
      <div class="field"><label>End</label><input class="input" type="datetime-local" name="end" value="<?= e($prefill['end']) ?>"></div>
      <div class="field full"><label>Notes</label><textarea name="notes" placeholder="Task details, reminders, products discussed, or next step"><?= e($prefill['notes']) ?></textarea></div>
    </div>
    <br><button class="btn primary" style="width:100%"><?= ($prefill['title'] !== '') ? 'Create Follow-Up Task' : 'Create Task' ?></button>
  </form>
  <div class="card">
    <div class="section-title"><div><span class="eyebrow">Filters</span><h2>Task list</h2></div></div>
    <form class="filters" style="grid-template-columns:1fr 1fr auto"><div class="field"><label>From</label><input class="input" type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label>To</label><input class="input" type="date" name="to" value="<?= e($to) ?>"></div><button class="btn primary">Apply</button></form>
    <br>
    <?php if(!$tasks): ?>
      <div class="empty tasks-empty">
        <p class="empty-note">No scheduled visits or tasks were found for this date range.</p>
        <div class="empty-actions">
          <a class="btn primary" href="tasks.php">Create Task</a>
          <a class="btn ghost" href="tasks.php?from=<?= e(date('Y-m-d')) ?>&to=<?= e(date('Y-m-d', strtotime('+14 days'))) ?>">Next 14 Days</a>
        </div>
      </div>
    <?php else: ?>
      <div class="detail-list">
        <?php foreach($tasks as $t):
          $taskStart = row_value($t, $startColumn, row_value($t, 'visit_datetime', date('Y-m-d H:i:s')));
          $taskPlace = ($t['city'] ?? '') ?: (($t['hospital_name'] ?? '') ?: ($t['dr_name'] ?? ''));
        ?>
          <div class="detail task-row">
            <div>
              <span><?= e(date('M d, Y g:i A', strtotime((string)$taskStart))) ?> · <?= e($t['rep'] ?? '') ?></span>
              <strong><?= e($t['title'] ?? 'Task') ?></strong>
              <p class="muted"><?= e($taskPlace) ?></p>
            </div>
            <div class="detail-actions">
              <a class="btn small primary" href="report_form.php?task=<?= (int)$t['id'] ?>">Generate Report</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<script>window.TASK_DOCTORS=<?= json_encode($doctors, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>; window.TASK_PREFILL_DOCTOR_ID=<?= (int)$prefill['doctor_id'] ?>; window.TASK_PREFILL_CITY=<?= json_encode($prefill['city'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php render_footer(); ?>
