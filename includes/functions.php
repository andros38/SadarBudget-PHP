<?php
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $version = is_file($absolutePath) ? (string)filemtime($absolutePath) : '1';

    return $path . '?v=' . rawurlencode($version);
}

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']);
}

function require_login(): void
{
    if (!is_logged_in()) {
        flash('error', 'Silakan login terlebih dahulu.');
        redirect('login.php');
    }
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}


function current_user_record(PDO $pdo): array
{
    static $cache = [];
    $userId = current_user_id();
    if ($userId <= 0) {
        return [];
    }
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    $profilePhotoSelect = schema_column_exists($pdo, 'users', 'profile_photo') ? 'profile_photo' : 'NULL AS profile_photo';
    $passwordChangedSelect = schema_column_exists($pdo, 'users', 'password_changed_at') ? 'password_changed_at' : 'NULL AS password_changed_at';
    $updatedAtSelect = schema_column_exists($pdo, 'users', 'updated_at') ? 'updated_at' : 'created_at AS updated_at';
    $sql = 'SELECT id, name, email, password, ' . $profilePhotoSelect . ', ' . $passwordChangedSelect
        . ', created_at, ' . $updatedAtSelect . ' FROM users WHERE id=? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $cache[$userId] = $stmt->fetch() ?: [];
    return $cache[$userId];
}

function profile_photo_url(?string $path): ?string
{
    $path = trim((string)$path);
    if ($path === '' || !preg_match('#^uploads/profiles/[A-Za-z0-9._-]+$#', $path)) {
        return null;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!is_file($absolutePath)) {
        return null;
    }

    return asset_url($path);
}

function user_initial(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return 'U';
    }

    return function_exists('mb_substr')
        ? mb_strtoupper(mb_substr($name, 0, 1))
        : strtoupper(substr($name, 0, 1));
}

function render_user_avatar(array $user, string $className = 'user-avatar', string $size = '40'): string
{
    $name = trim((string)($user['name'] ?? 'Pengguna'));
    $photo = profile_photo_url($user['profile_photo'] ?? null);
    $sizeValue = max(24, min(256, (int)$size));

    if ($photo !== null) {
        return '<span class="' . e($className) . ' has-photo"><img src="' . e($photo) . '" alt="Foto profil ' . e($name) . '" width="' . $sizeValue . '" height="' . $sizeValue . '"></span>';
    }

    return '<span class="' . e($className) . '" aria-hidden="true">' . e(user_initial($name)) . '</span>';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Permintaan tidak valid (CSRF token tidak cocok). Silakan muat ulang halaman.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    $value = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $value;
}

function format_rupiah(float|int|string $amount): string
{
    return 'Rp ' . number_format((float)$amount, 0, ',', '.');
}

function parse_rupiah_input(mixed $value): float
{
    if ($value === null) {
        return 0.0;
    }

    $digits = preg_replace('/[^0-9]/', '', trim((string)$value));
    if ($digits === null || $digits === '') {
        return 0.0;
    }

    return (float)$digits;
}

function format_rupiah_input(mixed $value): string
{
    $digits = preg_replace('/[^0-9]/', '', (string)$value);
    if ($digits === null || $digits === '') {
        return '';
    }

    $digits = ltrim($digits, '0');
    if ($digits === '') {
        $digits = '0';
    }

    return number_format((float)$digits, 0, ',', '.');
}

function limit_text(string $value, int $maxLength = 255): string
{
    if ($maxLength <= 0) {
        return '';
    }

    return function_exists('mb_substr')
        ? mb_substr($value, 0, $maxLength)
        : substr($value, 0, $maxLength);
}

function format_date_id(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    $months = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    return date('d', $timestamp) . ' ' . $months[(int)date('n', $timestamp)] . ' ' . date('Y', $timestamp);
}

function format_month_id(DateTimeInterface|string|null $value = null, bool $short = false): string
{
    if ($value instanceof DateTimeInterface) {
        $month = (int)$value->format('n');
        $year = $value->format('Y');
    } else {
        $timestamp = $value ? strtotime($value) : time();
        if ($timestamp === false) {
            return (string)$value;
        }
        $month = (int)date('n', $timestamp);
        $year = date('Y', $timestamp);
    }

    $full = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];
    $abbr = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des',
    ];

    return ($short ? $abbr[$month] : $full[$month]) . ' ' . $year;
}

