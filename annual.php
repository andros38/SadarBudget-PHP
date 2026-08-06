<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/annual_data.php';
require_login();

$pdo = db();
$userId = current_user_id();
$availableYears = get_available_report_years($pdo, $userId);
$requestedYear = valid_year($_GET['year'] ?? null) ? (int)$_GET['year'] : (int)date('Y');
$year = in_array($requestedYear, $availableYears, true) ? $requestedYear : (int)$availableYears[0];
$recap = get_annual_recap($pdo, $userId, $year);
$totals = $recap['totals'];
$isZero = static fn(float $value): bool => abs($value) < 0.005;
$valueState = static function (float $value, string $positiveClass = 'positive') use ($isZero): string {
    if ($isZero($value)) return 'is-zero';
    return $value < 0 ? 'negative' : $positiveClass;
};

$pageTitle = 'Laporan Tahunan';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading annual-heading">
    <div>
        <span class="eyebrow">Analisis transaksi</span>
        <h1>Laporan Tahunan</h1>
        <p class="muted">Lihat pola pemasukan, pengeluaran, dan perubahan saldo sepanjang tahun.</p>
    </div>
    <form method="get" class="year-picker" aria-label="Pilih tahun laporan">
        <label for="annualYear">Tahun</label>
        <select id="annualYear" name="year" onchange="this.form.submit()">
            <?php foreach ($availableYears as $optionYear): ?>
                <option value="<?= (int)$optionYear ?>" <?= $year === (int)$optionYear ? 'selected' : '' ?>><?= (int)$optionYear ?></option>
            <?php endforeach; ?>
        </select>
        <noscript><button class="btn primary" type="submit">Tampilkan</button></noscript>
    </form>
</div>

<section class="annual-summary-grid annual-summary-grid-three" aria-label="Ringkasan tahun <?= (int)$year ?>">
    <article class="annual-metric income <?= $isZero((float)$totals['income']) ? 'is-zero' : '' ?>">
        <span>Pemasukan</span><strong><?= format_rupiah($totals['income']) ?></strong><small>Total uang yang diterima</small>
    </article>
    <article class="annual-metric expense <?= $isZero((float)$totals['expense']) ? 'is-zero' : '' ?>">
        <span>Pengeluaran</span><strong><?= format_rupiah($totals['expense']) ?></strong><small>Total transaksi pengeluaran</small>
    </article>
    <article class="annual-metric net <?= e($valueState((float)$totals['asset_net'], 'positive')) ?>">
        <span>Perubahan saldo</span><strong><?= format_rupiah($totals['asset_net']) ?></strong><small>Selisih pemasukan dan pengeluaran</small>
    </article>
</section>

<section class="annual-insight-grid">
    <article class="card annual-chart-card">
        <div class="section-head compact"><div><span class="section-kicker">Tren bulanan</span><h2>Arus transaksi <?= (int)$year ?></h2><p class="muted">Perbandingan pemasukan dan pengeluaran setiap bulan.</p></div></div>
        <div class="annual-chart-legend" aria-hidden="true"><span class="income">Pemasukan</span><span class="expense">Pengeluaran</span></div>
        <div class="annual-chart-wrap"><canvas id="annualChart" role="img" aria-label="Grafik pemasukan dan pengeluaran bulanan tahun <?= (int)$year ?>"></canvas></div>
    </article>

    <aside class="card annual-highlights-card">
        <span class="section-kicker">Sorotan tahun</span><h2>Ringkasan cepat</h2>
        <dl class="annual-highlight-list">
            <div><dt>Bulan aktif</dt><dd><?= (int)$recap['active_months'] ?> dari 12</dd></div>
            <div><dt>Bulan terbaik</dt><dd><?= $recap['best_month'] ? e($recap['best_month']['full_label']) : 'Belum ada data' ?></dd></div>
            <div><dt>Total transaksi masuk</dt><dd><?= format_rupiah($totals['income']) ?></dd></div>
            <div><dt>Perubahan saldo</dt><dd class="<?= e($valueState((float)$totals['money_net'])) ?>"><?= format_rupiah($totals['money_net']) ?></dd></div>
        </dl>
    </aside>
