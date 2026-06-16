<?php
require __DIR__ . '/app/bootstrap.php';

require_any_permission(['tasks.view', 'reports.view', 'reports.view_own', 'reports.view_team']);
verify_csrf();

function dcr_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (column_exists($pdo, $table, $candidate)) return $candidate;
    }
    return null;
}

function dcr_date_input(string $value): string
{
    $time = strtotime($value);
    return $time ? date('Y-m-d', $time) : date('Y-m-d');
}

function dcr_display_time(?string $value): string
{
    if (!$value) return 'No time';
    $time = strtotime($value);
    return $time ? date('g:i A', $time) : $value;
}

function dcr_display_date(?string $value): string
{
    if (!$value) return 'Not provided';
    $time = strtotime($value);
    return $time ? date('M d, Y g:i A', $time) : $value;
}

function dcr_report_match_key(array $row, string $date): string
{
    $doctorId = (int)($row['doctor_id'] ?? 0);
    if ($doctorId > 0) {
        return 'doctor:' . $doctorId . ':' . $date;
    }

    $doctor = strtolower(trim((string)($row['doctor_name'] ?? '')));
    $hospital = strtolower(trim((string)($row['hospital_name'] ?? '')));
    $doctor = preg_replace('/^visit:\s*/i', '', $doctor);
    return 'text:' . md5($doctor . '|' . $hospital . '|' . $date);
}

function dcr_status_class(string $status): string
{
    $status = strtolower($status);
    if (in_array($status, ['approved', 'completed', 'done'], true)) return 'ok';
    if (in_array($status, ['rejected', 'declined', 'missed', 'cancelled'], true)) return 'bad';
    if (in_array($status, ['needs_changes', 'needs changes', 'for revision'], true)) return 'warn';
    return 'pending';
}

function dcr_has_geotag(array $report): bool
{
    return trim((string)($report['signature_latitude'] ?? '')) !== '' && trim((string)($report['signature_longitude'] ?? '')) !== '';
}

function dcr_has_signature(array $report): bool
{
    return trim((string)($report['signature_path'] ?? '')) !== '';
}

function dcr_geo_float($value): ?float
{
    $value = trim((string)$value);
    if ($value === '' || !is_numeric($value)) return null;
    return (float)$value;
}

function dcr_geo_distance_m(?float $lat1, ?float $lng1, ?float $lat2, ?float $lng2): ?float
{
    if ($lat1 === null || $lng1 === null || $lat2 === null || $lng2 === null) return null;

    $earthRadius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

    return $earthRadius * $c;
}

function dcr_visit_verification(array $report, array $doctorPins): array
{
    $manualStatus = trim((string)($report['visit_verification_status'] ?? ''));
    $manualMethod = trim((string)($report['visit_verification_method'] ?? ''));

    if ($manualMethod === 'manual' && in_array($manualStatus, ['manual_verified', 'manual_rejected', 'manual_review'], true)) {
        return [
            'label' => [
                'manual_verified' => 'Manual Verified',
                'manual_rejected' => 'Manual Rejected',
                'manual_review' => 'Manual Review',
            ][$manualStatus],
            'class' => $manualStatus === 'manual_verified' ? 'ok' : ($manualStatus === 'manual_rejected' ? 'bad' : 'warn'),
        ];
    }

    $sigLat = dcr_geo_float($report['signature_latitude'] ?? null);
    $sigLng = dcr_geo_float($report['signature_longitude'] ?? null);

    if ($sigLat === null || $sigLng === null) {
        return ['label' => 'No Signature Loc', 'class' => 'warn'];
    }

    $doctorId = (int)($report['doctor_id'] ?? 0);
    if ($doctorId <= 0 || empty($doctorPins[$doctorId]['has_pin'])) {
        return ['label' => 'No Doctor Pin', 'class' => 'warn'];
    }

    $pin = $doctorPins[$doctorId];
    $distance = dcr_geo_distance_m((float)$pin['lat'], (float)$pin['lng'], $sigLat, $sigLng);
    $radius = (int)($pin['radius_m'] ?? 200);

    if ($distance !== null && $distance <= $radius) {
        return ['label' => 'Verified', 'class' => 'ok'];
    }

    return ['label' => 'Outside Radius', 'class' => 'bad'];
}

