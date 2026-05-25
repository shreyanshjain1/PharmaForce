<?php
require __DIR__ . '/app/bootstrap.php';

require_login();
verify_csrf();

[$scopeSql, $scopeParams] = scope_clause($pdo, 'r');

$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT 
        r.*,
        u.name AS rep,
        u.email AS rep_email
    FROM reports r
    JOIN users u ON u.id = r.user_id
    WHERE r.id = ? AND $scopeSql
    LIMIT 1
");
$stmt->execute(array_merge([$id], $scopeParams));
$r = $stmt->fetch();

if (!$r) {
    http_response_code(404);
    exit('Report not found');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_manager()) {
    $status = normalize_status($_POST['status'] ?? 'pending');
    $comment = trim($_POST['manager_comment'] ?? '');

    $update = $pdo->prepare("
        UPDATE reports 
        SET status = ?, manager_comment = ?
        WHERE id = ?
    ");
    $update->execute([$status, $comment, $id]);

    flash('success', 'Manager review saved.');
    header('Location: report_view.php?id=' . $id);
    exit;
}

$signaturePath = trim((string)($r['signature_path'] ?? ''));
$attachmentPath = trim((string)($r['attachment_path'] ?? ''));

function report_value($value, $fallback = 'Not provided')
{
    $value = trim((string)$value);
    return $value !== '' ? $value : $fallback;
}

function report_date($value)
{
    if (!$value) {
        return 'Not provided';
    }

    $time = strtotime($value);
    return $time ? date('M d, Y g:i A', $time) : $value;
}

function is_image_attachment($path)
{
    $path = strtolower(trim((string)$path));

    if ($path === '') {
        return false;
    }

    $extension = pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION);

    return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true);
}

function attachment_file_name($path)
{
    $path = trim((string)$path);

    if ($path === '') {
        return 'Attachment';
    }

    $file = basename(parse_url($path, PHP_URL_PATH) ?? $path);
    return $file !== '' ? $file : 'Attachment';
}

$hasAttachment = $attachmentPath !== '';
$attachmentIsImage = is_image_attachment($attachmentPath);

render_header('Report Details');
?>

