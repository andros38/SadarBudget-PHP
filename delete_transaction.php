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
    flash('error', 'Transaksi yang akan dihapus tidak valid.');
    redirect($returnUrl);
}

$stmt = $pdo->prepare("SELECT t.*, t.transaction_date, t.category_name_snapshot AS category_name
    FROM transactions t WHERE t.id=? AND t.user_id=? LIMIT 1");
$stmt->execute([$rowId, $userId]);
$transaction = $stmt->fetch();

if (!$transaction) {
    flash('error', 'Transaksi tidak ditemukan atau bukan milik akun Anda.');
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM transactions WHERE id=? AND user_id=? FOR UPDATE');
        $lock->execute([$rowId, $userId]);
        if (!$lock->fetch()) {
            throw new RuntimeException('Transaksi sudah tidak tersedia.');
        }

        $delete = $pdo->prepare('DELETE FROM transactions WHERE id=? AND user_id=?');
        $delete->execute([$rowId, $userId]);
        $pdo->commit();
        auto_backup_after_financial_change($pdo, $userId, 'transaksi-dihapus');

        flash('success', 'Transaksi berhasil dihapus.');
        redirect($returnUrl);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Transaksi gagal dihapus. Silakan coba lagi.';
    }
}

$categoryLabel = transaction_category_history_name($transaction['category_name'] ?? null);

$pageTitle = 'Hapus Transaksi';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading compact">
    <div>
        <span class="eyebrow">Konfirmasi penghapusan</span>
        <h1>Hapus transaksi?</h1>
        <p class="muted">Tindakan ini menghapus entri dari laporan dan perhitungan saldo transaksi.</p>
    </div>
</div>
<section class="card form-card delete-transaction-card">
    <?php if ($errors): ?><div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>

    <div class="delete-transaction-summary" aria-label="Rincian transaksi yang akan dihapus">
        <div><span>Jenis</span><strong><?= e(transaction_type_label($transaction['type'])) ?></strong></div>
        <div><span>Nominal</span><strong class="amount <?= e(transaction_amount_class($transaction['type'])) ?>"><?= e(transaction_amount_prefix($transaction['type'])) ?><?= format_rupiah($transaction['amount']) ?></strong></div>
        <div><span>Tanggal</span><strong><?= e(format_date_id($transaction['transaction_date'])) ?></strong></div>
        <div><span>Kategori</span><strong><?= e($categoryLabel) ?></strong></div>
        <div class="full"><span>Keterangan</span><strong><?= e(($transaction['description'] ?? '') !== '' ? $transaction['description'] : '-') ?></strong></div>
    </div>

    <form method="post" class="actions form-submit-actions">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $rowId ?>">
        <input type="hidden" name="month" value="<?= e($historyContext['month']) ?>">
        <input type="hidden" name="type_filter" value="<?= e($historyContext['type']) ?>">
        <input type="hidden" name="page" value="<?= (int)$historyContext['page'] ?>">
        <button class="btn danger" type="submit">Ya, hapus transaksi</button>
        <a class="btn secondary" href="<?= e($returnUrl) ?>">Batal</a>
    </form>
</section>
<?php require __DIR__ . '/includes/footer.php';
