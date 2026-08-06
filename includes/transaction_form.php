<?php
$type = $type ?? 'expense';
$isIncome = $type === 'income';
$isEdit = $isEdit ?? false;
$title = $formTitle ?? ($isIncome ? 'Catat Pemasukan' : 'Catat Pengeluaran');
$submitLabel = $submitLabel ?? ($isIncome ? 'Simpan pemasukan' : 'Simpan pengeluaran');
$formEyebrow = $formEyebrow ?? ($isIncome ? 'Dana masuk' : 'Dana keluar');
$formDescription = $formDescription ?? 'Catat transaksi agar saldo dan laporan selalu akurat.';
$cancelUrl = $cancelUrl ?? 'transactions.php';
$categories = $categories ?? [];
$errors = $errors ?? [];
$formValues = $formValues ?? $_POST;
$allowHistoricalCategory = $allowHistoricalCategory ?? false;
$historicalCategoryLabel = $historicalCategoryLabel ?? 'Pertahankan kategori historis';
?>
<div class="page-heading compact">
    <div>
        <span class="eyebrow"><?= e($formEyebrow) ?></span>
        <h1><?= e($title) ?></h1>
        <p class="muted"><?= e($formDescription) ?></p>
    </div>
</div>
<section class="card form-card">
    <?php if ($errors): ?><div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div><?php endif; ?>
    <?php if (!$categories && !$allowHistoricalCategory): ?>
        <div class="alert error">Belum ada kategori <?= $isIncome ? 'pemasukan' : 'pengeluaran' ?>. <a href="categories.php">Tambahkan kategori dahulu.</a></div>
    <?php endif; ?>
    <form method="post" class="form-grid two-col">
        <?= csrf_field() ?>
        <?php if ($isEdit): ?>
            <input type="hidden" name="source" value="transaction">
            <input type="hidden" name="id" value="<?= (int)($rowId ?? 0) ?>">
            <input type="hidden" name="month" value="<?= e($historyContext['month'] ?? '') ?>">
            <input type="hidden" name="type_filter" value="<?= e($historyContext['type'] ?? '') ?>">
            <input type="hidden" name="page" value="<?= (int)($historyContext['page'] ?? 1) ?>">
            <label>Jenis transaksi
                <input type="text" value="<?= e(transaction_type_label($type)) ?>" readonly>
            </label>
        <?php endif; ?>
        <label>Nominal
            <?php $amountInputValue = format_rupiah_input($formValues['amount'] ?? ''); ?>
            <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="amount" inputmode="numeric" autocomplete="off" data-currency-input<?= $isEdit ? ' data-server-currency="' . e($amountInputValue) . '"' : '' ?> data-min="1" maxlength="19" pattern="[0-9.]+" placeholder="Contoh: 1.500.000" value="<?= e($amountInputValue) ?>" required></div>
        </label>
        <label>Tanggal
            <input type="date" name="transaction_date" value="<?= e($formValues['transaction_date'] ?? date('Y-m-d')) ?>" required>
        </label>
        <label>Kategori
            <select name="category_id" <?= $allowHistoricalCategory ? '' : 'required' ?>>
                <option value=""><?= e($allowHistoricalCategory ? $historicalCategoryLabel : 'Pilih kategori') ?></option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?= (int)$cat['id'] ?>" <?= (string)($cat['id']) === (string)($formValues['category_id'] ?? '') ? 'selected' : '' ?>><?= e($cat['name'] . ((isset($cat['is_active']) && (int)$cat['is_active'] !== 1) ? ' (nonaktif)' : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="full">Keterangan
            <textarea name="description" rows="3" maxlength="255" placeholder="Contoh: Gaji Juli, makan siang, bensin..."><?= e($formValues['description'] ?? '') ?></textarea>
        </label>
        <div class="full actions form-submit-actions">
            <button class="btn <?= $isEdit ? 'primary' : ($isIncome ? 'success' : 'danger') ?>" type="submit" <?= (!$categories && !$allowHistoricalCategory) ? 'disabled' : '' ?>><?= e($submitLabel) ?></button>
            <a class="btn secondary" href="<?= e($cancelUrl) ?>">Batal</a>
        </div>
    </form>
</section>
