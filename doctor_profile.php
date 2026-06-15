<?php
require __DIR__ . '/app/bootstrap.php';

require_permission('doctor_profiles.view');

$doctorId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM doctors_masterlist WHERE id = ? LIMIT 1");
$stmt->execute([$doctorId]);
$doctor = $stmt->fetch();

if (!$doctor) {
    http_response_code(404);
    exit('Doctor not found');
}

$doctorName = trim((string)($doctor['dr_name'] ?? ''));
$doctorEmail = trim((string)($doctor['email'] ?? ''));
$doctorHospital = trim((string)($doctor['hospital_address'] ?? ''));
$doctorPlace = trim((string)($doctor['place'] ?? ''));
$doctorSpecialty = trim((string)($doctor['speciality'] ?? ''));
$doctorClass = trim((string)($doctor['class'] ?? ''));
$doctorContact = trim((string)($doctor['contact_no'] ?? ''));

$locationColumns = [
    'lat' => column_exists($pdo, 'doctors_masterlist', 'clinic_latitude') ? 'clinic_latitude' : null,
    'lng' => column_exists($pdo, 'doctors_masterlist', 'clinic_longitude') ? 'clinic_longitude' : null,
    'radius' => column_exists($pdo, 'doctors_masterlist', 'allowed_visit_radius_m') ? 'allowed_visit_radius_m' : null,
    'updated_by' => column_exists($pdo, 'doctors_masterlist', 'location_updated_by') ? 'location_updated_by' : null,
    'updated_at' => column_exists($pdo, 'doctors_masterlist', 'location_updated_at') ? 'location_updated_at' : null,
];

$locationReady = $locationColumns['lat'] && $locationColumns['lng'];
$canSetDoctorLocation = can('doctors.set_location') || can('doctors.edit');

function doctor_profile_float_or_null($value): ?float
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return null;
    return (float)$value;
}

function doctor_profile_location_is_valid(?float $lat, ?float $lng): bool
{
    return $lat !== null && $lng !== null && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

function doctor_profile_maps_url($lat, $lng): string
{
    return 'https://www.google.com/maps?q=' . rawurlencode(trim((string)$lat) . ',' . trim((string)$lng));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['doctor_location_action'] ?? '') !== '') {
    verify_csrf();

    if (!$canSetDoctorLocation) {
        http_response_code(403);
        exit('You are not allowed to update doctor locations.');
    }

    if (!$locationReady) {
        flash('error', 'Doctor location columns are not installed yet. Import the doctor location migration first.');
        header('Location: doctor_profile.php?id=' . $doctorId);
        exit;
    }

    $action = (string)($_POST['doctor_location_action'] ?? '');

    if ($action === 'clear_location') {
        $values = [
            $locationColumns['lat'] => null,
            $locationColumns['lng'] => null,
        ];
        if ($locationColumns['radius']) $values[$locationColumns['radius']] = null;
        if ($locationColumns['updated_by']) $values[$locationColumns['updated_by']] = (int)(current_user()['id'] ?? 0);
        if ($locationColumns['updated_at']) $values[$locationColumns['updated_at']] = date('Y-m-d H:i:s');

        update_dynamic($pdo, 'doctors_masterlist', $values, 'id = ?', [$doctorId]);
        audit_log($pdo, 'doctor_location_cleared', 'doctor', $doctorId, [
            'doctor_name' => $doctorName,
        ]);
        flash('success', 'Doctor clinic location cleared.');
        header('Location: doctor_profile.php?id=' . $doctorId);
        exit;
    }

    $lat = doctor_profile_float_or_null($_POST['clinic_latitude'] ?? null);
    $lng = doctor_profile_float_or_null($_POST['clinic_longitude'] ?? null);
    $radius = max(50, min(2000, (int)($_POST['allowed_visit_radius_m'] ?? 200)));

    if (!doctor_profile_location_is_valid($lat, $lng)) {
        flash('error', 'Please set a valid map pin before saving.');
        header('Location: doctor_profile.php?id=' . $doctorId);
        exit;
    }

    $values = [
        $locationColumns['lat'] => $lat,
        $locationColumns['lng'] => $lng,
    ];
    if ($locationColumns['radius']) $values[$locationColumns['radius']] = $radius;
    if ($locationColumns['updated_by']) $values[$locationColumns['updated_by']] = (int)(current_user()['id'] ?? 0);
    if ($locationColumns['updated_at']) $values[$locationColumns['updated_at']] = date('Y-m-d H:i:s');

    update_dynamic($pdo, 'doctors_masterlist', $values, 'id = ?', [$doctorId]);
    audit_log($pdo, 'doctor_location_saved', 'doctor', $doctorId, [
        'doctor_name' => $doctorName,
        'latitude' => $lat,
        'longitude' => $lng,
        'radius_m' => $radius,
    ]);

    flash('success', 'Doctor clinic map pin saved.');
    header('Location: doctor_profile.php?id=' . $doctorId);
    exit;
}

