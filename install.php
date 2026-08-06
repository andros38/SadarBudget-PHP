<?php
session_start();

const SADARBUDGET_INSTALL_VERSION = '2.0.0';

function installer_escape(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function installer_split_sql(string $sql): array
{
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $escaped = false;
    $lineComment = false;
    $blockComment = false;

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';

        if ($lineComment) {
            if ($character === "\n") {
                $lineComment = false;
                $buffer .= $character;
            }
            continue;
        }

        if ($blockComment) {
            if ($character === '*' && $next === '/') {
                $blockComment = false;
                $index++;
            }
            continue;
        }

        if ($quote === null && $character === '-' && $next === '-') {
            $lineComment = true;
            $index++;
            continue;
        }

        if ($quote === null && $character === '/' && $next === '*') {
            $blockComment = true;
            $index++;
            continue;
        }

        $buffer .= $character;

        if ($quote !== null) {
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($character === '\\') {
                $escaped = true;
                continue;
            }
            if ($character === $quote) {
                if ($next === $quote && $quote !== '`') {
                    $buffer .= $next;
                    $index++;
                    continue;
                }
                $quote = null;
            }
            continue;
        }

        if (in_array($character, ["'", '"', '`'], true)) {
            $quote = $character;
            continue;
        }

        if ($character === ';') {
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

    if ($quote !== null || $blockComment) {
        throw new RuntimeException('schema.sql tidak lengkap atau memiliki sintaks komentar/kutipan yang tidak valid.');
    }

    return $statements;
}

function installer_config_content(array $settings): string
{
    $export = static fn(mixed $value): string => var_export($value, true);

    return "<?php\n"
        . "// Dibuat otomatis oleh installer SadarBudget " . SADARBUDGET_INSTALL_VERSION . ".\n"
        . "define('DB_HOST', " . $export($settings['host']) . ");\n"
        . "define('DB_PORT', " . $export($settings['port']) . ");\n"
        . "define('DB_NAME', " . $export($settings['database']) . ");\n"
        . "define('DB_USER', " . $export($settings['username']) . ");\n"
        . "define('DB_PASS', " . $export($settings['password']) . ");\n\n"
        . "define('APP_NAME', 'SadarBudget');\n"
        . "define('APP_VERSION', '2.0.0');\n"
        . "define('APP_SCHEMA_VERSION', '2.0.0');\n"
        . "define('APP_CURRENCY', 'IDR');\n\n"
        . "define('AUTO_BACKUP_ENABLED', true);\n"
        . "define('AUTO_BACKUP_INTERVAL_MINUTES', 30);\n"
        . "define('AUTO_BACKUP_MAX_FILES', 40);\n"
        . "// define('AUTO_BACKUP_DIRECTORY', 'D:/SadarBudget-Backups');\n";
}

function installer_prepare_directory(string $path, bool $denyAll): void
{
    if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Direktori tidak dapat dibuat: ' . basename($path));
    }
    if (!is_writable($path)) {
        throw new RuntimeException('Direktori tidak dapat ditulisi PHP: ' . basename($path));
    }

    $indexPath = $path . '/index.html';
    if (!is_file($indexPath)) {
        file_put_contents($indexPath, '<!doctype html><meta charset="utf-8"><title>Protected</title>', LOCK_EX);
    }

    if ($denyAll) {
        $apache = "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n";
        $iis = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></security></system.webServer></configuration>\n";
    } else {
        $apache = "Options -Indexes\n<FilesMatch \"\\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh)$\">\n    <IfModule mod_authz_core.c>\n        Require all denied\n    </IfModule>\n    <IfModule !mod_authz_core.c>\n        Deny from all\n    </IfModule>\n</FilesMatch>\n";
        $iis = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><requestFiltering><fileExtensions><add fileExtension=\".php\" allowed=\"false\"/><add fileExtension=\".phtml\" allowed=\"false\"/><add fileExtension=\".phar\" allowed=\"false\"/></fileExtensions></requestFiltering></security></system.webServer></configuration>\n";
    }

    file_put_contents($path . '/.htaccess', $apache, LOCK_EX);
    file_put_contents($path . '/web.config', $iis, LOCK_EX);
}

$root = __DIR__;
$configPath = $root . '/config.php';
$schemaPath = $root . '/schema.sql';
$alreadyInstalled = is_file($configPath);
$success = false;
$errors = [];

$requirements = [
    'PHP 8.1 atau lebih baru' => version_compare(PHP_VERSION, '8.1.0', '>='),
    'Ekstensi PDO MySQL' => extension_loaded('pdo_mysql'),
    'Ekstensi mbstring' => extension_loaded('mbstring'),
    'Ekstensi fileinfo' => extension_loaded('fileinfo'),
    'Ekstensi GD' => extension_loaded('gd'),
    'Folder aplikasi dapat ditulisi saat instalasi' => is_writable($root),
    'schema.sql tersedia' => is_file($schemaPath) && is_readable($schemaPath),
];

$form = [
    'host' => trim((string)($_POST['host'] ?? '127.0.0.1')),
    'port' => trim((string)($_POST['port'] ?? '3306')),
    'database' => trim((string)($_POST['database'] ?? 'sadarbudget')),
    'username' => trim((string)($_POST['username'] ?? 'root')),
    'password' => (string)($_POST['password'] ?? ''),
];

if (empty($_SESSION['installer_csrf'])) {
    $_SESSION['installer_csrf'] = bin2hex(random_bytes(24));
}

$requestMethod = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($requestMethod === 'POST' && !$alreadyInstalled) {
    if (!hash_equals((string)$_SESSION['installer_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        $errors[] = 'Sesi installer tidak valid. Muat ulang halaman lalu coba lagi.';
    }

    foreach ($requirements as $label => $available) {
        if (!$available) {
            $errors[] = 'Persyaratan belum terpenuhi: ' . $label . '.';
        }
    }

    if ($form['host'] === '') {
        $errors[] = 'Host database wajib diisi.';
    }
    if (!preg_match('/^\d{1,5}$/', $form['port']) || (int)$form['port'] < 1 || (int)$form['port'] > 65535) {
        $errors[] = 'Port database tidak valid.';
    }
    if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $form['database'])) {
        $errors[] = 'Nama database hanya boleh berisi huruf, angka, dan garis bawah.';
    }
    if ($form['username'] === '') {
        $errors[] = 'Nama pengguna database wajib diisi.';
    }

    if (!$errors) {
        $temporaryConfig = $configPath . '.installing';
        try {
            installer_prepare_directory($root . '/storage/auto-backups', true);
            installer_prepare_directory($root . '/uploads/profiles', false);

            $serverDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $form['host'], $form['port']);
            $server = new PDO($serverDsn, $form['username'], $form['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $databaseIdentifier = '`' . str_replace('`', '``', $form['database']) . '`';
            try {
                $server->exec("CREATE DATABASE IF NOT EXISTS {$databaseIdentifier} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $createError) {
                // Hosting tertentu tidak memberi izin CREATE DATABASE. Koneksi ke database
                // yang sudah dibuat tetap dicoba pada langkah berikutnya.
            }

            $databaseDsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $form['host'],
                $form['port'],
                $form['database']
            );
            $pdo = new PDO($databaseDsn, $form['username'], $form['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $tableCheck = $pdo->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME IN ('users','categories','transactions')"
            );
            $tableCheck->execute([$form['database']]);
            $existingTables = $tableCheck->fetchAll(PDO::FETCH_COLUMN);
            if ($existingTables) {
                throw new RuntimeException(
                    'Database tidak kosong. Clean installer membutuhkan database baru tanpa tabel users, categories, atau transactions.'
                );
            }

            $configContent = installer_config_content($form);
            if (file_put_contents($temporaryConfig, $configContent, LOCK_EX) === false) {
                throw new RuntimeException('config.php tidak dapat dibuat. Periksa izin tulis folder aplikasi.');
            }

            $schema = file_get_contents($schemaPath);
            if ($schema === false) {
                throw new RuntimeException('schema.sql tidak dapat dibaca.');
            }

            foreach (installer_split_sql($schema) as $statement) {
                $pdo->exec($statement);
            }

            if (!rename($temporaryConfig, $configPath)) {
                throw new RuntimeException('Konfigurasi instalasi tidak dapat disimpan sebagai config.php.');
            }

            $success = true;
            unset($_SESSION['installer_csrf']);
        } catch (Throwable $error) {
            if (is_file($temporaryConfig)) {
                @unlink($temporaryConfig);
            }
            $errors[] = 'Instalasi gagal: ' . $error->getMessage();
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>Installer SadarBudget <?= installer_escape(SADARBUDGET_INSTALL_VERSION) ?></title>
    <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
    <style>
        :root { color-scheme: light; --bg:#f4f7fb; --surface:#fff; --line:#dbe3ef; --text:#111827; --muted:#64748b; --primary:#4f46e5; --primary-soft:#eef2ff; --success:#087f5b; --success-soft:#ecfdf5; --danger:#dc2626; --danger-soft:#fef2f2; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:28px 16px; color:var(--text); background:var(--bg); font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        .installer { width:min(920px,100%); }
        .brand { display:flex; align-items:center; gap:12px; margin-bottom:18px; color:var(--text); font-size:22px; font-weight:850; }
        .brand img { width:48px; height:48px; }
        .brand span span { color:#0f766e; }
        .card { padding:clamp(20px,4vw,36px); border:1px solid var(--line); border-radius:22px; background:var(--surface); box-shadow:0 20px 45px rgba(15,23,42,.08); }
        .eyebrow { display:block; margin-bottom:8px; color:var(--primary); font-size:12px; font-weight:850; letter-spacing:.08em; text-transform:uppercase; }
        h1 { margin:0 0 8px; font-size:clamp(28px,5vw,42px); line-height:1.08; }
        .lead { margin:0 0 24px; color:var(--muted); line-height:1.65; }
        .requirements { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin:0 0 24px; padding:0; list-style:none; }
        .requirements li { display:flex; align-items:center; gap:8px; min-height:42px; padding:9px 11px; border:1px solid var(--line); border-radius:11px; font-size:13px; }
        .requirements .ok { color:var(--success); background:var(--success-soft); }
        .requirements .fail { color:var(--danger); background:var(--danger-soft); }
        .alert { margin-bottom:18px; padding:12px 14px; border:1px solid; border-radius:12px; line-height:1.55; }
        .alert.error { color:#991b1b; border-color:#fecaca; background:var(--danger-soft); }
        .alert.success { color:#065f46; border-color:#a7f3d0; background:var(--success-soft); }
        .alert ul { margin:0; padding-left:20px; }
        .grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:15px; }
        label { display:grid; gap:7px; color:#334155; font-size:13px; font-weight:750; }
        label.full { grid-column:1/-1; }
        input { width:100%; min-height:48px; padding:10px 13px; border:1px solid #cbd5e1; border-radius:11px; color:var(--text); background:var(--surface); font:inherit; font-size:15px; }
        input:focus { outline:3px solid rgba(79,70,229,.16); border-color:var(--primary); }
        .actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:20px; }
        .button { min-height:48px; display:inline-flex; align-items:center; justify-content:center; padding:10px 18px; border:0; border-radius:11px; color:#fff; background:var(--primary); font:inherit; font-weight:800; text-decoration:none; cursor:pointer; }
        .button.secondary { color:#334155; border:1px solid var(--line); background:var(--surface); }
        .note { margin:18px 0 0; color:var(--muted); font-size:12px; line-height:1.55; }
        code { padding:2px 5px; border-radius:6px; background:var(--primary-soft); }
        @media (max-width:650px) { body{padding:16px 10px}.card{border-radius:17px}.requirements,.grid{grid-template-columns:1fr}.actions .button{width:100%} }
        @media (prefers-color-scheme:dark) { :root{color-scheme:dark;--bg:#07101f;--surface:#0f172a;--line:#26354d;--text:#f8fafc;--muted:#a7b4c8;--primary:#818cf8;--primary-soft:rgba(129,140,248,.14);--success:#34d399;--success-soft:rgba(16,185,129,.12);--danger:#f87171;--danger-soft:rgba(239,68,68,.12)} label{color:#d7deea} input{border-color:#334155}.button.secondary{color:#e2e8f0} }
    </style>
</head>
<body>
<main class="installer">
    <a class="brand" href="install.php" aria-label="Installer SadarBudget">
        <img src="assets/sadarbudget-logo.svg" alt="">
        <span>Sadar<span>Budget</span></span>
    </a>
    <section class="card">
        <span class="eyebrow">Clean installer <?= installer_escape(SADARBUDGET_INSTALL_VERSION) ?></span>
        <h1>Siapkan SadarBudget</h1>
        <p class="lead">Installer membuat database, mengimpor struktur bersih, menyiapkan folder aplikasi, dan menghasilkan <code>config.php</code> secara otomatis.</p>

        <?php if ($alreadyInstalled && !$success): ?>
            <div class="alert success"><strong>SadarBudget sudah terpasang.</strong><br>Untuk memasang ulang, gunakan database kosong lalu hapus <code>config.php</code> secara manual.</div>
            <div class="actions"><a class="button" href="index.php">Buka aplikasi</a></div>
        <?php elseif ($success): ?>
            <div class="alert success"><strong>Instalasi berhasil.</strong><br>Database dan konfigurasi SadarBudget 2.0.0 sudah siap.</div>
            <div class="actions"><a class="button" href="register.php">Buat akun pertama</a><a class="button secondary" href="index.php">Buka beranda</a></div>
        <?php else: ?>
            <ul class="requirements" aria-label="Pemeriksaan sistem">
                <?php foreach ($requirements as $label => $available): ?>
                    <li class="<?= $available ? 'ok' : 'fail' ?>"><span aria-hidden="true"><?= $available ? '✓' : '×' ?></span><?= installer_escape($label) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($errors): ?>
                <div class="alert error" role="alert"><strong>Periksa kembali instalasi:</strong><ul><?php foreach ($errors as $error): ?><li><?= installer_escape($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <form method="post" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= installer_escape($_SESSION['installer_csrf']) ?>">
                <div class="grid">
                    <label>Host database<input type="text" name="host" value="<?= installer_escape($form['host']) ?>" required></label>
                    <label>Port database<input type="number" name="port" min="1" max="65535" value="<?= installer_escape($form['port']) ?>" required></label>
                    <label>Nama database<input type="text" name="database" value="<?= installer_escape($form['database']) ?>" pattern="[A-Za-z0-9_]+" required></label>
                    <label>Pengguna database<input type="text" name="username" value="<?= installer_escape($form['username']) ?>" required></label>
                    <label class="full">Kata sandi database <span style="font-weight:500;color:var(--muted)">(boleh kosong pada XAMPP lokal)</span><input type="password" name="password" value=""></label>
                </div>
                <div class="actions"><button class="button" type="submit">Pasang SadarBudget</button></div>
                <p class="note">Gunakan database baru atau kosong. Installer tidak menimpa tabel aplikasi yang sudah ada. Setelah berhasil, <code>config.php</code> akan diabaikan Git agar kredensial database tidak terunggah ke GitHub.</p>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
