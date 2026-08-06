<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/data_sql_importer.php';
require_login();

$pdo = db();
$userId = current_user_id();
$dataFeedback = form_feedback_pull('data-management');
$dataFeedbackForm = (string)($dataFeedback['form'] ?? '');
$dataFeedbackMessage = (string)($dataFeedback['message'] ?? '');
$dataFieldErrors = is_array($dataFeedback['fields'] ?? null) ? $dataFeedback['fields'] : [];
$dataOld = is_array($dataFeedback['old'] ?? null) ? $dataFeedback['old'] : [];
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'create_auto_backup') {
            $backup = auto_backup_create($pdo, $userId, 'manual', true);
            flash('success', 'Snapshot auto backup berhasil dibuat: ' . ($backup['filename'] ?? 'backup baru') . '.');
            redirect('data.php#auto-backup');
        }

        if ($action === 'restore_auto_backup') {
            $currentPassword = (string)($_POST['restore_password'] ?? '');
            $confirmation = trim((string)($_POST['restore_confirmation'] ?? ''));
            $acknowledged = isset($_POST['restore_acknowledge']);
            $snapshotName = trim((string)($_POST['snapshot_file'] ?? ''));
            $user = current_user_record($pdo);
            $restoreErrors = [];

            if ($snapshotName === '') {
                $restoreErrors['snapshot_file'] = 'Pilih snapshot yang ingin dipulihkan.';
            }
            if (!password_verify($currentPassword, (string)($user['password'] ?? ''))) {
                $restoreErrors['restore_password'] = 'Kata sandi saat ini tidak sesuai.';
            }
            if ($confirmation !== 'PULIHKAN BACKUP') {
                $restoreErrors['restore_confirmation'] = 'Tulisan harus sama persis: PULIHKAN BACKUP.';
            }
            if (!$acknowledged) {
                $restoreErrors['restore_acknowledge'] = 'Centang persetujuan sebelum memulihkan snapshot.';
            }

            if ($restoreErrors) {
                $dataFeedbackForm = 'restore';
                $dataFeedbackMessage = 'Pemulihan belum dijalankan. Periksa bagian yang ditandai.';
                $dataFieldErrors = $restoreErrors;
                $dataOld = [
                    'snapshot_file' => $snapshotName,
                    'restore_confirmation' => $confirmation,
                    'restore_acknowledge' => $acknowledged ? '1' : '',
                ];
            } else {
                $snapshotPath = auto_backup_resolve_file($userId, $snapshotName);
                $sql = file_get_contents($snapshotPath);
                if ($sql === false) {
                    throw new RuntimeException('Snapshot tidak dapat dibaca.');
                }

                auto_backup_create($pdo, $userId, 'sebelum-pemulihan', true);
                $backupPayload = parse_sadarbudget_backup($sql);
                $counts = restore_sadarbudget_backup(
                    $pdo,
                    $userId,
                    (string)($user['email'] ?? ''),
                    $backupPayload,
                    true
                );
                auto_backup_after_financial_change($pdo, $userId, 'setelah-pemulihan');

                flash(
                    'success',
                    'Snapshot berhasil dipulihkan: '
                    . $counts['transactions'] . ' transaksi dan '
                    . $counts['categories'] . ' kategori.'
                );
                redirect('data.php#auto-backup');
            }
        }

        if ($action === 'clean_data') {
            $currentPassword = (string)($_POST['clean_password'] ?? '');
            $confirmation = trim((string)($_POST['clean_confirmation'] ?? ''));
            $acknowledged = isset($_POST['clean_acknowledge']);
            $user = current_user_record($pdo);
            $cleanErrors = [];

            if (!password_verify($currentPassword, (string)($user['password'] ?? ''))) {
                $cleanErrors['clean_password'] = 'Kata sandi saat ini tidak sesuai.';
            }
            if ($confirmation !== 'BERSIHKAN DATA') {
                $cleanErrors['clean_confirmation'] = 'Tulisan harus sama persis: BERSIHKAN DATA.';
            }
            if (!$acknowledged) {
                $cleanErrors['clean_acknowledge'] = 'Centang persetujuan sebelum membersihkan data.';
            }

            if ($cleanErrors) {
                $dataFeedbackForm = 'clean';
                $dataFeedbackMessage = 'Data belum dibersihkan. Periksa bagian yang ditandai.';
                $dataFieldErrors = $cleanErrors;
                $dataOld = [
                    'clean_confirmation' => $confirmation,
                    'clean_acknowledge' => $acknowledged ? '1' : '',
                ];
            } else {
                auto_backup_create($pdo, $userId, 'sebelum-bersihkan', true);
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('DELETE FROM transactions WHERE user_id=?');
                $stmt->execute([$userId]);
                $stmt = $pdo->prepare('DELETE FROM categories WHERE user_id=?');
                $stmt->execute([$userId]);

                $defaults = [
                    ['Gaji', 'income'], ['Bonus', 'income'], ['Penjualan', 'income'], ['Lainnya', 'income'],
                    ['Makan', 'expense'], ['Transport', 'expense'], ['Belanja', 'expense'], ['Tagihan', 'expense'],
                    ['Hiburan', 'expense'], ['Kesehatan', 'expense'], ['Lainnya', 'expense'],
                ];
                $categoryInsert = $pdo->prepare('INSERT INTO categories (user_id, name, type, is_active) VALUES (?, ?, ?, 1)');
                foreach ($defaults as [$categoryName, $categoryType]) {
                    $categoryInsert->execute([$userId, $categoryName, $categoryType]);
                }

                $pdo->commit();
                auto_backup_after_financial_change($pdo, $userId, 'setelah-bersihkan');
                flash('success', 'Semua data keuangan berhasil dibersihkan. Snapshot sebelum pembersihan tetap tersedia pada Auto backup.');
                redirect('data.php');
            }
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Operasi data gagal diproses. Silakan coba lagi.';

        if (in_array($action, ['restore_auto_backup', 'clean_data'], true)) {
            $dataFeedbackForm = $action === 'restore_auto_backup' ? 'restore' : 'clean';
            $dataFeedbackMessage = $message;
        } else {
            flash('error', $message);
            redirect('data.php');
        }
    }
}

$dataCountStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM transactions WHERE user_id=?) AS transaction_count,
    (SELECT COUNT(*) FROM categories WHERE user_id=?) AS category_count");
$dataCountStmt->execute([$userId, $userId]);
$dataCounts = $dataCountStmt->fetch() ?: [
    'transaction_count' => 0,
    'category_count' => 0,
];
$autoBackupStatus = auto_backup_status($userId);
$autoBackupItems = array_slice($autoBackupStatus['items'], 0, 12);
$latestAutoBackup = $autoBackupStatus['latest'];
$pageTitle = 'Data & Backup';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading data-page-heading">
    <div>
        <span class="eyebrow">Data dan privasi</span>
        <h1>Data & Backup</h1>
        <p class="muted">Kelola auto backup, ekspor, impor, pemulihan, dan pembersihan data transaksi tanpa memenuhi halaman profil.</p>
    </div>
    <a class="btn secondary" href="profile.php">Kembali ke profil</a>
</div>

<section class="card data-management-card" id="data-management">
    <div class="section-head compact data-tools-intro">
<div>
<span class="section-kicker">Pusat pengelolaan</span>
<h2>Ringkasan dan alat data</h2>
<p class="muted">Auto backup, ekspor, impor, pemulihan, dan pembersihan data berada pada satu halaman khusus.</p>
</div>
    </div>

<div class="data-count-grid data-count-grid-compact" aria-label="Jumlah data akun">
    <div><strong><?= (int)$dataCounts['transaction_count'] ?></strong><span>Transaksi</span></div>
    <div><strong><?= (int)$dataCounts['category_count'] ?></strong><span>Kategori</span></div>
</div>