$clinicLatitude = $locationColumns['lat'] ? trim((string)($doctor[$locationColumns['lat']] ?? '')) : '';
$clinicLongitude = $locationColumns['lng'] ? trim((string)($doctor[$locationColumns['lng']] ?? '')) : '';
$allowedVisitRadius = $locationColumns['radius'] ? (int)($doctor[$locationColumns['radius']] ?? 200) : 200;
$allowedVisitRadius = $allowedVisitRadius > 0 ? $allowedVisitRadius : 200;
$clinicLocationSet = $clinicLatitude !== '' && $clinicLongitude !== '';
$clinicLocationUpdatedAt = $locationColumns['updated_at'] ? trim((string)($doctor[$locationColumns['updated_at']] ?? '')) : '';
$clinicLocationUpdatedBy = '';

if ($locationColumns['updated_by'] && !empty($doctor[$locationColumns['updated_by']])) {
    try {
        $locUserStmt = $pdo->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $locUserStmt->execute([(int)$doctor[$locationColumns['updated_by']]]);
        $clinicLocationUpdatedBy = (string)$locUserStmt->fetchColumn();
    } catch (Throwable $e) {
        $clinicLocationUpdatedBy = '';
    }
}


function doctor_profile_like_value(string $value): string
{
    return '%' . $value . '%';
}

