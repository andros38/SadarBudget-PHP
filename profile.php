<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$userId = current_user_id();
$errors = [];
$action = '';

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
                auto_backup_after_financial_change($pdo, $userId, 'profil-diperbarui');
                flash('success', 'Nama dan email profil berhasil diperbarui. Snapshot baru dibuat agar backup mengikuti email terbaru.');
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
            $croppedPayload = trim((string)($_POST['profile_photo_cropped'] ?? ''));
            $upload = $_FILES['profile_photo_source'] ?? null;
            $imageBinary = null;
            $imageTmpName = '';
            $imageSize = 0;

            if ($croppedPayload !== '') {
                if (!preg_match('#^data:image/(?:jpeg|jpg|png|webp);base64,([A-Za-z0-9+/=\r\n]+)$#', $croppedPayload, $matches)) {
                    $errors[] = 'Data hasil crop tidak valid. Pilih gambar dan ulangi pemotongan.';
                } else {
                    $imageBinary = base64_decode(preg_replace('/\s+/', '', $matches[1]), true);
                    if ($imageBinary === false || $imageBinary === '') {
                        $errors[] = 'Hasil crop tidak dapat dibaca. Silakan ulangi pemotongan.';
                        $imageBinary = null;
                    } else {
                        $imageSize = strlen($imageBinary);
                    }
                }
            } elseif (!$upload || !isset($upload['error']) || $upload['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Pilih gambar dan tentukan area crop 1:1 terlebih dahulu.';
            } elseif ($upload['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Gagal mengunggah gambar. Silakan coba lagi.';
            } else {
                $imageTmpName = (string)($upload['tmp_name'] ?? '');
                $imageSize = (int)($upload['size'] ?? 0);
            }

            if (!$errors && ($imageSize <= 0 || $imageSize > 2 * 1024 * 1024)) {
                $errors[] = 'Ukuran hasil foto maksimal 2 MB.';
            }

            $extension = '';
            if (!$errors) {
                if ($imageBinary !== null) {
                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$finfo->buffer($imageBinary);
                    } else {
                        $mime = '';
                    }
                    $dimensions = @getimagesizefromstring($imageBinary);
                    if ($mime === '' && is_array($dimensions)) {
                        $mime = (string)($dimensions['mime'] ?? '');
                    }
                } else {
                    if (class_exists('finfo')) {
                        $finfo = new finfo(FILEINFO_MIME_TYPE);
                        $mime = (string)$finfo->file($imageTmpName);
                    } else {
                        $mime = function_exists('mime_content_type')
                            ? (string)mime_content_type($imageTmpName)
                            : '';
                    }
                    $dimensions = @getimagesize($imageTmpName);
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

                if (!$dimensions || $dimensions[0] < 80 || $dimensions[1] < 80) {
                    $errors[] = 'Resolusi gambar minimal 80 × 80 piksel.';
                } elseif ($dimensions[0] > 5000 || $dimensions[1] > 5000) {
                    $errors[] = 'Resolusi gambar terlalu besar. Maksimal 5000 × 5000 piksel.';
                } elseif ((int)$dimensions[0] !== (int)$dimensions[1]) {
                    $errors[] = 'Foto profil harus dipotong dengan rasio 1:1. Pilih gambar lalu tentukan area potong terlebih dahulu.';
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

                $saved = $imageBinary !== null
                    ? file_put_contents($destination, $imageBinary, LOCK_EX) !== false
                    : move_uploaded_file($imageTmpName, $destination);

                if (!$saved) {
                    throw new RuntimeException('Gambar hasil crop gagal disimpan pada server.');
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

                flash('success', 'Foto profil 1:1 berhasil diperbarui.');
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
$pageTitle = 'Profil';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading profile-page-heading">
    <div>
        <span class="eyebrow">Akun pengguna</span>
        <h1>Profil</h1>
        <p class="muted">Kelola identitas akun, foto profil, email, dan kata sandi.</p>
    </div>
    <a class="btn secondary annual-shortcut" href="annual.php">Lihat laporan tahunan</a>
</div>

<?php if ($errors): ?>
    <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
<?php endif; ?>

<div class="profile-layout">
    <aside class="card profile-identity-card">
        <div class="profile-identity-summary">
            <div class="profile-photo-large">
                <?= render_user_avatar($user, 'profile-avatar-large', '160') ?>
            </div>
            <div class="profile-identity-copy">
                <h2><?= e($user['name'] ?? 'Pengguna') ?></h2>
                <p class="muted"><?= e($user['email'] ?? '') ?></p>
                <span class="profile-member-since">Bergabung <?= e(format_month_id((string)($user['created_at'] ?? ''))) ?></span>
            </div>
        </div>

        <form method="post" enctype="multipart/form-data" class="profile-photo-form" data-profile-photo-form>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="photo">
            <input type="hidden" name="profile_photo_cropped" value="" data-profile-photo-cropped>
            <label class="profile-file-label">Ganti foto profil
                <input type="file" id="profilePhotoInput" name="profile_photo_source" accept="image/jpeg,image/png,image/webp" data-profile-photo-input>
            </label>
            <small>Pilih JPG, PNG, atau WebP. Area foto akan dipotong dengan rasio 1:1 sebelum diunggah.</small>
            <div class="profile-crop-error" data-profile-crop-error hidden role="alert"></div>
            <div class="profile-crop-ready" data-profile-crop-ready hidden role="status">
                <span aria-hidden="true">✓</span>
                <span>Potongan 1:1 sudah siap diunggah.</span>
            </div>
            <button class="btn primary full" type="submit" data-profile-photo-submit disabled>Unggah foto</button>
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

    </div>
</div>

<div class="photo-crop-modal" data-photo-crop-modal hidden>
    <section class="photo-crop-card" role="dialog" aria-modal="true" aria-labelledby="photoCropTitle" aria-describedby="photoCropDescription" tabindex="-1">
        <header class="photo-crop-header">
            <div>
                <span class="section-kicker">Foto profil</span>
                <h2 id="photoCropTitle">Tentukan area foto</h2>
                <p id="photoCropDescription" class="muted">Geser gambar dan atur pembesaran. Area di dalam kotak akan disimpan dengan rasio 1:1.</p>
            </div>
            <button class="photo-crop-close" type="button" data-photo-crop-cancel aria-label="Batalkan pemotongan">&times;</button>
        </header>
        <div class="photo-crop-body">
            <div class="photo-crop-stage" data-photo-crop-stage>
                <img alt="Pratinjau foto yang akan dipotong" data-photo-crop-image draggable="false">
                <span class="photo-crop-grid" aria-hidden="true"></span>
            </div>
            <label class="photo-crop-zoom">Pembesaran
                <input type="range" min="1" max="3" step="0.01" value="1" data-photo-crop-zoom>
            </label>
            <p class="photo-crop-help">Tarik gambar untuk mengubah posisi. Hasil akhir disimpan sebagai foto persegi 512 × 512 piksel.</p>
        </div>
        <footer class="photo-crop-actions">
            <button class="btn secondary" type="button" data-photo-crop-cancel>Batal</button>
            <button class="btn primary" type="button" data-photo-crop-apply>Gunakan potongan</button>
        </footer>
    </section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>