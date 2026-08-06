<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$userId = current_user_id();
$rowId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$historyContext = transaction_history_context(
    $_GET['month'] ?? $_POST['month'] ?? '',
    $_GET['type'] ?? $_POST['type_filter'] ?? '',
    $_GET['page'] ?? $_POST['page'] ?? 1
);
$returnUrl = transaction_history_url($historyContext);
$errors = [];

if ($rowId <= 0) {
    flash('error', 'Transaksi yang akan diubah tidak valid.');
    redirect($returnUrl);
}

$stmt = $pdo->prepare('SELECT * FROM transactions WHERE id=? AND user_id=? LIMIT 1');
$stmt->execute([$rowId, $userId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    flash('error', 'Transaksi tidak ditemukan atau bukan milik akun Anda.');
    redirect($returnUrl);
}

$stmt = $pdo->prepare('SELECT id, name, is_active FROM categories WHERE user_id=? AND type=? AND (is_active=1 OR id=?) ORDER BY is_active DESC, name');
$stmt->execute([$userId, $transaction['type'], (int)$transaction['category_id']]);
$categories = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $amount = parse_rupiah_input($_POST['amount'] ?? '');
    $date = trim((string)($_POST['transaction_date'] ?? ''));
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $description = trim((string)($_POST['description'] ?? ''));
    $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);

    if ($amount <= 0) $errors[] = 'Nominal harus lebih dari 0.';
    if (!valid_date($date)) $errors[] = 'Tanggal transaksi tidak valid.';
    if ($descriptionLength > 255) $errors[] = 'Keterangan maksimal 255 karakter.';

    $category = null;
    if ($categoryId <= 0 && $transaction['category_id'] === null) {
        $category = [
            'id' => null,
            'name' => transaction_category_history_name($transaction['category_name_snapshot'] ?? null),
        ];
    } elseif ($categoryId <= 0) {
        $errors[] = 'Pilih kategori transaksi.';
    } else {
        $check = $pdo->prepare('SELECT id, name, is_active FROM categories WHERE id=? AND user_id=? AND type=? AND (is_active=1 OR id=?) LIMIT 1');
        $check->execute([$categoryId, $userId, $transaction['type'], (int)$transaction['category_id']]);
        $category = $check->fetch();
        if (!$category) $errors[] = 'Kategori tidak valid. Pilih kategori aktif yang sesuai.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM transactions WHERE id=? AND user_id=? FOR UPDATE');
            $lock->execute([$rowId, $userId]);
            if (!$lock->fetch()) {
                throw new RuntimeException('Transaksi sudah tidak tersedia.');
            }

            $stmt = $pdo->prepare('UPDATE transactions
                SET category_id=?, category_name_snapshot=?, amount=?, description=?, transaction_date=?
                WHERE id=? AND user_id=?');
            $stmt->execute([
                $category['id'],
                $category['name'],
                $amount,
                $description !== '' ? limit_text($description) : null,
                $date,
                $rowId,
                $userId,
            ]);
            $pdo->commit();
            auto_backup_after_financial_change($pdo, $userId, 'transaksi-diubah');

            flash('success', transaction_type_label($transaction['type']) . ' berhasil diperbarui.');
            redirect($returnUrl);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = $error instanceof RuntimeException
                ? $error->getMessage()
                : 'Perubahan transaksi gagal disimpan. Silakan coba lagi.';
        }
    }
}

$type = $transaction['type'];
$isEdit = true;
$formTitle = 'Ubah ' . transaction_type_label($type);
$formEyebrow = 'Perbaikan transaksi';
$formDescription = 'Perbarui nominal, tanggal, kategori, atau keterangan tanpa menulis ulang data dari awal.';
$submitLabel = 'Simpan perubahan';
$cancelUrl = $returnUrl;
$formValues = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [
    'amount' => $transaction['amount'],
    'transaction_date' => $transaction['transaction_date'],
    'category_id' => (string)$transaction['category_id'],
    'description' => $transaction['description'] ?? '',
];
$allowHistoricalCategory = $transaction['category_id'] === null;
$historicalCategoryLabel = 'Pertahankan kategori historis: ' . transaction_category_history_name($transaction['category_name_snapshot'] ?? null);

$pageTitle = $formTitle;
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/transaction_form.php';
require __DIR__ . '/includes/footer.php';