function doctor_profile_date(?string $value): string
{
    if (!$value) {
        return 'Not provided';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y g:i A', $time) : $value;
}

function doctor_profile_short_date(?string $value): string
{
    if (!$value) {
        return 'Not provided';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y', $time) : $value;
}

function doctor_profile_money(float $value): string
{
    return '₱' . number_format($value, 2);
}

[$reportScopeSql, $reportScopeParams] = scope_clause($pdo, 'r');

$reportWhere = ["($reportScopeSql)"];
$reportParams = $reportScopeParams;

$nameParts = preg_split('/\s+/', $doctorName);
$firstName = $nameParts[0] ?? $doctorName;
$lastName = $nameParts ? end($nameParts) : $doctorName;

$doctorMatchSql = [];
if ($doctorName !== '') {
    $doctorMatchSql[] = 'r.doctor_name LIKE ?';
    $reportParams[] = doctor_profile_like_value($doctorName);
}
if ($doctorEmail !== '') {
    $doctorMatchSql[] = 'r.doctor_email = ?';
    $reportParams[] = $doctorEmail;
}
if ($doctorHospital !== '') {
    $doctorMatchSql[] = 'r.hospital_name LIKE ?';
    $reportParams[] = doctor_profile_like_value($doctorHospital);
}
if ($firstName && $lastName && $firstName !== $lastName) {
    $doctorMatchSql[] = '(r.doctor_name LIKE ? AND r.doctor_name LIKE ?)';
    $reportParams[] = doctor_profile_like_value($firstName);
    $reportParams[] = doctor_profile_like_value($lastName);
}

if (!$doctorMatchSql) {
    $doctorMatchSql[] = '1=0';
}

$reportWhere[] = '(' . implode(' OR ', $doctorMatchSql) . ')';

$reports = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            u.name AS rep_name,
            u.email AS rep_email
        FROM reports r
        LEFT JOIN users u ON u.id = r.user_id
        WHERE " . implode(' AND ', $reportWhere) . "
        ORDER BY r.visit_datetime DESC, r.created_at DESC, r.id DESC
        LIMIT 80
    ");
    $stmt->execute($reportParams);
    $reports = $stmt->fetchAll();
} catch (Throwable $e) {
    $reports = [];
}

$tasks = [];
try {
    $eventColumns = table_columns($pdo, 'events');
    $eventSelect = "e.*";
    $taskSql = "
        SELECT $eventSelect, u.name AS rep_name
        FROM events e
        LEFT JOIN users u ON u.id = e.user_id
        WHERE e.doctor_id = ?
        ORDER BY COALESCE(e.visit_datetime, e.start, e.created_at) DESC
        LIMIT 30
    ";
    $stmt = $pdo->prepare($taskSql);
    $stmt->execute([$doctorId]);
    $tasks = $stmt->fetchAll();
} catch (Throwable $e) {
    $tasks = [];
}

$uniqueReps = [];
$productCounts = [];
$attachmentCount = 0;
$signatureCount = 0;
$lastVisit = null;
$approvedCount = 0;
$pendingCount = 0;
$needsChangesCount = 0;

foreach ($reports as $report) {
    $rep = trim((string)($report['rep_name'] ?? ''));
    if ($rep !== '') {
        $uniqueReps[$rep] = true;
    }

    $medicine = trim((string)($report['medicine_name'] ?? ''));
    if ($medicine !== '') {
        $productCounts[$medicine] = ($productCounts[$medicine] ?? 0) + 1;
    }

    if (trim((string)($report['attachment_path'] ?? '')) !== '') {
        $attachmentCount++;
    }

    if (trim((string)($report['signature_path'] ?? '')) !== '') {
        $signatureCount++;
    }

    $visit = $report['visit_datetime'] ?? null;
    if ($visit && (!$lastVisit || strtotime($visit) > strtotime($lastVisit))) {
        $lastVisit = $visit;
    }

    $status = (string)($report['status'] ?? 'pending');
    if ($status === 'approved') {
        $approvedCount++;
    } elseif ($status === 'needs_changes') {
        $needsChangesCount++;
    } else {
        $pendingCount++;
    }
}

arsort($productCounts);
$topProducts = array_slice($productCounts, 0, 6, true);

$timeline = [];
foreach ($reports as $report) {
    $timeline[] = [
        'type' => 'report',
        'date' => $report['visit_datetime'] ?? $report['created_at'] ?? null,
        'title' => $report['purpose'] ?: 'Sales report submitted',
        'subtitle' => ($report['rep_name'] ?? 'Unknown rep') . ' · ' . ($report['medicine_name'] ?: 'No product listed'),
        'status' => $report['status'] ?? 'pending',
        'url' => 'report_view.php?id=' . (int)$report['id'],
        'meta' => $report['summary'] ?? '',
        'id' => (int)$report['id'],
    ];
}

foreach ($tasks as $task) {
    $taskDate = $task['visit_datetime'] ?? $task['start'] ?? $task['created_at'] ?? null;
    $timeline[] = [
        'type' => 'task',
        'date' => $taskDate,
        'title' => $task['title'] ?? 'Scheduled task',
        'subtitle' => ($task['rep_name'] ?? 'Assigned rep') . ' · ' . ($task['purpose'] ?? 'Task'),
        'status' => 'task',
        'url' => 'report_form.php?task=' . (int)$task['id'],
        'meta' => $task['summary'] ?? $task['remarks'] ?? '',
        'id' => (int)$task['id'],
    ];
}

usort($timeline, function ($a, $b) {
    return strtotime((string)($b['date'] ?? '1970-01-01')) <=> strtotime((string)($a['date'] ?? '1970-01-01'));
});
$timeline = array_slice($timeline, 0, 30);

render_header('Doctor Profile');
?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">


<style>
.doctor-profile-shell {
    display: grid;
    gap: 20px;
}

.doctor-profile-hero {
    position: relative;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 24px;
    align-items: center;
    padding: 30px;
    border: 1px solid rgba(15, 118, 110, .16);
    border-radius: 34px;
    background:
        radial-gradient(circle at 86% 14%, rgba(250, 204, 21, .20), transparent 30%),
        radial-gradient(circle at 12% 0%, rgba(20, 184, 166, .18), transparent 34%),
        linear-gradient(135deg, #ffffff 0%, #ecfffb 80%);
    box-shadow: 0 22px 54px rgba(15, 118, 110, .09);
}

.doctor-profile-hero::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 7px;
    background: linear-gradient(180deg, #0f766e, #14b8a6, #facc15);
    border-radius: 999px;
}

.doctor-profile-identity {
    display: flex;
    align-items: center;
    gap: 18px;
    min-width: 0;
}

.doctor-avatar-xl {
    width: 86px;
    height: 86px;
    flex: 0 0 86px;
    display: grid;
    place-items: center;
    border-radius: 28px;
    color: #0f766e;
    background:
        radial-gradient(circle at top left, rgba(20, 184, 166, .22), transparent 40%),
        linear-gradient(135deg, #ffffff, #ecfdf5);
    border: 1px solid rgba(15, 118, 110, .18);
    box-shadow: 0 16px 34px rgba(15, 118, 110, .12);
    font-size: 30px;
    font-weight: 950;
    letter-spacing: -.04em;
}

.doctor-profile-title {
    min-width: 0;
}

.doctor-profile-title h2 {
    margin: 5px 0 6px;
    font-size: clamp(30px, 3vw, 46px);
    line-height: 1;
    letter-spacing: -.06em;
    color: #061f1c;
}

.doctor-profile-title p {
    margin: 0;
    color: #59736d;
    font-size: 16px;
    font-weight: 700;
}

.doctor-chip-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.doctor-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    min-height: 34px;
    padding: 7px 11px;
    border-radius: 999px;
    border: 1px solid rgba(15, 118, 110, .15);
    background: rgba(255, 255, 255, .78);
    color: #0b4f48;
    font-size: 12px;
    font-weight: 950;
}

.doctor-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
}

.doctor-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
}

