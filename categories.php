<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$userId = current_user_id();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'create';

    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = (string)($_POST['type'] ?? '');
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($nameLength < 2 || $nameLength > 100) {
            $errors[] = 'Nama kategori harus terdiri dari 2–100 karakter.';
        }
        if (!in_array($type, ['income', 'expense'], true)) {
            $errors[] = 'Jenis kategori tidak valid.';
        }

        if (!$errors) {
            $check = $pdo->prepare('SELECT id, is_active FROM categories WHERE user_id=? AND type=? AND LOWER(name)=LOWER(?)');
            $check->execute([$userId, $type, $name]);
            $existing = $check->fetch();

            if ($existing && (int)$existing['is_active'] === 1) {
                $errors[] = 'Kategori dengan nama tersebut sudah tersedia pada jenis yang sama.';
            } elseif ($existing) {
                $stmt = $pdo->prepare('UPDATE categories SET is_active=1, name=? WHERE id=? AND user_id=?');
                $stmt->execute([$name, (int)$existing['id'], $userId]);
                auto_backup_after_financial_change($pdo, $userId, 'kategori-diubah');
                flash('success', 'Kategori “' . $name . '” diaktifkan kembali.');
                redirect('categories.php');
            } else {
                $stmt = $pdo->prepare('INSERT INTO categories (user_id, name, type, is_active) VALUES (?, ?, ?, 1)');
                $stmt->execute([$userId, $name, $type]);
                auto_backup_after_financial_change($pdo, $userId, 'kategori-diubah');
                flash('success', 'Kategori “' . $name . '” berhasil ditambahkan.');
                redirect('categories.php');
            }
        }
    }

    if ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare('SELECT name FROM categories WHERE id=? AND user_id=? AND is_active=1');
        $stmt->execute([$id, $userId]);
        $categoryName = $stmt->fetchColumn();

        if ($categoryName !== false) {
            $stmt = $pdo->prepare('UPDATE categories SET is_active=0 WHERE id=? AND user_id=?');
            $stmt->execute([$id, $userId]);
            auto_backup_after_financial_change($pdo, $userId, 'kategori-diubah');
            flash('success', 'Kategori “' . $categoryName . '” dinonaktifkan. Nama kategori pada transaksi lama tetap dipertahankan.');
        }
        redirect('categories.php');
    }
}

