<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/report_data.php';
require_login();

$pdo = db();
$userId = current_user_id();
$savingsReady = empty($_SESSION['schema_error']);

$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
    FROM transactions WHERE user_id = ?");
$stmt->execute([$userId]);
$totals = $stmt->fetch();
$netAssets = (float)$totals['income'] - (float)$totals['expense'];
$position = $savingsReady
    ? get_financial_position($pdo, $userId)
    : ['net_assets' => $netAssets, 'savings_balance' => 0.0, 'available_cash' => $netAssets];
$availableCash = (float)$position['available_cash'];
$totalSavings = (float)$position['savings_balance'];

$currentMonth = date('Y-m');
[$start, $end] = month_range($currentMonth);
$stmt = $pdo->prepare("SELECT
    COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,
    COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense
    FROM transactions WHERE user_id = ? AND transaction_date >= ? AND transaction_date < ?");
$stmt->execute([$userId, $start, $end]);
$monthTotals = $stmt->fetch();
$monthIncome = (float)$monthTotals['income'];
$monthExpense = (float)$monthTotals['expense'];
$monthSavingsSpend = 0.0;

if ($savingsReady) {
    $stmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='spend' THEN amount ELSE 0 END),0) AS spent
        FROM savings_entries WHERE user_id=? AND entry_date >= ? AND entry_date < ?");
    $stmt->execute([$userId, $start, $end]);
    $monthSavingTotals = $stmt->fetch();
    $monthSavingsSpend = (float)$monthSavingTotals['spent'];
}

$monthTotalExpense = $monthExpense + $monthSavingsSpend;
$expenseRatio = $monthIncome > 0 ? min(100, ($monthTotalExpense / $monthIncome) * 100) : ($monthTotalExpense > 0 ? 100 : 0);

$firstGraphMonth = (new DateTimeImmutable('first day of this month'))->modify('-5 months')->format('Y-m-01');
$stmt = $pdo->prepare("SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS ym,
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense
    FROM transactions
    WHERE user_id = ? AND transaction_date >= ?
    GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
    ORDER BY ym");
$stmt->execute([$userId, $firstGraphMonth]);
$transactionRows = [];
foreach ($stmt->fetchAll() as $row) {
    $transactionRows[$row['ym']] = $row;
}

$savingRows = [];
if ($savingsReady) {
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(entry_date, '%Y-%m') AS ym,
        SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END) AS deposits,
        SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END) AS withdrawals,
        SUM(CASE WHEN type='spend' THEN amount ELSE 0 END) AS spent
        FROM savings_entries
        WHERE user_id=? AND entry_date >= ?
        GROUP BY DATE_FORMAT(entry_date, '%Y-%m')
        ORDER BY ym");
    $stmt->execute([$userId, $firstGraphMonth]);
    foreach ($stmt->fetchAll() as $row) {
        $savingRows[$row['ym']] = $row;
    }
}

$stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0)
    FROM transactions WHERE user_id=? AND transaction_date < ?");
$stmt->execute([$userId, $firstGraphMonth]);
$runningCash = (float)$stmt->fetchColumn();

if ($savingsReady) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount WHEN type='withdrawal' THEN -amount ELSE 0 END),0)
        FROM savings_entries WHERE user_id=? AND entry_date < ?");
    $stmt->execute([$userId, $firstGraphMonth]);
    $runningCash -= (float)$stmt->fetchColumn();
}

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $date = (new DateTimeImmutable('first day of this month'))->modify("-$i months");
    $ym = $date->format('Y-m');
    $income = (float)($transactionRows[$ym]['income'] ?? 0);
    $expense = (float)($transactionRows[$ym]['expense'] ?? 0);
    $deposits = (float)($savingRows[$ym]['deposits'] ?? 0);
    $withdrawals = (float)($savingRows[$ym]['withdrawals'] ?? 0);
    $spent = (float)($savingRows[$ym]['spent'] ?? 0);

    $runningCash += $income - $expense - $deposits + $withdrawals;
    $months[] = [
        'label' => format_month_id($date, true),
        'balance' => $runningCash,
        'income' => $income,
        'expense' => $expense + $spent,
        'savings' => $deposits - $withdrawals,
    ];
}
$recentReport = get_report_data($pdo, $userId, '', '');
$recent = array_slice($recentReport['rows'], 0, 7);