.doctor-stat-card {
    position: relative;
    overflow: hidden;
    min-height: 126px;
    padding: 22px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 30px;
    background: linear-gradient(145deg, rgba(255, 255, 255, .98), rgba(250, 255, 253, .94));
    box-shadow: 0 14px 34px rgba(15, 118, 110, .065);
}

.doctor-stat-card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 18px;
    right: 18px;
    height: 5px;
    border-radius: 999px;
    background: linear-gradient(90deg, #0f766e, #14b8a6, #facc15);
}

.doctor-stat-card span {
    display: block;
    margin-top: 8px;
    color: #607872;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .09em;
    font-weight: 950;
}

.doctor-stat-card strong {
    display: block;
    margin-top: 10px;
    color: #061f1c;
    font-size: 32px;
    line-height: 1;
    letter-spacing: -.05em;
}

.doctor-profile-layout {
    display: grid;
    grid-template-columns: minmax(0, .9fr) minmax(0, 1.35fr);
    gap: 18px;
    align-items: start;
}

.doctor-profile-panel {
    padding: 24px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 32px;
    background:
        radial-gradient(circle at right top, rgba(20, 184, 166, .09), transparent 26%),
        linear-gradient(145deg, #ffffff, #fbfffe);
    box-shadow: 0 14px 34px rgba(15, 118, 110, .065);
}

.doctor-profile-panel-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1px solid rgba(15, 118, 110, .10);
}

.doctor-profile-panel-head h3 {
    margin: 3px 0 0;
    color: #061f1c;
    font-size: 22px;
    letter-spacing: -.04em;
}

.doctor-info-list {
    display: grid;
    gap: 12px;
}

.doctor-info-item {
    display: grid;
    grid-template-columns: 145px minmax(0, 1fr);
    gap: 12px;
    align-items: center;
    padding: 14px;
    border: 1px solid rgba(15, 118, 110, .11);
    border-radius: 22px;
    background: rgba(255, 255, 255, .74);
}

.doctor-info-item span {
    color: #607872;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .09em;
    font-weight: 950;
}

.doctor-info-item strong {
    color: #082f2b;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.doctor-products {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.doctor-product-pill {
    display: inline-flex;
    gap: 8px;
    align-items: center;
    padding: 8px 11px;
    border-radius: 999px;
    background: #f8fffd;
    border: 1px solid rgba(15, 118, 110, .13);
    color: #0b4f48;
    font-size: 12px;
    font-weight: 900;
}

.doctor-timeline {
    display: grid;
    gap: 12px;
}

