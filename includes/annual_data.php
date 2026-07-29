<?php

/**
 * Hitung metrik satu bulan tanpa akses database.
 *
 * Perubahan tabungan memakai perubahan saldo bersih, bukan total setoran bruto:
 * setoran - pencairan - penggunaan langsung dari tabungan.
 */
function calculate_annual_month_metrics(
    float $income,
    float $expense,
    float $deposit,
    float $withdrawal,
    float $savingsSpend
): array {
    $totalExpense = $expense + $savingsSpend;
    $moneyNet = $income - $expense - $deposit + $withdrawal;
    $savingsNet = $deposit - $withdrawal - $savingsSpend;
    $overallNet = $income - $totalExpense;

    return [
        'income' => $income,
        'expense' => $expense,
        'savings_deposit' => $deposit,
        'savings_withdrawal' => $withdrawal,
        'savings_spend' => $savingsSpend,
        'total_expense' => $totalExpense,
        'money_net' => $moneyNet,
        'savings_net' => $savingsNet,
        // Nama internal dipertahankan agar kode lama tetap kompatibel.
        'asset_net' => $overallNet,
    ];
}

/**
 * Hitung saldo tabungan yang masih tersimpan saat ini.
 *
 * Saldo saat ini adalah seluruh setoran dikurangi pencairan dan penggunaan
 * langsung dari tabungan. Nilai dijaga tidak negatif untuk melindungi UI dari
 * data lama yang tidak konsisten.
 */
function calculate_current_savings_balance(
    float $deposit,
    float $withdrawal,
    float $savingsSpend
): float {
    return max(0.0, $deposit - $withdrawal - $savingsSpend);
}

function get_available_report_years(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT MIN(year_value) AS min_year, MAX(year_value) AS max_year
        FROM (
            SELECT YEAR(transaction_date) AS year_value FROM transactions WHERE user_id=?
            UNION ALL
            SELECT YEAR(entry_date) AS year_value FROM savings_entries WHERE user_id=?
        ) years_data");
    $stmt->execute([$userId, $userId]);
    $range = $stmt->fetch() ?: [];

    $currentYear = (int)date('Y');
    $minYear = isset($range['min_year']) && $range['min_year'] !== null ? (int)$range['min_year'] : $currentYear;
    $maxYear = isset($range['max_year']) && $range['max_year'] !== null ? (int)$range['max_year'] : $currentYear;
    $minYear = min($minYear, $currentYear);
    $maxYear = max($maxYear, $currentYear);

    return range($maxYear, $minYear);
}

function get_annual_recap(PDO $pdo, int $userId, int $year): array
{
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-01-01', $year + 1);

    $transactionStmt = $pdo->prepare("SELECT MONTH(transaction_date) AS month_number,
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id=? AND transaction_date>=? AND transaction_date<?
        GROUP BY MONTH(transaction_date)");
    $transactionStmt->execute([$userId, $start, $end]);
    $transactions = [];
    foreach ($transactionStmt->fetchAll() as $row) {
        $transactions[(int)$row['month_number']] = $row;
    }

    $savingsStmt = $pdo->prepare("SELECT MONTH(entry_date) AS month_number,
        COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals,
        COALESCE(SUM(CASE WHEN type='spend' THEN amount ELSE 0 END), 0) AS savings_spend
        FROM savings_entries
        WHERE user_id=? AND entry_date>=? AND entry_date<?
        GROUP BY MONTH(entry_date)");
    $savingsStmt->execute([$userId, $start, $end]);
    $savings = [];
    foreach ($savingsStmt->fetchAll() as $row) {
        $savings[(int)$row['month_number']] = $row;
    }

    $months = [];
    $totals = [
        'income' => 0.0,
        'expense' => 0.0,
        'savings_deposit' => 0.0,
        'savings_withdrawal' => 0.0,
        'savings_spend' => 0.0,
        'total_expense' => 0.0,
        'money_net' => 0.0,
        'savings_net' => 0.0,
        'asset_net' => 0.0,
    ];

    for ($month = 1; $month <= 12; $month++) {
        $metrics = calculate_annual_month_metrics(
            (float)($transactions[$month]['income'] ?? 0),
            (float)($transactions[$month]['expense'] ?? 0),
            (float)($savings[$month]['deposits'] ?? 0),
            (float)($savings[$month]['withdrawals'] ?? 0),
            (float)($savings[$month]['savings_spend'] ?? 0)
        );

        $date = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
        $months[] = array_merge($metrics, [
            'month' => $month,
            'label' => $date ? format_month_id($date, true) : (string)$month,
            'full_label' => $date ? format_month_id($date) : (string)$month,
            'has_activity' => $metrics['income'] > 0
                || $metrics['total_expense'] > 0
                || $metrics['savings_deposit'] > 0
                || $metrics['savings_withdrawal'] > 0,
        ]);

        foreach (array_keys($totals) as $key) {
            $totals[$key] += (float)$metrics[$key];
        }
    }

    $categoryStmt = $pdo->prepare("SELECT category_name, SUM(amount) AS total_amount
        FROM (
            SELECT COALESCE(NULLIF(TRIM(category_name_snapshot), ''), 'Tanpa kategori') AS category_name, amount
            FROM transactions
            WHERE user_id=? AND type='expense' AND transaction_date>=? AND transaction_date<?
            UNION ALL
            SELECT COALESCE(NULLIF(TRIM(category_name_snapshot), ''), 'Tanpa kategori') AS category_name, amount
            FROM savings_entries
            WHERE user_id=? AND type='spend' AND entry_date>=? AND entry_date<?
        ) expense_rows
        GROUP BY category_name
        ORDER BY total_amount DESC, category_name ASC
        LIMIT 6");
    $categoryStmt->execute([$userId, $start, $end, $userId, $start, $end]);
    $topCategories = $categoryStmt->fetchAll();

    $currentSavingsStmt = $pdo->prepare("SELECT
        COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE 0 END), 0) AS deposits,
        COALESCE(SUM(CASE WHEN type='withdrawal' THEN amount ELSE 0 END), 0) AS withdrawals,
        COALESCE(SUM(CASE WHEN type='spend' THEN amount ELSE 0 END), 0) AS savings_spend
        FROM savings_entries
        WHERE user_id=?");
    $currentSavingsStmt->execute([$userId]);
    $currentSavings = $currentSavingsStmt->fetch() ?: [];
    $currentSavingsBalance = calculate_current_savings_balance(
        (float)($currentSavings['deposits'] ?? 0),
        (float)($currentSavings['withdrawals'] ?? 0),
        (float)($currentSavings['savings_spend'] ?? 0)
    );

    $activeMonths = array_values(array_filter(
        $months,
        static fn(array $row): bool => (bool)$row['has_activity']
    ));

    $bestMonth = null;
    foreach ($activeMonths as $row) {
        if ($bestMonth === null || $row['asset_net'] > $bestMonth['asset_net']) {
            $bestMonth = $row;
        }
    }

    return [
        'year' => $year,
        'months' => $months,
        'totals' => $totals,
        'top_categories' => $topCategories,
        'active_months' => count($activeMonths),
        'best_month' => $bestMonth,
        'current_savings_balance' => $currentSavingsBalance,
    ];
}