</section>

<section class="annual-detail-grid">
    <article class="card annual-table-card">
        <div class="section-head compact"><div><span class="section-kicker">Rincian bulanan</span><h2>Januari–Desember</h2><p class="muted annual-mobile-hint">Bulan tanpa aktivitas diringkas. Ketuk bulan untuk melihat rinciannya.</p></div></div>
        <div class="table-wrap annual-desktop-table">
            <table><thead><tr><th>Bulan</th><th class="text-right">Pemasukan</th><th class="text-right">Pengeluaran</th><th class="text-right">Perubahan saldo</th></tr></thead><tbody>
            <?php foreach ($recap['months'] as $month): ?>
                <tr>
                    <td><strong><?= e($month['full_label']) ?></strong></td>
                    <td class="text-right amount income <?= $isZero((float)$month['income']) ? 'is-zero' : '' ?>"><?= format_rupiah($month['income']) ?></td>
                    <td class="text-right amount expense <?= $isZero((float)$month['expense']) ? 'is-zero' : '' ?>"><?= format_rupiah($month['expense']) ?></td>
                    <td class="text-right amount overall-change <?= e($valueState((float)$month['asset_net'])) ?>"><?= format_rupiah($month['asset_net']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
        <div class="annual-mobile-months" aria-label="Rincian bulanan tahun <?= (int)$year ?>">
            <?php foreach ($recap['months'] as $month): ?>
                <details class="annual-month-card" <?= $month['has_activity'] ? 'open' : '' ?>>
                    <summary>
                        <span class="annual-month-title"><strong><?= e($month['full_label']) ?></strong><small><?= $month['has_activity'] ? 'Ada aktivitas bulan ini' : 'Belum ada aktivitas' ?></small></span>
                        <span class="annual-month-summary-value <?= e($valueState((float)$month['asset_net'])) ?>"><small>Perubahan saldo</small><strong><?= format_rupiah($month['asset_net']) ?></strong></span>
                        <svg class="annual-month-chevron" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m8 10 4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </summary>
                    <div class="annual-month-metrics annual-month-metrics-three">
                        <div><span>Pemasukan</span><strong class="income <?= $isZero((float)$month['income']) ? 'is-zero' : '' ?>"><?= format_rupiah($month['income']) ?></strong></div>
                        <div><span>Pengeluaran</span><strong class="expense <?= $isZero((float)$month['expense']) ? 'is-zero' : '' ?>"><?= format_rupiah($month['expense']) ?></strong></div>
                        <div><span>Perubahan saldo</span><strong class="overall-change <?= e($valueState((float)$month['asset_net'])) ?>"><?= format_rupiah($month['asset_net']) ?></strong></div>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </article>

    <aside class="card top-category-card">
        <div class="section-head compact"><div><span class="section-kicker">Pengeluaran terbesar</span><h2>Kategori utama</h2></div></div>
        <?php if (!$recap['top_categories']): ?>
            <p class="empty compact-empty">Belum ada pengeluaran pada tahun ini.</p>
        <?php else: ?>
            <?php $maxCategory = max(array_map(static fn(array $row): float => (float)$row['total_amount'], $recap['top_categories'])); ?>
            <ol class="top-category-list">
                <?php foreach ($recap['top_categories'] as $category): ?>
                    <?php $percentage = $maxCategory > 0 ? ((float)$category['total_amount'] / $maxCategory) * 100 : 0; ?>
                    <li><div><strong><?= e($category['category_name']) ?></strong><span><?= format_rupiah($category['total_amount']) ?></span></div><div class="category-bar"><span style="width: <?= number_format($percentage, 2, '.', '') ?>%"></span></div></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </aside>
</section>

<script>
window.annualChartData = <?= json_encode(array_map(static fn(array $row): array => [
    'label' => $row['label'], 'income' => $row['income'], 'expense' => $row['expense'],
], $recap['months']), JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK) ?>;
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
