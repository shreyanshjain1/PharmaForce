<?php
require __DIR__ . '/app/bootstrap.php';

require_top_manager();

function fs_format_bytes(int $bytes): string
{
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
    return $bytes . ' B';
}

function fs_ext(string $path): string
{
    return strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));
}

function fs_allowed_ext(string $ext): bool
{
    return in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'], true);
}

function fs_expected_mime(string $ext): array
{
    return [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
    ][$ext] ?? [];
}

function fs_abs_path(string $relative): string
{
    return __DIR__ . '/' . ltrim($relative, '/');
}

function fs_scan_file(string $path, string $source, string $entityType, ?int $entityId, string $owner = '', string $label = ''): array
{
    $relative = trim($path);
    $absolute = fs_abs_path($relative);
    $ext = fs_ext($relative);
    $exists = $relative !== '' && is_file($absolute);
    $size = $exists ? filesize($absolute) : 0;
    $mime = '';
    $issues = [];

    if ($relative === '') {
        $issues[] = 'Empty path';
    }

    if (!$exists) {
        $issues[] = 'Missing file';
    }

    if (!fs_allowed_ext($ext)) {
        $issues[] = 'Invalid extension';
    }

    if ($exists) {
        if ($size > 10 * 1024 * 1024) {
            $issues[] = 'Over 10MB';
        }

        $base = basename($relative);
        if (preg_match('/\.(php|phtml|phar|cgi|pl|asp|aspx|jsp|sh|bat|cmd|exe)(\.|$)/i', $base)) {
            $issues[] = 'Executable-looking filename';
        }

        if (preg_match('/\s|%00|\.\./', $relative)) {
            $issues[] = 'Suspicious path';
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($absolute);
        $expected = fs_expected_mime($ext);

        if ($expected && !in_array($mime, $expected, true)) {
            $issues[] = 'MIME mismatch';
        }

        if (str_starts_with($mime, 'image/') && @getimagesize($absolute) === false) {
            $issues[] = 'Invalid image';
        }
    }

    return [
        'source' => $source,
        'label' => $label ?: basename($relative),
        'path' => $relative,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'owner' => $owner,
        'exists' => $exists,
        'size' => (int)$size,
        'extension' => $ext ?: 'none',
        'mime' => $mime ?: 'unknown',
        'issues' => $issues,
        'status' => $issues ? 'Needs Attention' : 'OK',
        'created_at' => $exists ? date('M d, Y g:i A', filemtime($absolute)) : '',
    ];
}

function fs_collect_report_files(PDO $pdo): array
{
    $files = [];
    try {
        $reportColumns = table_columns($pdo, 'reports');
        if (!$reportColumns) return [];

        $select = ['r.id'];
        foreach (['attachment_path', 'signature_path', 'doctor_name', 'created_at', 'user_id'] as $col) {
            if (in_array($col, $reportColumns, true)) $select[] = 'r.`' . $col . '`';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ', u.name owner_name FROM reports r LEFT JOIN users u ON u.id = r.user_id WHERE ';
        $where = [];
        if (in_array('attachment_path', $reportColumns, true)) $where[] = "(r.attachment_path IS NOT NULL AND r.attachment_path <> '')";
        if (in_array('signature_path', $reportColumns, true)) $where[] = "(r.signature_path IS NOT NULL AND r.signature_path <> '')";
        if (!$where) return [];

        $sql .= implode(' OR ', $where) . ' ORDER BY r.id DESC LIMIT 600';
        $rows = $pdo->query($sql)->fetchAll();

        foreach ($rows as $row) {
            $owner = trim((string)($row['owner_name'] ?? ''));
            $doctor = trim((string)($row['doctor_name'] ?? ''));
            if (!empty($row['attachment_path'])) {
                $files[] = fs_scan_file((string)$row['attachment_path'], 'Report Attachment', 'report', (int)$row['id'], $owner, $doctor);
            }
            if (!empty($row['signature_path'])) {
                $files[] = fs_scan_file((string)$row['signature_path'], 'Report Signature', 'report', (int)$row['id'], $owner, $doctor);
            }
        }
    } catch (Throwable $e) {
        error_log('Report file scan failed: ' . $e->getMessage());
    }

    return $files;
}

function fs_collect_expense_files(PDO $pdo): array
{
    $files = [];
    try {
        if (!table_columns($pdo, 'expense_items')) return [];

        $rows = $pdo->query("
            SELECT 
                ei.id,
                ei.expense_report_id,
                ei.receipt_path,
                ei.particulars,
                er.user_id,
                u.name owner_name
            FROM expense_items ei
            LEFT JOIN expense_reports er ON er.id = ei.expense_report_id
            LEFT JOIN users u ON u.id = er.user_id
            WHERE ei.receipt_path IS NOT NULL AND ei.receipt_path <> ''
            ORDER BY ei.id DESC
            LIMIT 800
        ")->fetchAll();

        foreach ($rows as $row) {
            $files[] = fs_scan_file(
                (string)$row['receipt_path'],
                'Expense Receipt',
                'expense',
                (int)$row['expense_report_id'],
                trim((string)($row['owner_name'] ?? '')),
                trim((string)($row['particulars'] ?? ''))
            );
        }
    } catch (Throwable $e) {
        error_log('Expense file scan failed: ' . $e->getMessage());
    }

    return $files;
}

function fs_collect_orphan_uploads(array $knownPaths): array
{
    $files = [];
    $known = array_flip(array_map(static fn($path) => str_replace('\\', '/', trim((string)$path)), $knownPaths));
    $roots = ['uploads', 'uploads/attachments', 'uploads/signatures', 'uploads/expenses'];

    foreach ($roots as $root) {
        $absoluteRoot = __DIR__ . '/' . $root;
        if (!is_dir($absoluteRoot)) continue;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) continue;
            $absolute = $fileInfo->getPathname();
            $relative = str_replace('\\', '/', substr($absolute, strlen(__DIR__) + 1));
            if (basename($relative) === '.htaccess') continue;
            if (isset($known[$relative])) continue;

            $files[] = fs_scan_file($relative, 'Orphan Upload', 'upload', null, '', 'Not linked');
        }
    }

    return $files;
}

$filterStatus = trim((string)($_GET['status'] ?? ''));
$filterSource = trim((string)($_GET['source'] ?? ''));

$files = array_merge(fs_collect_report_files($pdo), fs_collect_expense_files($pdo));
$knownPaths = array_column($files, 'path');
$files = array_merge($files, fs_collect_orphan_uploads($knownPaths));

$totalFiles = count($files);
$attentionFiles = count(array_filter($files, static fn($file) => $file['issues']));
$missingFiles = count(array_filter($files, static fn($file) => in_array('Missing file', $file['issues'], true)));
$orphanFiles = count(array_filter($files, static fn($file) => $file['source'] === 'Orphan Upload'));
$totalSize = array_sum(array_column($files, 'size'));

$sources = array_values(array_unique(array_column($files, 'source')));
sort($sources);

$visibleFiles = array_values(array_filter($files, static function (array $file) use ($filterStatus, $filterSource): bool {
    if ($filterStatus === 'attention' && !$file['issues']) return false;
    if ($filterStatus === 'ok' && $file['issues']) return false;
    if ($filterSource !== '' && $file['source'] !== $filterSource) return false;
    return true;
}));

usort($visibleFiles, static function (array $a, array $b): int {
    if ((bool)$a['issues'] !== (bool)$b['issues']) {
        return $a['issues'] ? -1 : 1;
    }
    return strcmp($b['created_at'], $a['created_at']);
});

audit_log($pdo, 'file_security_viewed', 'security', null, [
    'total_files' => $totalFiles,
    'attention_files' => $attentionFiles,
]);

render_header('File Security Center');
?>

<style>
.file-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:18px}
.file-metric{padding:18px;border:1px solid rgba(15,118,110,.13);border-radius:26px;background:linear-gradient(145deg,#fff,#fbfffe);box-shadow:0 14px 30px rgba(15,118,110,.055)}
.file-metric span{display:block;color:#64748b;font-size:12px;font-weight:950;text-transform:uppercase;letter-spacing:.08em}
.file-metric strong{display:block;margin-top:8px;color:#082f2b;font-size:28px;letter-spacing:-.04em}
.file-status{display:inline-flex;align-items:center;min-height:30px;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:950}
.file-status.ok{background:#ecfdf5;color:#15803d;border:1px solid #bbf7d0}
.file-status.warn{background:#fff1f2;color:#b91c1c;border:1px solid #fecdd3}
.file-issues{display:flex;gap:6px;flex-wrap:wrap}
.file-issue{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;font-size:11px;font-weight:900}
.file-path{max-width:320px;overflow-wrap:anywhere;color:#475569;font-size:12px;font-weight:750}
.file-filter-grid{display:grid;grid-template-columns:1fr 1fr auto auto;gap:12px;align-items:end}
@media(max-width:900px){.file-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.file-filter-grid{grid-template-columns:1fr}.file-filter-grid .btn{width:100%}}
@media(max-width:560px){.file-metrics{grid-template-columns:1fr}}
</style>

<div class="hero">
    <div>
        <span class="eyebrow">Internal Security</span>
        <h2>File Upload Security Center</h2>
        <p>Inspect report attachments, signatures, expense receipts, and orphan files inside the upload folders.</p>
    </div>
    <div class="actions">
        <a class="btn ghost" href="security.php">Security Center</a>
    </div>
</div>

<section class="file-metrics">
    <article class="file-metric"><span>Total Files</span><strong><?= (int)$totalFiles ?></strong></article>
    <article class="file-metric"><span>Needs Attention</span><strong><?= (int)$attentionFiles ?></strong></article>
    <article class="file-metric"><span>Missing Files</span><strong><?= (int)$missingFiles ?></strong></article>
    <article class="file-metric"><span>Total Size</span><strong><?= e(fs_format_bytes((int)$totalSize)) ?></strong></article>
</section>

<section class="card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Filters</span>
            <h2>Review uploaded files</h2>
        </div>
    </div>

    <form class="file-filter-grid" method="get">
        <div class="field">
            <label>Status</label>
            <select name="status">
                <option value="">All files</option>
                <option value="attention" <?= $filterStatus === 'attention' ? 'selected' : '' ?>>Needs Attention</option>
                <option value="ok" <?= $filterStatus === 'ok' ? 'selected' : '' ?>>OK</option>
            </select>
        </div>
        <div class="field">
            <label>Source</label>
            <select name="source">
                <option value="">All sources</option>
                <?php foreach ($sources as $source): ?>
                    <option value="<?= e($source) ?>" <?= $filterSource === $source ? 'selected' : '' ?>><?= e($source) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn primary">Filter</button>
        <a class="btn ghost" href="file_security.php">Reset</a>
    </form>
</section>

<br>

<section class="card">
    <div class="section-title">
        <div>
            <span class="eyebrow">Scan Results</span>
            <h2><?= count($visibleFiles) ?> files shown</h2>
        </div>
    </div>

    <?php if (!$visibleFiles): ?>
        <div class="empty">No uploaded files matched the selected filters.</div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>File</th>
                        <th>Source</th>
                        <th>Owner</th>
                        <th>Related</th>
                        <th>Size / Type</th>
                        <th>Issues</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visibleFiles as $file): ?>
                        <tr>
                            <td><span class="file-status <?= $file['issues'] ? 'warn' : 'ok' ?>"><?= e($file['status']) ?></span></td>
                            <td>
                                <strong><?= e($file['label'] ?: basename($file['path'])) ?></strong>
                                <div class="file-path"><?= e($file['path']) ?></div>
                            </td>
                            <td><?= e($file['source']) ?></td>
                            <td><?= e($file['owner'] ?: 'Unknown') ?></td>
                            <td>
                                <?php if ($file['entity_type'] === 'report' && $file['entity_id']): ?>
                                    <a href="report_view.php?id=<?= (int)$file['entity_id'] ?>">Report #<?= (int)$file['entity_id'] ?></a>
                                <?php elseif ($file['entity_type'] === 'expense' && $file['entity_id']): ?>
                                    <a href="expenses.php?action=view&id=<?= (int)$file['entity_id'] ?>">Expense #<?= (int)$file['entity_id'] ?></a>
                                <?php else: ?>
                                    <span class="muted">Not linked</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= e(fs_format_bytes((int)$file['size'])) ?></strong><br>
                                <span class="muted"><?= e($file['extension']) ?> · <?= e($file['mime']) ?></span>
                            </td>
                            <td>
                                <?php if (!$file['issues']): ?>
                                    <span class="muted">No issues</span>
                                <?php else: ?>
                                    <div class="file-issues">
                                        <?php foreach ($file['issues'] as $issue): ?>
                                            <span class="file-issue"><?= e($issue) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($file['exists']): ?>
                                    <a class="btn small ghost" target="_blank" href="<?= e($file['path']) ?>">Open</a>
                                <?php else: ?>
                                    <span class="muted">Unavailable</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php render_footer(); ?>