$selectedDate = dcr_date_input((string)($_GET['date'] ?? date('Y-m-d')));
$selectedUser = (int)($_GET['user_id'] ?? 0);
$current = current_user();
$currentUserId = (int)($current['id'] ?? 0);

$visibleIds = visible_user_ids($pdo);
if (!$visibleIds) {
    $visibleIds = [$currentUserId];
}
if ($selectedUser > 0 && !in_array($selectedUser, $visibleIds, true)) {
    $selectedUser = 0;
}

$userOptions = [];
try {
    $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
    $stmt = $pdo->prepare("SELECT id, name, role FROM users WHERE id IN ($placeholders) ORDER BY name ASC");
    $stmt->execute($visibleIds);
    $userOptions = $stmt->fetchAll();
} catch (Throwable $e) {
    $userOptions = [];
}

$eventCols = table_columns($pdo, 'events');
$reportCols = table_columns($pdo, 'reports');

$eventStartCol = in_array('start', $eventCols, true) ? 'start' : (in_array('start_datetime', $eventCols, true) ? 'start_datetime' : (in_array('visit_datetime', $eventCols, true) ? 'visit_datetime' : null));
$eventEndCol = in_array('end', $eventCols, true) ? 'end' : (in_array('end_datetime', $eventCols, true) ? 'end_datetime' : null);
$eventDoctorIdCol = in_array('doctor_id', $eventCols, true) ? 'doctor_id' : null;
$eventCityCol = in_array('city', $eventCols, true) ? 'city' : null;

$doctorNameCol = dcr_column($pdo, 'doctors_masterlist', ['dr_name', 'doctor_name', 'name']);
$doctorHospitalCol = dcr_column($pdo, 'doctors_masterlist', ['hospital_address', 'hospital', 'clinic', 'clinic_address']);
$doctorAreaCol = dcr_column($pdo, 'doctors_masterlist', ['place', 'area', 'city', 'territory']);

$plans = [];
if ($eventStartCol) {
    $select = [
        "e.*",
        "e.`$eventStartCol` AS dcr_start",
    ];
    if ($eventEndCol) $select[] = "e.`$eventEndCol` AS dcr_end";
    else $select[] = "NULL AS dcr_end";

    $joinDoctor = $eventDoctorIdCol && $doctorNameCol;
    if ($joinDoctor) {
        $select[] = "d.`$doctorNameCol` AS master_doctor_name";
        $select[] = $doctorHospitalCol ? "d.`$doctorHospitalCol` AS master_hospital" : "NULL AS master_hospital";
        $select[] = $doctorAreaCol ? "d.`$doctorAreaCol` AS master_area" : "NULL AS master_area";
    } else {
        $select[] = "NULL AS master_doctor_name";
        $select[] = "NULL AS master_hospital";
        $select[] = "NULL AS master_area";
    }

    $params = [$selectedDate . ' 00:00:00', $selectedDate . ' 23:59:59'];
    $where = ["e.`$eventStartCol` BETWEEN ? AND ?"];

    if ($selectedUser > 0) {
        $where[] = 'e.user_id = ?';
        $params[] = $selectedUser;
    } else {
        $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
        $where[] = "e.user_id IN ($placeholders)";
        $params = array_merge($params, $visibleIds);
    }

    $sql = 'SELECT ' . implode(', ', $select) . ', u.name AS rep_name FROM events e JOIN users u ON u.id = e.user_id ';
    if ($joinDoctor) $sql .= 'LEFT JOIN doctors_masterlist d ON d.id = e.`' . $eventDoctorIdCol . '` ';
    $sql .= 'WHERE ' . implode(' AND ', $where) . ' ORDER BY e.`' . $eventStartCol . '` ASC, e.id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $plans = $stmt->fetchAll();
}

