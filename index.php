<?php
require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Beranda';
$pageDescription = 'SadarBudget membantu Anda mencatat pemasukan, pengeluaran, kategori, dan laporan keuangan dalam satu aplikasi web.';
$landingLoggedIn = is_logged_in();

require __DIR__ . '/includes/header.php';
?>
<section class="landing-hero" aria-labelledby="landingTitle">
    <div class="landing-hero-copy">
        <span class="landing-kicker">Pencatatan keuangan pribadi</span>
        <h1 id="landingTitle">Pahami uang Anda tanpa membuat pencatatan terasa rumit.</h1>
        <p class="landing-lead">SadarBudget menyatukan pencatatan transaksi, kategori, laporan, dan pengelolaan data dalam ruang kerja yang jelas dan responsif.</p>

        <div class="landing-actions">
            <?php if ($landingLoggedIn): ?>
                <a class="btn primary landing-primary-action" href="dashboard.php">Buka ringkasan</a>
                <a class="btn secondary" href="transactions.php">Lihat transaksi</a>
            <?php else: ?>
                <a class="btn primary landing-primary-action" href="register.php">Mulai mencatat</a>
                <a class="btn secondary" href="login.php">Masuk ke akun</a>
            <?php endif; ?>
        </div>

        <div class="landing-assurances" aria-label="Keunggulan singkat">
            <span>
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m5 12 4 4L19 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Tanpa framework dan proses build
            </span>
            <span>
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m5 12 4 4L19 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Nyaman di desktop dan ponsel
            </span>
            <span>
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m5 12 4 4L19 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                Backup dan pemulihan dari aplikasi
            </span>
        </div>
    </div>

    <div class="landing-product-preview" aria-label="Contoh ringkasan SadarBudget">
        <div class="landing-preview-head">
            <div>
                <span class="landing-preview-label">Ringkasan keuangan</span>
                <strong>Kondisi bulan ini</strong>
            </div>
            <span class="landing-preview-status">Terkini</span>
        </div>

        <div class="landing-preview-metrics">
            <article>
                <span class="preview-icon available" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M4 7h16v11H4zM16 10h4v5h-4a2.5 2.5 0 0 1 0-5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                </span>
                <small>Uang tersedia</small>
                <strong>Terpantau</strong>
            </article>
            <article>
                <span class="preview-icon income" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 19V5m-5 5 5-5 5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <small>Pemasukan</small>
                <strong>Tercatat</strong>
            </article>
            <article>
                <span class="preview-icon expense" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="20" height="20"><path d="M12 5v14m5-5-5 5-5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                <small>Pengeluaran</small>
                <strong>Terkendali</strong>
            </article>
        </div>

        <div class="landing-preview-chart" aria-hidden="true">
            <div class="preview-chart-head"><span>Arus keuangan</span><small>6 bulan</small></div>
            <div class="preview-chart-grid">
                <span style="--bar-height: 30%"></span>
                <span style="--bar-height: 44%"></span>
                <span style="--bar-height: 38%"></span>
                <span style="--bar-height: 63%"></span>
                <span style="--bar-height: 55%"></span>
                <span style="--bar-height: 82%"></span>
            </div>
        </div>

        <div class="landing-preview-transaction">
            <span class="preview-transaction-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="18" height="18"><path d="M5 6h14M5 12h14M5 18h9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </span>
            <span><strong>Riwayat transaksi</strong><small>Filter, PDF, Excel, dan backup SQL</small></span>
            <svg class="preview-transaction-arrow" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
    </div>
</section>

