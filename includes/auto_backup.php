<?php
require_once __DIR__ . '/data_sql_builder.php';

/**
 * Backup otomatis SadarBudget.
 *
 * Snapshot disimpan sebagai SQL portabel per akun di storage/auto-backups.
 * Berkas ditulis secara atomik agar pemadaman saat proses backup tidak
 * meninggalkan berkas SQL setengah jadi.
 */

function auto_backup_enabled(): bool
{
    return !defined('AUTO_BACKUP_ENABLED') || AUTO_BACKUP_ENABLED === true;
}

function auto_backup_interval_seconds(): int
{
    $minutes = defined('AUTO_BACKUP_INTERVAL_MINUTES')
        ? (int)AUTO_BACKUP_INTERVAL_MINUTES
        : 30;

    return max(5, $minutes) * 60;
}

function auto_backup_max_files(): int
{
    $maxFiles = defined('AUTO_BACKUP_MAX_FILES')
        ? (int)AUTO_BACKUP_MAX_FILES
        : 40;

    return max(5, min(200, $maxFiles));
}

function auto_backup_root_directory(): string
{
    if (defined('AUTO_BACKUP_DIRECTORY') && trim((string)AUTO_BACKUP_DIRECTORY) !== '') {
        return rtrim((string)AUTO_BACKUP_DIRECTORY, "\\/");
    }

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'auto-backups';
}

function auto_backup_user_directory(int $userId): string
{
    if ($userId <= 0) {
        throw new RuntimeException('Pengguna backup tidak valid.');
    }

    return auto_backup_root_directory() . DIRECTORY_SEPARATOR . 'user-' . $userId;
}

function auto_backup_prepare_directory(int $userId): string
{
    $root = auto_backup_root_directory();
    $userDirectory = auto_backup_user_directory($userId);

    foreach ([$root, $userDirectory] as $directory) {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Folder auto backup tidak dapat dibuat.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Folder auto backup tidak dapat ditulisi oleh PHP.');
        }
    }

    $htaccess = $root . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents(
            $htaccess,
            "# Lindungi backup dari akses langsung melalui Apache.\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            LOCK_EX
        );
    }

    $webConfig = $root . DIRECTORY_SEPARATOR . 'web.config';
    if (!is_file($webConfig)) {
        @file_put_contents(
            $webConfig,
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            . "<configuration><system.webServer><security><authorization>"
            . "<remove users=\"*\" roles=\"\" verbs=\"\"/>"
            . "<add accessType=\"Deny\" users=\"*\"/>"
            . "</authorization></security></system.webServer></configuration>\n",
            LOCK_EX
        );
    }

    $index = $root . DIRECTORY_SEPARATOR . 'index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '<!doctype html><title>Forbidden</title>', LOCK_EX);
    }

    return $userDirectory;
}

function auto_backup_reason_slug(string $reason): string
{
    $reason = strtolower(trim($reason));
    $reason = preg_replace('/[^a-z0-9]+/', '-', $reason) ?? '';
    $reason = trim($reason, '-');

    return $reason !== '' ? substr($reason, 0, 42) : 'snapshot';
}

function auto_backup_reason_label(string $reason): string
{
    $labels = [
        'periodik' => 'Backup berkala',
        'manual' => 'Dibuat manual',
        'pemasukan-ditambah' => 'Setelah tambah pemasukan',
        'pengeluaran-ditambah' => 'Setelah tambah pengeluaran',
        'transaksi-diubah' => 'Setelah transaksi diubah',
        'transaksi-dihapus' => 'Setelah transaksi dihapus',
        'kategori-diubah' => 'Setelah kategori berubah',
        'sebelum-impor' => 'Sebelum impor data',
        'setelah-impor' => 'Setelah impor data',
        'sebelum-bersihkan' => 'Sebelum bersihkan data',
        'setelah-bersihkan' => 'Setelah bersihkan data',
        'sebelum-pemulihan' => 'Sebelum pemulihan snapshot',
        'setelah-pemulihan' => 'Setelah pemulihan snapshot',
        'profil-diperbarui' => 'Setelah profil diperbarui',
    ];

    return $labels[$reason] ?? ucwords(str_replace('-', ' ', $reason));
}

