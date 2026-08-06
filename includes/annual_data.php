<?php

/**
 * Menghitung metrik transaksi untuk satu bulan.
 */
function calculate_annual_month_metrics(float $income, float $expense): array
{
    $net = $income - $expense;

    return [
        'income' => $income,
        'expense' => $expense,
        'total_expense' => $expense,
        'money_net' => $net,
        'asset_net' => $net,
    ];
}

function get_available_report_years(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT MIN(YEAR(transaction_date)) AS min_year, MAX(YEAR(transaction_date)) AS max_year
        FROM transactions WHERE user_id=?");
    $stmt->execute([$userId]);
    $range = $stmt->fetch() ?: [];

    $currentYear = (int)date('Y');
    $minYear = isset($range['min_year']) && $range['min_year'] !== null ? (int)$range['min_year'] : $currentYear;
    $maxYear = isset($range['max_year']) && $range['max_year'] !== null ? (int)$range['max_year'] : $currentYear;

    return range(max($maxYear, $currentYear), min($minYear, $currentYear));
}

function get_annual_recap(PDO $pdo, int $userId, int $year): array
{
    $start = sprintf('%04d-01-01', $year);
    $end = sprintf('%04d-01-01', $year + 1);

    $stmt = $pdo->prepare("SELECT MONTH(transaction_date) AS month_number,
        COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END), 0) AS expense
        FROM transactions
        WHERE user_id=? AND transaction_date>=? AND transaction_date<?
        GROUP BY MONTH(transaction_date)");
    $stmt->execute([$userId, $start, $end]);
    $transactionMonths = [];
    foreach ($stmt->fetchAll() as $row) {
        $transactionMonths[(int)$row['month_number']] = $row;
    }

    $months = [];
    $totals = [
        'income' => 0.0,
        'expense' => 0.0,
        'total_expense' => 0.0,
        'money_net' => 0.0,
        'asset_net' => 0.0,
    ];

    for ($month = 1; $month <= 12; $month++) {
        $metrics = calculate_annual_month_metrics(
            (float)($transactionMonths[$month]['income'] ?? 0),
            (float)($transactionMonths[$month]['expense'] ?? 0)
        );
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-1');
        $months[] = array_merge($metrics, [
            'month' => $month,
            'label' => $date ? format_month_id($date, true) : (string)$month,
            'full_label' => $date ? format_month_id($date) : (string)$month,
            'has_activity' => $metrics['income'] > 0 || $metrics['expense'] > 0,
        ]);

        foreach (array_keys($totals) as $key) {
            $totals[$key] += (float)$metrics[$key];
        }
    }

    $categoryStmt = $pdo->prepare("SELECT
        COALESCE(NULLIF(TRIM(category_name_snapshot), ''), 'Tanpa kategori') AS category_name,
        SUM(amount) AS total_amount
        FROM transactions
        WHERE user_id=? AND type='expense' AND transaction_date>=? AND transaction_date<?
        GROUP BY category_name
        ORDER BY total_amount DESC, category_name ASC
        LIMIT 6");
    $categoryStmt->execute([$userId, $start, $end]);
    $topCategories = $categoryStmt->fetchAll();

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
    ];
}