<section class="auto-backup-panel" id="auto-backup" aria-labelledby="autoBackupTitle">
    <div class="auto-backup-head">
        <span class="data-action-icon auto-backup-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="24" height="24"><path d="M5 8a7 7 0 1 1 1.4 8.2M5 8V3m0 5h5M12 8v5l3 2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </span>
        <div>
            <div class="auto-backup-title-row">
                <h3 id="autoBackupTitle">Auto backup lokal</h3>
                <span class="auto-backup-status <?= $autoBackupStatus['enabled'] ? 'is-active' : 'is-disabled' ?>"><?= $autoBackupStatus['enabled'] ? 'Aktif' : 'Nonaktif' ?></span>
            </div>
            <p>Snapshot dibuat setelah perubahan data keuangan dan secara berkala saat aplikasi sedang digunakan. Berkas lama tetap tersimpan ketika XAMPP mati.</p>
        </div>
    </div>

    <div class="auto-backup-metrics" aria-label="Status auto backup">
        <div>
            <span>Backup terakhir</span>
            <strong><?= $latestAutoBackup ? e(format_date_id(substr((string)$latestAutoBackup['created_at'], 0, 10)) . ', ' . substr((string)$latestAutoBackup['created_at'], 11, 5)) : 'Belum tersedia' ?></strong>
            <small><?= $latestAutoBackup ? e($latestAutoBackup['reason_label']) : 'Snapshot pertama akan dibuat otomatis.' ?></small>
        </div>
        <div>
            <span>Snapshot tersimpan</span>
            <strong><?= (int)$autoBackupStatus['count'] ?> / <?= (int)$autoBackupStatus['max_files'] ?></strong>
            <small><?= e(auto_backup_human_size((int)$autoBackupStatus['total_size'])) ?> digunakan</small>
        </div>
        <div>
            <span>Interval berkala</span>
            <strong><?= (int)$autoBackupStatus['interval_minutes'] ?> menit</strong>
            <small>Berjalan saat aplikasi diakses.</small>
        </div>
    </div>

    <?php if (!empty($autoBackupStatus['last_error'])): ?>
        <div class="auto-backup-warning" role="alert">
            <strong>Backup terakhir mengalami kendala</strong>
            <span><?= e((string)$autoBackupStatus['last_error']) ?></span>
        </div>
    <?php endif; ?>

    <div class="auto-backup-actions">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_auto_backup">
            <button class="btn backup" type="submit">
                <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                Backup sekarang
            </button>
        </form>
        <?php if ($latestAutoBackup): ?>
            <a class="btn secondary" href="exports/auto_backup.php?file=latest">
                <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Unduh terbaru
            </a>
        <?php endif; ?>
    </div>

    <?php if ($autoBackupItems): ?>
        <div class="auto-backup-recent">
            <div class="auto-backup-recent-head">
                <strong>Snapshot terbaru</strong>
                <span>Disimpan di folder terlindungi <code>storage/auto-backups</code>.</span>
            </div>
            <div class="auto-backup-list">
                <?php foreach (array_slice($autoBackupItems, 0, 5) as $snapshot): ?>
                    <div class="auto-backup-item">
                        <div>
                            <strong><?= e(format_date_id(substr((string)$snapshot['created_at'], 0, 10)) . ', ' . substr((string)$snapshot['created_at'], 11, 5)) ?></strong>
                            <span><?= e($snapshot['reason_label']) ?> · <?= e(auto_backup_human_size((int)$snapshot['size'])) ?></span>
                        </div>
                        <a class="btn secondary small" href="exports/auto_backup.php?file=<?= rawurlencode((string)$snapshot['filename']) ?>">Unduh</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <details class="auto-backup-restore <?= $dataFeedbackForm === 'restore' ? 'has-form-error' : '' ?>" <?= $dataFeedbackForm === 'restore' ? 'open' : '' ?>>
            <summary>
                <span><strong>Pulihkan dari snapshot</strong><small>Data saat ini akan diganti. Sistem membuat snapshot pengaman terlebih dahulu.</small></span>
                <span aria-hidden="true">⌄</span>
            </summary>
            <form method="post" class="form-grid auto-backup-restore-form" novalidate data-inline-validation data-confirm-message="Pulihkan snapshot ini? Data keuangan saat ini akan diganti setelah snapshot pengaman dibuat.">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restore_auto_backup">
                <?php if ($dataFeedbackForm === 'restore' && $dataFeedbackMessage !== ''): ?>
                    <div class="form-inline-notice error full" role="alert" data-validation-notice><?= e($dataFeedbackMessage) ?></div>
                <?php endif; ?>
                <label class="full">Pilih snapshot
                    <select id="restoreSnapshot" name="snapshot_file" required data-required-message="Pilih snapshot yang ingin dipulihkan." data-error-target="restore-snapshot-error" <?= isset($dataFieldErrors['snapshot_file']) ? 'aria-invalid="true"' : '' ?>>
                        <option value="">Pilih waktu backup</option>
                        <?php foreach ($autoBackupItems as $snapshot): ?>
                            <option value="<?= e((string)$snapshot['filename']) ?>" <?= (($dataOld['snapshot_file'] ?? '') === $snapshot['filename']) ? 'selected' : '' ?>><?= e(format_date_id(substr((string)$snapshot['created_at'], 0, 10)) . ', ' . substr((string)$snapshot['created_at'], 11, 5) . ' — ' . $snapshot['reason_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="field-feedback error" id="restore-snapshot-error" data-field-error <?= isset($dataFieldErrors['snapshot_file']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['snapshot_file'] ?? '')) ?></small>
                </label>
                <label>Kata sandi saat ini
                    <input type="password" id="restorePassword" name="restore_password" autocomplete="current-password" required data-required-message="Masukkan kata sandi saat ini." data-error-target="restore-password-error" <?= isset($dataFieldErrors['restore_password']) ? 'aria-invalid="true"' : '' ?>>
                    <small class="field-feedback error" id="restore-password-error" data-field-error <?= isset($dataFieldErrors['restore_password']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['restore_password'] ?? '')) ?></small>
                </label>
                <label>Ketik <strong>PULIHKAN BACKUP</strong>
                    <input type="text" id="restoreConfirmation" name="restore_confirmation" autocomplete="off" value="<?= e((string)($dataOld['restore_confirmation'] ?? '')) ?>" required data-exact-value="PULIHKAN BACKUP" data-exact-message="Tulisan harus sama persis: PULIHKAN BACKUP." data-error-target="restore-confirmation-error" <?= isset($dataFieldErrors['restore_confirmation']) ? 'aria-invalid="true"' : '' ?>>
                    <small class="field-feedback error" id="restore-confirmation-error" data-field-error <?= isset($dataFieldErrors['restore_confirmation']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['restore_confirmation'] ?? '')) ?></small>
                </label>
                <label class="auto-backup-confirmation full <?= isset($dataFieldErrors['restore_acknowledge']) ? 'has-error' : '' ?>">
                    <input type="checkbox" id="restoreAcknowledge" name="restore_acknowledge" value="1" required data-required-message="Centang persetujuan sebelum memulihkan snapshot." data-error-target="restore-acknowledge-error" <?= !empty($dataOld['restore_acknowledge']) ? 'checked' : '' ?> <?= isset($dataFieldErrors['restore_acknowledge']) ? 'aria-invalid="true"' : '' ?>>
                    <span>Saya memahami bahwa data keuangan saat ini akan diganti oleh snapshot yang dipilih.</span>
                </label>
                <small class="field-feedback error full" id="restore-acknowledge-error" data-field-error <?= isset($dataFieldErrors['restore_acknowledge']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['restore_acknowledge'] ?? '')) ?></small>
                <button class="btn primary full" type="submit">Pulihkan snapshot</button>
            </form>
        </details>
    <?php endif; ?>

    <p class="auto-backup-note"><strong>Penting:</strong> jangan hapus folder <code>storage/auto-backups</code> ketika memperbarui aplikasi. Untuk perlindungan dari kerusakan hard disk, unduh backup terbaru secara berkala dan simpan salinannya di perangkat atau drive lain.</p>
