<?php
$pageTitle = $pageTitle ?? APP_NAME;
$current = basename($_SERVER['PHP_SELF']);
$headerUser = is_logged_in() ? current_user_record(db()) : [];
$userName = trim((string)($headerUser['name'] ?? $_SESSION['user_name'] ?? 'Pengguna'));
$userInitial = user_initial($userName);
$isAuthPage = in_array($current, ['login.php', 'register.php'], true);
$isLandingPage = $current === 'index.php';
$pageDescription = $pageDescription ?? 'SadarBudget adalah aplikasi pencatatan keuangan pribadi berbasis web.';
$pageBodyClass = $pageBodyClass ?? ('page-' . preg_replace('/[^a-z0-9-]+/', '-', strtolower((string)pathinfo($current, PATHINFO_FILENAME))));
?>
<!doctype html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta id="themeColor" name="theme-color" content="#f5f7fb">
    <title><?= e($pageTitle) ?> - <?= e(APP_NAME) ?></title>
    <meta name="description" content="<?= e($pageDescription) ?>">
    <script>
    (function () {
        'use strict';
        var storageKey = 'sadarbudget-theme';
        var legacyKey = 'keuanganku-theme';
        var selectedTheme = 'light';

        try {
            var storedTheme = window.localStorage.getItem(storageKey);
            if (storedTheme !== 'light' && storedTheme !== 'dark') {
                storedTheme = window.localStorage.getItem(legacyKey);
                if (storedTheme === 'light' || storedTheme === 'dark') {
                    window.localStorage.setItem(storageKey, storedTheme);
                }
            }

            if (storedTheme === 'light' || storedTheme === 'dark') {
                selectedTheme = storedTheme;
            } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                selectedTheme = 'dark';
            }
        } catch (error) {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                selectedTheme = 'dark';
            }
        }

        document.documentElement.setAttribute('data-theme', selectedTheme);
        document.documentElement.style.colorScheme = selectedTheme;
        var themeMeta = document.getElementById('themeColor');
        if (themeMeta) {
            themeMeta.setAttribute('content', selectedTheme === 'dark' ? '#0b1120' : '#f5f7fb');
        }
    }());
    </script>
    <link rel="icon" type="image/svg+xml" href="<?= e(asset_url('assets/favicon.svg')) ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?= e(asset_url('assets/favicon-32.png')) ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?= e(asset_url('assets/apple-touch-icon.png')) ?>">
    <link rel="stylesheet" href="<?= e(asset_url('assets/style.css')) ?>">
