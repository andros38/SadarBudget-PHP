<?php

/**
 * Menyusun data laporan dan saldo berjalan dari transaksi yang sudah diurutkan
 * kronologis (tanggal, waktu dibuat, lalu ID secara menaik).
 *
 * Filter hanya menentukan baris yang ditampilkan. Saldo setelah transaksi tetap
 * dihitung dari seluruh pemasukan dan pengeluaran agar angka tidak berubah secara
 * keliru saat pengguna memilih bulan atau jenis tertentu.
 */
function build_report_data_from_transaction_rows(array $allRows, string $month = '', string $type = ''): array
{
    $startDate = null;
    $endDate = null;
    if ($month !== '' && valid_month($month)) {
        [$startDate, $endDate] = month_range($month);
    }

    $typeFilter = in_array($type, ['income', 'expense'], true) ? $type : '';
    $displayRows = [];
    $income = 0.0;
    $expense = 0.0;
    $runningBalance = 0.0;

    foreach ($allRows as $row) {
        $amount = (float)($row['amount'] ?? 0);
        $rowType = (string)($row['type'] ?? '');
        $runningBalance += transaction_cash_effect($rowType, $amount);
        $row['balance_after'] = $runningBalance;

        $transactionDate = (string)($row['transaction_date'] ?? '');
        $matchesPeriod = $startDate === null
            || ($transactionDate >= $startDate && $transactionDate < $endDate);
        $matchesType = $typeFilter === '' || $rowType === $typeFilter;

        if (!$matchesPeriod || !$matchesType) {
            continue;
        }

        if ($rowType === 'income') {
            $income += $amount;
        } elseif ($rowType === 'expense') {
            $expense += $amount;
        }

        $displayRows[] = $row;
    }

    // Laporan tetap menampilkan transaksi terbaru di bagian atas. Nilai
    // balance_after tidak diubah karena sudah dihitung secara kronologis.
    $displayRows = array_reverse($displayRows);
    $net = $income - $expense;

    return [
        'rows' => $displayRows,
        'income' => $income,
        'expense' => $expense,
        'total_expense' => $expense,
        'cash_net' => $net,
        'asset_net' => $net,
        'net' => $net,
        'current_balance' => $runningBalance,
    ];
}

/**
 * Mengambil laporan transaksi utama beserta saldo berjalan.
 */
function get_report_data(PDO $pdo, int $userId, string $month = '', string $type = ''): array
{
    $stmt = $pdo->prepare("SELECT
            t.transaction_date,
            t.created_at,
            t.id AS row_id,
            t.type,
            t.amount,
            t.description,
            t.category_name_snapshot AS category_name,
            'transaction' AS source
        FROM transactions t
        WHERE t.user_id = :user_id
        ORDER BY t.transaction_date ASC, t.created_at ASC, t.id ASC");
    $stmt->execute([':user_id' => $userId]);

    return build_report_data_from_transaction_rows($stmt->fetchAll(), $month, $type);
}
