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


function form_feedback_set(string $scope, string $form, string $message, array $fields = [], array $old = []): void
{
    $_SESSION['form_feedback'][$scope] = [
        'form' => $form,
        'message' => $message,
        'fields' => $fields,
        'old' => $old,
    ];
}

function form_feedback_pull(string $scope): array
{
    $feedback = $_SESSION['form_feedback'][$scope] ?? [];
    unset($_SESSION['form_feedback'][$scope]);

    if (!is_array($feedback)) {
        return [];
    }

    return [
        'form' => (string)($feedback['form'] ?? ''),
        'message' => (string)($feedback['message'] ?? ''),
        'fields' => is_array($feedback['fields'] ?? null) ? $feedback['fields'] : [],
        'old' => is_array($feedback['old'] ?? null) ? $feedback['old'] : [],
    ];
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
    if ($value === null) {
        return '';
    }

    // Nilai DECIMAL dari MySQL biasanya dikembalikan sebagai string, misalnya
    // "500000.00". Titik pada nilai tersebut adalah pemisah desimal, bukan
    // pemisah ribuan. Tanpa penanganan khusus, penghapusan semua karakter
    // nonangka akan mengubah 500000.00 menjadi 50000000.
    if (is_int($value) || is_float($value)) {
        $amount = (float)$value;
    } else {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/^-?[0-9]+\.[0-9]{1,2}$/', $raw) === 1) {
            $amount = (float)$raw;
        } else {
            // Nilai dari input pengguna memakai titik sebagai pemisah ribuan,
            // misalnya "1.500.000".
            $digits = preg_replace('/[^0-9]/', '', $raw);
            if ($digits === null || $digits === '') {
                return '';
            }
            $amount = (float)$digits;
        }
    }

    return number_format(max(0, $amount), 0, ',', '.');
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
    return ['income', 'expense'];
}

function transaction_history_context(mixed $month, mixed $type, mixed $page): array
{
    $validMonth = valid_month(is_string($month) ? $month : null) ? (string)$month : '';
    $validType = in_array((string)$type, allowed_transaction_types(), true) ? (string)$type : '';
    $validPage = max(1, (int)$page);

    return [
        'month' => $validMonth,
        'type' => $validType,
        'page' => $validPage,
    ];
}

function transaction_history_url(array $context): string
{
    $query = array_filter([
        'month' => $context['month'] ?? '',
        'type' => $context['type'] ?? '',
        'page' => max(1, (int)($context['page'] ?? 1)),
    ], static fn(mixed $value, string $key): bool => $key === 'page' ? (int)$value > 1 : $value !== '', ARRAY_FILTER_USE_BOTH);

    return 'transactions.php' . ($query ? '?' . http_build_query($query) : '');
}

function transaction_type_label(string $type): string
{
    return match ($type) {
        'income' => 'Pemasukan',
        'expense' => 'Pengeluaran',
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
 * Tanda nominal transaksi utama.
 */
function transaction_amount_prefix(string $type): string
{
    return $type === 'income' ? '+' : '-';
}

/**
 * Dampak pada uang yang tersedia.
 */
function transaction_cash_effect(string $type, float $amount): float
{
    return match ($type) {
        'income' => $amount,
        'expense' => -$amount,
        default => 0.0,
    };
}

/**
 * Dampak transaksi terhadap saldo transaksi utama.
 */
function transaction_asset_effect(string $type, float $amount): float
{
    return match ($type) {
        'income' => $amount,
        'expense' => -$amount,
        default => 0.0,
    };
}

function schema_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function schema_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Memastikan database berasal dari schema SadarBudget 2.0.0 yang lengkap.
 * Clean installer tidak menjalankan migrasi destruktif atau mengubah data lama.
 */
function ensure_application_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $requiredTables = ['users', 'categories', 'transactions'];
    $requiredColumns = [
        ['users', 'profile_photo'],
        ['users', 'password_changed_at'],
        ['users', 'updated_at'],
        ['categories', 'is_active'],
        ['transactions', 'category_name_snapshot'],
    ];

    $missing = [];
    foreach ($requiredTables as $table) {
        if (!schema_table_exists($pdo, $table)) {
            $missing[] = 'tabel ' . $table;
        }
    }
    foreach ($requiredColumns as [$table, $column]) {
        if (schema_table_exists($pdo, $table) && !schema_column_exists($pdo, $table, $column)) {
            $missing[] = 'kolom ' . $table . '.' . $column;
        }
    }

    if ($missing) {
        throw new RuntimeException(
            'Struktur database belum lengkap (' . implode(', ', $missing) . '). Jalankan install.php menggunakan database baru.'
        );
    }

    $ready = true;
}