</head>
<body class="<?= $isAuthPage ? 'auth-page' : ($isLandingPage ? 'landing-page' : 'app-page') ?> <?= e($pageBodyClass) ?>">
<a class="skip-link" href="#main-content">Lewati ke konten utama</a>
<header class="topbar">
    <div class="container nav-wrap">
        <a class="brand" href="<?= is_logged_in() ? 'dashboard.php' : 'index.php' ?>" aria-label="Beranda <?= e(APP_NAME) ?>">
            <span class="brand-mark" aria-hidden="true">
                <picture>
                    <source srcset="<?= e(asset_url('assets/sadarbudget-logo.svg')) ?>" type="image/svg+xml">
                    <img src="<?= e(asset_url('assets/sadarbudget-logo.png')) ?>" alt="" width="42" height="42">
                </picture>
            </span>
            <span class="brand-name">Sadar<span>Budget</span></span>
        </a>

        <?php if (is_logged_in()): ?>
            <nav class="nav-links" id="primary-navigation" aria-label="Navigasi utama">
                <div class="nav-main">
                    <a class="<?= $current === 'dashboard.php' ? 'active' : '' ?>" href="dashboard.php">Ringkasan</a>
                    <a class="<?= in_array($current, ['transactions.php', 'edit_transaction.php', 'delete_transaction.php'], true) ? 'active' : '' ?>" href="transactions.php">Riwayat</a>
                    <a class="<?= $current === 'annual.php' ? 'active' : '' ?>" href="annual.php">Laporan</a>
                    <a class="<?= $current === 'categories.php' ? 'active' : '' ?>" href="categories.php">Kategori</a>
                    <a class="data-link <?= $current === 'data.php' ? 'active' : '' ?>" href="data.php"><span class="nav-label-wide">Data &amp; Backup</span><span class="nav-label-compact">Data</span></a>
                    <details class="nav-create-menu <?= in_array($current, ['add_income.php', 'add_expense.php'], true) ? 'is-active' : '' ?>" data-desktop-create-menu>
                        <summary aria-label="Buka menu pencatatan transaksi">
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            <span>Catat</span>
                            <svg class="nav-create-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="m7 9 5 5 5-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </summary>
                        <div class="nav-create-popover" role="menu">
                            <a class="nav-create-option income <?= $current === 'add_income.php' ? 'active' : '' ?>" href="add_income.php" role="menuitem">
                                <span class="nav-create-option-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="19" height="19"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M17 8.5v4m-2-2h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </span>
                                <span><strong>Catat pemasukan</strong><small>Tambahkan uang yang diterima</small></span>
                            </a>
                            <a class="nav-create-option expense <?= $current === 'add_expense.php' ? 'active' : '' ?>" href="add_expense.php" role="menuitem">
                                <span class="nav-create-option-icon" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" width="19" height="19"><rect x="3" y="5.5" width="18" height="13" rx="2.2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="11" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M15 10.5h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                                </span>
                                <span><strong>Catat pengeluaran</strong><small>Tambahkan pengeluaran transaksi</small></span>
                            </a>
                        </div>
                    </details>
                </div>

                <div class="account-menu">
                    <a class="user-chip <?= $current === 'profile.php' ? 'active' : '' ?>" href="profile.php" title="Buka profil <?= e($userName) ?>">
                        <?= render_user_avatar($headerUser, 'user-avatar', '38') ?>
                        <span class="user-name"><?= e($userName) ?></span>
                    </a>
                    <form class="logout-inline-form" action="logout.php" method="post" data-logout-form>
                        <?= csrf_field() ?>
                        <button class="logout-trigger" type="submit">
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M10 5H5v14h5M14 8l4 4-4 4m4-4H9" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                            <span>Keluar</span>
                        </button>
                    </form>
                </div>
            </nav>
        <?php elseif ($isLandingPage): ?>
            <nav class="nav-links public-nav" id="primary-navigation" aria-label="Navigasi halaman utama">
                <div class="nav-main public-nav-main">
                    <a href="#fitur">Fitur</a>
                    <a href="#cara-kerja">Cara kerja</a>
                    <a href="#data">Data</a>
                </div>
                <div class="public-nav-actions">
                    <a class="public-login-link" href="login.php">Masuk</a>
                    <a class="btn primary public-register-link" href="register.php">Buat akun</a>
                </div>
            </nav>
        <?php endif; ?>

        <div class="header-actions">
            <button class="theme-toggle" type="button" data-theme-toggle aria-label="Aktifkan tema gelap" title="Aktifkan tema gelap">
                <svg class="theme-icon theme-icon-moon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                    <path d="M20.4 15.2A8.4 8.4 0 0 1 8.8 3.6 8.5 8.5 0 1 0 20.4 15.2Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <svg class="theme-icon theme-icon-sun" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                    <circle cx="12" cy="12" r="4" fill="none" stroke="currentColor" stroke-width="2"/>
                    <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span class="sr-only" data-theme-label>Ganti tema</span>
            </button>

            <?php if (is_logged_in() || $isLandingPage): ?>
                <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="primary-navigation" aria-label="Buka menu navigasi">
                    <span></span><span></span><span></span>
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>
<main class="container page-shell<?= $isAuthPage ? ' auth-page-shell' : ($isLandingPage ? ' landing-page-shell' : '') ?>" id="main-content">
<?php if (!$isAuthPage && ($msg = flash('success'))): ?>
    <div class="alert success" role="status"><span class="alert-dot"></span><span><?= e($msg) ?></span></div>
<?php endif; ?>
<?php if (!$isAuthPage && ($msg = flash('warning'))): ?>
    <div class="alert warning" role="alert"><span class="alert-dot"></span><span><?= e($msg) ?></span></div>
<?php endif; ?>
<?php if (!$isAuthPage && ($msg = flash('error'))): ?>
    <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e($msg) ?></span></div>
<?php endif; ?>
<?php if (!$isAuthPage && !empty($_SESSION['schema_error'])): ?>
    <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e($_SESSION['schema_error']) ?> Periksa koneksi pada config.php atau jalankan instalasi ulang dengan database kosong.</span></div>
<?php endif; ?>
