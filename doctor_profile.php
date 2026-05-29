<?php
require __DIR__ . '/app/bootstrap.php';

require_login();

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

<?php render_footer(); ?>