.doctor-timeline-item {
    display: grid;
    grid-template-columns: 124px minmax(0, 1fr) auto;
    gap: 14px;
    align-items: center;
    padding: 15px;
    border: 1px solid rgba(15, 118, 110, .12);
    border-radius: 24px;
    background: linear-gradient(145deg, #ffffff, #fbfffe);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.doctor-timeline-item:hover {
    transform: translateY(-1px);
    border-color: rgba(20, 184, 166, .34);
    box-shadow: 0 14px 28px rgba(15, 118, 110, .07);
}

.doctor-timeline-date {
    color: #607872;
    font-size: 12px;
    font-weight: 950;
    text-transform: uppercase;
    letter-spacing: .06em;
}

.doctor-timeline-main strong {
    display: block;
    color: #082f2b;
    font-weight: 950;
}

.doctor-timeline-main p {
    margin: 4px 0 0;
    color: #607872;
    font-size: 13px;
    font-weight: 750;
}

.doctor-evidence-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}

.doctor-evidence-card {
    padding: 16px;
    border: 1px solid rgba(15, 118, 110, .12);
    border-radius: 24px;
    background: linear-gradient(145deg, #ffffff, #fbfffe);
}

.doctor-evidence-card span {
    display: block;
    color: #607872;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .09em;
    font-weight: 950;
}

.doctor-evidence-card strong {
    display: block;
    margin-top: 7px;
    color: #082f2b;
    font-size: 22px;
}

.doctor-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 1180px) {
    .doctor-stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .doctor-profile-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 760px) {
    .doctor-profile-hero {
        grid-template-columns: 1fr;
        padding: 24px;
    }

    .doctor-profile-identity {
        align-items: flex-start;
    }

    .doctor-avatar-xl {
        width: 68px;
        height: 68px;
        flex-basis: 68px;
        border-radius: 22px;
        font-size: 24px;
    }

    .doctor-actions,
    .doctor-actions .btn,
    .doctor-card-actions,
    .doctor-card-actions .btn {
        width: 100%;
    }

    .doctor-stat-grid,
    .doctor-evidence-grid {
        grid-template-columns: 1fr;
    }

    .doctor-info-item {
        grid-template-columns: 1fr;
        gap: 6px;
    }

    .doctor-timeline-item {
        grid-template-columns: 1fr;
    }
}

.doctor-location-map-card {
    display: grid;
    gap: 16px;
}

.doctor-location-status {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
}

.doctor-location-pill {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 11px;
    border-radius: 999px;
    border: 1px solid rgba(15, 118, 110, .16);
    background: #ecfdf5;
    color: #0f766e;
    font-size: 12px;
    font-weight: 950;
}

.doctor-location-pill.missing {
    background: #fff7ed;
    color: #c2410c;
    border-color: #fed7aa;
}

.doctor-location-controls {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) auto auto;
    gap: 10px;
    align-items: end;
}

.doctor-location-controls .field,
.doctor-location-radius-row .field {
    margin: 0;
}

.doctor-location-controls input,
.doctor-location-radius-row input,
.doctor-location-radius-row select {
    min-height: 48px;
    border-radius: 15px;
}

#doctorClinicMap {
    height: 380px;
    min-height: 380px;
    border-radius: 24px;
    border: 1px solid rgba(15, 118, 110, .18);
    overflow: hidden;
    background: #e2e8f0;
}

.doctor-location-radius-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    gap: 10px;
    align-items: end;
}

.doctor-location-help {
    padding: 14px 16px;
    border-radius: 18px;
    background: #f8fffd;
    border: 1px dashed rgba(15, 118, 110, .22);
    color: #64748b;
    font-weight: 800;
    line-height: 1.5;
}

@media(max-width: 860px) {
    .doctor-location-controls,
    .doctor-location-radius-row {
        grid-template-columns: 1fr;
    }

    .doctor-location-controls .btn,
    .doctor-location-radius-row .btn {
        width: 100%;
    }

    #doctorClinicMap {
        height: 320px;
        min-height: 320px;
    }
}

</style>

<?php
$avatarInitials = '';
foreach (preg_split('/\s+/', $doctorName) as $part) {
    if ($part !== '') {
        $avatarInitials .= strtoupper(substr($part, 0, 1));
    }
}
$avatarInitials = substr($avatarInitials ?: 'DR', 0, 2);
?>

