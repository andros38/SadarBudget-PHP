<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/data_sql_builder.php';

if (!is_logged_in()) {
    redirect('../login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Metode permintaan tidak diizinkan.');
}

verify_csrf();
$pdo = db();
$user = current_user_record($pdo);
$password = (string)($_POST['current_password'] ?? '');

if ($password === '' || !password_verify($password, (string)($user['password'] ?? ''))) {
    flash('error', 'Ekspor SQL dibatalkan karena kata sandi tidak sesuai.');
    redirect('../profile.php#data-management');
}

try {
    $output = build_user_data_sql($pdo, current_user_id());
} catch (Throwable $error) {
    flash('error', 'Backup SQL gagal dibuat. Silakan coba kembali.');
    redirect('../profile.php#data-management');
}

$safeDate = date('Y-m-d-His');
$filename = 'sadarbudget-backup-' . $safeDate . '.sql';
while (ob_get_level() > 0) {
    ob_end_clean();
}
ini_set('display_errors', '0');
header('Content-Type: application/sql; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $output;
exit;
