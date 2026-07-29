<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$userId = current_user_id();
$errors = [];

function delete_profile_photo_file(?string $relativePath): void
{
    $relativePath = trim((string)$relativePath);
    if ($relativePath === '' || !preg_match('#^uploads/profiles/[A-Za-z0-9._-]+$#', $relativePath)) {
        return;
    }

    $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'details');

    try {
        if ($action === 'details') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = strtolower(trim((string)($_POST['email'] ?? '')));

            if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) < 2) {
                $errors[] = 'Nama minimal 2 karakter.';
            }
            if ((function_exists('mb_strlen') ? mb_strlen($name) : strlen($name)) > 100) {
                $errors[] = 'Nama maksimal 100 karakter.';
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Format email tidak valid.';
            }

            if (!$errors) {
                $check = $pdo->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
                $check->execute([$email, $userId]);
                if ($check->fetch()) {
                    $errors[] = 'Email tersebut sudah digunakan akun lain.';
                }
            }

            if (!$errors) {
                $stmt = $pdo->prepare('UPDATE users SET name=?, email=? WHERE id=?');
                $stmt->execute([$name, $email, $userId]);
                $_SESSION['user_name'] = $name;
                flash('success', 'Nama dan email profil berhasil diperbarui.');
                redirect('profile.php');
            }
        }

        if ($action === 'password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $confirmPassword = (string)($_POST['confirm_password'] ?? '');
            $user = current_user_record($pdo);

            if (!password_verify($currentPassword, (string)($user['password'] ?? ''))) {
                $errors[] = 'Kata sandi saat ini tidak sesuai.';
            }
            if (strlen($newPassword) < 8) {
                $errors[] = 'Kata sandi baru minimal 8 karakter.';
            }
            if ($newPassword !== $confirmPassword) {
                $errors[] = 'Konfirmasi kata sandi baru tidak sama.';
            }
            if ($currentPassword !== '' && $currentPassword === $newPassword) {
                $errors[] = 'Kata sandi baru harus berbeda dari kata sandi saat ini.';
            }

            if (!$errors) {
                $stmt = $pdo->prepare('UPDATE users SET password=?, password_changed_at=NOW() WHERE id=?');
                $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
                session_regenerate_id(true);
                flash('success', 'Kata sandi berhasil diganti.');
                redirect('profile.php#security');
            }
        }

        if ($action === 'photo') {
            $upload = $_FILES['profile_photo'] ?? null;
            if (!$upload || !isset($upload['error']) || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Pilih gambar profil terlebih dahulu.';
            } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Gagal mengunggah gambar. Silakan coba lagi.';
            } elseif ((int)$upload['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Ukuran gambar maksimal 2 MB.';
            }

            $extension = '';
            if (!$errors) {
                if (class_exists('finfo')) {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = (string)$finfo->file((string)$upload['tmp_name']);
                } else {
                    $mime = function_exists('mime_content_type')
                        ? (string)mime_content_type((string)$upload['tmp_name'])
                        : '';
                }
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/webp' => 'webp',
                ];
                if (!isset($allowed[$mime])) {
                    $errors[] = 'Format gambar harus JPG, PNG, atau WebP.';
                } else {
                    $extension = $allowed[$mime];
                }

                $dimensions = @getimagesize((string)$upload['tmp_name']);
                if (!$dimensions || $dimensions[0] < 80 || $dimensions[1] < 80) {
                    $errors[] = 'Resolusi gambar minimal 80 × 80 piksel.';
                } elseif ($dimensions[0] > 5000 || $dimensions[1] > 5000) {
                    $errors[] = 'Resolusi gambar terlalu besar. Maksimal 5000 × 5000 piksel.';
                }
            }

            if (!$errors) {
                $uploadDirectory = __DIR__ . '/uploads/profiles';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Folder foto profil tidak dapat dibuat.');
                }
                if (!is_writable($uploadDirectory)) {
                    throw new RuntimeException('Folder uploads/profiles tidak dapat ditulisi oleh PHP.');
                }

                $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(10)) . '.' . $extension;
                $relativePath = 'uploads/profiles/' . $filename;
                $destination = $uploadDirectory . '/' . $filename;

                if (!move_uploaded_file((string)$upload['tmp_name'], $destination)) {
                    throw new RuntimeException('Gambar gagal disimpan pada server.');
                }

                $user = current_user_record($pdo);
                try {
                    $stmt = $pdo->prepare('UPDATE users SET profile_photo=? WHERE id=?');
                    $stmt->execute([$relativePath, $userId]);
                } catch (Throwable $databaseError) {
                    @unlink($destination);
                    throw $databaseError;
                }
                delete_profile_photo_file($user['profile_photo'] ?? null);

                flash('success', 'Foto profil berhasil diperbarui.');
                redirect('profile.php');
            }
        }

        if ($action === 'remove_photo') {
            $user = current_user_record($pdo);
            $stmt = $pdo->prepare('UPDATE users SET profile_photo=NULL WHERE id=?');
            $stmt->execute([$userId]);
            delete_profile_photo_file($user['profile_photo'] ?? null);
            flash('success', 'Foto profil dihapus. Avatar kembali menggunakan inisial nama.');
            redirect('profile.php');
        }


        if ($action === 'clean_data') {
            $currentPassword = (string)($_POST['clean_password'] ?? '');
            $confirmation = trim((string)($_POST['clean_confirmation'] ?? ''));
            $acknowledged = isset($_POST['clean_acknowledge']);
            $user = current_user_record($pdo);

            if (!password_verify($currentPassword, (string)($user['password'] ?? ''))) {
                $errors[] = 'Pembersihan data dibatalkan karena kata sandi tidak sesuai.';
            }
            if ($confirmation !== 'BERSIHKAN DATA') {
                $errors[] = 'Ketik BERSIHKAN DATA secara tepat untuk melanjutkan.';
            }
            if (!$acknowledged) {
                $errors[] = 'Anda harus menyetujui bahwa data keuangan akan dihapus permanen.';
            }

            if (!$errors) {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare('DELETE FROM savings_entries WHERE user_id=?');
                $stmt->execute([$userId]);
                $stmt = $pdo->prepare('DELETE FROM savings_goals WHERE user_id=?');
                $stmt->execute([$userId]);
                $stmt = $pdo->prepare('DELETE FROM transactions WHERE user_id=?');
                $stmt->execute([$userId]);
                $stmt = $pdo->prepare('DELETE FROM categories WHERE user_id=?');
                $stmt->execute([$userId]);

                $defaults = [
                    ['Gaji', 'income'], ['Bonus', 'income'], ['Penjualan', 'income'], ['Lainnya', 'income'],
                    ['Makan', 'expense'], ['Transport', 'expense'], ['Belanja', 'expense'], ['Tagihan', 'expense'],
                    ['Hiburan', 'expense'], ['Kesehatan', 'expense'], ['Lainnya', 'expense'],
                ];
                $categoryInsert = $pdo->prepare('INSERT INTO categories (user_id, name, type, is_active) VALUES (?, ?, ?, 1)');
                foreach ($defaults as [$categoryName, $categoryType]) {
                    $categoryInsert->execute([$userId, $categoryName, $categoryType]);
                }

                $pdo->commit();
                flash('success', 'Semua data keuangan berhasil dibersihkan. Akun, profil, foto, email, dan kata sandi tetap dipertahankan.');
                redirect('profile.php#data-management');
            }
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors[] = $error instanceof RuntimeException
            ? $error->getMessage()
            : 'Perubahan profil gagal disimpan. Silakan coba lagi.';
    }
}