function auto_backup_state_path(int $userId): string
{
    return auto_backup_user_directory($userId) . DIRECTORY_SEPARATOR . 'state.json';
}

function auto_backup_read_state(int $userId): array
{
    try {
        $path = auto_backup_state_path($userId);
    } catch (Throwable $error) {
        return [];
    }

    if (!is_file($path)) {
        return [];
    }

    $json = @file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return [];
    }

    $state = json_decode($json, true);
    return is_array($state) ? $state : [];
}

function auto_backup_write_state(int $userId, array $updates): void
{
    try {
        $directory = auto_backup_prepare_directory($userId);
        $path = $directory . DIRECTORY_SEPARATOR . 'state.json';
        $state = array_merge(auto_backup_read_state($userId), $updates);
        $state['updated_at'] = date(DATE_ATOM);
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents($path, $json . PHP_EOL, LOCK_EX);
        }
    } catch (Throwable $error) {
        // Status tidak boleh menggagalkan transaksi keuangan utama.
    }
}

function auto_backup_record_error(int $userId, Throwable|string $error): void
{
    $message = $error instanceof Throwable ? $error->getMessage() : (string)$error;
    auto_backup_write_state($userId, [
        'last_error_at' => date(DATE_ATOM),
        'last_error' => substr(trim($message), 0, 500),
    ]);
}

function auto_backup_list(int $userId, int $limit = 0): array
{
    try {
        $directory = auto_backup_user_directory($userId);
    } catch (Throwable $error) {
        return [];
    }

    if (!is_dir($directory)) {
        return [];
    }

    $files = glob($directory . DIRECTORY_SEPARATOR . 'sadarbudget-auto-*.sql') ?: [];
    usort($files, static fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

    $items = [];
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }

        $filename = basename($path);
        $reason = 'snapshot';
        if (preg_match('/^sadarbudget-auto-\d{8}-\d{6}-(.+)-[a-f0-9]{8}\.sql$/', $filename, $matches)) {
            $reason = (string)$matches[1];
        }

        $items[] = [
            'filename' => $filename,
            'path' => $path,
            'created_at' => date('Y-m-d H:i:s', filemtime($path) ?: time()),
            'timestamp' => filemtime($path) ?: 0,
            'size' => filesize($path) ?: 0,
            'reason' => $reason,
            'reason_label' => auto_backup_reason_label($reason),
        ];

        if ($limit > 0 && count($items) >= $limit) {
            break;
        }
    }

    return $items;
}

function auto_backup_latest(int $userId): ?array
{
    $items = auto_backup_list($userId, 1);
    return $items[0] ?? null;
}

function auto_backup_prune(int $userId): void
{
    $items = auto_backup_list($userId);
    $maxFiles = auto_backup_max_files();

    foreach (array_slice($items, $maxFiles) as $item) {
        if (!empty($item['path']) && is_file($item['path'])) {
            @unlink($item['path']);
        }
    }
}

