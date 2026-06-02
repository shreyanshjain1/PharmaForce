<?php
require __DIR__ . '/app/bootstrap.php';

require_login();
verify_csrf();

$canManageDoctors = is_manager();

function doctor_column(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (column_exists($pdo, 'doctors_masterlist', $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function doctor_first_value(array $row, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && trim((string)$row[$key]) !== '') {
            return trim((string)$row[$key]);
        }
    }
    return $fallback;
}

$doctorNameCol = doctor_column($pdo, ['dr_name', 'doctor_name', 'name']);
$specialtyCol = doctor_column($pdo, ['speciality', 'specialty', 'specialization']);
$hospitalCol = doctor_column($pdo, ['hospital_address', 'hospital_name', 'hospital', 'address']);
$placeCol = doctor_column($pdo, ['place', 'city', 'area']);
$classCol = doctor_column($pdo, ['class', 'classification']);
$contactCol = doctor_column($pdo, ['contact_no', 'contact_number', 'phone', 'mobile']);
$emailCol = doctor_column($pdo, ['email', 'doctor_email']);
$createdAtCol = doctor_column($pdo, ['created_at']);
$updatedAtCol = doctor_column($pdo, ['updated_at']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManageDoctors) {
        http_response_code(403);
        exit('You are not allowed to manage doctors.');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create_doctor') {
        $doctorName = trim((string)($_POST['doctor_name'] ?? ''));
        $specialty = trim((string)($_POST['specialty'] ?? ''));
        $hospital = trim((string)($_POST['hospital'] ?? ''));
        $place = trim((string)($_POST['place'] ?? ''));
        $class = trim((string)($_POST['class'] ?? ''));
        $contact = trim((string)($_POST['contact'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));

        if ($doctorName === '') {
            flash('error', 'Doctor name is required.');
            header('Location: doctors.php?new=1');
            exit;
        }

        $values = [];
        if ($doctorNameCol) $values[$doctorNameCol] = $doctorName;
        if ($specialtyCol) $values[$specialtyCol] = $specialty;
        if ($hospitalCol) $values[$hospitalCol] = $hospital;
        if ($placeCol) $values[$placeCol] = $place;
        if ($classCol) $values[$classCol] = $class;
        if ($contactCol) $values[$contactCol] = $contact;
        if ($emailCol) $values[$emailCol] = $email;
        if ($createdAtCol) $values[$createdAtCol] = date('Y-m-d H:i:s');
        if ($updatedAtCol) $values[$updatedAtCol] = date('Y-m-d H:i:s');

        try {
            $newId = insert_dynamic($pdo, 'doctors_masterlist', $values);
            flash('success', 'Doctor added to the masterlist.');
            header('Location: doctor_profile.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            flash('error', 'Doctor could not be added: ' . $e->getMessage());
            header('Location: doctors.php?new=1');
            exit;
        }
    }
}

$q = trim($_GET['q'] ?? '');
$class = $_GET['class'] ?? '';
$place = trim($_GET['place'] ?? '');
$showCreate = $canManageDoctors && (($_GET['new'] ?? '') === '1');

$where = [];
$params = [];

$searchParts = [];
if ($doctorNameCol) $searchParts[] = "`{$doctorNameCol}` LIKE ?";
if ($specialtyCol) $searchParts[] = "`{$specialtyCol}` LIKE ?";
if ($hospitalCol) $searchParts[] = "`{$hospitalCol}` LIKE ?";
if ($placeCol) $searchParts[] = "`{$placeCol}` LIKE ?";
if ($contactCol) $searchParts[] = "`{$contactCol}` LIKE ?";

if ($q !== '' && $searchParts) {
    $where[] = '(' . implode(' OR ', $searchParts) . ')';
    $like = "%{$q}%";
    for ($i = 0; $i < count($searchParts); $i++) {
        $params[] = $like;
    }
}

if ($classCol && in_array($class, ['A', 'B', 'C'], true)) {
    $where[] = "`{$classCol}` = ?";
    $params[] = $class;
}

if ($place !== '' && $placeCol) {
    $where[] = "`{$placeCol}` LIKE ?";
    $params[] = "%{$place}%";
}

$orderCol = $doctorNameCol ?: 'id';
$sql = 'SELECT * FROM doctors_masterlist' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY `{$orderCol}` ASC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$doctors = $stmt->fetchAll();

$totalDoctors = 0;
try {
    $totalDoctors = (int)$pdo->query('SELECT COUNT(*) FROM doctors_masterlist')->fetchColumn();
} catch (Throwable $e) {
    $totalDoctors = count($doctors);
}

$classes = ['A', 'B', 'C'];

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
    gap: 22px;
    align-items: center;
    padding: 30px 34px;
    border: 1px solid rgba(15, 118, 110, .16);
    border-radius: 34px;
    background:
        radial-gradient(circle at 90% 15%, rgba(250, 204, 21, .20), transparent 30%),
        radial-gradient(circle at 12% 0%, rgba(20, 184, 166, .18), transparent 34%),
        linear-gradient(135deg, #ffffff 0%, #ecfffb 78%);
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
    font-size: clamp(30px, 3vw, 42px);
    line-height: 1;
    letter-spacing: -.055em;
    color: #061f1c;
}

.doctors-hero p {
    margin: 0;
    color: #607872;
    font-weight: 750;
    line-height: 1.55;
}

.doctors-hero-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
}

.doctor-create-panel {
    padding: 24px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 34px;
    background:
        radial-gradient(circle at right top, rgba(20, 184, 166, .10), transparent 28%),
        linear-gradient(145deg, #ffffff, #fbfffe);
    box-shadow: 0 16px 36px rgba(15, 118, 110, .065);
}

.doctor-create-panel .section-title {
    padding-bottom: 14px;
    border-bottom: 1px solid rgba(15, 118, 110, .10);
}

.doctor-create-grid {
    display: grid;
    grid-template-columns: minmax(260px, 1.2fr) minmax(220px, .8fr) minmax(180px, .55fr);
    gap: 14px;
}

.doctor-create-grid .field {
    padding: 14px 16px;
    border: 1px solid rgba(15, 118, 110, .12);
    border-radius: 24px;
    background: rgba(255, 255, 255, .86);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .92);
}

.doctor-create-grid .field:focus-within {
    border-color: #16b8a8;
    box-shadow: 0 0 0 4px rgba(20, 184, 166, .12), 0 14px 28px rgba(15, 118, 110, .065);
    background: #ffffff;
}

.doctor-create-grid .field.full {
    grid-column: 1 / -1;
}

.doctor-create-grid input,
.doctor-create-grid select,
.doctor-create-grid textarea {
    min-height: 38px !important;
    padding: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    font-weight: 850 !important;
}

.doctor-filter-card {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) 180px minmax(200px, .7fr) auto auto;
    gap: 12px;
    align-items: end;
    padding: 18px;
    border-radius: 32px;
    background: linear-gradient(135deg, #ffffff, #f8fffd);
}

.doctor-filter-card .field {
    min-width: 0;
}

.doctors-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.doctor-stat-card {
    position: relative;
    overflow: hidden;
    padding: 20px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 28px;
    background: linear-gradient(145deg, #ffffff, #fbfffe);
    box-shadow: 0 12px 28px rgba(15, 118, 110, .055);
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
    margin-top: 6px;
    color: #607872;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .09em;
    font-weight: 950;
}

.doctor-stat-card strong {
    display: block;
    margin-top: 8px;
    color: #061f1c;
    font-size: 30px;
    line-height: 1;
    letter-spacing: -.045em;
}

.doctors-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 16px;
}

.doctor-card.upgraded {
    position: relative;
    overflow: hidden;
    padding: 20px;
    border: 1px solid rgba(15, 118, 110, .14);
    border-radius: 30px;
    background:
        radial-gradient(circle at right top, rgba(20, 184, 166, .08), transparent 28%),
        linear-gradient(145deg, #ffffff, #fbfffe);
    box-shadow: 0 14px 34px rgba(15, 118, 110, .065);
}

.doctor-card.upgraded::before {
    content: "";
    position: absolute;
    top: 12px;
    left: 20px;
    right: 20px;
    height: 5px;
    border-radius: 999px;
    background: linear-gradient(90deg, #0f766e, #14b8a6, #facc15);
}

.doctor-card-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding-top: 8px;
}

.doctor-card h3 {
    margin: 10px 0 6px;
    color: #061f1c;
    font-size: 20px;
    letter-spacing: -.035em;
}

.doctor-meta {
    display: grid;
    gap: 7px;
    margin: 12px 0;
    color: #486a64;
    font-weight: 750;
    line-height: 1.35;
}

.doctor-card-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
}

.doctor-card-actions .btn {
    flex: 1 1 auto;
}

.doctor-class-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 7px 11px;
    border-radius: 999px;
    border: 1px solid rgba(15, 118, 110, .16);
    background: #ecfdf5;
    color: #0f766e;
    font-size: 12px;
    font-weight: 950;
}

