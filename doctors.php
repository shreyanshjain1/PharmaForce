<?php
require __DIR__ . '/app/bootstrap.php';

require_login();

$q = trim($_GET['q'] ?? '');
$class = $_GET['class'] ?? '';
$place = trim($_GET['place'] ?? '');

$where = [];
$params = [];

if ($q !== '') {
    $where[] = '(dr_name LIKE ? OR speciality LIKE ? OR hospital_address LIKE ? OR place LIKE ? OR email LIKE ? OR contact_no LIKE ?)';
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

if (in_array($class, ['A', 'B', 'C'], true)) {
    $where[] = 'class = ?';
    $params[] = $class;
}

if ($place !== '') {
    $where[] = 'place LIKE ?';
    $params[] = "%$place%";
}

$sql = 'SELECT * FROM doctors_masterlist' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY dr_name ASC LIMIT 300';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$doctors = $stmt->fetchAll();

$reportCounts = [];
$lastVisits = [];

if ($doctors) {
    try {
        [$scopeSql, $scopeParams] = scope_clause($pdo, 'r');
        $reportSql = "
            SELECT r.doctor_name, r.doctor_email, r.hospital_name, MAX(r.visit_datetime) AS last_visit, COUNT(*) AS total_reports
            FROM reports r
            WHERE $scopeSql
            GROUP BY r.doctor_name, r.doctor_email, r.hospital_name
        ";
        $stmt = $pdo->prepare($reportSql);
        $stmt->execute($scopeParams);
        $reportRows = $stmt->fetchAll();

        foreach ($doctors as $doctor) {
            $id = (int)$doctor['id'];
            $name = strtolower(trim((string)($doctor['dr_name'] ?? '')));
            $email = strtolower(trim((string)($doctor['email'] ?? '')));
            $hospital = strtolower(trim((string)($doctor['hospital_address'] ?? '')));

            foreach ($reportRows as $row) {
                $reportName = strtolower(trim((string)($row['doctor_name'] ?? '')));
                $reportEmail = strtolower(trim((string)($row['doctor_email'] ?? '')));
                $reportHospital = strtolower(trim((string)($row['hospital_name'] ?? '')));

                $matched = false;

                if ($name !== '' && $reportName !== '' && ($name === $reportName || str_contains($reportName, $name) || str_contains($name, $reportName))) {
                    $matched = true;
                }

                if (!$matched && $email !== '' && $reportEmail !== '' && $email === $reportEmail) {
                    $matched = true;
                }

                if (!$matched && $hospital !== '' && $reportHospital !== '' && ($hospital === $reportHospital || str_contains($reportHospital, $hospital) || str_contains($hospital, $reportHospital))) {
                    $matched = true;
                }

                if ($matched) {
                    $reportCounts[$id] = ($reportCounts[$id] ?? 0) + (int)$row['total_reports'];
                    $last = $row['last_visit'] ?? null;
                    if ($last && (empty($lastVisits[$id]) || strtotime($last) > strtotime($lastVisits[$id]))) {
                        $lastVisits[$id] = $last;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        $reportCounts = [];
        $lastVisits = [];
    }
}

function doctors_short_date(?string $value): string
{
    if (!$value) {
        return 'No visits yet';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y', $time) : $value;
}

render_header('Doctors');
?>

<style>
.doctors-center {
    display: grid;
    gap: 18px;
}

.doctors-hero {
    position: relative;
    overflow: hidden;
    display: flex;
    justify-content: space-between;
    gap: 18px;
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

.doctors-hero::before {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 7px;
    background: linear-gradient(180deg, #0f766e, #14b8a6, #facc15);
    border-radius: 999px;
}

.doctors-hero h2 {
    margin: 4px 0 8px;
    font-size: clamp(28px, 3vw, 40px);
    letter-spacing: -.055em;
    color: #061f1c;
}

.doctors-hero p {
    margin: 0;
    color: #59736d;
    font-size: 16px;
    font-weight: 700;
}

.doctor-filter-card {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 170px minmax(190px, .7fr) auto auto;
    gap: 12px;
    align-items: end;
    padding: 18px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 30px;
    background: linear-gradient(135deg, #ffffff, #f8fffd);
    box-shadow: 0 14px 34px rgba(15, 118, 110, .055);
}

.doctor-filter-card .field {
    min-width: 0;
    padding: 14px 16px;
    border: 1px solid rgba(15, 118, 110, .12);
    border-radius: 24px;
    background: rgba(255, 255, 255, .86);
}

.doctor-filter-card .field input,
.doctor-filter-card .field select {
    min-height: 36px !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
}

.doctor-directory-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.doctor-profile-card {
    position: relative;
    overflow: hidden;
    display: grid;
    gap: 14px;
    padding: 20px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 30px;
    background:
        radial-gradient(circle at right top, rgba(20, 184, 166, .08), transparent 28%),
        linear-gradient(145deg, #ffffff, #fbfffe);
    box-shadow: 0 14px 34px rgba(15, 118, 110, .065);
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
}

.doctor-profile-card::before {
    content: "";
    position: absolute;
    top: 12px;
    left: 20px;
    right: 20px;
    height: 5px;
    border-radius: 999px;
    background: linear-gradient(90deg, #0f766e, #14b8a6, #facc15);
}

.doctor-profile-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 20px 48px rgba(15, 118, 110, .10);
    border-color: rgba(20, 184, 166, .34);
}

.doctor-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding-top: 10px;
}

.doctor-card-top h3 {
    margin: 0;
    color: #061f1c;
    font-size: 20px;
    letter-spacing: -.04em;
}

.doctor-card-top p {
    margin: 5px 0 0;
    color: #607872;
    font-size: 13px;
    font-weight: 750;
    line-height: 1.45;
}

.doctor-class-badge {
    display: inline-flex;
    min-width: 42px;
    justify-content: center;
    padding: 8px 10px;
    border-radius: 999px;
    background: #ecfdf5;
    border: 1px solid #99f6e4;
    color: #0f766e;
    font-size: 12px;
    font-weight: 950;
}

.doctor-card-meta {
    display: grid;
    gap: 8px;
}

.doctor-card-meta span {
    color: #607872;
    font-weight: 750;
    line-height: 1.45;
}

.doctor-card-stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.doctor-card-stat {
    padding: 12px;
    border-radius: 20px;
    border: 1px solid rgba(15, 118, 110, .12);
    background: rgba(255, 255, 255, .76);
}

.doctor-card-stat span {
    display: block;
    color: #607872;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .08em;
    font-weight: 950;
}

.doctor-card-stat strong {
    display: block;
    margin-top: 5px;
    color: #082f2b;
    font-weight: 950;
}

.doctor-card-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}

@media (max-width: 1260px) {
    .doctor-directory-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .doctor-filter-card {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 760px) {
    .doctors-hero {
        display: grid;
        padding: 24px;
    }

    .doctor-directory-grid,
    .doctor-filter-card,
    .doctor-card-actions {
        grid-template-columns: 1fr;
    }

    .doctor-filter-card .btn {
        width: 100%;
    }
}
</style>

<div class="doctors-center">
    <section class="doctors-hero">
        <div>
            <span class="eyebrow">Masterlist</span>
            <h2>Doctor coverage directory</h2>
            <p>Search doctors, hospitals, places, and class levels from the production masterlist.</p>
        </div>
        <a class="btn primary" href="report_form.php">New Report</a>
    </section>

    <form class="doctor-filter-card">
        <div class="field">
            <label>Search</label>
            <input class="input" name="q" value="<?= e($q) ?>" placeholder="Doctor, hospital, specialty, contact">
        </div>
        <div class="field">
            <label>Class</label>
            <select name="class">
                <option value="">All Classes</option>
                <?php foreach (['A', 'B', 'C'] as $c): ?>
                    <option value="<?= e($c) ?>" <?= $class === $c ? 'selected' : '' ?>>Class <?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Place</label>
            <input class="input" name="place" value="<?= e($place) ?>" placeholder="City / area">
        </div>
        <button class="btn primary">Search</button>
        <a class="btn ghost" href="doctors.php">Reset</a>
    </form>

    <?php if ($doctors): ?>
        <div class="doctor-directory-grid">
            <?php foreach ($doctors as $d): ?>
                <?php
                    $doctorId = (int)$d['id'];
                    $totalReports = (int)($reportCounts[$doctorId] ?? 0);
                    $lastVisit = $lastVisits[$doctorId] ?? null;
                ?>
                <article class="doctor-profile-card">
                    <div class="doctor-card-top">
                        <div>
                            <h3><?= e($d['dr_name'] ?? 'Unnamed Doctor') ?></h3>
                            <p><?= e($d['speciality'] ?: 'Specialty not provided') ?></p>
                        </div>
                        <span class="doctor-class-badge">Class <?= e($d['class'] ?: 'N/A') ?></span>
                    </div>

                    <div class="doctor-card-meta">
                        <span><?= e($d['hospital_address'] ?: 'Hospital not provided') ?></span>
                        <span><?= e($d['place'] ?: 'Place not provided') ?> &middot; <?= e($d['contact_no'] ?: 'No contact') ?></span>
                    </div>

                    <div class="doctor-card-stats">
                        <div class="doctor-card-stat">
                            <span>Visits</span>
                            <strong><?= number_format($totalReports) ?></strong>
                        </div>
                        <div class="doctor-card-stat">
                            <span>Last Visit</span>
                            <strong><?= e(doctors_short_date($lastVisit)) ?></strong>
                        </div>
                    </div>

                    <div class="doctor-card-actions">
                        <a class="btn ghost" href="doctor_profile.php?id=<?= $doctorId ?>">View History</a>
                        <a class="btn primary" href="report_form.php?doctor=<?= $doctorId ?>">Create Report</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            No doctors found for your filters.
        </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
