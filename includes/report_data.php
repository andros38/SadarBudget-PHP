<?php
function get_report_data(PDO $pdo, int $userId, string $month = '', string $type = ''): array
{
    $hasSavings = empty($_SESSION['schema_error']);
    $params = [':user_transaction' => $userId];

    $dateSqlTransactions = '';
    $dateSqlSavings = '';
    if ($month && valid_month($month)) {
        [$start, $end] = month_range($month);
        $dateSqlTransactions = ' AND t.transaction_date >= :start_tx AND t.transaction_date < :end_tx';
        $params[':start_tx'] = $start;
        $params[':end_tx'] = $end;

        if ($hasSavings) {
            $dateSqlSavings = ' AND se.entry_date >= :start_sv AND se.entry_date < :end_sv';
            $params[':start_sv'] = $start;
            $params[':end_sv'] = $end;
        }
    }

    $typeTransactionSql = '';
    $typeSavingsSql = '';
    if ($type === 'income' || $type === 'expense') {
        $typeTransactionSql = ' AND t.type = :tx_type';
        $typeSavingsSql = ' AND 1=0';
        $params[':tx_type'] = $type;
    } elseif ($type === 'savings_deposit') {
        $typeTransactionSql = ' AND 1=0';
        $typeSavingsSql = " AND se.type = 'deposit'";
    } elseif ($type === 'savings_withdrawal') {
        $typeTransactionSql = ' AND 1=0';
        $typeSavingsSql = " AND se.type = 'withdrawal'";
    } elseif ($type === 'savings_spend') {
        $typeTransactionSql = ' AND 1=0';
        $typeSavingsSql = " AND se.type = 'spend'";
    }

    $transactionSelect = "
        SELECT
            t.transaction_date,
            t.created_at,
            t.id AS row_id,
            2 AS source_priority,
            t.type AS type,
            t.amount,
            t.description,
            t.category_name_snapshot AS category_name,
            NULL AS goal_name,
            NULL AS savings_goal_id,
            'transaction' AS source
        FROM transactions t
        WHERE t.user_id = :user_transaction {$dateSqlTransactions} {$typeTransactionSql}";

    $selects = [$transactionSelect];

    if ($hasSavings) {
        $params[':user_savings'] = $userId;
        $selects[] = "
            SELECT
                se.entry_date AS transaction_date,
                se.created_at,
                se.id AS row_id,
                1 AS source_priority,
                CASE
                    WHEN se.type = 'deposit' THEN 'savings_deposit'
                    WHEN se.type = 'withdrawal' THEN 'savings_withdrawal'
                    ELSE 'savings_spend'
                END AS type,
                se.amount,
                se.note AS description,
                CASE
                    WHEN se.type = 'spend' THEN COALESCE(se.category_name_snapshot, 'Tanpa kategori')
                    ELSE 'Tabungan'
                END AS category_name,
                COALESCE(
                    NULLIF(TRIM(se.goal_name_snapshot), ''),
                    NULLIF(TRIM(sg.name), ''),
                    CONCAT('Tujuan tabungan #', se.savings_goal_id)
                ) AS goal_name,
                se.savings_goal_id,
                'savings' AS source
            FROM savings_entries se
            LEFT JOIN savings_goals sg
                ON sg.id = se.savings_goal_id
               AND sg.user_id = se.user_id
            WHERE se.user_id = :user_savings {$dateSqlSavings} {$typeSavingsSql}";
    }

    $sql = 'SELECT * FROM (' . implode(' UNION ALL ', $selects) . ') entries '
        . 'ORDER BY entries.transaction_date DESC, entries.created_at DESC, entries.source_priority DESC, entries.row_id DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $summary = [
        'income' => 0.0,
        'expense' => 0.0,
        'savings_deposit' => 0.0,
        'savings_withdrawal' => 0.0,
        'savings_spend' => 0.0,
        'cash_net' => 0.0,
        'asset_net' => 0.0,
    ];

    foreach ($rows as $row) {
        $amount = (float)$row['amount'];
        if (array_key_exists($row['type'], $summary)) {
            $summary[$row['type']] += $amount;
        }
        $summary['cash_net'] += transaction_cash_effect($row['type'], $amount);
        $summary['asset_net'] += transaction_asset_effect($row['type'], $amount);
    }

    return [
        'rows' => $rows,
        'income' => $summary['income'],
        'expense' => $summary['expense'],
        'savings_deposit' => $summary['savings_deposit'],
        'savings_withdrawal' => $summary['savings_withdrawal'],
        'savings_spend' => $summary['savings_spend'],
        'total_expense' => $summary['expense'] + $summary['savings_spend'],
        'cash_net' => $summary['cash_net'],
        'asset_net' => $summary['asset_net'],
        'net' => $summary['asset_net'],
    ];
}
