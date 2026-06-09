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
    $ownsReport = (int)($report['user_id'] ?? 0) === (int)(current_user()['id'] ?? 0);
    if (!can('reports.edit_team') && !($ownsReport && can('reports.edit_own'))) {
        http_response_code(403);
        exit('You are not allowed to edit this report.');
    }
}

if (!$id && !can('reports.create')) {
    http_response_code(403);
    exit('You are not allowed to create reports.');
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

    $signatureLatitude = trim((string)($_POST['signature_latitude'] ?? ''));
    $signatureLongitude = trim((string)($_POST['signature_longitude'] ?? ''));
    $signatureAccuracy = trim((string)($_POST['signature_accuracy'] ?? ''));
    $signatureCapturedAt = trim((string)($_POST['signature_captured_at'] ?? ''));
    $signatureLocationStatus = trim((string)($_POST['signature_location_status'] ?? ''));

    if ($signatureLocationStatus !== '') {
        $values['signature_location_status'] = $signatureLocationStatus;
    }

    if ($signatureLatitude !== '' && $signatureLongitude !== '') {
        $values['signature_latitude'] = $signatureLatitude;
        $values['signature_longitude'] = $signatureLongitude;
        $values['signature_accuracy'] = $signatureAccuracy !== '' ? $signatureAccuracy : null;
        $values['signature_captured_at'] = $signatureCapturedAt !== '' ? str_replace('T', ' ', $signatureCapturedAt) : date('Y-m-d H:i:s');
        $values['signature_location_status'] = 'captured';
    } elseif (in_array($signatureLocationStatus, ['denied', 'unavailable', 'unsupported', 'error'], true)) {
        $values['signature_location_status'] = $signatureLocationStatus;
    }

    if ($signaturePath) $values['signature_path'] = $signaturePath;
    if ($attachmentPath) $values['attachment_path'] = $attachmentPath;

    if ($id && $report) {
        unset($values['user_id'], $values['status'], $values['created_at']);
        update_dynamic($pdo, 'reports', $values, 'id = ?', [$id]);
        audit_log($pdo, 'report_updated', 'report', $id, [
            'doctor_name' => $values['doctor_name'] ?? '',
            'status' => $report['status'] ?? '',
            'has_signature' => !empty($values['signature_path']) || !empty($report['signature_path']),
            'has_attachment' => !empty($values['attachment_path']) || !empty($report['attachment_path']),
            'has_geotag' => !empty($values['signature_latitude']) && !empty($values['signature_longitude']),
        ]);
        if (!empty($values['signature_latitude']) && !empty($values['signature_longitude'])) {
            audit_log($pdo, 'signature_geotag_captured', 'report', $id, [
                'latitude' => $values['signature_latitude'],
                'longitude' => $values['signature_longitude'],
                'accuracy' => $values['signature_accuracy'] ?? null,
            ]);
        }
        flash('success', 'Report updated.');
        header('Location: report_view.php?id=' . $id);
        exit;
    }

    $newReportId = insert_dynamic($pdo, 'reports', $values);
    audit_log($pdo, 'report_created', 'report', $newReportId, [
        'doctor_name' => $values['doctor_name'] ?? '',
        'hospital_name' => $values['hospital_name'] ?? '',
        'status' => $values['status'] ?? '',
        'has_signature' => !empty($values['signature_path']),
        'has_attachment' => !empty($values['attachment_path']),
        'has_geotag' => !empty($values['signature_latitude']) && !empty($values['signature_longitude']),
    ]);
    if (!empty($values['signature_latitude']) && !empty($values['signature_longitude'])) {
        audit_log($pdo, 'signature_geotag_captured', 'report', $newReportId, [
            'latitude' => $values['signature_latitude'],
            'longitude' => $values['signature_longitude'],
            'accuracy' => $values['signature_accuracy'] ?? null,
        ]);
    }
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


<style>
.location-proof-card{margin-top:14px;padding:16px;border:1px solid rgba(15,118,110,.14);border-radius:24px;background:radial-gradient(circle at right top,rgba(20,184,166,.10),transparent 30%),linear-gradient(145deg,#ffffff,#f8fffd)}
.location-proof-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.location-proof-head h3{margin:3px 0 4px;color:#082f2b;letter-spacing:-.03em}
.location-proof-head p{margin:0;color:#607872;font-weight:750;line-height:1.45}
.location-proof-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:12px}
.location-proof-item{padding:12px;border:1px solid rgba(15,118,110,.11);border-radius:18px;background:rgba(255,255,255,.82)}
.location-proof-item span{display:block;margin-bottom:5px;color:#607872;font-size:11px;text-transform:uppercase;letter-spacing:.08em;font-weight:950}
.location-proof-item strong{color:#082f2b;font-size:13px;overflow-wrap:anywhere}
.location-status-pill{display:inline-flex;align-items:center;min-height:34px;padding:7px 11px;border-radius:999px;border:1px solid rgba(15,118,110,.16);background:#fffdf2;color:#854d0e;font-size:12px;font-weight:950;white-space:nowrap}
.location-status-pill.captured{background:#ecfdf5;color:#15803d;border-color:#bbf7d0}
.location-status-pill.denied,.location-status-pill.error,.location-status-pill.unavailable,.location-status-pill.unsupported{background:#fff1f2;color:#b91c1c;border-color:#fecdd3}
.location-proof-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}

.location-map-preview{margin-top:12px;overflow:hidden;border:1px solid rgba(15,118,110,.14);border-radius:22px;background:#ffffff;box-shadow:0 12px 28px rgba(15,118,110,.06)}
.location-map-preview iframe{display:block;width:100%;height:230px;border:0}
.location-status-pill.waiting{background:#f8fafc;color:#475569;border-color:#cbd5e1}
.location-status-pill.capturing{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
@media(max-width:760px){.location-proof-head{display:grid}.location-proof-grid{grid-template-columns:1fr}.location-proof-actions,.location-proof-actions .btn{width:100%}}
</style>

<?php if (!empty($prefill['source_note']) && !$id): ?>
  <div class="alert success"><?= e($prefill['source_note']) ?></div>
<?php endif; ?>

<form class="card" method="post" enctype="multipart/form-data" data-report-form>
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="signature_data" data-signature-data>
  <input type="hidden" name="signature_latitude" data-geo-latitude value="<?= e($report['signature_latitude'] ?? '') ?>">
  <input type="hidden" name="signature_longitude" data-geo-longitude value="<?= e($report['signature_longitude'] ?? '') ?>">
  <input type="hidden" name="signature_accuracy" data-geo-accuracy value="<?= e($report['signature_accuracy'] ?? '') ?>">
  <input type="hidden" name="signature_captured_at" data-geo-captured-at value="<?= e($report['signature_captured_at'] ?? '') ?>">
  <input type="hidden" name="signature_location_status" data-geo-status value="<?= e($report['signature_location_status'] ?? '') ?>">

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

      <div class="location-proof-card" data-location-proof>
        <div class="location-proof-head">
          <div>
            <span class="eyebrow">Location Proof</span>
            <h3>Automatic signature geotag</h3>
            <p>The app will automatically capture the tablet location when the doctor/client starts signing. Clearing the signature also clears the saved location.</p>
          </div>
          <span class="location-status-pill" data-geo-status-label>Waiting for Signature</span>
        </div>
        <div class="location-proof-grid">
          <div class="location-proof-item"><span>Latitude</span><strong data-geo-latitude-label><?= e($report['signature_latitude'] ?? 'Not captured') ?></strong></div>
          <div class="location-proof-item"><span>Longitude</span><strong data-geo-longitude-label><?= e($report['signature_longitude'] ?? 'Not captured') ?></strong></div>
          <div class="location-proof-item"><span>Accuracy</span><strong data-geo-accuracy-label><?= !empty($report['signature_accuracy']) ? e(round((float)$report['signature_accuracy'])) . ' meters' : 'Not captured' ?></strong></div>
        </div>
        <div class="location-map-preview" data-geo-map-preview hidden>
          <iframe data-geo-map-frame title="Signature location map" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
        <div class="location-proof-actions">
          <a class="btn small ghost" data-geo-map-link target="_blank" href="#" hidden>Open Map</a>
        </div>
        <p class="muted" data-geo-message style="margin:.75rem 0 0">Ask for location permission when prompted. Location is optional for now, but reports without it will show no location proof.</p>
      </div>
    </div>
  </div>

  <br>
  <div class="actions"><button class="btn primary">Save Report</button><a class="btn ghost" href="reports.php">Cancel</a></div>
</form>
<script>window.DOCTORS=<?= json_encode($doctors, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;</script>
<?php render_footer(); ?>
