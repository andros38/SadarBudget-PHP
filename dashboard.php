<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/report_data.php';
require_login();

$pdo = db();
$userId = current_user_id();

$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
    FROM transactions WHERE user_id=?");
$stmt->execute([$userId]);
$totals = $stmt->fetch() ?: ['income' => 0, 'expense' => 0];
$totalIncome = (float)$totals['income'];
$totalExpense = (float)$totals['expense'];
$currentBalance = $totalIncome - $totalExpense;

$currentMonth = date('Y-m');
[$start, $end] = month_range($currentMonth);
$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
    FROM transactions WHERE user_id=? AND transaction_date>=? AND transaction_date<?");
$stmt->execute([$userId, $start, $end]);
$monthTotals = $stmt->fetch() ?: ['income' => 0, 'expense' => 0];
$monthIncome = (float)$monthTotals['income'];
$monthExpense = (float)$monthTotals['expense'];
$expenseRatio = null;
$expenseRatioProgress = 0.0;
$expenseRatioState = 'neutral';
$expenseRatioNote = 'Belum ada pemasukan atau pengeluaran pada bulan ini.';

if ($monthIncome > 0) {
    $expenseRatio = ($monthExpense / $monthIncome) * 100;
    $expenseRatioProgress = min(100, max(0, $expenseRatio));

    if ($expenseRatio > 100) {
        $expenseRatioState = 'danger';
        $expenseRatioNote = 'Pengeluaran melampaui pemasukan bulan ini sebesar ' . format_rupiah($monthExpense - $monthIncome) . '.';
    } elseif ($expenseRatio >= 80) {
        $expenseRatioState = 'warning';
        $expenseRatioNote = 'Pengeluaran sudah mendekati seluruh pemasukan bulan ini.';
    } elseif ($expenseRatio >= 50) {
        $expenseRatioState = 'attention';
        $expenseRatioNote = 'Lebih dari separuh pemasukan bulan ini telah digunakan.';
    } else {
        $expenseRatioState = 'healthy';
        $expenseRatioNote = 'Pengeluaran masih di bawah separuh pemasukan bulan ini.';
    }
} elseif ($monthExpense > 0) {
    $expenseRatioProgress = 100.0;
    $expenseRatioState = 'danger';
    $expenseRatioNote = 'Belum ada pemasukan bulan ini, sehingga rasio tidak dapat dihitung.';
}

$firstGraphMonth = (new DateTimeImmutable('first day of this month'))->modify('-5 months')->format('Y-m-01');
$stmt = $pdo->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
    FROM transactions
    WHERE user_id=? AND transaction_date>=?
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY ym");
$stmt->execute([$userId, $firstGraphMonth]);
$transactionRows = [];
foreach ($stmt->fetchAll() as $row) {
    $transactionRows[$row['ym']] = $row;
}

$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
    FROM transactions WHERE user_id=? AND transaction_date<?");
$stmt->execute([$userId, $firstGraphMonth]);
$runningBalance = (float)$stmt->fetchColumn();

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $date = (new DateTimeImmutable('first day of this month'))->modify("-$i months");
    $ym = $date->format('Y-m');
    $income = (float)($transactionRows[$ym]['income'] ?? 0);
    $expense = (float)($transactionRows[$ym]['expense'] ?? 0);
    $runningBalance += $income - $expense;
    $months[] = [
        'label' => format_month_id($date, true),
        'balance' => $runningBalance,
        'income' => $income,
        'expense' => $expense,
    ];
}

$recentReport = get_report_data($pdo, $userId);
$recent = array_slice($recentReport['rows'], 0, 7);

$pageTitle = 'Ringkasan';
$pageBodyClass = 'page-dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-intro">
    <div class="intro-copy">
        <span class="eyebrow">Ringkasan transaksi</span>
        <h1>Halo, <?= e($_SESSION['user_name'] ?? 'Pengguna') ?></h1>
        <p>Pantau saldo, pemasukan, pengeluaran, rasio bulanan, dan aktivitas transaksi terbaru.</p>
    </div>
    <div class="dashboard-actions" aria-label="Aksi cepat">
        <a class="btn success" href="add_income.php">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M17 8.5v4m-2-2h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="action-label-desktop">Catat pemasukan</span><span class="action-label-mobile">Masuk</span>
        </a>
        <a class="btn danger" href="add_expense.php">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 10.5h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="action-label-desktop">Catat pengeluaran</span><span class="action-label-mobile">Keluar</span>
        </a>
    </div>
</section>

