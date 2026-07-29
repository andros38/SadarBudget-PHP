<?php
/**
 * Importer backup SadarBudget.
 *
 * Importer ini tidak pernah mengeksekusi SQL unggahan secara langsung. Berkas
 * hanya diparsing sebagai format backup SadarBudget, lalu datanya dimasukkan
 * melalui PDO prepared statements. Dengan demikian, backup dapat dipulihkan
 * dari browser tanpa membuka phpMyAdmin dan tanpa memberi akses arbitrary SQL.
 */

function sb_backup_text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function sb_backup_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function sb_backup_decode_payload(string $sql): ?array
{
    if (!preg_match(
        '/^-- SADARBUDGET_PAYLOAD_BEGIN\R(?<payload>(?:-- [A-Za-z0-9+\/=]*\R)+)-- SADARBUDGET_PAYLOAD_END$/m',
        $sql,
        $match
    )) {
        return null;
    }

    $encoded = preg_replace('/^-- ?/m', '', trim((string)$match['payload']));
    $encoded = preg_replace('/\s+/', '', (string)$encoded);
    $json = base64_decode((string)$encoded, true);
    sb_backup_assert($json !== false, 'Payload backup tidak dapat dibaca.');

    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Payload backup tidak memiliki format JSON yang valid.');
    }

    sb_backup_assert(is_array($data), 'Payload backup tidak valid.');
    return $data;
}

function sb_sql_split_statements(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $inQuote = false;
    $escaped = false;
    $lineComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($lineComment) {
            if ($char === "\n") {
                $lineComment = false;
                $buffer .= $char;
            }
            continue;
        }

        if (!$inQuote && $char === '-' && $next === '-') {
            $lineComment = true;
            $i++;
            continue;
        }

        $buffer .= $char;

        if ($inQuote) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === "'") {
                if ($next === "'") {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $inQuote = false;
            }
            continue;
        }

        if ($char === "'") {
            $inQuote = true;
            continue;
        }

        if ($char === ';') {
            $statement = trim(substr($buffer, 0, -1));
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
        }
    }

    $remaining = trim($buffer);
    if ($remaining !== '') {
        $statements[] = $remaining;
    }

    sb_backup_assert(!$inQuote, 'Backup SQL terpotong di dalam nilai teks.');
    return $statements;
}

function sb_sql_split_values(string $values): array
{
    $parts = [];
    $buffer = '';
    $length = strlen($values);
    $inQuote = false;
    $escaped = false;
    $depth = 0;

    for ($i = 0; $i < $length; $i++) {
        $char = $values[$i];
        $next = $i + 1 < $length ? $values[$i + 1] : '';

        if ($inQuote) {
            $buffer .= $char;
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\') {
                $escaped = true;
                continue;
            }
            if ($char === "'") {
                if ($next === "'") {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
                $inQuote = false;
            }
            continue;
        }

        if ($char === "'") {
            $inQuote = true;
            $buffer .= $char;
            continue;
        }
        if ($char === '(') {
            $depth++;
            $buffer .= $char;
            continue;
        }
        if ($char === ')') {
            $depth--;
            $buffer .= $char;
            continue;
        }
        if ($char === ',' && $depth === 0) {
            $parts[] = trim($buffer);
            $buffer = '';
            continue;
        }
        $buffer .= $char;
    }

    if (trim($buffer) !== '' || $values === '') {
        $parts[] = trim($buffer);
    }

    sb_backup_assert(!$inQuote && $depth === 0, 'Daftar nilai backup SQL tidak valid.');
    return $parts;
}

function sb_sql_decode_string(string $token): string
{
    sb_backup_assert(strlen($token) >= 2 && $token[0] === "'" && substr($token, -1) === "'", 'Nilai teks SQL tidak valid.');
    $value = substr($token, 1, -1);
    $value = str_replace("''", "'", $value);

    return preg_replace_callback('/\\\\([0bnrtZ\\\\\'\"])/', static function (array $match): string {
        return match ($match[1]) {
            '0' => "\0",
            'b' => "\x08",
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            'Z' => "\x1a",
            '\\' => '\\',
            "'" => "'",
            '"' => '"',
            default => $match[0],
        };
    }, $value) ?? $value;
}

