<?php
require_once __DIR__ . '/includes/bootstrap.php';
if (is_logged_in()) {
    redirect('dashboard.php');
}

$errors = [];
$email = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid.';
    }
    if ($password === '') {
        $errors[] = 'Kata sandi wajib diisi.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT id, name, password FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            redirect('dashboard.php');
        }
        $errors[] = 'Email atau kata sandi salah.';
    }
}

$successMessage = flash('success');
$errorMessage = flash('error');
$pageTitle = 'Masuk';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-layout auth-layout-login" aria-labelledby="authTitle">
    <aside class="auth-showcase" aria-label="Ringkasan SadarBudget">
        <div class="auth-showcase-copy">
            <h2>Catat uang tanpa membuatnya rumit.</h2>
            <p>Pantau uang tersedia, pengeluaran, tabungan, dan rekapan tahunan dalam satu tempat.</p>
        </div>
        <div class="auth-benefits" aria-label="Keunggulan aplikasi">
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                </span>
                <span><strong>Ringkasan jelas</strong><small>Lihat kondisi keuangan tanpa perhitungan manual.</small></span>
            </div>
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M9 5V3h6v2M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
                </span>
                <span><strong>Tabungan terarah</strong><small>Pisahkan dana sesuai tujuan dan tenggat.</small></span>
            </div>
            <div>
                <span class="auth-benefit-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22"><path d="M12 3 5 6v5c0 4.6 2.8 8.4 7 10 4.2-1.6 7-5.4 7-10V6l-7-3Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="m9 12 2 2 4-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <span><strong>Data tetap milik Anda</strong><small>Backup dan pulihkan data langsung dari aplikasi.</small></span>
            </div>
        </div>
    </aside>

    <section class="auth-card card">
        <header class="auth-card-head">
            <h1 id="authTitle">Masuk ke akun</h1>
            <p class="muted">Lanjutkan pencatatan dan pantau kondisi keuangan Anda.</p>
        </header>

        <?php if ($successMessage): ?>
            <div class="alert success" role="status"><span class="alert-dot"></span><span><?= e($successMessage) ?></span></div>
        <?php elseif (isset($_GET['logged_out'])): ?>
            <div class="alert success" role="status"><span class="alert-dot"></span><span>Anda berhasil keluar dari akun.</span></div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e($errorMessage) ?></span></div>
        <?php endif; ?>
        <?php if ($errors): ?>
            <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
        <?php endif; ?>

        <form method="post" class="auth-form">
            <?= csrf_field() ?>
            <label for="loginEmail">Email
                <input id="loginEmail" type="email" name="email" value="<?= e($email) ?>" required autocomplete="email" inputmode="email" autofocus>
            </label>
            <label for="loginPassword">Kata sandi
                <input id="loginPassword" type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn primary auth-submit" type="submit">Masuk</button>
        </form>

        <p class="auth-switch">Belum punya akun? <a href="register.php">Daftar sekarang</a></p>
    </section>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