$dashboardGoals = [];
if ($savingsReady) {
    $stmt = $pdo->prepare("SELECT sg.*,
        COALESCE(summary.current_amount, 0) AS current_amount
        FROM savings_goals sg
        LEFT JOIN (
            SELECT savings_goal_id, SUM(CASE WHEN type='deposit' THEN amount WHEN type IN ('withdrawal','spend') THEN -amount ELSE 0 END) AS current_amount
            FROM savings_entries WHERE user_id=? GROUP BY savings_goal_id
        ) summary ON summary.savings_goal_id=sg.id
        WHERE sg.user_id=? AND sg.status='active'
        ORDER BY (sg.target_date IS NULL), sg.target_date ASC, sg.created_at DESC
        LIMIT 3");
    $stmt->execute([$userId, $userId]);
    $dashboardGoals = $stmt->fetchAll();
}

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/header.php';
?>
<section class="dashboard-intro">
    <div class="intro-copy">
        <span class="eyebrow">Ringkasan keuangan</span>
        <h1>Halo, <?= e($_SESSION['user_name'] ?? 'Pengguna') ?></h1>
        <p>Pantau uang, tabungan, arus dana, dan transaksi terbaru dalam satu tampilan yang terstruktur.</p>
    </div>
    <div class="dashboard-actions" aria-label="Aksi cepat">
        <a class="btn success" href="add_income.php">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M17 8.5v4m-2-2h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="action-label-desktop">Tambah pemasukan</span><span class="action-label-mobile">Masuk</span>
        </a>
        <a class="btn danger" href="add_expense.php">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 10.5h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            <span class="action-label-desktop">Tambah pengeluaran</span><span class="action-label-mobile">Belanja</span>
        </a>
        <a class="btn saving" href="savings.php">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            <span class="action-label-desktop">Kelola tabungan</span><span class="action-label-mobile">Tabungan</span>
        </a>
    </div>
</section>

<section class="summary-grid four-columns" aria-label="Ringkasan keuangan">
    <article class="metric-card balance">
        <div class="metric-head">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 7h16v11H4zM16 11h4v4h-4a2 2 0 0 1 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M4 7l3-3h10l3 3" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg></span>
            <span class="metric-label">Uang tersedia</span>
        </div>
        <strong class="metric-value"><?= format_rupiah($availableCash) ?></strong>
        <span class="metric-meta">Dana yang belum dipindahkan ke tabungan</span>
    </article>

    <article class="metric-card savings">
        <div class="metric-head">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
            <span class="metric-label">Total tabungan</span>
        </div>
        <strong class="metric-value"><?= format_rupiah($totalSavings) ?></strong>
        <a class="metric-meta metric-link" href="savings.php">Lihat tujuan tabungan</a>
    </article>

    <article class="metric-card income">
        <div class="metric-head">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 19V5m-5 5 5-5 5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="metric-label">Pemasukan bulan ini</span>
        </div>
        <strong class="metric-value"><?= format_rupiah($monthIncome) ?></strong>
        <span class="metric-meta"><?= e(format_month_id()) ?></span>
    </article>

    <article class="metric-card expense">
        <div class="metric-head">
            <span class="metric-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 5v14m5-5-5 5-5-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span class="metric-label">Total pengeluaran bulan ini</span>
        </div>
        <strong class="metric-value"><?= format_rupiah($monthTotalExpense) ?></strong>
        <span class="metric-meta">
            <?= e(format_month_id()) ?>
            <?php if ($monthSavingsSpend > 0): ?> · termasuk <?= format_rupiah($monthSavingsSpend) ?> dari tabungan<?php endif; ?>
        </span>
    </article>
</section>

<section class="overview-grid overview-grid-single">
    <article class="card ratio-card">
        <div class="section-head">
            <div>
                <span class="section-kicker">Kontrol anggaran</span>
                <h2>Rasio pengeluaran bulanan</h2>
                <p class="muted">Persentase pengeluaran terhadap pemasukan bulan berjalan.</p>
            </div>
            <strong class="ratio-value"><?= number_format($expenseRatio, 1, ',', '.') ?>%</strong>
        </div>
        <div class="progress-track" role="progressbar" aria-label="Rasio pengeluaran" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($expenseRatio) ?>">
            <div class="progress-bar" style="width: <?= number_format($expenseRatio, 2, '.', '') ?>%"></div>
        </div>
        <div class="progress-scale"><span>0%</span><span>100%</span></div>
    </article>
</section>

<section class="card dashboard-savings-card">
    <div class="section-head">
        <div>
            <span class="section-kicker">Dana terencana</span>
            <h2>Tujuan tabungan</h2>
            <p class="muted">Uang yang disetor ke tabungan tetap menjadi bagian dari total dana tercatat.</p>
        </div>
        <a class="section-link" href="savings.php"><?= $dashboardGoals ? 'Kelola tabungan' : 'Buat tujuan pertama' ?></a>
    </div>

    <?php if (!$dashboardGoals): ?>
        <div class="dashboard-saving-empty">
            <span class="goal-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></span>
            <div><strong>Belum ada tujuan tabungan</strong><span>Mulai pisahkan dana untuk kebutuhan darurat atau rencana masa depan.</span></div>
        </div>
    <?php else: ?>
        <div class="dashboard-goal-grid">
            <?php foreach ($dashboardGoals as $goal):
                $currentAmount = max(0, (float)$goal['current_amount']);
                $targetAmount = (float)$goal['target_amount'];
                $progress = savings_progress($currentAmount, $targetAmount);
            ?>
                <a class="dashboard-goal-item" href="savings.php#goal-<?= (int)$goal['id'] ?>">
                    <div class="dashboard-goal-head"><strong><?= e($goal['name']) ?></strong><span><?= number_format($progress, 0, ',', '.') ?>%</span></div>
                    <div class="saving-progress-track compact"><div class="saving-progress-bar" style="width: <?= number_format($progress, 2, '.', '') ?>%"></div></div>
                    <div class="dashboard-goal-values"><span><?= format_rupiah($currentAmount) ?></span><span>dari <?= format_rupiah($targetAmount) ?></span></div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="card chart-card">
    <div class="section-head">
        <div>
            <span class="section-kicker">Perkembangan</span>
            <h2>Grafik uang tersedia 6 bulan</h2>
            <p class="muted">Uang kumulatif setelah pemasukan, pengeluaran, setoran, dan pencairan tabungan.</p>
        </div>
    </div>
    <div class="chart-wrap">
        <canvas id="balanceChart" aria-label="Grafik uang tersedia enam bulan" role="img"></canvas>
    </div>
</section>

<section class="card transaction-card">
    <div class="section-head">
        <div>
            <span class="section-kicker">Aktivitas terbaru</span>
            <h2>Transaksi terbaru</h2>
            <p class="muted">Menampilkan tujuh mutasi terbaru, termasuk transfer ke tabungan dan pencairan dana.</p>
        </div>
        <a class="section-link" href="transactions.php">Lihat semua transaksi</a>
    </div>
    <div class="table-wrap responsive-table">
        <table>
            <thead><tr><th>Tanggal</th><th>Kategori / Tujuan</th><th>Keterangan</th><th>Jenis</th><th class="text-right">Nominal</th></tr></thead>
            <tbody>
            <?php if (!$recent): ?>
                <tr><td colspan="5" class="empty">Belum ada transaksi. Mulai dengan menambah pemasukan, pengeluaran, atau tabungan.</td></tr>
            <?php else: foreach ($recent as $transaction): ?>
                <?php
                if ($transaction['type'] === 'savings_spend') {
                    $categoryLabel = transaction_category_history_name($transaction['category_name'] ?? null) . ' — ' . savings_goal_history_name($transaction['goal_name'] ?? null, null, (int)($transaction['savings_goal_id'] ?? 0));
                } elseif ($transaction['source'] === 'savings') {
                    $categoryLabel = 'Tabungan — ' . savings_goal_history_name($transaction['goal_name'] ?? null, null, (int)($transaction['savings_goal_id'] ?? 0));
                } else {
                    $categoryLabel = transaction_category_history_name($transaction['category_name'] ?? null);
                }
                ?>
                <tr>
                    <td data-label="Tanggal"><?= e(format_date_id($transaction['transaction_date'])) ?></td>
                    <td data-label="Kategori / Tujuan"><span class="mobile-card-title"><?= e($categoryLabel) ?></span><span class="mobile-card-date"><?= e(format_date_id($transaction['transaction_date'])) ?></span></td>
                    <td data-label="Keterangan"><?= e(($transaction['description'] ?? '') !== '' ? $transaction['description'] : '-') ?></td>
                    <td data-label="Jenis" class="mobile-card-side">
                        <span class="mobile-card-side-amount amount <?= e(transaction_amount_class($transaction['type'])) ?>"><?= e(transaction_amount_prefix($transaction['type'])) ?><?= format_rupiah($transaction['amount']) ?></span>
                        <span class="badge <?= e(transaction_badge_class($transaction['type'])) ?>"><?= e(transaction_type_label($transaction['type'])) ?></span>
                    </td>
                    <td data-label="Nominal" class="text-right amount desktop-card-amount <?= e(transaction_amount_class($transaction['type'])) ?>"><?= e(transaction_amount_prefix($transaction['type'])) ?><?= format_rupiah($transaction['amount']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
window.balanceChartData = <?= json_encode($months, JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
