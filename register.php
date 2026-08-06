<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$name = '';
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['password_confirm'] ?? '';

    if (mb_strlen($name) < 2) $errors[] = 'Nama minimal 2 karakter.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Format email tidak valid.';
    if (strlen($password) < 8) $errors[] = 'Kata sandi minimal 8 karakter.';
    if ($password !== $confirm) $errors[] = 'Konfirmasi kata sandi tidak sama.';

    if (!$errors) {
        $pdo = db();
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $check->execute([$email]);
        if ($check->fetch()) {
            $errors[] = 'Email sudah terdaftar.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (?, ?, ?)');
                $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $userId = (int)$pdo->lastInsertId();

                $defaults = [
                    ['Gaji', 'income'], ['Bonus', 'income'], ['Penjualan', 'income'], ['Lainnya', 'income'],
                    ['Makan', 'expense'], ['Transport', 'expense'], ['Belanja', 'expense'], ['Tagihan', 'expense'], ['Hiburan', 'expense'], ['Kesehatan', 'expense'], ['Lainnya', 'expense'],
                ];
                $cat = $pdo->prepare('INSERT INTO categories (user_id, name, type) VALUES (?, ?, ?)');
                foreach ($defaults as [$catName, $type]) {
                    $cat->execute([$userId, $catName, $type]);
                }
                $pdo->commit();
                flash('success', 'Akun berhasil dibuat. Silakan masuk.');
                redirect('login.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $errors[] = 'Gagal membuat akun. Silakan coba lagi.';
            }
        }
    }
}

$pageTitle = 'Daftar';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout auth-layout-register" aria-labelledby="authTitle">
    <aside class="auth-showcase" aria-label="Ringkasan SadarBudget">
        <div class="auth-showcase-copy">
            <h2>Bangun kebiasaan keuangan yang lebih terarah.</h2>
            <p>Akun Anda menyiapkan kategori awal, laporan, serta backup data dalam satu ruang kerja.</p>
        </div>
        <div class="auth-benefits" aria-label="Fitur yang tersedia">
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 7h16M4 12h16M4 17h10" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                </span>
                <span><strong>Transaksi terorganisasi</strong><small>Pemasukan dan pengeluaran tersusun berdasarkan kategori.</small></span>
            </div>
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                </span>
                <span><strong>Laporan otomatis</strong><small>Lihat pola bulanan dan tahunan tanpa menyusun tabel sendiri.</small></span>
            </div>
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M12 3 5 6v5c0 4.6 2.8 8.4 7 10 4.2-1.6 7-5.4 7-10V6l-7-3Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="m9 12 2 2 4-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span><strong>Kontrol data pribadi</strong><small>Ekspor, impor, atau bersihkan data dari menu Data & Backup.</small></span>
            </div>
        </div>
    </aside>

    <section class="auth-card card">
        <header class="auth-card-head">
            <h1 id="authTitle">Daftar SadarBudget</h1>
            <p class="muted">Isi data berikut untuk menyiapkan ruang pencatatan pribadi Anda.</p>
        </header>

        <?php if ($errors): ?>
            <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
        <?php endif; ?>

        <form method="post" class="auth-form auth-register-form">
            <?= csrf_field() ?>
            <label for="registerName">Nama
                <input id="registerName" type="text" name="name" value="<?= e($name) ?>" required autocomplete="name" autofocus>
            </label>
            <label for="registerEmail">Email
                <input id="registerEmail" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" inputmode="email">
            </label>
            <label for="registerPassword">Kata sandi
                <input id="registerPassword" type="password" name="password" required minlength="8" autocomplete="new-password" aria-describedby="passwordHint">
                <small id="passwordHint" class="field-hint">Minimal 8 karakter.</small>
            </label>
            <label for="registerPasswordConfirm">Konfirmasi kata sandi
                <input id="registerPasswordConfirm" type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
            </label>
            <button class="btn primary auth-submit" type="submit">Buat akun</button>
        </form>

        <p class="auth-switch">Sudah punya akun? <a href="login.php">Masuk</a></p>
    </section>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
