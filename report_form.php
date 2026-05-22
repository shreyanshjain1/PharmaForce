<?php
require __DIR__ . '/app/bootstrap.php';
require_login();
verify_csrf();

$id = (int)($_GET['id'] ?? 0);
$doctorIdFromUrl = (int)($_GET['doctor'] ?? 0);
$taskIdFromUrl = (int)($_GET['task'] ?? 0);
$report = null;
$prefill = [
    'doctor_id' => 0,
    'doctor_name' => '',
    'doctor_email' => '',
    'hospital_name' => '',
    'purpose' => 'Doctor Visit',
    'medicine_name' => '',
    'visit_datetime' => date('Y-m-d\TH:i'),
    'summary' => '',
    'remarks' => '',
    'source_note' => '',
];

function build_hospital_value(array $doctor): string
{
    return trim(implode(', ', array_filter([
        $doctor['hospital_address'] ?? '',
        $doctor['place'] ?? '',
    ])));
}

function apply_doctor_prefill(PDO $pdo, int $doctorId, array &$prefill): void
{
    if ($doctorId <= 0) return;
    $stmt = $pdo->prepare('SELECT * FROM doctors_masterlist WHERE id = ? LIMIT 1');
    $stmt->execute([$doctorId]);
    $doctor = $stmt->fetch();
    if (!$doctor) return;

    $prefill['doctor_id'] = (int)$doctor['id'];
    $prefill['doctor_name'] = (string)($doctor['dr_name'] ?? '');
    $prefill['doctor_email'] = (string)($doctor['email'] ?? '');
    $prefill['hospital_name'] = build_hospital_value($doctor);
    if (!empty($doctor['speciality'])) {
        $prefill['purpose'] = 'Visit / Coverage';
        $prefill['summary'] = 'Doctor specialty: ' . $doctor['speciality'];
    }
}

function fetch_task_prefill(PDO $pdo, int $taskId): ?array
{
    if ($taskId <= 0) return null;
    [$scopeSql, $scopeParams] = scope_clause($pdo, 'e');
    $eventCols = table_columns($pdo, 'events');
    if (!$eventCols) return null;

    $startColumn = in_array('start', $eventCols, true) ? 'start' : (in_array('start_datetime', $eventCols, true) ? 'start_datetime' : 'visit_datetime');
    $descriptionColumn = in_array('description', $eventCols, true) ? 'description' : (in_array('notes', $eventCols, true) ? 'notes' : null);
    $selectDescription = $descriptionColumn ? 'e.`' . $descriptionColumn . '` AS task_description,' : "'' AS task_description,";
    $joinDoctor = in_array('doctor_id', $eventCols, true) && table_columns($pdo, 'doctors_masterlist');

    $sql = "SELECT e.*, e.`$startColumn` AS task_start, $selectDescription u.name AS rep_name";
    $sql .= $joinDoctor ? ', d.dr_name, d.email AS doctor_email_master, d.hospital_address, d.place, d.speciality' : ", NULL AS dr_name, NULL AS doctor_email_master, NULL AS hospital_address, NULL AS place, NULL AS speciality";
    $sql .= ' FROM events e JOIN users u ON u.id = e.user_id ';
    if ($joinDoctor) $sql .= 'LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id ';
    $sql .= "WHERE e.id = ? AND $scopeSql LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$taskId], $scopeParams));
    return $stmt->fetch() ?: null;
}

if ($id) {
    [$scopeSql, $scopeParams] = scope_clause($pdo, 'r');
    $stmt = $pdo->prepare("SELECT r.* FROM reports r WHERE r.id = ? AND $scopeSql");
    $stmt->execute(array_merge([$id], $scopeParams));
    $report = $stmt->fetch();
    if (!$report) { http_response_code(404); exit('Report not found'); }
}

if (!$id && $doctorIdFromUrl > 0) {
    apply_doctor_prefill($pdo, $doctorIdFromUrl, $prefill);
    $prefill['source_note'] = 'Doctor details were loaded from the masterlist.';
}

