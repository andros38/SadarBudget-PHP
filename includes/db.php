<?php
$configPath = dirname(__DIR__) . '/config.php';

if (!is_file($configPath)) {
    if (PHP_SAPI === 'cli') {
        throw new RuntimeException('config.php belum tersedia. Jalankan install.php atau salin config.example.php menjadi config.php.');
    }

    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
    $applicationRoot = realpath(dirname(__DIR__));
    $basePath = '';

    if ($documentRoot !== false && $applicationRoot !== false) {
        $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
        $normalizedApplicationRoot = str_replace('\\', '/', $applicationRoot);
        if (str_starts_with($normalizedApplicationRoot, $normalizedDocumentRoot)) {
            $basePath = substr($normalizedApplicationRoot, strlen($normalizedDocumentRoot));
        }
    }

    $installUrl = rtrim($basePath, '/') . '/install.php';
    header('Location: ' . ($installUrl !== '' ? $installUrl : 'install.php'));
    exit;
}

require_once $configPath;

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');

    return $pdo;
}