function sb_sql_decode_value(string $token): mixed
{
    $token = trim($token);
    if (strcasecmp($token, 'NULL') === 0) {
        return null;
    }
    if ($token !== '' && $token[0] === "'") {
        return sb_sql_decode_string($token);
    }
    if (strcasecmp($token, '@sb_user_id') === 0) {
        return ['reference' => 'user'];
    }
    if (preg_match('/^@sb_(category|goal)_(\d+)$/', $token, $match)) {
        return ['reference' => $match[1], 'id' => (int)$match[2]];
    }
    if (preg_match('/^-?\d+(?:\.\d+)?$/', $token)) {
        return str_contains($token, '.') ? (float)$token : (int)$token;
    }

    throw new RuntimeException('Backup berisi nilai SQL yang tidak didukung: ' . substr($token, 0, 80));
}

function sb_parse_insert_statement(string $statement): ?array
{
    if (!preg_match(
        '/^INSERT\s+INTO\s+`?(categories|transactions|savings_goals|savings_entries)`?\s*\((?<columns>[^)]+)\)\s*VALUES\s*\((?<values>.*)\)$/is',
        trim($statement),
        $match
    )) {
        return null;
    }

    $columns = array_map(
        static fn(string $column): string => trim($column, " \t\n\r\0\x0B`"),
        explode(',', (string)$match['columns'])
    );
    $tokens = sb_sql_split_values((string)$match['values']);
    sb_backup_assert(count($columns) === count($tokens), 'Jumlah kolom dan nilai pada backup tidak sama.');

    $row = [];
    foreach ($columns as $index => $column) {
        $row[$column] = sb_sql_decode_value($tokens[$index]);
    }

    return ['table' => $match[1], 'row' => $row];
}

function sb_parse_legacy_backup(string $sql): array
{
    sb_backup_assert(str_contains($sql, 'SadarBudget'), 'Berkas bukan backup SadarBudget.');

    $email = null;
    if (preg_match('/SET\s+@sb_email\s*=\s*(\'(?:\\\\.|\'\'|[^\'])*\')\s*;/i', $sql, $match)) {
        $email = sb_sql_decode_string($match[1]);
    } elseif (preg_match('/^-- Akun:\s*(.+)$/mi', $sql, $match)) {
        $email = trim($match[1]);
    }
    sb_backup_assert(is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL), 'Email akun pada backup tidak ditemukan atau tidak valid.');

    $result = [
        'format' => 1,
        'email' => strtolower($email),
        'generated_at' => null,
        'categories' => [],
        'transactions' => [],
        'goals' => [],
        'entries' => [],
    ];

    if (preg_match('/^-- Dibuat:\s*(.+)$/mi', $sql, $match)) {
        $result['generated_at'] = trim($match[1]);
    }

    $pendingCategory = null;
    $pendingGoal = null;
    foreach (sb_sql_split_statements($sql) as $statement) {
        $insert = sb_parse_insert_statement($statement);
        if ($insert !== null) {
            $row = $insert['row'];
            unset($row['user_id']);

            if ($insert['table'] === 'categories') {
                if ($pendingCategory !== null) {
                    throw new RuntimeException('Backup kategori tidak memiliki pemetaan ID yang lengkap.');
                }
                $pendingCategory = $row;
                continue;
            }
            if ($insert['table'] === 'savings_goals') {
                if ($pendingGoal !== null) {
                    throw new RuntimeException('Backup tujuan tabungan tidak memiliki pemetaan ID yang lengkap.');
                }
                $pendingGoal = $row;
                continue;
            }
            if ($insert['table'] === 'transactions') {
                $category = $row['category_id'] ?? null;
                $row['category_old_id'] = is_array($category) && ($category['reference'] ?? '') === 'category'
                    ? (int)$category['id']
                    : null;
                unset($row['category_id']);
                $result['transactions'][] = $row;
                continue;
            }
            if ($insert['table'] === 'savings_entries') {
                $goal = $row['savings_goal_id'] ?? null;
                $category = $row['category_id'] ?? null;
                sb_backup_assert(is_array($goal) && ($goal['reference'] ?? '') === 'goal', 'Aktivitas tabungan tidak memiliki referensi tujuan yang valid.');
                $row['goal_old_id'] = (int)$goal['id'];
                $row['category_old_id'] = is_array($category) && ($category['reference'] ?? '') === 'category'
                    ? (int)$category['id']
                    : null;
                unset($row['savings_goal_id'], $row['category_id']);
                $result['entries'][] = $row;
            }
            continue;
        }

        if (preg_match('/^SET\s+@sb_category_(\d+)\s*=\s*LAST_INSERT_ID\(\)$/i', trim($statement), $match)) {
            sb_backup_assert($pendingCategory !== null, 'Pemetaan kategori pada backup tidak valid.');
            $pendingCategory['old_id'] = (int)$match[1];
            $result['categories'][] = $pendingCategory;
            $pendingCategory = null;
            continue;
        }
        if (preg_match('/^SET\s+@sb_goal_(\d+)\s*=\s*LAST_INSERT_ID\(\)$/i', trim($statement), $match)) {
            sb_backup_assert($pendingGoal !== null, 'Pemetaan tujuan tabungan pada backup tidak valid.');
            $pendingGoal['old_id'] = (int)$match[1];
            $result['goals'][] = $pendingGoal;
            $pendingGoal = null;
        }
    }

    sb_backup_assert($pendingCategory === null && $pendingGoal === null, 'Backup berakhir sebelum pemetaan ID selesai.');
    return $result;
}

