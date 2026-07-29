<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/data_sql_importer.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../profile.php#data-management');
}

verify_csrf();
$pdo = db();
$userId = current_user_id();
$user = current_user_record($pdo);

try {
    $password = (string)($_POST['current_password'] ?? '');
    $confirmation = trim((string)($_POST['import_confirmation'] ?? ''));
    $acknowledged = isset($_POST['import_acknowledge']);

    if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
        throw new RuntimeException('Impor dibatalkan karena kata sandi tidak sesuai.');
    }
    if ($confirmation !== 'IMPOR DATA') {
        throw new RuntimeException('Ketik IMPOR DATA untuk mengonfirmasi pemulihan backup.');
    }
    if (!$acknowledged) {
        throw new RuntimeException('Anda harus menyetujui bahwa data keuangan saat ini akan diganti.');
    }

    $file = $_FILES['backup_sql'] ?? null;
    if (!is_array($file)) {
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
        throw new RuntimeException($message);
    }

    $originalName = basename((string)($file['name'] ?? 'backup.sql'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        throw new RuntimeException('Format berkas harus .sql hasil ekspor SadarBudget.');
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Ukuran backup harus lebih dari 0 byte dan maksimal 10 MB.');
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new RuntimeException('Berkas unggahan tidak dapat diverifikasi oleh server.');
    }

    $sql = file_get_contents($temporaryPath);
    if ($sql === false) {
        throw new RuntimeException('Berkas backup tidak dapat dibaca.');
    }

    $backup = parse_sadarbudget_backup($sql);
    $counts = restore_sadarbudget_backup(
        $pdo,
        $userId,
        (string)($user['email'] ?? ''),
        $backup
    );

    flash(
        'success',
        'Backup berhasil dipulihkan: '
        . $counts['transactions'] . ' transaksi, '
        . $counts['goals'] . ' tujuan, '
        . $counts['entries'] . ' aktivitas tabungan, dan '
        . $counts['categories'] . ' kategori.'
    );
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash(
        'error',
        $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Impor backup gagal. Data lama tidak diubah.'
    );
}

redirect('../profile.php#data-management');
