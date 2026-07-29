<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/report_data.php';
require_once __DIR__ . '/../includes/report_pdf_builder.php';

if (!is_logged_in()) {
    redirect('../login.php');
}

$month = valid_month($_GET['month'] ?? null) ? $_GET['month'] : '';
$type = in_array($_GET['type'] ?? '', allowed_transaction_types(), true) ? $_GET['type'] : '';
$report = get_report_data(db(), current_user_id(), $month, $type);
$userName = trim((string)($_SESSION['user_name'] ?? 'Pengguna'));
$output = build_finance_report_pdf($report, $month, $type, $userName);

$requestedFilename = basename((string)($_GET['download_name'] ?? ''));
if (!preg_match('/^sadarbudget-laporan-\d{4}-\d{2}-\d{2}-\d{6}\.pdf$/i', $requestedFilename)) {
    $requestedFilename = 'sadarbudget-laporan-' . date('Y-m-d-His') . '.pdf';
}

// Pastikan tidak ada BOM, spasi, warning, atau HTML yang ikut masuk ke PDF.
while (ob_get_level() > 0) {
    ob_end_clean();
}

ini_set('display_errors', '0');
header_remove('X-Powered-By');
header('Content-Type: application/pdf');
header('Content-Description: File Transfer');
header(
    'Content-Disposition: attachment; filename="' . $requestedFilename
    . '"; filename*=UTF-8\'\'' . rawurlencode($requestedFilename)
);
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . strlen($output));
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: public');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

echo $output;
exit;
