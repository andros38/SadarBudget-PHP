<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auto_backup.php';

if (is_logged_in() && ($_SESSION['schema_version_checked'] ?? '') !== APP_SCHEMA_VERSION) {
    try {
        ensure_application_schema(db());
        $_SESSION['schema_version_checked'] = APP_SCHEMA_VERSION;
        unset($_SESSION['schema_checked']);
        unset($_SESSION['schema_error']);
    } catch (Throwable $error) {
        $_SESSION['schema_error'] = $error->getMessage();
    }
}

if (is_logged_in() && empty($_SESSION['schema_error'])) {
    auto_backup_maybe_periodic(db(), current_user_id());
}