<style>
    .report-page {
        display: grid;
        gap: 1.35rem;
    }

    .report-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.25rem 1.35rem;
        border: 1px solid rgba(15, 118, 110, 0.16);
        border-radius: 24px;
        background:
            radial-gradient(circle at top left, rgba(20, 184, 166, 0.12), transparent 34%),
            linear-gradient(135deg, #ffffff, #f8fffd);
        box-shadow: 0 18px 45px rgba(15, 23, 42, 0.07);
    }

    .report-toolbar h2 {
        margin: 0;
        font-size: clamp(1.45rem, 2vw, 2.15rem);
        line-height: 1.05;
        color: #092f2b;
    }

    .report-toolbar p {
        margin: .45rem 0 0;
        color: #64748b;
    }

    .report-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: .65rem;
    }

    .print-btn {
        border: none;
        cursor: pointer;
    }

    .report-print-sheet {
        display: grid;
        gap: 1.15rem;
    }

    .report-document {
        overflow: hidden;
        border: 1px solid rgba(15, 118, 110, 0.16);
        border-radius: 28px;
        background: #ffffff;
        box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08);
    }

    .report-document-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1.25rem;
        padding: 1.5rem;
        background:
            linear-gradient(135deg, rgba(15, 118, 110, 0.96), rgba(20, 184, 166, 0.92)),
            radial-gradient(circle at right top, rgba(250, 204, 21, 0.35), transparent 36%);
        color: #ffffff;
    }

    .report-brand {
        display: flex;
        align-items: center;
        gap: .8rem;
    }

    .report-logo {
        width: 52px;
        height: 52px;
        display: grid;
        place-items: center;
        border-radius: 18px;
        background: rgba(255, 255, 255, 0.16);
        border: 1px solid rgba(255, 255, 255, 0.28);
        font-weight: 900;
        letter-spacing: .04em;
    }

    .report-brand h1 {
        margin: 0;
        font-size: 1.45rem;
        line-height: 1.1;
    }

    .report-brand p,
    .report-meta p {
        margin: .25rem 0 0;
        color: rgba(255, 255, 255, 0.82);
    }

    .report-meta {
        text-align: right;
    }

    .report-meta strong {
        display: block;
        font-size: 1.05rem;
    }

    .report-body {
        padding: 1.35rem;
        display: grid;
        gap: 1.15rem;
    }

    .report-section {
        border: 1px solid rgba(15, 118, 110, 0.13);
        border-radius: 22px;
        background: #ffffff;
        overflow: hidden;
    }

    .report-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1rem 1.1rem;
        border-bottom: 1px solid rgba(15, 118, 110, 0.1);
        background: #f8fffd;
    }

    .report-section-head h3 {
        margin: .2rem 0 0;
        color: #0f172a;
        font-size: 1.15rem;
    }

    .report-section-content {
        padding: 1.1rem;
    }

    .report-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: .85rem;
    }

    .report-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: .85rem;
    }

    .report-field {
        min-height: 86px;
        padding: .9rem;
        border: 1px solid rgba(15, 118, 110, 0.12);
        border-radius: 18px;
        background: #fbfffd;
    }

    .report-field.full {
        grid-column: 1 / -1;
    }

    .report-field span {
        display: block;
        margin-bottom: .4rem;
        font-size: .72rem;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
        color: #64748b;
    }

    .report-field strong {
        display: block;
        color: #0f172a;
        font-size: .98rem;
        line-height: 1.4;
    }

    .report-field p {
        margin: 0;
        color: #334155;
        line-height: 1.6;
        white-space: normal;
    }

    .report-media-layout {
        display: grid;
        grid-template-columns: minmax(280px, 1.2fr) minmax(280px, .8fr);
        gap: 1rem;
        align-items: stretch;
    }

    .attachment-preview-card,
    .signature-preview-card {
        display: grid;
        gap: .85rem;
        padding: 1rem;
        border: 1px solid rgba(15, 118, 110, 0.12);
        border-radius: 22px;
        background: #fbfffd;
    }

    .attachment-preview-card h4,
    .signature-preview-card h4 {
        margin: 0;
        color: #0f172a;
        font-size: 1rem;
    }

    .attachment-frame,
    .signature-preview {
        min-height: 220px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1rem;
        border: 1px dashed rgba(15, 118, 110, 0.35);
        border-radius: 20px;
        background:
            linear-gradient(45deg, rgba(15, 118, 110, 0.035) 25%, transparent 25%),
            linear-gradient(-45deg, rgba(15, 118, 110, 0.035) 25%, transparent 25%),
            #ffffff;
        background-size: 22px 22px;
    }

    .attachment-frame img {
        width: 100%;
        max-height: 460px;
        object-fit: contain;
        border-radius: 16px;
        display: block;
        box-shadow: 0 14px 30px rgba(15, 23, 42, 0.12);
        background: #ffffff;
    }

    .signature-preview {
        min-height: 170px;
    }

    .signature-preview img {
        width: 100%;
        max-width: 380px;
        max-height: 160px;
        object-fit: contain;
        display: block;
        filter: contrast(1.08);
    }

    .media-caption {
        padding: .85rem;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid rgba(15, 118, 110, 0.1);
    }

    .media-caption p {
        margin: .35rem 0 0;
        color: #64748b;
        line-height: 1.55;
    }

    .attachment-empty,
    .signature-empty {
        text-align: center;
        color: #64748b;
        font-weight: 800;
    }

    .attachment-file-box {
        display: grid;
        gap: .65rem;
        width: 100%;
        max-width: 420px;
        text-align: center;
        padding: 1.25rem;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid rgba(15, 118, 110, 0.12);
    }

    .attachment-file-icon {
        width: 58px;
        height: 58px;
        display: grid;
        place-items: center;
        margin: 0 auto;
        border-radius: 20px;
        background: rgba(15, 118, 110, 0.1);
        color: #0f766e;
        font-size: 1.45rem;
        font-weight: 900;
    }

    .manager-review-card {
        border: 1px solid rgba(15, 118, 110, 0.16);
        border-radius: 24px;
        background: #ffffff;
        box-shadow: 0 16px 42px rgba(15, 23, 42, 0.07);
        padding: 1.15rem;
    }

    .manager-review-card h2 {
        margin-top: .2rem;
    }

    .manager-review-grid {
        display: grid;
        grid-template-columns: minmax(220px, 320px) 1fr auto;
        gap: 1rem;
        align-items: end;
    }

    @media (max-width: 1080px) {
        .report-media-layout {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 980px) {
        .report-toolbar,
        .report-document-header {
            flex-direction: column;
        }

        .report-meta {
            text-align: left;
        }

        .report-grid-2,
        .report-grid-3,
        .manager-review-grid {
            grid-template-columns: 1fr;
        }
    }

    @media print {
        @page {
            size: A4;
            margin: 10mm;
        }

        body {
            background: #ffffff !important;
        }

        body * {
            visibility: hidden;
        }

        .report-print-sheet,
        .report-print-sheet * {
            visibility: visible;
        }

        .report-print-sheet {
            position: absolute;
            inset: 0;
            width: 100%;
            display: block;
        }

        .no-print,
        .no-print *,
        .sidebar,
        .app-sidebar,
        .topbar,
        .app-topbar,
        .header,
        .site-header,
        nav,
        aside {
            display: none !important;
            visibility: hidden !important;
        }

        .app-main,
        main,
        .content,
        .page-content {
            margin: 0 !important;
            padding: 0 !important;
            width: 100% !important;
            max-width: none !important;
        }

        .report-document {
            box-shadow: none !important;
            border: 1px solid #d7e5e1 !important;
            border-radius: 0 !important;
            overflow: visible !important;
        }

        .report-document-header {
            color: #ffffff !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            border-radius: 0 !important;
            padding: 14px !important;
        }

        .report-body {
            padding: 12px !important;
            gap: 9px !important;
        }

        .report-section {
            break-inside: avoid;
            page-break-inside: avoid;
            border-radius: 10px !important;
        }

        .report-section-head {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            padding: 9px 11px !important;
        }

        .report-section-content {
            padding: 10px !important;
        }

        .report-grid-2,
        .report-grid-3 {
            grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
            gap: 7px !important;
        }

        .report-field {
            min-height: auto !important;
            padding: 8px !important;
            border-radius: 10px !important;
        }

        .report-media-layout {
            grid-template-columns: 1fr !important;
            gap: 8px !important;
        }

        .attachment-preview-card,
        .signature-preview-card {
            padding: 8px !important;
            border-radius: 10px !important;
            break-inside: avoid;
            page-break-inside: avoid;
        }

        .attachment-frame {
            min-height: 180px !important;
            border-radius: 10px !important;
            padding: 8px !important;
        }

        .attachment-frame img {
            max-height: 360px !important;
            border-radius: 8px !important;
            box-shadow: none !important;
        }

        .signature-preview {
            min-height: 115px !important;
            border-radius: 10px !important;
            padding: 8px !important;
        }

        .signature-preview img {
            max-height: 105px !important;
        }

        .media-caption {
            padding: 7px !important;
            border-radius: 8px !important;
        }

        .btn,
        button {
            display: none !important;
        }
    }
</style>

<div class="report-page">
    <div class="report-toolbar no-print">
        <div>
            <span class="eyebrow">Report #<?= (int)$r['id'] ?></span>
            <h2><?= e(report_value($r['doctor_name'], 'Doctor not provided')) ?></h2>
            <p>
                <?= e(report_value($r['hospital_name'], 'Hospital not provided')) ?>
                &middot;
                <?= e(report_date($r['visit_datetime'])) ?>
            </p>
        </div>

        <div class="report-actions">
            <a class="btn ghost" href="reports.php">Back</a>
            <a class="btn ghost" href="report_form.php?id=<?= (int)$r['id'] ?>">Edit</a>
            <button type="button" class="btn primary print-btn" onclick="window.print()">Export to PDF / Print</button>
        </div>
    </div>

    <div class="report-print-sheet">
        <article class="report-document">
            <header class="report-document-header">
                <div class="report-brand">
                    <div class="report-logo">PS</div>
                    <div>
                        <h1>Pharmastar Sales Report</h1>
                        <p>Medicine Sales CRM &middot; Field Visit Documentation</p>
                    </div>
                </div>

                <div class="report-meta">
                    <strong>Report #<?= (int)$r['id'] ?></strong>
                    <p>Status: <?= e(status_label($r['status'])) ?></p>
                    <p>Created: <?= e(report_date($r['created_at'])) ?></p>
                </div>
            </header>

            <div class="report-body">
                <section class="report-section">
                    <div class="report-section-head">
                        <div>
                            <span class="eyebrow">Visit Details</span>
                            <h3>Report Information</h3>
                        </div>
                        <span class="badge <?= e($r['status']) ?>"><?= e(status_label($r['status'])) ?></span>
                    </div>

                    <div class="report-section-content">
                        <div class="report-grid-3">
                            <div class="report-field">
                                <span>Sales Rep</span>
                                <strong><?= e(report_value($r['rep'])) ?></strong>
                                <p><?= e(report_value($r['rep_email'])) ?></p>
                            </div>

                            <div class="report-field">
                                <span>Visit Date</span>
                                <strong><?= e(report_date($r['visit_datetime'])) ?></strong>
                            </div>

                            <div class="report-field">
                                <span>Status</span>
                                <strong><?= e(status_label($r['status'])) ?></strong>
                            </div>

                            <div class="report-field">
                                <span>Purpose</span>
                                <strong><?= e(report_value($r['purpose'])) ?></strong>
                            </div>

                            <div class="report-field">
                                <span>Medicine / Product</span>
                                <strong><?= e(report_value($r['medicine_name'])) ?></strong>
                            </div>

                            <div class="report-field">
                                <span>Report ID</span>
                                <strong>#<?= (int)$r['id'] ?></strong>
                            </div>

                            <div class="report-field full">
                                <span>Summary</span>
                                <p><?= nl2br(e(report_value($r['summary'], 'No summary provided.'))) ?></p>
                            </div>

                            <div class="report-field full">
                                <span>Remarks</span>
                                <p><?= nl2br(e(report_value($r['remarks'], 'No remarks provided.'))) ?></p>
                            </div>

                            <?php if (trim((string)($r['manager_comment'] ?? '')) !== ''): ?>
                                <div class="report-field full">
                                    <span>Manager Comment</span>
                                    <p><?= nl2br(e($r['manager_comment'])) ?></p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <section class="report-section">
                    <div class="report-section-head">
                        <div>
                            <span class="eyebrow">Doctor Profile</span>
                            <h3>Client / Hospital Details</h3>
                        </div>
                    </div>

                    <div class="report-section-content">
                        <div class="report-grid-2">
                            <div class="report-field">
                                <span>Doctor Name</span>
                                <strong><?= e(report_value($r['doctor_name'])) ?></strong>
                            </div>

                            <div class="report-field">
                                <span>Doctor Email</span>
                                <strong><?= e(report_value($r['doctor_email'])) ?></strong>
                            </div>

                            <div class="report-field full">
                                <span>Hospital / Clinic</span>
                                <strong><?= e(report_value($r['hospital_name'])) ?></strong>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="report-section">
                    <div class="report-section-head">
                        <div>
                            <span class="eyebrow">Evidence & Confirmation</span>
                            <h3>Attachment and Signature</h3>
                        </div>
                    </div>

                    <div class="report-section-content">
                        <div class="report-media-layout">
                            <div class="attachment-preview-card">
                                <div>
                                    <h4>Uploaded Attachment</h4>
                                    <p class="muted">
                                        <?= $hasAttachment ? e(attachment_file_name($attachmentPath)) : 'No attachment uploaded for this report.' ?>
                                    </p>
                                </div>

                                <div class="attachment-frame">
                                    <?php if ($hasAttachment && $attachmentIsImage): ?>
                                        <img src="<?= e($attachmentPath) ?>" alt="Uploaded attachment for report #<?= (int)$r['id'] ?>">
                                    <?php elseif ($hasAttachment): ?>
                                        <div class="attachment-file-box">
                                            <div class="attachment-file-icon">FILE</div>
                                            <strong><?= e(attachment_file_name($attachmentPath)) ?></strong>
                                            <p class="muted">This attachment is not an image preview. Open it using the button below.</p>
                                            <a class="btn small ghost no-print" target="_blank" href="<?= e($attachmentPath) ?>">Open Attachment</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="attachment-empty">
                                            No attachment uploaded.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($hasAttachment): ?>
                                    <div class="media-caption">
                                        <strong>Attachment Record</strong>
                                        <p>
                                            This file was submitted as supporting documentation for the field visit report.
                                        </p>
                                        <p class="no-print" style="margin-top:.75rem;">
                                            <a class="btn small ghost" target="_blank" href="<?= e($attachmentPath) ?>">Open Original Attachment</a>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="signature-preview-card">
                                <div>
                                    <h4>Doctor Signature</h4>
                                    <p class="muted">
                                        <?= $signaturePath !== '' ? 'Captured signature is shown below.' : 'No signature captured for this report.' ?>
                                    </p>
                                </div>

                                <div class="signature-preview">
                                    <?php if ($signaturePath !== ''): ?>
                                        <img src="<?= e($signaturePath) ?>" alt="Doctor signature for report #<?= (int)$r['id'] ?>">
                                    <?php else: ?>
                                        <div class="signature-empty">
                                            No signature captured.
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="media-caption">
                                    <strong>Signature Confirmation</strong>
                                    <p>
                                        This signature confirms the field visit report details recorded by
                                        <?= e(report_value($r['rep'], 'the sales representative')) ?>
                                        for <?= e(report_value($r['doctor_name'], 'the doctor')) ?>.
                                    </p>

                                    <?php if ($signaturePath !== ''): ?>
                                        <p class="no-print" style="margin-top:.75rem;">
                                            <a class="btn small ghost" target="_blank" href="<?= e($signaturePath) ?>">Open Signature Image</a>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </article>
    </div>

    <?php if (is_manager()): ?>
        <form class="manager-review-card no-print" method="post">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">

            <div class="section-title">
                <div>
                    <span class="eyebrow">Review</span>
                    <h2>Manager Action</h2>
                </div>
            </div>

            <div class="manager-review-grid">
                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['pending', 'approved', 'needs_changes'] as $s): ?>
                            <option value="<?= e($s) ?>" <?= $r['status'] === $s ? 'selected' : '' ?>>
                                <?= e(status_label($s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Comment</label>
                    <textarea name="manager_comment" rows="3"><?= e($r['manager_comment']) ?></textarea>
                </div>

                <button class="btn primary">Save Review</button>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php render_footer(); ?>