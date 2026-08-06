<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$userId = current_user_id();
$requested = trim((string)($_GET['file'] ?? ''));

try {
    if ($requested === 'latest') {
        $latest = auto_backup_latest($userId);
        if (!$latest) {
            throw new RuntimeException('Belum ada snapshot auto backup yang dapat diunduh.');
        }
        $requested = (string)$latest['filename'];
    }

    $path = auto_backup_resolve_file($userId, $requested);
    $size = filesize($path);
    if ($size === false) {
        throw new RuntimeException('Ukuran berkas snapshot tidak dapat dibaca.');
    }

    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
    header('Content-Length: ' . $size);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    readfile($path);
    exit;
} catch (Throwable $error) {
    flash('error', $error instanceof RuntimeException
        ? $error->getMessage()
        : 'Snapshot auto backup gagal diunduh.');
    redirect('../data.php#auto-backup');
}
