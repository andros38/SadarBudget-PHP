<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();
$type = 'income';
$userId = current_user_id();
$pdo = db();

$stmt = $pdo->prepare('SELECT id, name FROM categories WHERE user_id=? AND type=? AND is_active=1 ORDER BY name');
$stmt->execute([$userId, $type]);
$categories = $stmt->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $amount = parse_rupiah_input($_POST['amount'] ?? '');
    $date = $_POST['transaction_date'] ?? '';
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if ($amount <= 0) $errors[] = 'Nominal harus lebih dari 0.';
    if (!valid_date($date)) $errors[] = 'Tanggal tidak valid.';
    $check = $pdo->prepare('SELECT id, name FROM categories WHERE id=? AND user_id=? AND type=? AND is_active=1');
    $check->execute([$categoryId, $userId, $type]);
    $category = $check->fetch();
    if (!$category) $errors[] = 'Kategori tidak valid atau sudah dinonaktifkan.';

    if (!$errors) {
        $stmt = $pdo->prepare('INSERT INTO transactions (user_id, category_id, category_name_snapshot, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $categoryId, $category['name'], $type, $amount, $description ?: null, $date]);
        auto_backup_after_financial_change($pdo, $userId, 'pemasukan-ditambah');
        flash('success', 'Pemasukan berhasil ditambahkan.');
        redirect('transactions.php');
    }
}
$pageTitle = 'Catat Pemasukan';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/transaction_form.php';
require __DIR__ . '/includes/footer.php';
