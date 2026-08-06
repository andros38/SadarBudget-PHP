<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/report_data.php';
require_login();

$pdo = db();
$userId = current_user_id();

$month = valid_month($_GET['month'] ?? null) ? $_GET['month'] : '';
$type = in_array($_GET['type'] ?? '', allowed_transaction_types(), true) ? $_GET['type'] : '';
$report = get_report_data($pdo, $userId, $month, $type);
$allTransactions = $report['rows'];

$perPage = 12;
$totalRows = count($allTransactions);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$transactions = array_slice($allTransactions, ($page - 1) * $perPage, $perPage);
$historyContext = ['month' => $month, 'type' => $type, 'page' => $page];
$transactionActionUrl = static function (string $endpoint, array $transaction) use ($historyContext): string {
    return $endpoint . '?' . http_build_query(array_merge($historyContext, [
        'id' => (int)$transaction['row_id'],
    ]));
};

$filterQuery = array_filter(['month' => $month, 'type' => $type]);
$exportQuery = http_build_query($filterQuery);
$exportTimestamp = date('Y-m-d-His');
$pdfFilename = 'sadarbudget-laporan-' . $exportTimestamp . '.pdf';
$excelFilename = 'sadarbudget-laporan-' . $exportTimestamp . '.xlsx';
$pdfExportUrl = 'exports/pdf/' . rawurlencode($pdfFilename) . ($exportQuery ? '?' . $exportQuery : '');
$excelExportUrl = 'exports/excel/' . rawurlencode($excelFilename) . ($exportQuery ? '?' . $exportQuery : '');
$pageUrl = static function (int $targetPage) use ($filterQuery): string {
    return 'transactions.php?' . http_build_query(array_merge($filterQuery, ['page' => $targetPage]));
};

$viewMeta = match ($type) {
    'income' => [
        'title' => 'Riwayat Pemasukan',
        'description' => 'Menampilkan pemasukan sesuai periode yang dipilih.',
        'empty' => 'Tidak ada pemasukan untuk filter ini.',
    ],
    'expense' => [
        'title' => 'Riwayat Pengeluaran',
        'description' => 'Menampilkan pengeluaran sesuai periode yang dipilih.',
        'empty' => 'Tidak ada pengeluaran untuk filter ini.',
    ],
    default => [
        'title' => 'Riwayat Keuangan',
        'description' => 'Pemasukan dan pengeluaran tercatat dalam satu riwayat keuangan.',
        'empty' => 'Tidak ada transaksi untuk filter ini.',
    ],
};

