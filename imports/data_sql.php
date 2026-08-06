<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/data_sql_importer.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../data.php');
}

verify_csrf();
$pdo = db();
$userId = current_user_id();
$user = current_user_record($pdo);
$feedbackField = '';
$safeOld = [
    'import_confirmation' => trim((string)($_POST['import_confirmation'] ?? '')),
    'import_acknowledge' => isset($_POST['import_acknowledge']) ? '1' : '',
];

try {
    $password = (string)($_POST['current_password'] ?? '');
    $confirmation = trim((string)($_POST['import_confirmation'] ?? ''));
    $acknowledged = isset($_POST['import_acknowledge']);

    if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
        $feedbackField = 'import_password';
        throw new RuntimeException('Kata sandi saat ini tidak sesuai.');
    }
    if ($confirmation !== 'IMPOR DATA') {
        $feedbackField = 'import_confirmation';
        throw new RuntimeException('Tulisan harus sama persis: IMPOR DATA.');
    }
    if (!$acknowledged) {
        $feedbackField = 'import_acknowledge';
        throw new RuntimeException('Centang persetujuan sebelum mengimpor backup.');
    }

    $file = $_FILES['backup_sql'] ?? null;
    if (!is_array($file)) {
        $feedbackField = 'import_file';
        throw new RuntimeException('Pilih berkas backup SQL terlebih dahulu.');
    }

    $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        $message = match ($uploadError) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran berkas backup melebihi batas server.',
            UPLOAD_ERR_PARTIAL => 'Unggahan backup tidak selesai. Silakan ulangi.',
            UPLOAD_ERR_NO_FILE => 'Pilih berkas backup SQL terlebih dahulu.',
            default => 'Berkas backup gagal diunggah.',
        };
        $feedbackField = 'import_file';
        throw new RuntimeException($message);
    }

    $originalName = basename((string)($file['name'] ?? 'backup.sql'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        $feedbackField = 'import_file';
        throw new RuntimeException('Format berkas harus .sql hasil ekspor SadarBudget.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        $feedbackField = 'import_file';
        throw new RuntimeException('Ukuran backup harus lebih dari 0 byte dan maksimal 10 MB.');
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        $feedbackField = 'import_file';
        throw new RuntimeException('Berkas unggahan tidak dapat diverifikasi oleh server.');
    }

    $sql = file_get_contents($temporaryPath);
    if ($sql === false) {
        $feedbackField = 'import_file';
        throw new RuntimeException('Berkas backup tidak dapat dibaca.');
    }

    $backup = parse_sadarbudget_backup($sql);

    // Jangan mengganti data aktif tanpa snapshot pengaman terlebih dahulu.
    auto_backup_create($pdo, $userId, 'sebelum-impor', true);
    $counts = restore_sadarbudget_backup(
        $pdo,
        $userId,
        (string)($user['email'] ?? ''),
        $backup
    );
    auto_backup_after_financial_change($pdo, $userId, 'setelah-impor');

    flash(
        'success',
        'Backup berhasil dipulihkan: '
        . $counts['transactions'] . ' transaksi dan '
        . $counts['categories'] . ' kategori.'
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = $error instanceof RuntimeException
        ? $error->getMessage()
        : 'Impor backup gagal. Data lama tidak diubah.';
    $fields = $feedbackField !== '' ? [$feedbackField => $message] : [];
    form_feedback_set(
        'data-management',
        'import',
        $feedbackField !== ''
            ? 'Impor belum dijalankan. Periksa bagian yang ditandai.'
            : $message,
        $fields,
        $safeOld
    );
}

redirect('../data.php');
