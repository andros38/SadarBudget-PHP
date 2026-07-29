</main>
<?php if (is_logged_in()):
    $footerUser = current_user_record(db());
    $footerUserName = trim((string)($footerUser['name'] ?? $_SESSION['user_name'] ?? 'Pengguna'));
?>
<nav class="mobile-bottom-nav" aria-label="Navigasi utama mobile">
    <a class="<?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 13h6V4H4v9Zm10 7h6v-9h-6v9ZM4 20h6v-3H4v3Zm10-13h6V4h-6v3Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
        <span>Beranda</span>
    </a>
    <a class="<?= $current === 'transactions.php' ? 'active' : '' ?>" href="transactions.php">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 6h14M5 12h14M5 18h9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span>Riwayat</span>
    </a>
    <button class="mobile-bottom-action <?= in_array($current, ['add_income.php', 'add_expense.php'], true) ? 'active' : '' ?>" type="button" data-mobile-sheet-trigger="mobileCreateSheet" aria-controls="mobileCreateSheet" aria-expanded="false">
        <span class="mobile-action-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>
        </span>
        <span>Tambah</span>
    </button>
    <a class="<?= $current === 'savings.php' ? 'active' : '' ?>" href="savings.php">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="1.9"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
        <span>Tabungan</span>
    </a>
    <button class="mobile-bottom-action <?= in_array($current, ['categories.php', 'profile.php', 'annual.php'], true) ? 'active' : '' ?>" type="button" data-mobile-sheet-trigger="mobileAccountSheet" aria-controls="mobileAccountSheet" aria-expanded="false">
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
        <span>Akun</span>
    </button>
</nav>

<div class="mobile-sheet-backdrop" data-mobile-sheet-backdrop hidden></div>

<section class="mobile-sheet" id="mobileCreateSheet" role="dialog" aria-modal="true" aria-labelledby="mobileCreateTitle" hidden tabindex="-1">
    <div class="mobile-sheet-handle" aria-hidden="true"></div>
    <div class="mobile-sheet-head">
        <div>
            <span class="section-kicker">Transaksi baru</span>
            <h2 id="mobileCreateTitle">Pilih jenis transaksi</h2>
        </div>
        <button class="mobile-sheet-close" type="button" data-mobile-sheet-close aria-label="Tutup menu">&times;</button>
    </div>
    <div class="mobile-sheet-actions">
        <a class="mobile-sheet-link income" href="add_income.php">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M17 8.5v4m-2-2h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
            <span><strong>Tambah pemasukan</strong><small>Catat uang yang diterima</small></span>
        </a>
        <a class="mobile-sheet-link expense" href="add_expense.php">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 10.5h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg></span>
            <span><strong>Tambah pengeluaran</strong><small>Catat belanja dari uang tersedia</small></span>
        </a>
    </div>
</section>

<section class="mobile-sheet" id="mobileAccountSheet" role="dialog" aria-modal="true" aria-labelledby="mobileAccountTitle" hidden tabindex="-1">
    <div class="mobile-sheet-handle" aria-hidden="true"></div>
    <div class="mobile-sheet-head">
        <div>
            <span class="section-kicker">Akun dan pengaturan</span>
            <h2 id="mobileAccountTitle">Menu akun</h2>
        </div>
        <button class="mobile-sheet-close" type="button" data-mobile-sheet-close aria-label="Tutup menu">&times;</button>
    </div>
    <div class="mobile-account-summary">
        <?= render_user_avatar($footerUser, 'user-avatar', '44') ?>
        <div><strong><?= e($footerUserName) ?></strong><small><?= e($footerUser['email'] ?? 'Pengguna SadarBudget') ?></small></div>
    </div>
    <div class="mobile-sheet-actions compact">
        <a class="mobile-sheet-link" href="profile.php">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm-7 8a7 7 0 0 1 14 0" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></span>
            <span><strong>Profil</strong><small>Nama, email, foto, dan kata sandi</small></span>
        </a>
        <a class="mobile-sheet-link" href="annual.php">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg></span>
            <span><strong>Rekapan tahunan</strong><small>Analisis keuangan Januari–Desember</small></span>
        </a>
        <a class="mobile-sheet-link" href="categories.php">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 5h7v6H4zM13 5h7v4h-7zM13 11h7v8h-7zM4 13h7v6H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg></span>
            <span><strong>Kelola kategori</strong><small>Atur kategori pemasukan dan pengeluaran</small></span>
        </a>
        <a class="mobile-sheet-link" href="profile.php#data-management">
            <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M5 5h14v14H5zM8 9h8M8 13h8M8 17h5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            <span><strong>Data dan backup</strong><small>Ekspor SQL atau bersihkan seluruh riwayat</small></span>
        </a>
        <form class="mobile-logout-form" action="logout.php" method="post" data-logout-form>
            <?= csrf_field() ?>
            <button class="mobile-sheet-link logout" type="submit">
                <span class="sheet-link-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="21" height="21"><path d="M10 5H5v14h5M14 8l4 4-4 4m4-4H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                <span><strong>Keluar dari akun</strong><small>Akhiri sesi pada perangkat ini</small></span>
            </button>
        </form>
    </div>
</section>
<?php endif; ?>

<footer class="footer">
    <div class="container footer-inner">
        <span><?= e(APP_NAME) ?></span>
        <span>&copy; <?= date('Y') ?> Ahmad Asyhari</span>
    </div>
</footer>

<?php if (is_logged_in()): ?>
<div class="modal-backdrop" id="logoutModal" hidden>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="logoutTitle" aria-describedby="logoutDescription" tabindex="-1">
        <button class="modal-close" type="button" aria-label="Tutup dialog" data-logout-close>&times;</button>
        <div class="modal-icon danger" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="28" height="28"><path d="M10 5H5v14h5M14 8l4 4-4 4m4-4H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <h2 id="logoutTitle">Keluar dari akun?</h2>
        <p id="logoutDescription" class="muted">Sesi Anda akan diakhiri. Data yang sudah disimpan tetap aman.</p>
        <form action="logout.php" method="post" class="modal-actions">
            <?= csrf_field() ?>
            <button class="btn secondary" type="button" data-logout-close>Batal</button>
            <button class="btn danger" type="submit">Ya, keluar</button>
        </form>
    </section>
</div>
<?php endif; ?>
<script src="<?= e(asset_url('assets/app.js')) ?>"></script>
</body>
</html>