<div class="doctor-profile-shell">
    <section class="doctor-profile-hero">
        <div class="doctor-profile-identity">
            <div class="doctor-avatar-xl"><?= e($avatarInitials) ?></div>
            <div class="doctor-profile-title">
                <span class="eyebrow">Doctor Profile</span>
                <h2><?= e($doctorName ?: 'Unnamed Doctor') ?></h2>
                <p><?= e($doctorSpecialty ?: 'Specialty not provided') ?> &middot; <?= e($doctorHospital ?: 'Hospital not provided') ?></p>

                <div class="doctor-chip-row">
                    <span class="doctor-chip">Class <?= e($doctorClass ?: 'N/A') ?></span>
                    <span class="doctor-chip"><?= e($doctorPlace ?: 'No city/place') ?></span>
                    <span class="doctor-chip">Doctor ID #<?= (int)$doctorId ?></span>
                </div>
            </div>
        </div>

        <div class="doctor-actions">
            <a class="btn ghost" href="doctors.php">Back to Doctors</a>
            <a class="btn ghost" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=7">Follow Up in 7 Days</a>
            <a class="btn ghost" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=14">14 Days</a>
            <a class="btn ghost" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=30">30 Days</a>
            <a class="btn primary" href="report_form.php?doctor=<?= (int)$doctorId ?>">Create Report</a>
        </div>
    </section>

    <section class="doctor-stat-grid">
        <div class="doctor-stat-card">
            <span>Total Visits</span>
            <strong><?= number_format(count($reports)) ?></strong>
        </div>
        <div class="doctor-stat-card">
            <span>Last Visit</span>
            <strong style="font-size:22px;line-height:1.2"><?= e(doctor_profile_short_date($lastVisit)) ?></strong>
        </div>
        <div class="doctor-stat-card">
            <span>Reps Visited</span>
            <strong><?= number_format(count($uniqueReps)) ?></strong>
        </div>
        <div class="doctor-stat-card">
            <span>Open Items</span>
            <strong><?= number_format($pendingCount + $needsChangesCount + count($tasks)) ?></strong>
        </div>
    </section>

    <section class="doctor-profile-panel">
        <div class="doctor-profile-panel-head">
            <div>
                <span class="eyebrow">Follow-Up Workflow</span>
                <h3>Schedule next doctor visit</h3>
            </div>
        </div>
        <div class="doctor-card-actions">
            <a class="btn primary" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=7">Create 7-Day Follow-Up</a>
            <a class="btn ghost" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=14">Create 14-Day Follow-Up</a>
            <a class="btn ghost" href="tasks.php?followup=1&doctor=<?= (int)$doctorId ?>&days=30">Create 30-Day Follow-Up</a>
        </div>
    </section>

    <section class="doctor-profile-panel doctor-location-map-card">
        <div class="doctor-profile-panel-head">
            <div>
                <span class="eyebrow">Clinic Location</span>
                <h3>Set doctor map pin</h3>
            </div>
            <div class="doctor-location-status">
                <?php if ($clinicLocationSet): ?>
                    <span class="doctor-location-pill">Location Saved</span>
                    <a class="btn small ghost" target="_blank" href="<?= e(doctor_profile_maps_url($clinicLatitude, $clinicLongitude)) ?>">Open in Maps</a>
                <?php else: ?>
                    <span class="doctor-location-pill missing">Location Not Set</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$locationReady): ?>
            <div class="doctor-location-help">
                Doctor location columns are not installed yet. Import
                <strong>database/migrations/2026_06_15_add_doctor_map_pin_location.sql</strong>
                before using the map pin setup.
            </div>
        <?php else: ?>
            <form method="post" id="doctorLocationForm">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="doctor_location_action" value="save_location">
                <input type="hidden" name="clinic_latitude" id="clinicLatitude" value="<?= e($clinicLatitude) ?>">
                <input type="hidden" name="clinic_longitude" id="clinicLongitude" value="<?= e($clinicLongitude) ?>">

                <div class="doctor-location-controls">
                    <div class="field">
                        <label>Search clinic / area</label>
                        <input type="text" id="clinicSearchInput" value="<?= e(trim($doctorName . ' ' . $doctorHospital . ' ' . $doctorPlace)) ?>" placeholder="Search doctor, clinic, hospital, or area">
                    </div>
                    <button type="button" class="btn ghost" id="clinicSearchButton">Search Map</button>
                    <button type="button" class="btn ghost" id="clinicCurrentLocationButton">Use Current Location</button>
                </div>

                <div id="doctorClinicMap"></div>

                <div class="doctor-location-radius-row">
                    <div class="field">
                        <label>Allowed Visit Radius</label>
                        <select name="allowed_visit_radius_m">
                            <?php foreach ([100, 200, 300, 500, 1000] as $radiusOption): ?>
                                <option value="<?= (int)$radiusOption ?>" <?= $allowedVisitRadius === $radiusOption ? 'selected' : '' ?>><?= (int)$radiusOption ?> meters</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn primary">Save Pin Location</button>
                    <?php if ($clinicLocationSet): ?>
                        <button type="submit" class="btn ghost" name="doctor_location_action" value="clear_location" data-confirm="Clear this doctor's saved clinic location?">Clear Location</button>
                    <?php endif; ?>
                </div>

                <div class="doctor-location-help">
                    Drag the pin or click on the map to set the exact clinic location. Reps can also tap
                    <strong>Use Current Location</strong> while physically at the clinic.
                    <?php if ($clinicLocationSet): ?>
                        <br>Saved coordinates: <strong><?= e($clinicLatitude) ?>, <?= e($clinicLongitude) ?></strong>
                        <?php if ($clinicLocationUpdatedAt): ?>
                            · Updated <?= e(doctor_profile_date($clinicLocationUpdatedAt)) ?>
                        <?php endif; ?>
                        <?php if ($clinicLocationUpdatedBy): ?>
                            by <?= e($clinicLocationUpdatedBy) ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    </section>


    <section class="doctor-profile-layout">
        <div class="doctor-profile-panel">
            <div class="doctor-profile-panel-head">
                <div>
                    <span class="eyebrow">Masterlist Details</span>
                    <h3>Doctor information</h3>
                </div>
            </div>

            <div class="doctor-info-list">
                <div class="doctor-info-item">
                    <span>Doctor</span>
                    <strong><?= e($doctorName ?: 'Not provided') ?></strong>
                </div>
                <div class="doctor-info-item">
                    <span>Specialty</span>
                    <strong><?= e($doctorSpecialty ?: 'Not provided') ?></strong>
                </div>
                <div class="doctor-info-item">
                    <span>Hospital</span>
                    <strong><?= e($doctorHospital ?: 'Not provided') ?></strong>
                </div>
                <div class="doctor-info-item">
                    <span>Place</span>
                    <strong><?= e($doctorPlace ?: 'Not provided') ?></strong>
                </div>
                <div class="doctor-info-item">
                    <span>Email</span>
                    <strong><?= e($doctorEmail ?: 'Not provided') ?></strong>
                </div>
                <div class="doctor-info-item">
                    <span>Contact</span>
                    <strong><?= e($doctorContact ?: 'Not provided') ?></strong>
                </div>
            </div>

            <br>

            <div class="doctor-profile-panel-head">
                <div>
                    <span class="eyebrow">Products</span>
                    <h3>Products discussed</h3>
                </div>
            </div>

            <?php if ($topProducts): ?>
                <div class="doctor-products">
                    <?php foreach ($topProducts as $product => $count): ?>
                        <span class="doctor-product-pill"><?= e($product) ?> <strong><?= (int)$count ?></strong></span>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">No products discussed yet.</div>
            <?php endif; ?>

            <br>

            <div class="doctor-evidence-grid">
                <div class="doctor-evidence-card">
                    <span>Attachments</span>
                    <strong><?= number_format($attachmentCount) ?></strong>
                </div>
                <div class="doctor-evidence-card">
                    <span>Signatures</span>
                    <strong><?= number_format($signatureCount) ?></strong>
                </div>
            </div>
        </div>

        <div class="doctor-profile-panel">
            <div class="doctor-profile-panel-head">
                <div>
                    <span class="eyebrow">Visit History</span>
                    <h3>Reports and tasks</h3>
                </div>
                <a class="btn small ghost" href="report_form.php?doctor=<?= (int)$doctorId ?>">New Report</a>
            </div>

            <?php if ($timeline): ?>
                <div class="doctor-timeline">
                    <?php foreach ($timeline as $item): ?>
                        <div class="doctor-timeline-item">
                            <div class="doctor-timeline-date">
                                <?= e(doctor_profile_short_date($item['date'])) ?>
                            </div>
                            <div class="doctor-timeline-main">
                                <strong><?= e($item['title']) ?></strong>
                                <p><?= e($item['subtitle']) ?></p>
                                <?php if (trim((string)$item['meta']) !== ''): ?>
                                    <p><?= e(mb_strimwidth((string)$item['meta'], 0, 150, '...')) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="doctor-card-actions">
                                <?php if ($item['type'] === 'report'): ?>
                                    <span class="badge <?= e($item['status']) ?>"><?= e(status_label((string)$item['status'])) ?></span>
                                    <a class="btn small ghost" href="<?= e($item['url']) ?>">View</a>
                                <?php else: ?>
                                    <span class="badge">Task</span>
                                    <a class="btn small primary" href="<?= e($item['url']) ?>">Generate Report</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty">
                    No visit history yet. Create the first report for this doctor.
                    <br><br>
                    <a class="btn primary" href="report_form.php?doctor=<?= (int)$doctorId ?>">Create Report</a>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mapEl = document.getElementById('doctorClinicMap');
    if (!mapEl || typeof L === 'undefined') return;

    const latInput = document.getElementById('clinicLatitude');
    const lngInput = document.getElementById('clinicLongitude');
    const searchInput = document.getElementById('clinicSearchInput');
    const searchButton = document.getElementById('clinicSearchButton');
    const currentButton = document.getElementById('clinicCurrentLocationButton');

    const savedLat = parseFloat(latInput.value);
    const savedLng = parseFloat(lngInput.value);
    const hasSavedPin = !Number.isNaN(savedLat) && !Number.isNaN(savedLng);

    const defaultLat = hasSavedPin ? savedLat : 14.5995;
    const defaultLng = hasSavedPin ? savedLng : 120.9842;
    const defaultZoom = hasSavedPin ? 17 : 11;

    const map = L.map(mapEl).setView([defaultLat, defaultLng], defaultZoom);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const marker = L.marker([defaultLat, defaultLng], {
        draggable: true
    }).addTo(map);

    function setPin(lat, lng, zoom = 17) {
        marker.setLatLng([lat, lng]);
        map.setView([lat, lng], zoom);
        latInput.value = Number(lat).toFixed(7);
        lngInput.value = Number(lng).toFixed(7);
    }

    if (!hasSavedPin) {
        latInput.value = '';
        lngInput.value = '';
        marker.bindPopup('Drag or click the map to set the clinic pin.').openPopup();
    } else {
        marker.bindPopup('Saved clinic location').openPopup();
    }

    marker.on('dragend', function () {
        const pos = marker.getLatLng();
        setPin(pos.lat, pos.lng, map.getZoom());
    });

    map.on('click', function (event) {
        setPin(event.latlng.lat, event.latlng.lng, map.getZoom());
    });

    if (currentButton) {
        currentButton.addEventListener('click', function () {
            if (!navigator.geolocation) {
                alert('Location is not supported by this browser.');
                return;
            }

            currentButton.disabled = true;
            currentButton.textContent = 'Locating...';

            navigator.geolocation.getCurrentPosition(function (position) {
                setPin(position.coords.latitude, position.coords.longitude, 18);
                currentButton.disabled = false;
                currentButton.textContent = 'Use Current Location';
            }, function () {
                alert('Unable to get current location. Please allow location access or drag the pin manually.');
                currentButton.disabled = false;
                currentButton.textContent = 'Use Current Location';
            }, {
                enableHighAccuracy: true,
                timeout: 12000,
                maximumAge: 0
            });
        });
    }

    if (searchButton) {
        searchButton.addEventListener('click', function () {
            const query = (searchInput.value || '').trim();
            if (!query) {
                alert('Enter a clinic, hospital, doctor, or area to search.');
                return;
            }

            searchButton.disabled = true;
            searchButton.textContent = 'Searching...';

            fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(query))
                .then(response => response.json())
                .then(results => {
                    if (!results || !results.length) {
                        alert('No map result found. Try a more specific clinic, hospital, or city.');
                        return;
                    }

                    setPin(parseFloat(results[0].lat), parseFloat(results[0].lon), 17);
                })
                .catch(() => {
                    alert('Map search failed. You can still drag the pin manually.');
                })
                .finally(() => {
                    searchButton.disabled = false;
                    searchButton.textContent = 'Search Map';
                });
        });
    }

    setTimeout(function () {
        map.invalidateSize();
    }, 300);
});
</script>

<?php render_footer(); ?>
