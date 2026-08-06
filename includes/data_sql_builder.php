<?php
/**
 * Membuat backup SQL portabel untuk satu akun SadarBudget.
 *
 * Backup SadarBudget 2.0 memuat kategori serta transaksi pemasukan/pengeluaran.
 * Payload JSON terenkode disertakan agar importer web tidak mengeksekusi SQL
 * unggahan secara langsung.
 */
function build_user_data_sql(PDO $pdo, int $userId): string
{
    $userStmt = $pdo->prepare('SELECT name, email, created_at FROM users WHERE id=? LIMIT 1');
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        throw new RuntimeException('Akun pengguna tidak ditemukan.');
    }

    $fetchAll = static function (string $sql) use ($pdo, $userId): array {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetchAll();
    };

    $categories = $fetchAll('SELECT id, name, type, is_active, created_at, updated_at FROM categories WHERE user_id=? ORDER BY id');
    $transactions = $fetchAll('SELECT id, category_id, category_name_snapshot, type, amount, description, transaction_date, created_at FROM transactions WHERE user_id=? ORDER BY id');

    $quote = static fn(mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string)$value);
    $number = static fn(mixed $value): string => number_format((float)$value, 2, '.', '');
    $bool = static fn(mixed $value): string => (int)((bool)$value) === 1 ? '1' : '0';

    $generatedAt = date('Y-m-d H:i:s');
    $payload = [
        'format' => 2,
        'application' => 'SadarBudget',
        'generated_at' => $generatedAt,
        'email' => strtolower((string)$user['email']),
        'categories' => array_map(static fn(array $row): array => [
            'old_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'type' => (string)$row['type'],
            'is_active' => (int)$row['is_active'],
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ], $categories),
        'transactions' => array_map(static fn(array $row): array => [
            'category_old_id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'category_name_snapshot' => (string)$row['category_name_snapshot'],
            'type' => (string)$row['type'],
            'amount' => (float)$row['amount'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'transaction_date' => (string)$row['transaction_date'],
            'created_at' => (string)$row['created_at'],
        ], $transactions),
    ];

    try {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Data backup gagal dikodekan.');
    }
    $payloadLines = str_split(base64_encode($payloadJson), 100);

    $emailSql = $quote($user['email']);
    $lines = [
        '-- SadarBudget — backup data pengguna',
        '-- Format: 2',
        '-- Dibuat: ' . $generatedAt,
        '-- Akun: ' . str_replace(["\r", "\n"], ' ', (string)$user['email']),
        '--',
        '-- PEMULIHAN YANG DISARANKAN:',
        '-- Login ke SadarBudget, lalu buka Data & Backup > Impor backup SQL.',
        '-- Cara manual melalui phpMyAdmin tetap didukung.',
        '-- PERINGATAN: pemulihan mengganti seluruh kategori dan transaksi akun tersebut.',
        '-- SADARBUDGET_PAYLOAD_BEGIN',
    ];
    foreach ($payloadLines as $payloadLine) {
        $lines[] = '-- ' . $payloadLine;
    }
    $lines[] = '-- SADARBUDGET_PAYLOAD_END';
    $lines[] = '';
    $lines[] = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;';
    $lines[] = 'START TRANSACTION;';
    $lines[] = '';
    $lines[] = 'SET @sb_email = ' . $emailSql . ';';
    $lines[] = 'SET @sb_user_id = (SELECT id FROM users WHERE BINARY email = BINARY @sb_email LIMIT 1);';
    $lines[] = 'CREATE TEMPORARY TABLE sb_restore_guard (user_id INT UNSIGNED NOT NULL);';
    $lines[] = 'INSERT INTO sb_restore_guard (user_id) VALUES (@sb_user_id);';
    $lines[] = 'DROP TEMPORARY TABLE sb_restore_guard;';
    $lines[] = '';
    $lines[] = '-- Bersihkan data keuangan lama milik akun tujuan.';
    $lines[] = 'DELETE FROM transactions WHERE user_id=@sb_user_id;';
    $lines[] = 'DELETE FROM categories WHERE user_id=@sb_user_id;';
    $lines[] = '';
    $lines[] = '-- Kategori';

    foreach ($categories as $category) {
        $variable = '@sb_category_' . (int)$category['id'];
        $lines[] = 'INSERT INTO categories (user_id, name, type, is_active, created_at, updated_at) VALUES ('
            . '@sb_user_id, ' . $quote($category['name']) . ', ' . $quote($category['type']) . ', '
            . $bool($category['is_active']) . ', ' . $quote($category['created_at']) . ', ' . $quote($category['updated_at']) . ');';
        $lines[] = 'SET ' . $variable . ' = LAST_INSERT_ID();';
    }

    $lines[] = '';
    $lines[] = '-- Transaksi pemasukan dan pengeluaran';
    foreach ($transactions as $transaction) {
        $categoryReference = $transaction['category_id'] !== null
            ? '@sb_category_' . (int)$transaction['category_id']
            : 'NULL';
        $lines[] = 'INSERT INTO transactions (user_id, category_id, category_name_snapshot, type, amount, description, transaction_date, created_at) VALUES ('
            . '@sb_user_id, ' . $categoryReference . ', ' . $quote($transaction['category_name_snapshot']) . ', '
            . $quote($transaction['type']) . ', ' . $number($transaction['amount']) . ', ' . $quote($transaction['description']) . ', '
            . $quote($transaction['transaction_date']) . ', ' . $quote($transaction['created_at']) . ');';
    }

    $lines[] = '';
    $lines[] = 'COMMIT;';
    $lines[] = 'SELECT CONCAT("Backup SadarBudget berhasil dipulihkan untuk ", @sb_email) AS status;';
    $lines[] = '';

    return implode("\n", $lines);
}
