<?php
$type = $type ?? 'expense';
$isIncome = $type === 'income';
$title = $isIncome ? 'Tambah Pemasukan' : 'Tambah Pengeluaran';
$submitLabel = $isIncome ? 'Simpan pemasukan' : 'Simpan pengeluaran';
$categories = $categories ?? [];
$errors = $errors ?? [];
?>
<div class="page-heading compact">
    <div>
        <span class="eyebrow"><?= $isIncome ? 'Dana masuk' : 'Dana keluar' ?></span>
        <h1><?= e($title) ?></h1>
        <p class="muted">Catat transaksi agar saldo dan laporan selalu akurat.</p>
    </div>
</div>
<section class="card form-card">
    <?php if ($errors): ?><div class="alert error"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
    <?php if (!$categories): ?>
        <div class="alert error">Belum ada kategori <?= $isIncome ? 'pemasukan' : 'pengeluaran' ?>. <a href="categories.php">Tambahkan kategori dahulu.</a></div>
    <?php endif; ?>
    <form method="post" class="form-grid two-col">
        <?= csrf_field() ?>
        <label>Nominal
            <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="amount" inputmode="numeric" autocomplete="off" data-currency-input data-min="1" maxlength="19" pattern="[0-9.]+" placeholder="Contoh: 1.500.000" value="<?= e(format_rupiah_input($_POST['amount'] ?? '')) ?>" required></div>
        </label>
        <label>Tanggal
            <input type="date" name="transaction_date" value="<?= e($_POST['transaction_date'] ?? date('Y-m-d')) ?>" required>
        </label>
        <label>Kategori
            <select name="category_id" required>
                <option value="">Pilih kategori</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= (string)($cat['id']) === ($_POST['category_id'] ?? '') ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="full">Keterangan
            <textarea name="description" rows="3" maxlength="255" placeholder="Contoh: Gaji Juli, makan siang, bensin..."><?= e($_POST['description'] ?? '') ?></textarea>
        </label>
        <div class="full actions form-submit-actions">
            <button class="btn <?= $isIncome ? 'success' : 'danger' ?>" type="submit" <?= !$categories ? 'disabled' : '' ?>><?= e($submitLabel) ?></button>
            <a class="btn secondary" href="transactions.php">Batal</a>
        </div>
    </form>
</section>
