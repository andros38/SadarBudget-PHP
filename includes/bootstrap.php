<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (is_logged_in() && empty($_SESSION['schema_checked'])) {
    try {
        ensure_application_schema(db());
        $_SESSION['schema_checked'] = true;
        unset($_SESSION['schema_error']);
    } catch (Throwable $error) {
        $_SESSION['schema_error'] = $error->getMessage();
    }
}