@media(max-width: 1180px) {
    .doctors-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .doctor-filter-card,
    .doctor-create-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media(max-width: 760px) {
    .doctors-hero {
        display: grid;
        padding: 24px 22px;
    }

    .doctors-hero-actions,
    .doctors-hero-actions .btn,
    .doctor-card-actions .btn {
        width: 100%;
    }

    .doctor-filter-card,
    .doctor-create-grid,
    .doctors-stats,
    .doctors-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="doctors-center">
    <section class="doctors-hero">
        <div>
            <span class="eyebrow">Masterlist</span>
            <h2>Doctor coverage directory</h2>
            <p>Search doctors, view history, generate reports, and maintain the masterlist used by the sales field team.</p>
        </div>

        <div class="doctors-hero-actions">
            <?php if ($canManageDoctors): ?>
                <a class="btn primary" href="doctors.php?new=1">Add New Doctor</a>
            <?php endif; ?>
            <a class="btn ghost" href="report_form.php">Create Report</a>
        </div>
    </section>

    <?php if ($showCreate): ?>
        <form class="doctor-create-panel" method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="create_doctor">

            <div class="section-title">
                <div>
                    <span class="eyebrow">New Masterlist Record</span>
                    <h2>Add doctor</h2>
                    <p class="muted">Available to Manager and District Manager accounts.</p>
                </div>
                <a class="btn ghost" href="doctors.php">Cancel</a>
            </div>

            <div class="doctor-create-grid">
                <div class="field">
                    <label>Doctor Name</label>
                    <input class="input" name="doctor_name" required placeholder="Dr. Full Name">
                </div>

                <div class="field">
                    <label>Specialty</label>
                    <input class="input" name="specialty" placeholder="Cardiology, Pathology, etc.">
                </div>

                <div class="field">
                    <label>Class</label>
                    <select name="class">
                        <option value="">Select class</option>
                        <?php foreach ($classes as $c): ?>
                            <option value="<?= e($c) ?>"><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field full">
                    <label>Hospital / Clinic / Address</label>
                    <textarea name="hospital" rows="2" placeholder="Hospital, clinic, or address"></textarea>
                </div>

                <div class="field">
                    <label>City / Place / Area</label>
                    <input class="input" name="place" placeholder="City or coverage area">
                </div>

                <div class="field">
                    <label>Contact Number</label>
                    <input class="input" name="contact" placeholder="Contact number">
                </div>

                <div class="field">
                    <label>Email</label>
                    <input class="input" type="email" name="email" placeholder="doctor@example.com">
                </div>
            </div>

            <div class="actions" style="margin-top:18px;justify-content:flex-end">
                <a class="btn ghost" href="doctors.php">Cancel</a>
                <button class="btn primary">Save Doctor</button>
            </div>
        </form>
    <?php endif; ?>

    <section class="doctors-stats">
        <div class="doctor-stat-card">
            <span>Total Doctors</span>
            <strong><?= number_format($totalDoctors) ?></strong>
        </div>
        <div class="doctor-stat-card">
            <span>Showing</span>
            <strong><?= number_format(count($doctors)) ?></strong>
        </div>
        <div class="doctor-stat-card">
            <span>Access</span>
            <strong><?= $canManageDoctors ? 'Manage' : 'View' ?></strong>
        </div>
    </section>

    <form class="card doctor-filter-card" method="get">
        <div class="field">
            <label>Search</label>
            <input class="input" name="q" value="<?= e($q) ?>" placeholder="Doctor, hospital, specialty, city">
        </div>

        <div class="field">
            <label>Class</label>
            <select name="class">
                <option value="">All</option>
                <?php foreach ($classes as $c): ?>
                    <option value="<?= e($c) ?>" <?= $class === $c ? 'selected' : '' ?>><?= e($c) ?></option>
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

    <?php if (!$doctors): ?>
        <div class="empty doctors-empty">
            <p class="empty-note">No doctor records match your current search. Try a wider city/place filter or add the doctor to the masterlist.</p>
            <div class="empty-actions">
                <?php if ($canManageDoctors): ?><a class="btn primary" href="doctors.php?new=1">Add New Doctor</a><?php endif; ?>
                <a class="btn ghost" href="doctors.php">Reset Filters</a>
            </div>
        </div>
    <?php else: ?>
        <section class="doctors-grid">
            <?php foreach ($doctors as $d): ?>
                <?php
                    $doctorName = doctor_first_value($d, ['dr_name', 'doctor_name', 'name'], 'Unnamed Doctor');
                    $specialty = doctor_first_value($d, ['speciality', 'specialty', 'specialization'], 'Specialty not provided');
                    $hospital = doctor_first_value($d, ['hospital_address', 'hospital_name', 'hospital', 'address'], 'Hospital/address not provided');
                    $doctorPlace = doctor_first_value($d, ['place', 'city', 'area'], 'Area not provided');
                    $doctorClass = doctor_first_value($d, ['class', 'classification'], 'N/A');
                    $contact = doctor_first_value($d, ['contact_no', 'contact_number', 'phone', 'mobile'], 'Contact not provided');
                ?>
                <article class="doctor-card upgraded">
                    <div class="doctor-card-top">
                        <span class="doctor-class-pill">Class <?= e($doctorClass) ?></span>
                    </div>

                    <h3><?= e($doctorName) ?></h3>

                    <div class="doctor-meta">
                        <span><?= e($specialty) ?></span>
                        <span><?= e($hospital) ?></span>
                        <span><?= e($doctorPlace) ?> &middot; <?= e($contact) ?></span>
                    </div>

                    <div class="doctor-card-actions">
                        <a class="btn small ghost" href="doctor_profile.php?id=<?= (int)$d['id'] ?>">View History</a>
                        <a class="btn small primary" href="report_form.php?doctor=<?= (int)$d['id'] ?>">Create Report</a>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