$periodLabel = $month ? format_month_id($month . '-01') : 'Semua periode';
$typeLabel = $type ? transaction_type_label($type) : 'Semua jenis';
$pageTitle = $viewMeta['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading transaction-heading">
    <div>
        <span class="eyebrow">Catatan transaksi</span>
        <h1><?= e($viewMeta['title']) ?></h1>
        <p class="muted"><?= e($viewMeta['description']) ?></p>
    </div>
    <div class="actions export-actions">
        <a class="btn export-excel" href="<?= e($excelExportUrl) ?>">
            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M5 3h10l4 4v14H5zM15 3v5h5M8 12l6 6m0-6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Excel
        </a>
        <a class="btn export-pdf" href="<?= e($pdfExportUrl) ?>">
            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M5 3h10l4 4v14H5zM15 3v5h5M8 15h8M8 11h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            PDF
        </a>
    </div>
</div>

<details class="card filter-card responsive-disclosure" open data-responsive-disclosure>
    <summary>
        <span>
            <strong>Filter transaksi</strong>
            <small><?= e($periodLabel) ?> · <?= e($typeLabel) ?></small>
        </span>
        <span class="summary-action summary-action-filter" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="16" height="16">
                <path d="M4 6h16M7 12h10M10 18h4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
            <span class="summary-action-closed">Buka filter</span>
            <span class="summary-action-open">Tutup filter</span>
        </span>
    </summary>
    <div class="disclosure-content">
        <form method="get" class="filter-row">
            <label>Bulan
                <input type="month" name="month" value="<?= e($month) ?>">
            </label>
            <label>Jenis
                <select name="type">
                    <option value="">Semua jenis</option>
                    <?php foreach (allowed_transaction_types() as $option): ?>
                        <option value="<?= e($option) ?>" <?= $type === $option ? 'selected' : '' ?>><?= e(transaction_type_label($option)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn primary" type="submit">Terapkan</button>
            <a class="btn secondary" href="transactions.php">Reset</a>
        </form>
    </div>
</details>

<section class="card transaction-list-card">
    <div class="compact-list-heading">
        <div class="transaction-list-meta">
            <strong><?= $totalRows ?> transaksi</strong>
            <span>Halaman <?= $page ?> dari <?= $totalPages ?></span>
        </div>
        <span class="active-filter-context"><?= e($periodLabel) ?> · <?= e($typeLabel) ?></span>
    </div>
    <div class="table-wrap responsive-table transaction-table-wrap">
        <table class="transaction-table">
            <colgroup>
                <col class="transaction-col-date">
                <col class="transaction-col-category">
                <col class="transaction-col-description">
                <col class="transaction-col-type">
                <col class="transaction-col-amount">
                <col class="transaction-col-actions">
            </colgroup>
            <thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Jenis</th><th class="text-right">Nominal</th><th class="text-right">Aksi</th></tr></thead>
            <tbody>
                <?php if (!$transactions): ?>
                    <tr><td colspan="6" class="empty"><?= e($viewMeta['empty']) ?></td></tr>
                <?php else: foreach ($transactions as $t): ?>
                    <?php $categoryLabel = transaction_category_history_name($t['category_name'] ?? null); ?>
                    <tr>
                        <td data-label="Tanggal"><?= e(format_date_id($t['transaction_date'])) ?></td>
                        <td data-label="Kategori"><span class="mobile-card-title"><?= e($categoryLabel) ?></span><span class="mobile-card-date"><?= e(format_date_id($t['transaction_date'])) ?></span></td>
                        <td data-label="Keterangan"><?= e(($t['description'] ?? '') !== '' ? $t['description'] : '-') ?></td>
                        <td data-label="Jenis" class="mobile-card-side">
                            <span class="mobile-card-side-amount amount <?= e(transaction_amount_class($t['type'])) ?>"><?= e(transaction_amount_prefix($t['type'])) ?><?= format_rupiah($t['amount']) ?></span>
                            <span class="badge <?= e(transaction_badge_class($t['type'])) ?>"><?= e(transaction_type_label($t['type'])) ?></span>
                            <div class="transaction-row-actions transaction-row-actions-mobile" aria-label="Aksi transaksi">
                                <a class="transaction-action edit" href="<?= e($transactionActionUrl('edit_transaction.php', $t)) ?>" aria-label="Ubah transaksi">Ubah</a>
                                <a class="transaction-action delete" href="<?= e($transactionActionUrl('delete_transaction.php', $t)) ?>" aria-label="Hapus transaksi">Hapus</a>
                            </div>
                        </td>
                        <td data-label="Nominal" class="text-right desktop-card-amount transaction-amount-cell">
                            <span class="amount <?= e(transaction_amount_class($t['type'])) ?>"><?= e(transaction_amount_prefix($t['type'])) ?><?= format_rupiah($t['amount']) ?></span>
                        </td>
                        <td data-label="Aksi" class="text-right desktop-card-actions transaction-actions-cell">
                            <div class="transaction-row-actions" aria-label="Aksi transaksi">
                                <a class="transaction-action edit" href="<?= e($transactionActionUrl('edit_transaction.php', $t)) ?>">Ubah</a>
                                <a class="transaction-action delete" href="<?= e($transactionActionUrl('delete_transaction.php', $t)) ?>">Hapus</a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="pagination" aria-label="Navigasi halaman transaksi">
            <?php if ($page > 1): ?><a class="btn secondary" href="<?= e($pageUrl($page - 1)) ?>">Sebelumnya</a><?php endif; ?>
            <span><?= $page ?> / <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn secondary" href="<?= e($pageUrl($page + 1)) ?>">Berikutnya</a><?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
