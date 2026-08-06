<?php
/**
 * Importer backup SadarBudget.
 *
 * SQL unggahan tidak pernah dieksekusi secara langsung. Importer hanya membaca
 * payload SadarBudget atau perintah INSERT kategori/transaksi yang didukung,
 * lalu menulis data melalui prepared statement.
 *
 * Payload JSON dan perintah INSERT kategori/transaksi dari backup SadarBudget
 * dibaca secara aman tanpa mengeksekusi SQL unggahan secara langsung.
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
    if (preg_match('/^@sb_category_(\d+)$/', $token, $match)) {
        return ['reference' => 'category', 'id' => (int)$match[1]];
    }
    if (preg_match('/^-?\d+(?:\.\d+)?$/', $token)) {
        return str_contains($token, '.') ? (float)$token : (int)$token;
    }
    throw new RuntimeException('Backup berisi nilai SQL yang tidak didukung: ' . substr($token, 0, 80));
}

function sb_parse_insert_statement(string $statement): ?array
{
    if (!preg_match(
        '/^INSERT\s+INTO\s+`?(categories|transactions)`?\s*\((?<columns>[^)]+)\)\s*VALUES\s*\((?<values>.*)\)$/is',
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
    ];
    if (preg_match('/^-- Dibuat:\s*(.+)$/mi', $sql, $match)) {
        $result['generated_at'] = trim($match[1]);
    }

    $pendingCategory = null;
    foreach (sb_sql_split_statements($sql) as $statement) {
        $insert = sb_parse_insert_statement($statement);
        if ($insert !== null) {
            $row = $insert['row'];
            unset($row['user_id']);

            if ($insert['table'] === 'categories') {
                sb_backup_assert($pendingCategory === null, 'Backup kategori tidak memiliki pemetaan ID yang lengkap.');
                $pendingCategory = $row;
                continue;
            }

            $category = $row['category_id'] ?? null;
            $row['category_old_id'] = is_array($category) && ($category['reference'] ?? '') === 'category'
                ? (int)$category['id']
                : null;
            unset($row['category_id']);
            $result['transactions'][] = $row;
            continue;
        }

        if (preg_match('/^SET\s+@sb_category_(\d+)\s*=\s*LAST_INSERT_ID\(\)$/i', trim($statement), $match)) {
            sb_backup_assert($pendingCategory !== null, 'Pemetaan kategori pada backup tidak valid.');
            $pendingCategory['old_id'] = (int)$match[1];
            $result['categories'][] = $pendingCategory;
            $pendingCategory = null;
        }
    }

    sb_backup_assert($pendingCategory === null, 'Backup berakhir sebelum pemetaan ID kategori selesai.');
    return $result;
}

function sb_validate_datetime_value(mixed $value): string
{
    $value = trim((string)$value);
    sb_backup_assert((bool)preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value), 'Nilai waktu pada backup tidak valid.');
    return $value;
}

function sb_validate_date_value(mixed $value): string
{
    $value = trim((string)$value);
    sb_backup_assert(valid_date($value), 'Nilai tanggal pada backup tidak valid.');
    return $value;
}

function sb_validate_backup_data(array $data): array
{
    $email = strtolower(trim((string)($data['email'] ?? '')));
    sb_backup_assert((bool)filter_var($email, FILTER_VALIDATE_EMAIL), 'Email pada backup tidak valid.');

    foreach (['categories', 'transactions'] as $key) {
        sb_backup_assert(isset($data[$key]) && is_array($data[$key]), 'Bagian ' . $key . ' tidak ditemukan pada backup.');
    }
    sb_backup_assert(count($data['categories']) <= 500, 'Jumlah kategori pada backup terlalu banyak.');
    sb_backup_assert(count($data['transactions']) <= 100000, 'Jumlah transaksi pada backup melebihi batas impor.');

    $categoryIds = [];
    foreach ($data['categories'] as &$category) {
        sb_backup_assert(is_array($category), 'Data kategori tidak valid.');
        $category['old_id'] = (int)($category['old_id'] ?? $category['id'] ?? 0);
        $category['name'] = trim((string)($category['name'] ?? ''));
        $category['type'] = (string)($category['type'] ?? '');
        $category['is_active'] = (int)($category['is_active'] ?? 1) === 1 ? 1 : 0;
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
        $transaction['description'] = ($transaction['description'] ?? null) === null ? null : trim((string)$transaction['description']);
        sb_backup_assert($transaction['category_name_snapshot'] !== '' && sb_backup_text_length($transaction['category_name_snapshot']) <= 100, 'Snapshot kategori transaksi tidak valid.');
        sb_backup_assert(in_array($transaction['type'], ['income', 'expense'], true), 'Jenis transaksi pada backup tidak valid.');
        sb_backup_assert($transaction['amount'] > 0 && $transaction['amount'] <= 9999999999999.99, 'Nominal transaksi pada backup tidak valid.');
        sb_backup_assert($transaction['description'] === null || sb_backup_text_length($transaction['description']) <= 255, 'Keterangan transaksi terlalu panjang.');
        $transaction['transaction_date'] = sb_validate_date_value($transaction['transaction_date'] ?? null);
        $transaction['created_at'] = sb_validate_datetime_value($transaction['created_at'] ?? null);
    }
    unset($transaction);

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

function restore_sadarbudget_backup(PDO $pdo, int $userId, string $currentEmail, array $backup, bool $allowEmailMismatch = false): array
{
    $backup = sb_validate_backup_data($backup);
    if (!$allowEmailMismatch) {
        sb_backup_assert(strcasecmp($backup['email'], trim($currentEmail)) === 0, 'Email pada backup berbeda dari email akun yang sedang login.');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE user_id=?');
        $stmt->execute([$userId]);
        $stmt = $pdo->prepare('DELETE FROM categories WHERE user_id=?');
        $stmt->execute([$userId]);

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
    ];
}