<section class="summary-grid dashboard-summary-primary" aria-label="Ringkasan utama transaksi">
    <article class="metric-card balance">
        <div class="metric-head"><span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 7h16v11H4zM16 11h4v4h-4a2 2 0 0 1 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span><span class="metric-label">Saldo transaksi</span></div>
        <strong class="metric-value"><?= format_rupiah($currentBalance) ?></strong>
        <span class="metric-meta">Pemasukan dikurangi pengeluaran</span>
    </article>
    <article class="metric-card income">
        <div class="metric-head"><span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 19V5m-5 5 5-5 5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="metric-label">Pemasukan bulan ini</span></div>
        <strong class="metric-value"><?= format_rupiah($monthIncome) ?></strong><span class="metric-meta"><?= e(format_month_id()) ?></span>
    </article>
    <article class="metric-card expense">
        <div class="metric-head"><span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 5v14m5-5-5 5-5-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span><span class="metric-label">Pengeluaran bulan ini</span></div>
        <strong class="metric-value"><?= format_rupiah($monthExpense) ?></strong><span class="metric-meta"><?= e(format_month_id()) ?></span>
    </article>
</section>

<section class="overview-grid overview-grid-single dashboard-ratio-section" aria-label="Kontrol pengeluaran bulan ini">
    <article class="card ratio-card ratio-<?= e($expenseRatioState) ?>">
        <div class="section-head compact ratio-heading">
            <div>
                <span class="section-kicker">Kontrol pengeluaran</span>
                <h2>Rasio pengeluaran bulanan</h2>
            </div>
        </div>
        <strong class="ratio-value"><?= $expenseRatio === null ? '—' : number_format($expenseRatio, 1, ',', '.') . '%' ?></strong>
        <p class="ratio-note"><?= e($expenseRatioNote) ?></p>
        <div class="ratio-progress-wrap">
            <div class="ratio-track" role="progressbar" aria-label="Persentase pengeluaran terhadap pemasukan bulan ini" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($expenseRatioProgress) ?>">
                <span class="ratio-fill" style="width: <?= number_format($expenseRatioProgress, 2, '.', '') ?>%"></span>
            </div>
            <div class="ratio-scale" aria-hidden="true"><span>Batas 100%</span></div>
        </div>
    </article>
</section>

<section class="card chart-card">
    <div class="section-head"><div><span class="section-kicker">Tren enam bulan</span><h2>Saldo transaksi kumulatif</h2><p class="muted">Dihitung hanya dari pemasukan dan pengeluaran pada menu transaksi.</p></div></div>
    <div class="chart-wrap"><canvas id="balanceChart" aria-label="Grafik saldo transaksi enam bulan" role="img"></canvas></div>
</section>

<section class="card transaction-card">
    <div class="section-head"><div><span class="section-kicker">Aktivitas terbaru</span><h2>Transaksi terakhir</h2><p class="muted">Menampilkan tujuh pemasukan atau pengeluaran terbaru.</p></div><a class="section-link" href="transactions.php">Lihat semua</a></div>
    <div class="table-wrap responsive-table">
        <table><thead><tr><th>Tanggal</th><th>Kategori</th><th>Keterangan</th><th>Jenis</th><th class="text-right">Nominal</th></tr></thead><tbody>
        <?php if (!$recent): ?>
            <tr><td colspan="5" class="empty">Belum ada transaksi. Mulai dengan mencatat pemasukan atau pengeluaran.</td></tr>
        <?php else: foreach ($recent as $transaction): ?>
            <?php $categoryLabel = transaction_category_history_name($transaction['category_name'] ?? null); ?>
            <tr>
                <td data-label="Tanggal"><?= e(format_date_id($transaction['transaction_date'])) ?></td>
                <td data-label="Kategori"><span class="mobile-card-title"><?= e($categoryLabel) ?></span><span class="mobile-card-date"><?= e(format_date_id($transaction['transaction_date'])) ?></span></td>
                <td data-label="Keterangan"><?= e(($transaction['description'] ?? '') !== '' ? $transaction['description'] : '-') ?></td>
                <td data-label="Jenis" class="mobile-card-side"><span class="mobile-card-side-amount amount <?= e(transaction_amount_class($transaction['type'])) ?>"><?= e(transaction_amount_prefix($transaction['type'])) ?><?= format_rupiah($transaction['amount']) ?></span><span class="badge <?= e(transaction_badge_class($transaction['type'])) ?>"><?= e(transaction_type_label($transaction['type'])) ?></span></td>
                <td data-label="Nominal" class="text-right amount desktop-card-amount <?= e(transaction_amount_class($transaction['type'])) ?>"><?= e(transaction_amount_prefix($transaction['type'])) ?><?= format_rupiah($transaction['amount']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody></table>
    </div>
</section>

<script>window.balanceChartData = <?= json_encode($months, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
