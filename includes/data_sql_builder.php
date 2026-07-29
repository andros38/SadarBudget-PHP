<?php
/**
 * Membuat backup SQL portabel untuk satu akun SadarBudget.
 *
 * Berkas hasil dapat dipulihkan melalui halaman Profil > Data dan privasi atau
 * secara manual melalui phpMyAdmin. Payload JSON terenkode disertakan sebagai
 * komentar agar importer web tidak perlu mengeksekusi SQL mentah.
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
    $goals = $fetchAll('SELECT id, name, target_amount, description, target_date, status, deleted_at, created_at, updated_at FROM savings_goals WHERE user_id=? ORDER BY id');
    $entries = $fetchAll('SELECT id, savings_goal_id, type, amount, category_id, goal_name_snapshot, category_name_snapshot, note, entry_date, created_at FROM savings_entries WHERE user_id=? ORDER BY id');

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
        'goals' => array_map(static fn(array $row): array => [
            'old_id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'target_amount' => (float)$row['target_amount'],
            'description' => $row['description'] !== null ? (string)$row['description'] : null,
            'target_date' => $row['target_date'] !== null ? (string)$row['target_date'] : null,
            'status' => (string)$row['status'],
            'deleted_at' => $row['deleted_at'] !== null ? (string)$row['deleted_at'] : null,
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)$row['updated_at'],
        ], $goals),
        'entries' => array_map(static fn(array $row): array => [
            'goal_old_id' => (int)$row['savings_goal_id'],
            'category_old_id' => $row['category_id'] !== null ? (int)$row['category_id'] : null,
            'type' => (string)$row['type'],
            'amount' => (float)$row['amount'],
            'goal_name_snapshot' => (string)$row['goal_name_snapshot'],
            'category_name_snapshot' => $row['category_name_snapshot'] !== null ? (string)$row['category_name_snapshot'] : null,
            'note' => $row['note'] !== null ? (string)$row['note'] : null,
            'entry_date' => (string)$row['entry_date'],
            'created_at' => (string)$row['created_at'],
        ], $entries),
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
        '-- Login ke SadarBudget, lalu buka Profil > Data dan privasi > Impor backup SQL.',
        '-- Cara manual melalui phpMyAdmin tetap didukung.',
        '-- PERINGATAN: pemulihan mengganti seluruh data keuangan akun tersebut.',
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
    // BINARY comparison menghindari konflik utf8mb4_unicode_ci vs utf8mb4_general_ci.
    $lines[] = 'SET @sb_user_id = (SELECT id FROM users WHERE BINARY email = BINARY @sb_email LIMIT 1);';
    $lines[] = 'CREATE TEMPORARY TABLE sb_restore_guard (user_id INT UNSIGNED NOT NULL);';
    $lines[] = 'INSERT INTO sb_restore_guard (user_id) VALUES (@sb_user_id);';
    $lines[] = 'DROP TEMPORARY TABLE sb_restore_guard;';
    $lines[] = '';
    $lines[] = '-- Bersihkan data keuangan lama milik akun tujuan.';
    $lines[] = 'DELETE FROM savings_entries WHERE user_id=@sb_user_id;';
    $lines[] = 'DELETE FROM savings_goals WHERE user_id=@sb_user_id;';
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
    $lines[] = '-- Tujuan tabungan';
    foreach ($goals as $goal) {
        $variable = '@sb_goal_' . (int)$goal['id'];
        $lines[] = 'INSERT INTO savings_goals (user_id, name, target_amount, description, target_date, status, deleted_at, created_at, updated_at) VALUES ('
            . '@sb_user_id, ' . $quote($goal['name']) . ', ' . $number($goal['target_amount']) . ', ' . $quote($goal['description']) . ', '
            . $quote($goal['target_date']) . ', ' . $quote($goal['status']) . ', ' . $quote($goal['deleted_at']) . ', '
            . $quote($goal['created_at']) . ', ' . $quote($goal['updated_at']) . ');';
        $lines[] = 'SET ' . $variable . ' = LAST_INSERT_ID();';
    }

    $lines[] = '';
    $lines[] = '-- Aktivitas tabungan';
    foreach ($entries as $entry) {
        $goalReference = '@sb_goal_' . (int)$entry['savings_goal_id'];
        $categoryReference = $entry['category_id'] !== null
            ? '@sb_category_' . (int)$entry['category_id']
            : 'NULL';
        $lines[] = 'INSERT INTO savings_entries (user_id, savings_goal_id, type, amount, category_id, goal_name_snapshot, category_name_snapshot, note, entry_date, created_at) VALUES ('
            . '@sb_user_id, ' . $goalReference . ', ' . $quote($entry['type']) . ', ' . $number($entry['amount']) . ', '
            . $categoryReference . ', ' . $quote($entry['goal_name_snapshot']) . ', ' . $quote($entry['category_name_snapshot']) . ', '
            . $quote($entry['note']) . ', ' . $quote($entry['entry_date']) . ', ' . $quote($entry['created_at']) . ');';
    }

    $lines[] = '';
    $lines[] = 'COMMIT;';
    $lines[] = 'SELECT CONCAT("Backup SadarBudget berhasil dipulihkan untuk ", @sb_email) AS status;';
    $lines[] = '';

    return implode("\n", $lines);
}