function valid_date(?string $date): bool
{
    if (!$date || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
        return false;
    }
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

function valid_month(?string $month): bool
{
    if (!$month || !preg_match('/^\d{4}-\d{2}$/', $month)) {
        return false;
    }
    [$year, $m] = array_map('intval', explode('-', $month));
    return $year >= 2000 && $year <= 2100 && $m >= 1 && $m <= 12;
}

function valid_year(mixed $year): bool
{
    $value = filter_var($year, FILTER_VALIDATE_INT);
    return $value !== false && $value >= 2000 && $value <= 2100;
}

function month_range(string $month): array
{
    $start = $month . '-01';
    $date = new DateTimeImmutable($start);
    $end = $date->modify('first day of next month')->format('Y-m-d');
    return [$start, $end];
}

function allowed_transaction_types(): array
{
    return ['income', 'expense', 'savings_deposit', 'savings_withdrawal', 'savings_spend'];
}

function transaction_type_label(string $type): string
{
    return match ($type) {
        'income' => 'Pemasukan',
        'expense' => 'Pengeluaran',
        'savings_deposit' => 'Transfer ke tabungan',
        'savings_withdrawal' => 'Pencairan ke uang tersedia',
        'savings_spend' => 'Pengeluaran dari tabungan',
        default => ucfirst(str_replace('_', ' ', $type)),
    };
}

function transaction_badge_class(string $type): string
{
    return str_replace('_', '-', $type);
}

function transaction_amount_class(string $type): string
{
    return transaction_badge_class($type);
}

/**
 * Menghasilkan nama tujuan yang selalu layak tampil pada histori.
 * Operator ?? tidak menangani string kosong, sehingga snapshot lama perlu dinormalisasi.
 */
function savings_goal_history_name(mixed $snapshot, mixed $fallback = null, int $goalId = 0): string
{
    foreach ([$snapshot, $fallback] as $candidate) {
        $name = trim((string)$candidate);
        if ($name !== '') {
            return $name;
        }
    }

    return $goalId > 0 ? 'Tujuan tabungan #' . $goalId : 'Tujuan tabungan';
}

function transaction_category_history_name(mixed $snapshot): string
{
    $name = trim((string)$snapshot);
    return $name !== '' ? $name : 'Tanpa kategori';
}

/**
 * Menormalkan istilah lama "kas" pada teks historis agar konsisten
 * dengan istilah antarmuka "uang tersedia". Huruf kapital dipertahankan.
 */
function normalize_user_facing_money_terms(mixed $value): string
{
    $text = (string)$value;

    return preg_replace_callback(
        '/(?<![A-Za-z0-9_])kas(?![A-Za-z0-9_])/i',
        static function (array $match): string {
            $word = (string)$match[0];
            if ($word === strtoupper($word)) {
                return 'UANG TERSEDIA';
            }
            if ($word !== '' && $word[0] === strtoupper($word[0])) {
                return 'Uang tersedia';
            }
            return 'uang tersedia';
        },
        $text
    ) ?? $text;
}

/**
 * Tanda nilai aktivitas. Transfer internal tetap diberi tanda menurut arah aset.
 */
function transaction_amount_prefix(string $type): string
{
    return in_array($type, ['income', 'savings_withdrawal'], true) ? '+' : '-';
}

/**
 * Dampak pada uang yang tersedia.
 */
function transaction_cash_effect(string $type, float $amount): float
{
    return match ($type) {
        'income', 'savings_withdrawal' => $amount,
        'expense', 'savings_deposit' => -$amount,
        'savings_spend' => 0.0,
        default => 0.0,
    };
}

/**
 * Menghitung perubahan uang yang benar-benar tersedia selama satu periode.
 * Setoran tabungan mengurangi uang tersedia, sedangkan pencairan menambahnya.
 * Penggunaan langsung dari tabungan tidak masuk ke rumus ini karena tidak
 * melewati saldo uang tersedia.
 */
function calculate_available_money_change(
    float $income,
    float $expenseFromAvailableMoney,
    float $savingsDeposits,
    float $savingsWithdrawals
): float {
    return $income
        - $expenseFromAvailableMoney
        - $savingsDeposits
        + $savingsWithdrawals;
}

/**
 * Dampak pada total dana tercatat.
 */
function transaction_asset_effect(string $type, float $amount): float
{
    return match ($type) {
        'income' => $amount,
        'expense', 'savings_spend' => -$amount,
        'savings_deposit', 'savings_withdrawal' => 0.0,
        default => 0.0,
    };
}

function schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Memastikan struktur database utama tersedia dan lengkap.
 * Data pengguna tidak diubah selain pengisian snapshot historis yang kosong.
 */
function ensure_application_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS savings_goals (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(100) NOT NULL,
        target_amount DECIMAL(15,2) NOT NULL,
        description VARCHAR(255) NULL,
        target_date DATE NULL,
        status ENUM('active', 'archived', 'deleted') NOT NULL DEFAULT 'active',
        deleted_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_savings_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_savings_goals_user_status (user_id, status),
        INDEX idx_savings_goals_target_date (user_id, target_date)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS savings_entries (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        savings_goal_id BIGINT UNSIGNED NOT NULL,
        type ENUM('deposit', 'withdrawal', 'spend') NOT NULL,
        amount DECIMAL(15,2) NOT NULL,
        category_id INT UNSIGNED NULL,
        goal_name_snapshot VARCHAR(100) NOT NULL,
        category_name_snapshot VARCHAR(100) NULL,
        note VARCHAR(255) NULL,
        entry_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_savings_entries_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_savings_entries_goal FOREIGN KEY (savings_goal_id) REFERENCES savings_goals(id) ON DELETE RESTRICT,
        CONSTRAINT fk_savings_entries_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
        INDEX idx_savings_entries_user_date (user_id, entry_date),
        INDEX idx_savings_entries_goal_date (savings_goal_id, entry_date),
        INDEX idx_savings_entries_category (category_id)
    ) ENGINE=InnoDB DEFAULT CHARACTER SET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (!schema_column_exists($pdo, 'users', 'profile_photo')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER password");
    }
    if (!schema_column_exists($pdo, 'users', 'password_changed_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER profile_photo");
    }
    if (!schema_column_exists($pdo, 'users', 'updated_at')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }

    $requiredColumns = [
        ['users', 'profile_photo'],
        ['users', 'password_changed_at'],
        ['users', 'updated_at'],
        ['categories', 'is_active'],
        ['transactions', 'category_name_snapshot'],
        ['savings_goals', 'deleted_at'],
        ['savings_entries', 'goal_name_snapshot'],
        ['savings_entries', 'category_name_snapshot'],
        ['savings_entries', 'category_id'],
    ];

    foreach ($requiredColumns as [$table, $column]) {
        if (!schema_column_exists($pdo, $table, $column)) {
            throw new RuntimeException('Struktur database belum lengkap. Impor schema.sql melalui phpMyAdmin.');
        }
    }

    // Perbaikan idempotent untuk data warisan: hanya mengisi snapshot yang kosong.
    // Nama historis yang sudah terisi tidak pernah ditimpa.
    $pdo->exec("UPDATE savings_goals
        SET name=CONCAT('Tujuan tabungan #', id)
        WHERE name IS NULL OR TRIM(name)=''");

    $pdo->exec("UPDATE savings_entries se
        INNER JOIN savings_goals sg
            ON sg.id=se.savings_goal_id
           AND sg.user_id=se.user_id
        SET se.goal_name_snapshot=COALESCE(
            NULLIF(TRIM(se.goal_name_snapshot), ''),
            NULLIF(TRIM(sg.name), ''),
            CONCAT('Tujuan tabungan #', se.savings_goal_id)
        )
        WHERE se.goal_name_snapshot IS NULL OR TRIM(se.goal_name_snapshot)=''");

    $pdo->exec("UPDATE savings_entries se
        LEFT JOIN categories c
            ON c.id=se.category_id
           AND c.user_id=se.user_id
        SET se.category_name_snapshot=COALESCE(
            NULLIF(TRIM(se.category_name_snapshot), ''),
            NULLIF(TRIM(c.name), ''),
            'Tanpa kategori'
        )
        WHERE se.type='spend'
          AND (se.category_name_snapshot IS NULL OR TRIM(se.category_name_snapshot)='')");

    $ready = true;
}

function get_financial_position(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("SELECT
        COALESCE((SELECT SUM(CASE WHEN type='income' THEN amount ELSE -amount END)
                  FROM transactions WHERE user_id=?), 0) AS transaction_assets,
        COALESCE((SELECT SUM(CASE WHEN type='spend' THEN amount ELSE 0 END)
                  FROM savings_entries WHERE user_id=?), 0) AS savings_spent,
        COALESCE((SELECT SUM(CASE
                    WHEN type='deposit' THEN amount
                    WHEN type IN ('withdrawal', 'spend') THEN -amount
                    ELSE 0 END)
                  FROM savings_entries WHERE user_id=?), 0) AS savings_balance");
    $stmt->execute([$userId, $userId, $userId]);
    $row = $stmt->fetch() ?: ['transaction_assets' => 0, 'savings_spent' => 0, 'savings_balance' => 0];

    $netAssets = (float)$row['transaction_assets'] - (float)$row['savings_spent'];
    $savingsBalance = max(0.0, (float)$row['savings_balance']);

    return [
        'net_assets' => $netAssets,
        'savings_balance' => $savingsBalance,
        'available_cash' => $netAssets - $savingsBalance,
    ];
}

function get_savings_goal_balance(PDO $pdo, int $userId, int $goalId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE
            WHEN type='deposit' THEN amount
            WHEN type IN ('withdrawal', 'spend') THEN -amount
            ELSE 0 END), 0)
        FROM savings_entries WHERE user_id=? AND savings_goal_id=?");
    $stmt->execute([$userId, $goalId]);
    return max(0.0, (float)$stmt->fetchColumn());
}

function savings_entry_type_label(string $type): string
{
    return match ($type) {
        'deposit' => 'Setoran',
        'withdrawal' => 'Pencairan',
        'spend' => 'Digunakan',
        default => ucfirst($type),
    };
}

function savings_progress(float $current, float $target): float
{
    if ($target <= 0) {
        return 0.0;
    }
    return min(100.0, max(0.0, ($current / $target) * 100));
}

function savings_remaining_target(float $currentAmount, float $targetAmount): float
{
    return max(0.0, $targetAmount - $currentAmount);
}

function savings_max_deposit(float $availableCash, float $currentAmount, float $targetAmount): float
{
    return max(0.0, min($availableCash, savings_remaining_target($currentAmount, $targetAmount)));
}

function savings_target_covers_balance(float $targetAmount, float $currentAmount): bool
{
    return $targetAmount + 0.00001 >= $currentAmount;
}