</section>

<div class="data-action-grid">
    <article class="data-action-panel backup-panel <?= $dataFeedbackForm === 'export' ? 'has-form-error' : '' ?>">
        <div class="data-action-head">
            <span class="data-action-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="23" height="23"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div><h3>Ekspor backup SQL</h3><p>Simpan transaksi dan kategori dalam satu berkas <code>.sql</code>.</p></div>
        </div>
        <?php if ($dataFeedbackForm === 'export' && $dataFeedbackMessage !== ''): ?>
            <div class="form-inline-notice error" role="alert" data-validation-notice><?= e($dataFeedbackMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="exports/data_sql.php" class="data-action-form" novalidate data-inline-validation>
            <?= csrf_field() ?>
            <label>Kata sandi saat ini
                <input type="password" id="exportPassword" name="current_password" autocomplete="current-password" required data-required-message="Masukkan kata sandi saat ini." data-error-target="export-password-error" <?= isset($dataFieldErrors['export_password']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="export-password-error" data-field-error <?= isset($dataFieldErrors['export_password']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['export_password'] ?? '')) ?></small>
            </label>
            <button class="btn backup full" type="submit">Unduh backup SQL</button>
        </form>
        <small>Backup format baru tetap dapat diimpor secara manual, tetapi pemulihan melalui web lebih aman karena SQL unggahan tidak dieksekusi secara langsung.</small>
    </article>

    <article class="data-action-panel import-panel <?= $dataFeedbackForm === 'import' ? 'has-form-error' : '' ?>">
        <div class="data-action-head">
            <span class="data-action-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="23" height="23"><path d="M12 20V9m0 0-4 4m4-4 4 4M5 5h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div><h3>Impor backup SQL</h3><p>Pulihkan berkas <code>.sql</code> hasil ekspor SadarBudget langsung ke akun ini.</p></div>
        </div>
        <?php if ($dataFeedbackForm === 'import' && $dataFeedbackMessage !== ''): ?>
            <div class="form-inline-notice error" role="alert" data-validation-notice><?= e($dataFeedbackMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="imports/data_sql.php" enctype="multipart/form-data" class="data-action-form" novalidate data-inline-validation data-confirm-message="Data keuangan saat ini akan diganti oleh isi backup. Lanjutkan impor?">
            <?= csrf_field() ?>
            <label>Berkas backup SQL
                <input type="file" id="importFile" name="backup_sql" accept=".sql,text/plain,application/sql" required data-required-message="Pilih berkas backup SQL terlebih dahulu." data-error-target="import-file-error" <?= isset($dataFieldErrors['import_file']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="import-file-error" data-field-error <?= isset($dataFieldErrors['import_file']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['import_file'] ?? '')) ?></small>
            </label>
            <label>Kata sandi saat ini
                <input type="password" id="importPassword" name="current_password" autocomplete="current-password" required data-required-message="Masukkan kata sandi saat ini." data-error-target="import-password-error" <?= isset($dataFieldErrors['import_password']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="import-password-error" data-field-error <?= isset($dataFieldErrors['import_password']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['import_password'] ?? '')) ?></small>
            </label>
            <label>Ketik <strong>IMPOR DATA</strong>
                <input type="text" id="importConfirmation" name="import_confirmation" autocomplete="off" value="<?= e((string)($dataOld['import_confirmation'] ?? '')) ?>" required data-exact-value="IMPOR DATA" data-exact-message="Tulisan harus sama persis: IMPOR DATA." data-error-target="import-confirmation-error" <?= isset($dataFieldErrors['import_confirmation']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="import-confirmation-error" data-field-error <?= isset($dataFieldErrors['import_confirmation']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['import_confirmation'] ?? '')) ?></small>
            </label>
            <label class="import-confirmation-check <?= isset($dataFieldErrors['import_acknowledge']) ? 'has-error' : '' ?>">
                <input type="checkbox" id="importAcknowledge" name="import_acknowledge" value="1" required data-required-message="Centang persetujuan sebelum mengimpor backup." data-error-target="import-acknowledge-error" <?= !empty($dataOld['import_acknowledge']) ? 'checked' : '' ?> <?= isset($dataFieldErrors['import_acknowledge']) ? 'aria-invalid="true"' : '' ?>>
                <span>Saya memahami bahwa transaksi dan kategori saat ini akan diganti oleh data dari backup.</span>
            </label>
            <small class="field-feedback error" id="import-acknowledge-error" data-field-error <?= isset($dataFieldErrors['import_acknowledge']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['import_acknowledge'] ?? '')) ?></small>
            <button class="btn import-data full" type="submit">Impor dan pulihkan data</button>
        </form>
        <small>Mendukung backup SadarBudget. Email di dalam backup harus sama dengan email akun yang sedang login. Maksimal 10 MB.</small>
    </article>

    <article class="data-action-panel clean-panel <?= $dataFeedbackForm === 'clean' ? 'has-form-error' : '' ?>">
        <div class="data-action-head">
            <span class="data-action-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="23" height="23"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div><h3>Bersihkan data keuangan</h3><p>Hapus seluruh transaksi dan kategori. Akun serta profil tidak ikut dihapus.</p></div>
        </div>
        <?php if ($dataFeedbackForm === 'clean' && $dataFeedbackMessage !== ''): ?>
            <div class="form-inline-notice error" role="alert" data-validation-notice><?= e($dataFeedbackMessage) ?></div>
        <?php endif; ?>
        <form method="post" class="data-action-form" novalidate data-inline-validation data-confirm-message="Semua data keuangan akan dihapus permanen. Lanjutkan?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clean_data">
            <label>Kata sandi saat ini
                <input type="password" id="cleanPassword" name="clean_password" autocomplete="current-password" required data-required-message="Masukkan kata sandi saat ini." data-error-target="clean-password-error" <?= isset($dataFieldErrors['clean_password']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="clean-password-error" data-field-error <?= isset($dataFieldErrors['clean_password']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['clean_password'] ?? '')) ?></small>
            </label>
            <label>Ketik <strong>BERSIHKAN DATA</strong>
                <input type="text" id="cleanConfirmation" name="clean_confirmation" autocomplete="off" value="<?= e((string)($dataOld['clean_confirmation'] ?? '')) ?>" required data-exact-value="BERSIHKAN DATA" data-exact-message="Tulisan harus sama persis: BERSIHKAN DATA." data-error-target="clean-confirmation-error" <?= isset($dataFieldErrors['clean_confirmation']) ? 'aria-invalid="true"' : '' ?>>
                <small class="field-feedback error" id="clean-confirmation-error" data-field-error <?= isset($dataFieldErrors['clean_confirmation']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['clean_confirmation'] ?? '')) ?></small>
            </label>
            <label class="clean-confirmation-check <?= isset($dataFieldErrors['clean_acknowledge']) ? 'has-error' : '' ?>">
                <input type="checkbox" id="cleanAcknowledge" name="clean_acknowledge" value="1" required data-required-message="Centang persetujuan sebelum membersihkan data." data-error-target="clean-acknowledge-error" <?= !empty($dataOld['clean_acknowledge']) ? 'checked' : '' ?> <?= isset($dataFieldErrors['clean_acknowledge']) ? 'aria-invalid="true"' : '' ?>>
                <span>Saya memahami bahwa data yang belum dibackup tidak dapat dipulihkan.</span>
            </label>
            <small class="field-feedback error" id="clean-acknowledge-error" data-field-error <?= isset($dataFieldErrors['clean_acknowledge']) ? '' : 'hidden' ?>><?= e((string)($dataFieldErrors['clean_acknowledge'] ?? '')) ?></small>
            <button class="btn danger full" type="submit">Bersihkan semua data keuangan</button>
        </form>
    </article>
</div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