$user = current_user_record($pdo);
$dataCountStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM transactions WHERE user_id=?) AS transaction_count,
    (SELECT COUNT(*) FROM savings_goals WHERE user_id=?) AS goal_count,
    (SELECT COUNT(*) FROM savings_entries WHERE user_id=?) AS saving_entry_count,
    (SELECT COUNT(*) FROM categories WHERE user_id=?) AS category_count");
$dataCountStmt->execute([$userId, $userId, $userId, $userId]);
$dataCounts = $dataCountStmt->fetch() ?: [
    'transaction_count' => 0,
    'goal_count' => 0,
    'saving_entry_count' => 0,
    'category_count' => 0,
];
$pageTitle = 'Profil';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading profile-page-heading">
    <div>
        <span class="eyebrow">Akun pengguna</span>
        <h1>Profil</h1>
        <p class="muted">Kelola identitas akun, foto profil, email, dan kata sandi.</p>
    </div>
    <a class="btn secondary annual-shortcut" href="annual.php">Lihat rekapan tahunan</a>
</div>

<?php if ($errors): ?>
    <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
<?php endif; ?>

<div class="profile-layout">
    <aside class="card profile-identity-card">
        <div class="profile-photo-large">
            <?= render_user_avatar($user, 'profile-avatar-large', '160') ?>
        </div>
        <h2><?= e($user['name'] ?? 'Pengguna') ?></h2>
        <p class="muted"><?= e($user['email'] ?? '') ?></p>
        <span class="profile-member-since">Bergabung <?= e(format_month_id((string)($user['created_at'] ?? ''))) ?></span>

        <form method="post" enctype="multipart/form-data" class="profile-photo-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="photo">
            <label class="profile-file-label">Ganti foto profil
                <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" required>
            </label>
            <small>JPG, PNG, atau WebP. Maksimal 2 MB.</small>
            <button class="btn primary full" type="submit">Unggah foto</button>
        </form>

        <?php if (profile_photo_url($user['profile_photo'] ?? null) !== null): ?>
            <form method="post" onsubmit="return confirm('Hapus foto profil saat ini?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_photo">
                <button class="btn text-danger full" type="submit">Hapus foto profil</button>
            </form>
        <?php endif; ?>
    </aside>

    <div class="profile-content-stack">
        <section class="card profile-form-card">
            <div class="section-head compact">
                <div>
                    <span class="section-kicker">Informasi akun</span>
                    <h2>Nama dan email</h2>
                    <p class="muted">Informasi ini digunakan untuk mengenali akun Anda.</p>
                </div>
            </div>
            <form method="post" class="form-grid two-col compact-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="details">
                <label>Nama
                    <input type="text" name="name" maxlength="100" value="<?= e(($_POST['action'] ?? '') === 'details' ? ($_POST['name'] ?? '') : ($user['name'] ?? '')) ?>" required>
                </label>
                <label>Email
                    <input type="email" name="email" maxlength="190" value="<?= e(($_POST['action'] ?? '') === 'details' ? ($_POST['email'] ?? '') : ($user['email'] ?? '')) ?>" required>
                </label>
                <div class="full actions">
                    <button class="btn primary" type="submit">Simpan profil</button>
                </div>
            </form>
        </section>

        <section class="card profile-form-card" id="security">
            <div class="section-head compact">
                <div>
                    <span class="section-kicker">Keamanan akun</span>
                    <h2>Kata sandi</h2>
                    <p class="muted">Kata sandi saat ini tidak pernah ditampilkan. Anda hanya dapat menggantinya setelah verifikasi.</p>
                </div>
                <span class="password-status">••••••••</span>
            </div>
            <div class="password-meta">
                <span>Terakhir diganti</span>
                <strong><?= !empty($user['password_changed_at']) ? e(format_date_id((string)$user['password_changed_at'])) : 'Belum pernah diganti' ?></strong>
            </div>
            <form method="post" class="form-grid password-grid compact-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <label>Kata sandi saat ini
                    <input type="password" name="current_password" autocomplete="current-password" required>
                </label>
                <label>Kata sandi baru
                    <input type="password" name="new_password" minlength="8" autocomplete="new-password" required>
                </label>
                <label>Konfirmasi kata sandi baru
                    <input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required>
                </label>
                <div class="full actions">
                    <button class="btn primary" type="submit">Ganti kata sandi</button>
                </div>
            </form>
        </section>

        <section class="card profile-form-card data-management-card" id="data-management">
            <div class="section-head compact">
                <div>
                    <span class="section-kicker">Data dan privasi</span>
                    <h2>Backup, pulihkan, dan bersihkan data</h2>
                    <p class="muted">Unduh atau pulihkan backup langsung dari browser. Fitur ini dapat digunakan melalui desktop, Android, iPhone, dan iPad tanpa membuka phpMyAdmin.</p>
                </div>
            </div>

            <div class="data-count-grid" aria-label="Jumlah data akun">
                <div><strong><?= (int)$dataCounts['transaction_count'] ?></strong><span>Transaksi</span></div>
                <div><strong><?= (int)$dataCounts['goal_count'] ?></strong><span>Tujuan</span></div>
                <div><strong><?= (int)$dataCounts['saving_entry_count'] ?></strong><span>Aktivitas tabungan</span></div>
                <div><strong><?= (int)$dataCounts['category_count'] ?></strong><span>Kategori</span></div>
            </div>

            <div class="data-action-grid">
                <article class="data-action-panel backup-panel">
                    <div class="data-action-head">
                        <span class="data-action-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="23" height="23"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <div><h3>Ekspor backup SQL</h3><p>Simpan transaksi, kategori, tujuan, dan aktivitas tabungan dalam satu berkas <code>.sql</code>.</p></div>
                    </div>
                    <form method="post" action="exports/data_sql.php" class="data-action-form">
                        <?= csrf_field() ?>
                        <label>Kata sandi saat ini
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </label>
                        <button class="btn backup full" type="submit">Unduh backup SQL</button>
                    </form>
                    <small>Backup format baru tetap dapat diimpor secara manual, tetapi pemulihan melalui web lebih aman karena SQL unggahan tidak dieksekusi secara langsung.</small>
                </article>

                <article class="data-action-panel import-panel">
                    <div class="data-action-head">
                        <span class="data-action-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="23" height="23"><path d="M12 20V9m0 0-4 4m4-4 4 4M5 5h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <div><h3>Impor backup SQL</h3><p>Pulihkan berkas <code>.sql</code> hasil ekspor SadarBudget langsung ke akun ini.</p></div>
                    </div>
                    <form method="post" action="imports/data_sql.php" enctype="multipart/form-data" class="data-action-form" onsubmit="return confirm('Data keuangan saat ini akan diganti oleh isi backup. Lanjutkan impor?');">
                        <?= csrf_field() ?>
                        <label>Berkas backup SQL
                            <input type="file" name="backup_sql" accept=".sql,text/plain,application/sql" required>
                        </label>
                        <label>Kata sandi saat ini
                            <input type="password" name="current_password" autocomplete="current-password" required>
                        </label>
                        <label>Ketik <strong>IMPOR DATA</strong>
                            <input type="text" name="import_confirmation" autocomplete="off" required>
                        </label>
                        <label class="import-confirmation-check">
                            <input type="checkbox" name="import_acknowledge" value="1" required>
                            <span>Saya memahami bahwa transaksi, kategori, dan tabungan saat ini akan diganti oleh data dari backup.</span>
                        </label>
                        <button class="btn import-data full" type="submit">Impor dan pulihkan data</button>
                    </form>
                    <small>Mendukung backup SadarBudget. Email di dalam backup harus sama dengan email akun yang sedang login. Maksimal 10 MB.</small>
                </article>

                <article class="data-action-panel clean-panel">
                    <div class="data-action-head">
                        <span class="data-action-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" width="23" height="23"><path d="M4 7h16M9 7V4h6v3m-8 0 1 13h8l1-13M10 11v5m4-5v5" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </span>
                        <div><h3>Bersihkan data keuangan</h3><p>Hapus seluruh transaksi, tabungan, dan kategori. Akun serta profil tidak ikut dihapus.</p></div>
                    </div>
                    <form method="post" class="data-action-form" onsubmit="return confirm('Semua data keuangan akan dihapus permanen. Lanjutkan?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="clean_data">
                        <label>Kata sandi saat ini
                            <input type="password" name="clean_password" autocomplete="current-password" required>
                        </label>
                        <label>Ketik <strong>BERSIHKAN DATA</strong>
                            <input type="text" name="clean_confirmation" autocomplete="off" required>
                        </label>
                        <label class="clean-confirmation-check">
                            <input type="checkbox" name="clean_acknowledge" value="1" required>
                            <span>Saya memahami bahwa data yang belum dibackup tidak dapat dipulihkan.</span>
                        </label>
                        <button class="btn danger full" type="submit">Bersihkan semua data keuangan</button>
                    </form>
                </article>
            </div>
        </section>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