$reports = [];
$reportDateCol = in_array('visit_datetime', $reportCols, true) ? 'visit_datetime' : (in_array('created_at', $reportCols, true) ? 'created_at' : null);
if ($reportDateCol) {
    $params = [$selectedDate . ' 00:00:00', $selectedDate . ' 23:59:59'];
    $where = ["r.`$reportDateCol` BETWEEN ? AND ?"];

    if ($selectedUser > 0) {
        $where[] = 'r.user_id = ?';
        $params[] = $selectedUser;
    } else {
        $placeholders = implode(',', array_fill(0, count($visibleIds), '?'));
        $where[] = "r.user_id IN ($placeholders)";
        $params = array_merge($params, $visibleIds);
    }

    $stmt = $pdo->prepare("
        SELECT r.*, u.name AS rep_name
        FROM reports r
        JOIN users u ON u.id = r.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY r.`$reportDateCol` ASC, r.id ASC
    ");
    $stmt->execute($params);
    $reports = $stmt->fetchAll();
}

$doctorPins = [];
$doctorIdsForPins = array_values(array_unique(array_filter(array_map(static fn($report) => (int)($report['doctor_id'] ?? 0), $reports))));

if ($doctorIdsForPins && column_exists($pdo, 'doctors_masterlist', 'clinic_latitude') && column_exists($pdo, 'doctors_masterlist', 'clinic_longitude')) {
    try {
        $radiusSelect = column_exists($pdo, 'doctors_masterlist', 'allowed_visit_radius_m') ? 'allowed_visit_radius_m' : '200 AS allowed_visit_radius_m';
        $placeholders = implode(',', array_fill(0, count($doctorIdsForPins), '?'));
        $pinStmt = $pdo->prepare("SELECT id, clinic_latitude, clinic_longitude, {$radiusSelect} FROM doctors_masterlist WHERE id IN ($placeholders)");
        $pinStmt->execute($doctorIdsForPins);
        foreach ($pinStmt->fetchAll() as $pinRow) {
            $lat = dcr_geo_float($pinRow['clinic_latitude'] ?? null);
            $lng = dcr_geo_float($pinRow['clinic_longitude'] ?? null);
            $doctorPins[(int)$pinRow['id']] = [
                'has_pin' => $lat !== null && $lng !== null,
                'lat' => $lat,
                'lng' => $lng,
                'radius_m' => (int)($pinRow['allowed_visit_radius_m'] ?? 200) ?: 200,
            ];
        }
    } catch (Throwable $e) {
        $doctorPins = [];
    }
}

$reportsByUserDoctor = [];
$reportsByUserText = [];
foreach ($reports as $report) {
    $reportDate = date('Y-m-d', strtotime((string)($report[$reportDateCol] ?? $selectedDate)));
    $doctorId = (int)($report['doctor_id'] ?? 0);
    $userId = (int)($report['user_id'] ?? 0);

    if ($doctorId > 0) {
        $reportsByUserDoctor[$userId . ':doctor:' . $doctorId . ':' . $reportDate][] = $report;
    }

    $doctor = strtolower(trim((string)($report['doctor_name'] ?? '')));
    $hospital = strtolower(trim((string)($report['hospital_name'] ?? '')));
    if ($doctor !== '' || $hospital !== '') {
        $reportsByUserText[$userId . ':text:' . md5($doctor . '|' . $hospital . '|' . $reportDate)][] = $report;
    }
}

$rows = [];
$matchedReportIds = [];

foreach ($plans as $plan) {
    $planDate = date('Y-m-d', strtotime((string)($plan['dcr_start'] ?? $selectedDate)));
    $doctorId = (int)($plan['doctor_id'] ?? 0);
    $userId = (int)($plan['user_id'] ?? 0);

    $title = trim((string)($plan['title'] ?? ''));
    $fallbackDoctorName = trim(preg_replace('/^visit:\s*/i', '', $title));
    $doctorName = trim((string)($plan['master_doctor_name'] ?? '')) ?: $fallbackDoctorName;
    $hospital = trim((string)($plan['hospital_name'] ?? '')) ?: trim((string)($plan['master_hospital'] ?? ''));
    $area = trim((string)($plan['city'] ?? '')) ?: trim((string)($plan['master_area'] ?? ''));

    $matchedReport = null;
    if ($doctorId > 0) {
        $key = $userId . ':doctor:' . $doctorId . ':' . $planDate;
        if (!empty($reportsByUserDoctor[$key])) {
            $matchedReport = array_shift($reportsByUserDoctor[$key]);
        }
    }

    if (!$matchedReport) {
        $textKey = $userId . ':text:' . md5(strtolower($doctorName) . '|' . strtolower($hospital) . '|' . $planDate);
        if (!empty($reportsByUserText[$textKey])) {
            $matchedReport = array_shift($reportsByUserText[$textKey]);
        }
    }

    if ($matchedReport) {
        $matchedReportIds[(int)$matchedReport['id']] = true;
    }

    $taskStatus = strtolower(trim((string)($plan['status'] ?? 'pending'))) ?: 'pending';
    $isPast = strtotime((string)($plan['dcr_start'] ?? '')) < time() && $selectedDate < date('Y-m-d');
    $reportStatus = $matchedReport ? strtolower((string)($matchedReport['status'] ?? 'pending')) : '';
    $completion = $matchedReport ? 'reported' : ($isPast && !in_array($taskStatus, ['completed', 'done', 'approved'], true) ? 'missed' : $taskStatus);

    $rows[] = [
        'type' => 'planned',
        'plan' => $plan,
        'report' => $matchedReport,
        'time' => $plan['dcr_start'] ?? null,
        'rep' => $plan['rep_name'] ?? '',
        'doctor_name' => $doctorName ?: 'Unlisted Doctor / Meeting',
        'hospital' => $hospital,
        'area' => $area,
        'task_status' => $taskStatus,
        'report_status' => $reportStatus,
        'completion' => $completion,
    ];
}

foreach ($reports as $report) {
    if (isset($matchedReportIds[(int)$report['id']])) continue;

    $rows[] = [
        'type' => 'unplanned_report',
        'plan' => null,
        'report' => $report,
        'time' => $report[$reportDateCol] ?? null,
        'rep' => $report['rep_name'] ?? '',
        'doctor_name' => trim((string)($report['doctor_name'] ?? 'Unplanned Report')),
        'hospital' => trim((string)($report['hospital_name'] ?? '')),
        'area' => '',
        'task_status' => 'unplanned',
        'report_status' => strtolower((string)($report['status'] ?? 'pending')),
        'completion' => 'reported',
    ];
}

usort($rows, static fn($a, $b) => strtotime((string)($a['time'] ?? '')) <=> strtotime((string)($b['time'] ?? '')));

$plannedCount = count($plans);
$reportedCount = count(array_filter($rows, static fn($row) => $row['report']));
$pendingCount = count(array_filter($rows, static fn($row) => !$row['report'] && in_array($row['completion'], ['pending', 'to do', 'in progress'], true)));
$missedCount = count(array_filter($rows, static fn($row) => $row['completion'] === 'missed'));
$geotagCount = count(array_filter($rows, static fn($row) => $row['report'] && dcr_visit_verification($row['report'], $doctorPins)['class'] === 'ok'));
$signatureCount = count(array_filter($rows, static fn($row) => $row['report'] && dcr_has_signature($row['report'])));

audit_log($pdo, 'dcr_viewed', 'dcr', null, [
    'date' => $selectedDate,
    'selected_user' => $selectedUser,
    'planned_count' => $plannedCount,
    'reported_count' => $reportedCount,
]);

render_header('Daily Call Report');
?>

<style>
.dcr-page{display:grid;gap:22px}
.dcr-card{padding:22px}
.dcr-filter{display:grid;grid-template-columns:1fr 1.15fr auto auto;gap:18px;align-items:end}
.dcr-page .field{display:flex;flex-direction:column;gap:8px;margin:0}
.dcr-page .field label{font-size:12px;line-height:1;font-weight:950;text-transform:uppercase;letter-spacing:.06em;color:#0f766e;margin:0}
.dcr-page input,
.dcr-page select{width:100%;min-height:54px;border:1px solid rgba(15,118,110,.18);border-radius:16px;background:#fff;color:#0f172a;font-size:14px;font-weight:800;padding:0 16px;box-shadow:inset 0 1px 0 rgba(255,255,255,.85);outline:none;transition:border-color .18s ease,box-shadow .18s ease,background .18s ease}
.dcr-page input[type="date"]{font-variant-numeric:tabular-nums}
.dcr-page input:focus,
.dcr-page select:focus{border-color:#0f766e;box-shadow:0 0 0 4px rgba(15,118,110,.11);background:#fff}
.dcr-page .btn{min-height:54px;border-radius:16px;padding:0 22px;white-space:nowrap}
.dcr-metrics{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:14px}
.dcr-metric{padding:18px;border:1px solid rgba(15,118,110,.13);border-radius:24px;background:linear-gradient(145deg,#fff,#fbfffe);box-shadow:0 14px 30px rgba(15,118,110,.055)}
.dcr-metric span{display:block;color:#64748b;font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.08em}
.dcr-metric strong{display:block;margin-top:8px;color:#082f2b;font-size:28px;letter-spacing:-.04em}
.dcr-status{display:inline-flex;align-items:center;min-height:30px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:950;text-transform:capitalize}
.dcr-status.ok{background:#ecfdf5;color:#15803d;border:1px solid #bbf7d0}
.dcr-status.bad{background:#fff1f2;color:#be123c;border:1px solid #fecdd3}
.dcr-status.warn{background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
.dcr-status.pending{background:#f8fafc;color:#475569;border:1px solid #e2e8f0}
.dcr-status.reported{background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe}
.dcr-doctor strong{display:block;color:#082f2b}
.dcr-doctor span{display:block;margin-top:4px;color:#64748b;font-size:12px;font-weight:750;line-height:1.4}
.dcr-actions{display:flex;gap:8px;flex-wrap:wrap}
.dcr-actions .btn{min-height:38px;border-radius:12px;padding:0 12px}
.dcr-mini{font-size:12px;color:#64748b;font-weight:750}
.dcr-empty{padding:32px;text-align:center;border:1px dashed rgba(15,118,110,.25);border-radius:20px;background:#fbfffe;color:#64748b;font-weight:850}
@media(max-width:1200px){.dcr-metrics{grid-template-columns:repeat(3,minmax(0,1fr))}.dcr-filter{grid-template-columns:1fr 1fr}}
@media(max-width:780px){.dcr-metrics,.dcr-filter{grid-template-columns:1fr}.dcr-card{padding:18px}.dcr-page .btn{width:100%}}
</style>

<div class="hero">
    <div>
        <span class="eyebrow">Daily Workflow</span>
        <h2>Daily Call Report</h2>
        <p>Review planned visits, submitted reports, pending visits, missed visits, signatures, and geotagged reports for the selected day.</p>
    </div>
    <div class="actions">
        <a class="btn ghost" href="plan_day.php?date=<?= e($selectedDate) ?>">Plan Your Day</a>
        <a class="btn ghost" href="tasks.php?from=<?= e($selectedDate) ?>&to=<?= e($selectedDate) ?>">Tasks</a>
    </div>
</div>

<div class="dcr-page">

<section class="card dcr-card">
    <form method="get" class="dcr-filter">
        <div class="field">
            <label>Date</label>
            <input type="date" name="date" value="<?= e($selectedDate) ?>">
        </div>

        <div class="field">
            <label>Sales Rep</label>
            <select name="user_id">
                <option value="0">All visible users</option>
                <?php foreach ($userOptions as $user): ?>
                    <option value="<?= (int)$user['id'] ?>" <?= $selectedUser === (int)$user['id'] ? 'selected' : '' ?>>
                        <?= e($user['name'] . ' · ' . role_label((string)$user['role'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <button class="btn primary">View DCR</button>
        <a class="btn ghost" href="dcr.php">Today</a>
    </form>
</section>

<section class="dcr-metrics">
    <article class="dcr-metric"><span>Planned</span><strong><?= (int)$plannedCount ?></strong></article>
    <article class="dcr-metric"><span>Reported</span><strong><?= (int)$reportedCount ?></strong></article>
    <article class="dcr-metric"><span>Pending</span><strong><?= (int)$pendingCount ?></strong></article>
    <article class="dcr-metric"><span>Missed</span><strong><?= (int)$missedCount ?></strong></article>
    <article class="dcr-metric"><span>Signatures</span><strong><?= (int)$signatureCount ?></strong></article>
    <article class="dcr-metric"><span>Verified</span><strong><?= (int)$geotagCount ?></strong></article>
</section>

<section class="card dcr-card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Visit Summary</span>
            <h2><?= count($rows) ?> DCR rows</h2>
        </div>
    </div>

    <?php if (!$rows): ?>
        <div class="dcr-empty">No planned visits or reports found for this day. Start by using Plan Your Day.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Rep</th>
                        <th>Doctor / Location</th>
                        <th>Task</th>
                        <th>Report</th>
                        <th>Signature</th>
                        <th>Geotag</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $plan = $row['plan'];
                            $report = $row['report'];
                            $taskId = $plan ? (int)$plan['id'] : 0;
                            $reportId = $report ? (int)$report['id'] : 0;
                            $doctorId = $plan ? (int)($plan['doctor_id'] ?? 0) : (int)($report['doctor_id'] ?? 0);
                            $statusClass = dcr_status_class((string)$row['task_status']);
                            $reportClass = $report ? dcr_status_class((string)$row['report_status']) : 'pending';
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(dcr_display_time((string)($row['time'] ?? ''))) ?></strong>
                                <div class="dcr-mini"><?= e($row['type'] === 'unplanned_report' ? 'Unplanned report' : 'Planned visit') ?></div>
                            </td>
                            <td><?= e($row['rep'] ?: 'Unknown') ?></td>
                            <td class="dcr-doctor">
                                <strong><?= e($row['doctor_name'] ?: 'Doctor / Meeting') ?></strong>
                                <span><?= e(trim(implode(' • ', array_filter([$row['hospital'], $row['area']])))) ?></span>
                            </td>
                            <td>
                                <span class="dcr-status <?= e($statusClass) ?>"><?= e($row['task_status']) ?></span>
                            </td>
                            <td>
                                <?php if ($report): ?>
                                    <span class="dcr-status <?= e($reportClass) ?>"><?= e($row['report_status'] ?: 'submitted') ?></span>
                                    <div class="dcr-mini">Report #<?= (int)$reportId ?> · <?= e(dcr_display_date((string)($report[$reportDateCol] ?? ''))) ?></div>
                                <?php else: ?>
                                    <span class="dcr-status pending">No report</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($report && dcr_has_signature($report)): ?>
                                    <span class="dcr-status ok">Signed</span>
                                <?php elseif ($report): ?>
                                    <span class="dcr-status warn">No signature</span>
                                <?php else: ?>
                                    <span class="dcr-status pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($report): ?>
                                    <?php $dcrVerification = dcr_visit_verification($report, $doctorPins); ?>
                                    <span class="dcr-status <?= e($dcrVerification['class']) ?>"><?= e($dcrVerification['label']) ?></span>
                                <?php else: ?>
                                    <span class="dcr-status pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="dcr-actions">
                                    <?php if ($report): ?>
                                        <a class="btn small ghost" href="report_view.php?id=<?= (int)$reportId ?>">View Report</a>
                                    <?php elseif ($taskId): ?>
                                        <a class="btn small primary" href="report_form.php?task=<?= (int)$taskId ?>">Create Report</a>
                                    <?php else: ?>
                                        <a class="btn small primary" href="report_form.php">Create Report</a>
                                    <?php endif; ?>

                                    <?php if ($taskId): ?>
                                        <a class="btn small ghost" href="tasks.php?from=<?= e($selectedDate) ?>&to=<?= e($selectedDate) ?>">Task</a>
                                    <?php endif; ?>

                                    <?php if ($doctorId > 0): ?>
                                        <a class="btn small ghost" href="doctor_profile.php?id=<?= (int)$doctorId ?>">Doctor</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

</div>

<?php render_footer(); ?>