<section class="landing-section" id="fitur" aria-labelledby="featuresTitle">
    <header class="landing-section-head">
        <span class="landing-kicker">Fitur utama</span>
        <h2 id="featuresTitle">Informasi penting tersedia saat Anda membutuhkannya.</h2>
        <p>Setiap bagian dirancang untuk menjawab kebutuhan pencatatan harian tanpa memenuhi layar dengan informasi yang tidak relevan.</p>
    </header>

    <div class="landing-feature-grid">
        <article class="landing-feature-card">
            <span class="landing-feature-icon available" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 7h16v11H4zM16 10h4v5h-4a2.5 2.5 0 0 1 0-5Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
            </span>
            <h3>Uang tersedia yang jelas</h3>
            <p>Lihat saldo transaksi berdasarkan pemasukan dan pengeluaran yang benar-benar dicatat.</p>
        </article>
        <article class="landing-feature-card">
            <span class="landing-feature-icon transactions" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M5 6h14M5 12h14M5 18h9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
            </span>
            <h3>Transaksi terorganisasi</h3>
            <p>Kelompokkan pemasukan dan pengeluaran berdasarkan kategori, tanggal, serta keterangan.</p>
        </article>
        <article class="landing-feature-card">
            <span class="landing-feature-icon budget" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/><path d="M3 21h18" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            </span>
            <h3>Kontrol pengeluaran</h3>
            <p>Bandingkan pengeluaran terhadap pemasukan bulanan agar kondisi uang lebih mudah dipantau.</p>
        </article>
        <article class="landing-feature-card">
            <span class="landing-feature-icon annual" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 19V9m5 10V5m5 14v-7m5 7V3" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round"/></svg>
            </span>
            <h3>Laporan tahunan</h3>
            <p>Tinjau pola pemasukan, pengeluaran, dan kategori terbesar dari Januari sampai Desember.</p>
        </article>
        <article class="landing-feature-card">
            <span class="landing-feature-icon reports" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M6 3h9l4 4v14H6zM15 3v5h5M9 13h7M9 17h7" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h3>Laporan siap digunakan</h3>
            <p>Ekspor riwayat yang sedang difilter menjadi PDF atau Excel dengan nama file yang teratur.</p>
        </article>
        <article class="landing-feature-card">
            <span class="landing-feature-icon backup" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="24" height="24"><path d="M12 3 5 6v5c0 4.6 2.8 8.4 7 10 4.2-1.6 7-5.4 7-10V6l-7-3Z" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round"/><path d="m9 12 2 2 4-5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <h3>Kontrol atas data</h3>
            <p>Ekspor, impor, atau bersihkan data keuangan pada menu Data & Backup dengan validasi dan konfirmasi.</p>
        </article>
    </div>
</section>

<section class="landing-section landing-how" id="cara-kerja" aria-labelledby="howTitle">
    <div class="landing-how-copy">
        <span class="landing-kicker">Cara menggunakan</span>
        <h2 id="howTitle">Mulai dari pencatatan sederhana, lalu lihat gambaran besarnya.</h2>
        <p>SadarBudget memisahkan aktivitas harian dari analisis agar halaman tetap fokus dan mudah dipahami.</p>
    </div>
    <ol class="landing-steps">
        <li>
            <span>01</span>
            <div><strong>Buat akun pribadi</strong><p>Kategori awal dibuat otomatis dan dapat disesuaikan kapan saja.</p></div>
        </li>
        <li>
            <span>02</span>
            <div><strong>Catat pemasukan dan pengeluaran</strong><p>Setiap transaksi tersimpan berdasarkan kategori, tanggal, nominal, dan keterangan.</p></div>
        </li>
        <li>
            <span>03</span>
            <div><strong>Tinjau ringkasan dan laporan</strong><p>Gunakan ringkasan, laporan tahunan, serta ekspor untuk memahami kondisi keuangan.</p></div>
        </li>
    </ol>
</section>

<section class="landing-section landing-data" id="data" aria-labelledby="dataTitle">
    <div class="landing-data-visual" aria-hidden="true">
        <div class="landing-data-shield">
            <svg viewBox="0 0 24 24" width="46" height="46"><path d="M12 3 5 6v5c0 4.6 2.8 8.4 7 10 4.2-1.6 7-5.4 7-10V6l-7-3Z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m9 12 2 2 4-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </div>
        <div class="landing-data-file"><span>SQL</span><strong>Backup data</strong><small>Dapat dipulihkan dari browser</small></div>
    </div>
    <div class="landing-data-copy">
        <span class="landing-kicker">Data dalam kendali Anda</span>
        <h2 id="dataTitle">Database berada pada server yang Anda kelola.</h2>
        <p>SadarBudget dapat dijalankan melalui XAMPP, Laragon, LAMP, atau hosting PHP–MySQL. Data setiap akun dipisahkan dan backup keuangan dapat diunduh tanpa membuka phpMyAdmin.</p>
        <ul>
            <li>Impor backup langsung dari menu Data & Backup.</li>
            <li>Kata sandi disimpan sebagai hash, bukan teks asli.</li>
            <li>Clean data mempertahankan akun dan profil pengguna.</li>
        </ul>
    </div>
</section>

<section class="landing-cta" aria-labelledby="ctaTitle">
    <div>
        <span class="landing-kicker">Mulai menggunakan SadarBudget</span>
        <h2 id="ctaTitle">Buat pencatatan keuangan yang lebih mudah ditinjau.</h2>
        <p>Gunakan satu aplikasi untuk transaksi harian, kategori, laporan, dan backup data.</p>
    </div>
    <div class="landing-cta-actions">
        <?php if ($landingLoggedIn): ?>
            <a class="btn primary" href="dashboard.php">Kembali ke ringkasan</a>
        <?php else: ?>
            <a class="btn primary" href="register.php">Buat akun</a>
            <a class="btn secondary" href="login.php">Masuk</a>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