$stmt = $pdo->prepare("SELECT c.*,
    (SELECT COUNT(*) FROM transactions t WHERE t.category_id=c.id) AS transaction_count
    FROM categories c
    WHERE c.user_id=? AND c.is_active=1
    ORDER BY c.type, c.name");
$stmt->execute([$userId]);
$categories = $stmt->fetchAll();

$categoryGroups = [
    'income' => array_values(array_filter($categories, static fn(array $category): bool => $category['type'] === 'income')),
    'expense' => array_values(array_filter($categories, static fn(array $category): bool => $category['type'] === 'expense')),
];

$pageTitle = 'Kategori Transaksi';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading category-page-heading">
    <div>
        <span class="eyebrow">Pengaturan transaksi</span>
        <h1>Kategori Transaksi</h1>
        <p class="muted">Kelompokkan pemasukan dan pengeluaran agar riwayat serta laporan lebih mudah dianalisis.</p>
    </div>
    <div class="category-total-chip" aria-label="Jumlah seluruh kategori">
        <span><?= count($categories) ?></span>
        <small>Total kategori</small>
    </div>
</div>

<div class="category-manager-layout">
    <details class="card category-create-card responsive-disclosure" open data-responsive-disclosure data-keep-open="<?= $errors ? 'true' : 'false' ?>">
        <summary>
            <span>
                <span class="section-kicker">Kategori baru</span>
                <strong>Tambah kategori</strong>
                <small>Buat kategori pemasukan atau pengeluaran.</small>
            </span>
            <span class="disclosure-control" aria-hidden="true">
                <span class="disclosure-control-icon">
                    <svg class="disclosure-icon-open" viewBox="0 0 24 24" width="17" height="17"><path d="M6 3h9l3 3v15H6zM15 3v4h4M12 11v6m-3-3h6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <svg class="disclosure-icon-close" viewBox="0 0 24 24" width="17" height="17"><path d="M7 7l10 10M17 7 7 17" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                <span class="disclosure-label-closed">Isi form</span>
                <span class="disclosure-label-open">Tutup</span>
            </span>
        </summary>
        <div class="disclosure-content">
        <p class="muted category-create-note">Gunakan nama yang singkat dan spesifik.</p>

        <?php if ($errors): ?>
            <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
        <?php endif; ?>

        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <label>Nama kategori
                <input type="text" name="name" maxlength="100" required placeholder="Contoh: Pendidikan" value="<?= e((($_POST['action'] ?? 'create') === 'create') ? ($_POST['name'] ?? '') : '') ?>">
            </label>
            <label>Jenis kategori
                <select name="type" required>
                    <option value="expense" <?= ($_POST['type'] ?? 'expense') === 'expense' ? 'selected' : '' ?>>Pengeluaran</option>
                    <option value="income" <?= ($_POST['type'] ?? '') === 'income' ? 'selected' : '' ?>>Pemasukan</option>
                </select>
            </label>
            <button class="btn primary" type="submit">Tambah kategori</button>
        </form>
        </div>
    </details>

    <div class="category-groups" aria-label="Daftar kategori berdasarkan jenis">
        <?php foreach (['income', 'expense'] as $groupType):
            $items = $categoryGroups[$groupType];
            $isIncome = $groupType === 'income';
        ?>
            <details class="card category-group-card <?= $isIncome ? 'category-group-income' : 'category-group-expense' ?>" open>
                <summary class="category-group-head">
                    <div class="category-group-title">
                        <span class="category-group-icon" aria-hidden="true">
                            <?php if ($isIncome): ?>
                                <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 19V5m-5 5 5-5 5 5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php else: ?>
                                <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 5v14m5-5-5 5-5-5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <?php endif; ?>
                        </span>
                        <div>
                            <span class="section-kicker"><?= $isIncome ? 'Dana masuk' : 'Dana keluar' ?></span>
                            <h2><?= $isIncome ? 'Kategori pemasukan' : 'Kategori pengeluaran' ?></h2>
                        </div>
                    </div>
                    <span class="category-summary-tools">
                        <span class="category-count-badge"><?= count($items) ?></span>
                        <span class="summary-action summary-action-list" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="15" height="15">
                                <path d="M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <span class="summary-action-closed">Lihat daftar</span>
                            <span class="summary-action-open">Sembunyikan</span>
                        </span>
                    </span>
                </summary>

                <div class="category-group-content">
                <?php if (!$items): ?>
                    <div class="category-empty-state">
                        <strong>Belum ada kategori</strong>
                        <span>Tambahkan kategori <?= $isIncome ? 'pemasukan' : 'pengeluaran' ?> melalui formulir di samping.</span>
                    </div>
                <?php else: ?>
                    <div class="category-card-grid">
                        <?php foreach ($items as $cat): ?>
                            <article class="category-tile">
                                <div class="category-tile-copy">
                                    <strong title="<?= e($cat['name']) ?>"><?= e($cat['name']) ?></strong>
                                    <span><?= (int)$cat['transaction_count'] ?> transaksi</span>
                                </div>
                                <form method="post" onsubmit="return confirm('Nonaktifkan kategori ini? Nama kategori pada transaksi lama tetap dipertahankan.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
                                    <button class="category-delete-button" type="submit" aria-label="Nonaktifkan kategori <?= e($cat['name']) ?>" title="Nonaktifkan kategori">
                                        <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <span>Nonaktifkan</span>
                                    </button>
                                </form>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