function sb_validate_datetime_value(mixed $value, bool $nullable = false): ?string
{
    if ($value === null && $nullable) {
        return null;
    }
    $value = trim((string)$value);
    sb_backup_assert((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value), 'Nilai waktu pada backup tidak valid.');
    return $value;
}

function sb_validate_date_value(mixed $value, bool $nullable = false): ?string
{
    if ($value === null && $nullable) {
        return null;
    }
    $value = trim((string)$value);
    sb_backup_assert(valid_date($value), 'Nilai tanggal pada backup tidak valid.');
    return $value;
}

function sb_validate_backup_data(array $data): array
{
    $email = strtolower(trim((string)($data['email'] ?? '')));
    sb_backup_assert((bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Email pada backup tidak valid.');

    foreach (['categories', 'transactions', 'goals', 'entries'] as $key) {
        sb_backup_assert(isset($data[$key]) && is_array($data[$key]), 'Bagian ' . $key . ' tidak ditemukan pada backup.');
    }

    sb_backup_assert(count($data['categories']) <= 500, 'Jumlah kategori pada backup terlalu banyak.');
    sb_backup_assert(count($data['transactions']) <= 100000, 'Jumlah transaksi pada backup melebihi batas impor.');
    sb_backup_assert(count($data['goals']) <= 10000, 'Jumlah tujuan tabungan pada backup melebihi batas impor.');
    sb_backup_assert(count($data['entries']) <= 100000, 'Jumlah aktivitas tabungan pada backup melebihi batas impor.');

    $categoryIds = [];
    foreach ($data['categories'] as &$category) {
        sb_backup_assert(is_array($category), 'Data kategori tidak valid.');
        $category['old_id'] = (int)($category['old_id'] ?? $category['id'] ?? 0);
        $category['name'] = trim((string)($category['name'] ?? ''));
        $category['type'] = (string)($category['type'] ?? '');
        $category['is_active'] = (int)!empty($category['is_active']);
        sb_backup_assert($category['old_id'] > 0 && !isset($categoryIds[$category['old_id']]), 'ID kategori pada backup tidak valid atau duplikat.');
        sb_backup_assert($category['name'] !== '' && sb_backup_text_length($category['name']) <= 100, 'Nama kategori pada backup tidak valid.');
        sb_backup_assert(in_array($category['type'], ['income', 'expense'], true), 'Jenis kategori pada backup tidak valid.');
        $category['created_at'] = sb_validate_datetime_value($category['created_at'] ?? null);
        $category['updated_at'] = sb_validate_datetime_value($category['updated_at'] ?? $category['created_at']);
        $categoryIds[$category['old_id']] = true;
    }
    unset($category);

    foreach ($data['transactions'] as &$transaction) {
        sb_backup_assert(is_array($transaction), 'Data transaksi tidak valid.');
        $transaction['category_old_id'] = isset($transaction['category_old_id']) ? (int)$transaction['category_old_id'] : null;
        if ($transaction['category_old_id'] !== null) {
            sb_backup_assert(isset($categoryIds[$transaction['category_old_id']]), 'Transaksi merujuk kategori yang tidak tersedia dalam backup.');
        }
        $transaction['category_name_snapshot'] = trim((string)($transaction['category_name_snapshot'] ?? 'Tanpa kategori'));
        $transaction['type'] = (string)($transaction['type'] ?? '');
        $transaction['amount'] = (float)($transaction['amount'] ?? 0);
        $transaction['description'] = $transaction['description'] === null ? null : trim((string)$transaction['description']);
        sb_backup_assert($transaction['category_name_snapshot'] !== '' && sb_backup_text_length($transaction['category_name_snapshot']) <= 100, 'Snapshot kategori transaksi tidak valid.');
        sb_backup_assert(in_array($transaction['type'], ['income', 'expense'], true), 'Jenis transaksi pada backup tidak valid.');
        sb_backup_assert($transaction['amount'] > 0 && $transaction['amount'] <= 9999999999999.99, 'Nominal transaksi pada backup tidak valid.');
        sb_backup_assert($transaction['description'] === null || sb_backup_text_length($transaction['description']) <= 255, 'Keterangan transaksi terlalu panjang.');
        $transaction['transaction_date'] = sb_validate_date_value($transaction['transaction_date'] ?? null);
        $transaction['created_at'] = sb_validate_datetime_value($transaction['created_at'] ?? null);
    }
    unset($transaction);

    $goalIds = [];
    foreach ($data['goals'] as &$goal) {
        sb_backup_assert(is_array($goal), 'Data tujuan tabungan tidak valid.');
        $goal['old_id'] = (int)($goal['old_id'] ?? $goal['id'] ?? 0);
        $goal['name'] = trim((string)($goal['name'] ?? ''));
        $goal['target_amount'] = (float)($goal['target_amount'] ?? 0);
        $goal['description'] = $goal['description'] === null ? null : trim((string)$goal['description']);
        $goal['status'] = (string)($goal['status'] ?? 'active');
        sb_backup_assert($goal['old_id'] > 0 && !isset($goalIds[$goal['old_id']]), 'ID tujuan tabungan pada backup tidak valid atau duplikat.');
        sb_backup_assert($goal['name'] !== '' && sb_backup_text_length($goal['name']) <= 100, 'Nama tujuan tabungan pada backup tidak valid.');
        sb_backup_assert($goal['target_amount'] > 0 && $goal['target_amount'] <= 9999999999999.99, 'Target tabungan pada backup tidak valid.');
        sb_backup_assert($goal['description'] === null || sb_backup_text_length($goal['description']) <= 255, 'Catatan tujuan tabungan terlalu panjang.');
        sb_backup_assert(in_array($goal['status'], ['active', 'archived', 'deleted'], true), 'Status tujuan tabungan pada backup tidak valid.');
        $goal['target_date'] = sb_validate_date_value($goal['target_date'] ?? null, true);
        $goal['deleted_at'] = sb_validate_datetime_value($goal['deleted_at'] ?? null, true);
        $goal['created_at'] = sb_validate_datetime_value($goal['created_at'] ?? null);
        $goal['updated_at'] = sb_validate_datetime_value($goal['updated_at'] ?? $goal['created_at']);
        $goalIds[$goal['old_id']] = true;
    }
    unset($goal);

    foreach ($data['entries'] as &$entry) {
        sb_backup_assert(is_array($entry), 'Data aktivitas tabungan tidak valid.');
        $entry['goal_old_id'] = (int)($entry['goal_old_id'] ?? $entry['savings_goal_id'] ?? 0);
        $entry['category_old_id'] = isset($entry['category_old_id']) ? (int)$entry['category_old_id'] : null;
        sb_backup_assert(isset($goalIds[$entry['goal_old_id']]), 'Aktivitas tabungan merujuk tujuan yang tidak tersedia dalam backup.');
        if ($entry['category_old_id'] !== null) {
            sb_backup_assert(isset($categoryIds[$entry['category_old_id']]), 'Aktivitas tabungan merujuk kategori yang tidak tersedia dalam backup.');
        }
        $entry['type'] = (string)($entry['type'] ?? '');
        $entry['amount'] = (float)($entry['amount'] ?? 0);
        $entry['goal_name_snapshot'] = trim((string)($entry['goal_name_snapshot'] ?? 'Tujuan tabungan'));
        $entry['category_name_snapshot'] = $entry['category_name_snapshot'] === null ? null : trim((string)$entry['category_name_snapshot']);
        $entry['note'] = $entry['note'] === null ? null : trim((string)$entry['note']);
        sb_backup_assert(in_array($entry['type'], ['deposit', 'withdrawal', 'spend'], true), 'Jenis aktivitas tabungan pada backup tidak valid.');
        sb_backup_assert($entry['amount'] > 0 && $entry['amount'] <= 9999999999999.99, 'Nominal aktivitas tabungan pada backup tidak valid.');
        sb_backup_assert($entry['goal_name_snapshot'] !== '' && sb_backup_text_length($entry['goal_name_snapshot']) <= 100, 'Snapshot tujuan tabungan tidak valid.');
        sb_backup_assert($entry['category_name_snapshot'] === null || sb_backup_text_length($entry['category_name_snapshot']) <= 100, 'Snapshot kategori aktivitas tabungan terlalu panjang.');
        sb_backup_assert($entry['note'] === null || sb_backup_text_length($entry['note']) <= 255, 'Catatan aktivitas tabungan terlalu panjang.');
        $entry['entry_date'] = sb_validate_date_value($entry['entry_date'] ?? null);
        $entry['created_at'] = sb_validate_datetime_value($entry['created_at'] ?? null);
    }
    unset($entry);

    $data['email'] = $email;
    return $data;
}

function parse_sadarbudget_backup(string $sql): array
{
    sb_backup_assert(strlen($sql) > 0, 'Berkas backup kosong.');
    sb_backup_assert(strlen($sql) <= 10 * 1024 * 1024, 'Ukuran backup melebihi batas 10 MB.');
    if (str_starts_with($sql, "\xEF\xBB\xBF")) {
        $sql = substr($sql, 3);
    }

    $payload = sb_backup_decode_payload($sql);
    return sb_validate_backup_data($payload ?? sb_parse_legacy_backup($sql));
}

function restore_sadarbudget_backup(PDO $pdo, int $userId, string $currentEmail, array $backup): array
{
    $backup = sb_validate_backup_data($backup);
    sb_backup_assert(strcasecmp($backup['email'], trim($currentEmail)) === 0, 'Email pada backup berbeda dari email akun yang sedang login.');

    $pdo->beginTransaction();
    try {
        foreach (['savings_entries', 'savings_goals', 'transactions', 'categories'] as $table) {
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE user_id=?');
            $stmt->execute([$userId]);
        }

        $categoryMap = [];
        $categoryStmt = $pdo->prepare('INSERT INTO categories (user_id, name, type, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($backup['categories'] as $category) {
            $categoryStmt->execute([
                $userId,
                $category['name'],
                $category['type'],
                $category['is_active'],
                $category['created_at'],
                $category['updated_at'],
            ]);
            $categoryMap[$category['old_id']] = (int)$pdo->lastInsertId();
        }

        $transactionStmt = $pdo->prepare('INSERT INTO transactions (user_id, category_id, category_name_snapshot, type, amount, description, transaction_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($backup['transactions'] as $transaction) {
            $transactionStmt->execute([
                $userId,
                $transaction['category_old_id'] !== null ? ($categoryMap[$transaction['category_old_id']] ?? null) : null,
                $transaction['category_name_snapshot'],
                $transaction['type'],
                $transaction['amount'],
                $transaction['description'],
                $transaction['transaction_date'],
                $transaction['created_at'],
            ]);
        }

        $goalMap = [];
        $goalStmt = $pdo->prepare('INSERT INTO savings_goals (user_id, name, target_amount, description, target_date, status, deleted_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($backup['goals'] as $goal) {
            $goalStmt->execute([
                $userId,
                $goal['name'],
                $goal['target_amount'],
                $goal['description'],
                $goal['target_date'],
                $goal['status'],
                $goal['deleted_at'],
                $goal['created_at'],
                $goal['updated_at'],
            ]);
            $goalMap[$goal['old_id']] = (int)$pdo->lastInsertId();
        }

        $entryStmt = $pdo->prepare('INSERT INTO savings_entries (user_id, savings_goal_id, type, amount, category_id, goal_name_snapshot, category_name_snapshot, note, entry_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach ($backup['entries'] as $entry) {
            $entryStmt->execute([
                $userId,
                $goalMap[$entry['goal_old_id']],
                $entry['type'],
                $entry['amount'],
                $entry['category_old_id'] !== null ? ($categoryMap[$entry['category_old_id']] ?? null) : null,
                $entry['goal_name_snapshot'],
                $entry['category_name_snapshot'],
                $entry['note'],
                $entry['entry_date'],
                $entry['created_at'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    return [
        'categories' => count($backup['categories']),
        'transactions' => count($backup['transactions']),
        'goals' => count($backup['goals']),
        'entries' => count($backup['entries']),
    ];
}