function auto_backup_create(PDO $pdo, int $userId, string $reason = 'snapshot', bool $force = true): array
{
    if (!auto_backup_enabled()) {
        return ['created' => false, 'reason' => 'disabled'];
    }

    $reason = auto_backup_reason_slug($reason);
    $latest = auto_backup_latest($userId);
    if (!$force && $latest && (time() - (int)$latest['timestamp']) < auto_backup_interval_seconds()) {
        return ['created' => false, 'reason' => 'not_due', 'backup' => $latest];
    }

    $directory = auto_backup_prepare_directory($userId);
    $lockPath = $directory . DIRECTORY_SEPARATOR . '.backup.lock';
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Kunci proses auto backup tidak dapat dibuat.');
    }

    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Proses auto backup sedang terkunci.');
        }

        // Periksa ulang setelah mendapat lock agar dua request bersamaan tidak
        // membuat snapshot periodik ganda.
        if (!$force) {
            $latest = auto_backup_latest($userId);
            if ($latest && (time() - (int)$latest['timestamp']) < auto_backup_interval_seconds()) {
                return ['created' => false, 'reason' => 'not_due', 'backup' => $latest];
            }
        }

        $sql = build_user_data_sql($pdo, $userId);
        $random = bin2hex(random_bytes(4));
        $filename = 'sadarbudget-auto-' . date('Ymd-His') . '-' . $reason . '-' . $random . '.sql';
        $finalPath = $directory . DIRECTORY_SEPARATOR . $filename;
        $temporaryPath = $directory . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));

        $written = @file_put_contents($temporaryPath, $sql, LOCK_EX);
        if ($written === false || $written !== strlen($sql)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Berkas auto backup gagal ditulis lengkap.');
        }

        if (!@rename($temporaryPath, $finalPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException('Berkas auto backup gagal diselesaikan secara atomik.');
        }

        @chmod($finalPath, 0640);
        auto_backup_prune($userId);

        $result = [
            'created' => true,
            'filename' => $filename,
            'path' => $finalPath,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'size' => filesize($finalPath) ?: strlen($sql),
            'reason' => $reason,
            'reason_label' => auto_backup_reason_label($reason),
        ];

        auto_backup_write_state($userId, [
            'last_success_at' => date(DATE_ATOM),
            'last_success_file' => $filename,
            'last_success_reason' => $reason,
            'last_error_at' => null,
            'last_error' => null,
        ]);

        return $result;
    } catch (Throwable $error) {
        auto_backup_record_error($userId, $error);
        throw $error;
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function auto_backup_after_financial_change(PDO $pdo, int $userId, string $reason): void
{
    try {
        auto_backup_create($pdo, $userId, $reason, true);
    } catch (Throwable $error) {
        // Perubahan data sudah berhasil. Kegagalan backup tidak membatalkan
        // transaksi, tetapi pengguna harus diberi tahu agar dapat memperbaiki
        // izin folder sebelum perubahan berikutnya.
        auto_backup_record_error($userId, $error);
        if (session_status() === PHP_SESSION_ACTIVE) {
            flash('warning', 'Data berhasil disimpan, tetapi auto backup gagal dibuat. Periksa status Auto backup pada menu Data & Backup.');
        }
    }
}

function auto_backup_maybe_periodic(PDO $pdo, int $userId): void
{
    if (!auto_backup_enabled() || $userId <= 0) {
        return;
    }

    try {
        auto_backup_create($pdo, $userId, 'periodik', false);
    } catch (Throwable $error) {
        auto_backup_record_error($userId, $error);
    }
}

function auto_backup_resolve_file(int $userId, string $filename): string
{
    $filename = basename(trim($filename));
    if (!preg_match('/^sadarbudget-auto-\d{8}-\d{6}-[a-z0-9-]+-[a-f0-9]{8}\.sql$/', $filename)) {
        throw new RuntimeException('Berkas snapshot tidak valid.');
    }

    $directory = auto_backup_user_directory($userId);
    $path = $directory . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('Berkas snapshot tidak ditemukan.');
    }

    $realDirectory = realpath($directory);
    $realPath = realpath($path);
    if ($realDirectory === false || $realPath === false || !str_starts_with($realPath, $realDirectory . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Lokasi berkas snapshot tidak valid.');
    }

    return $realPath;
}

function auto_backup_status(int $userId): array
{
    $items = auto_backup_list($userId);
    $state = auto_backup_read_state($userId);
    $totalSize = array_sum(array_map(static fn(array $item): int => (int)$item['size'], $items));

    return [
        'enabled' => auto_backup_enabled(),
        'interval_minutes' => (int)(auto_backup_interval_seconds() / 60),
        'max_files' => auto_backup_max_files(),
        'count' => count($items),
        'total_size' => $totalSize,
        'latest' => $items[0] ?? null,
        'items' => $items,
        'last_error_at' => $state['last_error_at'] ?? null,
        'last_error' => $state['last_error'] ?? null,
        'directory' => auto_backup_root_directory(),
    ];
}

function auto_backup_human_size(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }

    return number_format($bytes / (1024 * 1024), 1, ',', '.') . ' MB';
}
