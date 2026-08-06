<?php
// Salin file ini menjadi config.php jika melakukan instalasi manual.
// Untuk instalasi otomatis, buka install.php melalui browser.

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'sadarbudget');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'SadarBudget');
define('APP_VERSION', '2.0.0');
define('APP_SCHEMA_VERSION', '2.0.0');
define('APP_CURRENCY', 'IDR');

// Auto backup lokal. Snapshot SQL disimpan di storage/auto-backups.
define('AUTO_BACKUP_ENABLED', true);
define('AUTO_BACKUP_INTERVAL_MINUTES', 30);
define('AUTO_BACKUP_MAX_FILES', 40);
// define('AUTO_BACKUP_DIRECTORY', 'D:/SadarBudget-Backups');