if (!$id && $taskIdFromUrl > 0) {
    $task = fetch_task_prefill($pdo, $taskIdFromUrl);
    if ($task) {
        $taskDoctorId = (int)($task['doctor_id'] ?? 0);
        if ($taskDoctorId > 0) {
            apply_doctor_prefill($pdo, $taskDoctorId, $prefill);
        }

        $taskTitle = trim((string)($task['title'] ?? ''));
        $fallbackDoctorName = preg_replace('/^visit:\s*/i', '', $taskTitle);
        $doctorName = trim((string)($task['dr_name'] ?? '')) ?: ($fallbackDoctorName ?: '');
        $hospital = trim((string)($task['hospital_name'] ?? '')) ?: build_hospital_value([
            'hospital_address' => $task['hospital_address'] ?? '',
            'place' => $task['place'] ?? ($task['city'] ?? ''),
        ]);
        $taskDescription = trim((string)($task['task_description'] ?? ''));
        $taskSummary = trim((string)($task['summary'] ?? ''));
        $taskRemarks = trim((string)($task['remarks'] ?? ''));
        $taskStart = trim((string)($task['task_start'] ?? ''));

        if ($doctorName !== '') $prefill['doctor_name'] = $doctorName;
        if (!empty($task['doctor_email_master'])) $prefill['doctor_email'] = (string)$task['doctor_email_master'];
        if ($hospital !== '') $prefill['hospital_name'] = $hospital;
        if (!empty($task['purpose'])) $prefill['purpose'] = (string)$task['purpose'];
        elseif ($taskTitle !== '') $prefill['purpose'] = $taskTitle;
        if (!empty($task['medicine_name'])) $prefill['medicine_name'] = (string)$task['medicine_name'];
        if ($taskStart !== '') $prefill['visit_datetime'] = date('Y-m-d\TH:i', strtotime($taskStart));

        $summaryParts = array_filter([
            $taskSummary,
            $taskDescription !== '' ? 'Task notes: ' . $taskDescription : '',
            !empty($task['city']) ? 'City / Area: ' . $task['city'] : '',
        ]);
        if ($summaryParts) $prefill['summary'] = implode("\n", $summaryParts);
        if ($taskRemarks !== '') $prefill['remarks'] = $taskRemarks;
        $prefill['source_note'] = 'This report was started from Task #' . (int)$task['id'] . '. Doctor, place, schedule, purpose, and notes were pre-filled where available.';
    } else {
        flash('warning', 'Task was not found or you do not have access to it. You can still create a report manually.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $visitDate = str_replace('T', ' ', ($_POST['visit_datetime'] ?? date('Y-m-d\TH:i'))) . ':00';
    $signaturePath = save_base64_image($_POST['signature_data'] ?? '', 'uploads/signatures', 'signature');
    $attachmentPath = save_uploaded_file('attachment', 'uploads/attachments');

    $values = [
        'user_id' => current_user()['id'],
        'doctor_name' => trim($_POST['doctor_name'] ?? ''),
        'doctor_email' => trim($_POST['doctor_email'] ?? ''),
        'purpose' => trim($_POST['purpose'] ?? ''),
        'medicine_name' => trim($_POST['medicine_name'] ?? ''),
        'hospital_name' => trim($_POST['hospital_name'] ?? ''),
        'visit_datetime' => $visitDate,
        'summary' => trim($_POST['summary'] ?? ''),
        'remarks' => trim($_POST['remarks'] ?? ''),
        'status' => $report['status'] ?? 'pending',
        'created_at' => date('Y-m-d H:i:s'),
    ];

    if ($signaturePath) $values['signature_path'] = $signaturePath;
    if ($attachmentPath) $values['attachment_path'] = $attachmentPath;

    if ($id && $report) {
        unset($values['user_id'], $values['status'], $values['created_at']);
        update_dynamic($pdo, 'reports', $values, 'id = ?', [$id]);
        flash('success', 'Report updated.');
        header('Location: report_view.php?id=' . $id);
        exit;
    }

    $newReportId = insert_dynamic($pdo, 'reports', $values);
    flash('success', 'Report created.');
    header('Location: report_view.php?id=' . $newReportId);
    exit;
}

$doctors = fetch_doctors($pdo);
$values = [
    'doctor_id' => $report['doctor_id'] ?? $prefill['doctor_id'],
    'doctor_name' => $report['doctor_name'] ?? $prefill['doctor_name'],
    'doctor_email' => $report['doctor_email'] ?? $prefill['doctor_email'],
    'hospital_name' => $report['hospital_name'] ?? $prefill['hospital_name'],
    'purpose' => $report['purpose'] ?? $prefill['purpose'],
    'medicine_name' => $report['medicine_name'] ?? $prefill['medicine_name'],
    'visit_datetime' => $report ? date('Y-m-d\TH:i', strtotime($report['visit_datetime'])) : $prefill['visit_datetime'],
    'summary' => $report['summary'] ?? $prefill['summary'],
    'remarks' => $report['remarks'] ?? $prefill['remarks'],
];

render_header($id ? 'Edit Report' : 'Create Report');
?>
<div class="hero">
  <div>
    <span class="eyebrow">Report Form</span>
    <h2><?= $id ? 'Edit existing report' : 'Create a clean sales report' ?></h2>
    <p>Focused form for doctor visits, medicine discussions, summary, remarks, and digital signature capture.</p>
  </div>
  <a class="btn ghost" href="reports.php">Back to Reports</a>
</div>

<?php if (!empty($prefill['source_note']) && !$id): ?>
  <div class="alert success"><?= e($prefill['source_note']) ?></div>
<?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" data-report-form>
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="signature_data" data-signature-data>

  <div class="form-grid">
    <div class="field">
      <label>Choose from Doctor Masterlist</label>
      <select data-doctor-select>
        <option value="">Select doctor to auto-fill</option>
        <?php foreach($doctors as $d): ?>
          <option value="<?= (int)$d['id'] ?>" <?= (int)$values['doctor_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= e(($d['dr_name'] ?? 'Doctor') . ' · ' . ($d['place'] ?? '')) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field"><label>Visit Date & Time</label><input class="input" type="datetime-local" name="visit_datetime" required value="<?= e($values['visit_datetime']) ?>"></div>
    <div class="field"><label>Doctor Name</label><input class="input" name="doctor_name" required value="<?= e($values['doctor_name']) ?>"></div>
    <div class="field"><label>Doctor Email</label><input class="input" type="email" name="doctor_email" value="<?= e($values['doctor_email']) ?>"></div>
    <div class="field"><label>Hospital / Clinic</label><input class="input" name="hospital_name" value="<?= e($values['hospital_name']) ?>"></div>
    <div class="field"><label>Purpose</label><input class="input" name="purpose" value="<?= e($values['purpose']) ?>"></div>
    <div class="field"><label>Medicine / Product</label><input class="input" name="medicine_name" value="<?= e($values['medicine_name']) ?>"></div>
    <div class="field"><label>Optional Attachment</label><input class="input" type="file" name="attachment"></div>
    <div class="field full"><label>Visit Summary</label><textarea name="summary" placeholder="What happened during the visit?"><?= e($values['summary']) ?></textarea></div>
    <div class="field full"><label>Remarks / Next Step</label><textarea name="remarks" placeholder="Follow-up items, commitment, issues, or reminders..."><?= e($values['remarks']) ?></textarea></div>

    <div class="field full">
      <label>Doctor / Client Signature</label>
      <div class="signature-pad-wrap">
        <canvas class="signature-pad" data-signature-pad width="1200" height="320" aria-label="Signature pad"></canvas>
        <div class="signature-actions">
          <span>Sign directly on the tablet screen.</span>
          <button class="btn small ghost" type="button" data-clear-signature>Clear Signature</button>
        </div>
      </div>
      <?php if (!empty($report['signature_path'])): ?><p class="muted">Existing signature is saved. Draw a new one only if you want to replace it.</p><?php endif; ?>
    </div>
  </div>

  <br>
  <div class="actions"><button class="btn primary">Save Report</button><a class="btn ghost" href="reports.php">Cancel</a></div>
</form>
<script>window.DOCTORS=<?= json_encode($doctors, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php render_footer(); ?>
