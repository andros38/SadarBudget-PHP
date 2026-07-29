<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/report_data.php';
require_once __DIR__ . '/../includes/report_excel_builder.php';

if (!is_logged_in()) {
    redirect('../login.php');
}

$month = valid_month($_GET['month'] ?? null) ? $_GET['month'] : '';
$type = in_array($_GET['type'] ?? '', allowed_transaction_types(), true) ? $_GET['type'] : '';
$report = get_report_data(db(), current_user_id(), $month, $type);

$requestedFilename = basename((string)($_GET['download_name'] ?? ''));
if (!preg_match('/^sadarbudget-laporan-\d{4}-\d{2}-\d{2}-\d{6}\.xlsx$/i', $requestedFilename)) {
    $requestedFilename = 'sadarbudget-laporan-' . date('Y-m-d-His') . '.xlsx';
}

try {
    $output = build_finance_report_xlsx($report, $month, $type);
} catch (Throwable $error) {
    error_log('SadarBudget XLSX export error: ' . $error->getMessage());
    http_response_code(500);
    exit('Laporan Excel gagal dibuat. Silakan periksa log PHP atau hubungi administrator.');
}

// Hapus seluruh output buffer agar spasi, BOM, warning, atau HTML tidak
// tercampur dengan paket ZIP/XLSX yang bersifat biner.
while (ob_get_level() > 0) {
    ob_end_clean();
}

ini_set('display_errors', '0');
header_remove('X-Powered-By');
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
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
