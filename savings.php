<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$pdo = db();
$userId = current_user_id();
$errors = [];
$savingsUnavailable = !empty($_SESSION['schema_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$savingsUnavailable) {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'create_goal') {
            $name = trim((string)($_POST['name'] ?? ''));
            $targetAmount = parse_rupiah_input($_POST['target_amount'] ?? '');
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
            $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);

            if ($nameLength < 2 || $nameLength > 100) {
                $errors[] = 'Nama tabungan harus terdiri dari 2–100 karakter.';
            }
            if ($targetAmount <= 0) {
                $errors[] = 'Target tabungan harus lebih dari 0.';
            }
            if ($targetDate !== '' && !valid_date($targetDate)) {
                $errors[] = 'Tanggal target tidak valid.';
            }
            if ($descriptionLength > 255) {
                $errors[] = 'Catatan tujuan maksimal 255 karakter.';
            }

            if (!$errors) {
                $check = $pdo->prepare("SELECT id FROM savings_goals WHERE user_id=? AND status='active' AND LOWER(name)=LOWER(?)");
                $check->execute([$userId, $name]);
                if ($check->fetch()) {
                    $errors[] = 'Tujuan tabungan aktif dengan nama tersebut sudah ada.';
                } else {
                    $stmt = $pdo->prepare('INSERT INTO savings_goals (user_id, name, target_amount, description, target_date) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$userId, $name, $targetAmount, $description ?: null, $targetDate ?: null]);
                    flash('success', 'Tujuan tabungan berhasil dibuat. Saldo uang belum berubah sampai Anda menambahkan setoran.');
                    redirect('savings.php');
                }
            }
        }


        if ($action === 'update_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            $targetAmount = parse_rupiah_input($_POST['target_amount'] ?? '');
            $targetDate = trim((string)($_POST['target_date'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));

            $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
            $descriptionLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);

            if ($goalId <= 0) $errors[] = 'Tujuan tabungan tidak valid.';
            if ($nameLength < 2 || $nameLength > 100) $errors[] = 'Nama tabungan harus terdiri dari 2–100 karakter.';
            if ($targetAmount <= 0) $errors[] = 'Target tabungan harus lebih dari 0.';
            if ($targetDate !== '' && !valid_date($targetDate)) $errors[] = 'Tanggal target tidak valid.';
            if ($descriptionLength > 255) $errors[] = 'Catatan tujuan maksimal 255 karakter.';

            if (!$errors) {
                $pdo->beginTransaction();

                $goalCheck = $pdo->prepare("SELECT id, name FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
                $goalCheck->execute([$goalId, $userId]);
                if (!$goalCheck->fetch()) {
                    throw new RuntimeException('Tujuan tabungan tidak ditemukan atau sudah diarsipkan.');
                }

                $currentBalance = get_savings_goal_balance($pdo, $userId, $goalId);
                if (!savings_target_covers_balance($targetAmount, $currentBalance)) {
                    throw new RuntimeException('Target tidak boleh lebih kecil daripada saldo yang sudah terkumpul. Target minimal untuk tujuan ini adalah ' . format_rupiah($currentBalance) . '.');
                }

                $check = $pdo->prepare("SELECT id FROM savings_goals WHERE user_id=? AND status='active' AND LOWER(name)=LOWER(?) AND id<>?");
                $check->execute([$userId, $name, $goalId]);
                if ($check->fetch()) {
                    throw new RuntimeException('Tujuan tabungan aktif dengan nama tersebut sudah ada.');
                }

                $stmt = $pdo->prepare("UPDATE savings_goals SET name=?, target_amount=?, description=?, target_date=? WHERE id=? AND user_id=? AND status='active'");
                $stmt->execute([$name, $targetAmount, $description ?: null, $targetDate ?: null, $goalId, $userId]);
                $pdo->commit();

                flash('success', 'Rencana tabungan berhasil diperbarui.');
                redirect('savings.php#goal-' . $goalId);
            }
        }

        if ($action === 'deposit') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $amount = parse_rupiah_input($_POST['amount'] ?? '');
            $entryDate = trim((string)($_POST['entry_date'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($amount <= 0) $errors[] = 'Nominal setoran harus lebih dari 0.';
            if (!valid_date($entryDate)) $errors[] = 'Tanggal setoran tidak valid.';
            if ((function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 255) $errors[] = 'Catatan maksimal 255 karakter.';

            if (!$errors) {
                $pdo->beginTransaction();

                $lockUser = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lockUser->execute([$userId]);

                $goal = $pdo->prepare("SELECT id, name, target_amount FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
                $goal->execute([$goalId, $userId]);
                $goalRow = $goal->fetch();
                if (!$goalRow) {
                    throw new RuntimeException('Tujuan tabungan tidak ditemukan atau sudah diarsipkan.');
                }

                $position = get_financial_position($pdo, $userId);
                $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
                $remainingTarget = savings_remaining_target($goalBalance, (float)$goalRow['target_amount']);

                if ($remainingTarget <= 0.00001) {
                    throw new RuntimeException('Target tabungan ini sudah tercapai. Naikkan target terlebih dahulu jika ingin menambah dana lagi.');
                }
                if ($amount > $remainingTarget + 0.00001) {
                    throw new RuntimeException('Setoran akan melebihi target. Maksimal setoran yang masih dibutuhkan adalah ' . format_rupiah($remainingTarget) . '.');
                }
                if ($amount > $position['available_cash'] + 0.00001) {
                    throw new RuntimeException('Setoran melebihi uang tersedia. Maksimal yang dapat dipindahkan adalah ' . format_rupiah(max(0, $position['available_cash'])) . '.');
                }

                $stmt = $pdo->prepare("INSERT INTO savings_entries (user_id, savings_goal_id, type, amount, goal_name_snapshot, note, entry_date) VALUES (?, ?, 'deposit', ?, ?, ?, ?)");
                $stmt->execute([$userId, $goalId, $amount, $goalRow['name'], $note ?: null, $entryDate]);
                $pdo->commit();

                flash('success', format_rupiah($amount) . ' berhasil dipindahkan dari uang ke tabungan “' . $goalRow['name'] . '”.');
                redirect('savings.php#goal-' . $goalId);
            }
        }

        if ($action === 'withdraw') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $amount = parse_rupiah_input($_POST['amount'] ?? '');
            $entryDate = trim((string)($_POST['entry_date'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            if ($amount <= 0) $errors[] = 'Nominal pencairan harus lebih dari 0.';
            if (!valid_date($entryDate)) $errors[] = 'Tanggal pencairan tidak valid.';
            if ((function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 255) $errors[] = 'Catatan maksimal 255 karakter.';

            if (!$errors) {
                $pdo->beginTransaction();

                $lockUser = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');
                $lockUser->execute([$userId]);

                $goal = $pdo->prepare("SELECT id, name FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
                $goal->execute([$goalId, $userId]);
                $goalRow = $goal->fetch();
                if (!$goalRow) {
                    throw new RuntimeException('Tujuan tabungan tidak ditemukan atau sudah diarsipkan.');
                }

                $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
                if ($amount > $goalBalance + 0.00001) {
                    throw new RuntimeException('Pencairan melebihi saldo tabungan. Saldo yang dapat dicairkan adalah ' . format_rupiah($goalBalance) . '.');
                }

                $stmt = $pdo->prepare("INSERT INTO savings_entries (user_id, savings_goal_id, type, amount, goal_name_snapshot, note, entry_date) VALUES (?, ?, 'withdrawal', ?, ?, ?, ?)");
                $stmt->execute([$userId, $goalId, $amount, $goalRow['name'], $note ?: null, $entryDate]);
                $pdo->commit();

                flash('success', format_rupiah($amount) . ' berhasil dicairkan dari “' . $goalRow['name'] . '” dan dikembalikan ke uang tersedia.');
                redirect('savings.php#goal-' . $goalId);
            }
        }


        if ($action === 'spend_savings') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $amount = parse_rupiah_input($_POST['amount'] ?? '');
            $entryDate = trim((string)($_POST['entry_date'] ?? ''));
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $description = trim((string)($_POST['description'] ?? ''));

            if ($amount <= 0) $errors[] = 'Nominal penggunaan dana harus lebih dari 0.';
            if (!valid_date($entryDate)) $errors[] = 'Tanggal penggunaan dana tidak valid.';
            if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) < 2) $errors[] = 'Tuliskan keterangan penggunaan dana.';
            if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) $errors[] = 'Keterangan maksimal 255 karakter.';

            $categoryCheck = $pdo->prepare("SELECT id, name FROM categories WHERE id=? AND user_id=? AND type='expense' AND is_active=1");
            $categoryCheck->execute([$categoryId, $userId]);
            $categoryRow = $categoryCheck->fetch();
            if (!$categoryRow) $errors[] = 'Kategori pengeluaran tidak valid atau sudah dinonaktifkan.';

            if (!$errors) {
                $pdo->beginTransaction();

                $goal = $pdo->prepare("SELECT id, name FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
                $goal->execute([$goalId, $userId]);
                $goalRow = $goal->fetch();
                if (!$goalRow) {
                    throw new RuntimeException('Tujuan tabungan tidak ditemukan atau sudah diarsipkan.');
                }

                $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
                if ($amount > $goalBalance + 0.00001) {
                    throw new RuntimeException('Penggunaan dana melebihi saldo tabungan. Saldo yang tersedia adalah ' . format_rupiah($goalBalance) . '.');
                }

                $stmt = $pdo->prepare("INSERT INTO savings_entries
                    (user_id, savings_goal_id, type, amount, category_id, goal_name_snapshot, category_name_snapshot, note, entry_date)
                    VALUES (?, ?, 'spend', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $userId,
                    $goalId,
                    $amount,
                    $categoryId,
                    $goalRow['name'],
                    $categoryRow['name'],
                    limit_text($description),
                    $entryDate,
                ]);
                $pdo->commit();

                flash('success', format_rupiah($amount) . ' digunakan langsung dari tabungan “' . $goalRow['name'] . '”. Aktivitas dicatat sekali sebagai pengeluaran dari tabungan.');
                redirect('savings.php#goal-' . $goalId);
            }
        }

        if ($action === 'close_goal') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $entryDate = trim((string)($_POST['entry_date'] ?? date('Y-m-d')));
            $note = trim((string)($_POST['note'] ?? ''));

            if (!valid_date($entryDate)) $errors[] = 'Tanggal penutupan tidak valid.';
            if ((function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 255) $errors[] = 'Catatan maksimal 255 karakter.';

            if (!$errors) {
                $pdo->beginTransaction();

                $goal = $pdo->prepare("SELECT id, name FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
                $goal->execute([$goalId, $userId]);
                $goalRow = $goal->fetch();
                if (!$goalRow) {
                    throw new RuntimeException('Tujuan tabungan tidak ditemukan.');
                }

                $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
                if ($goalBalance > 0.00001) {
                    $stmt = $pdo->prepare("INSERT INTO savings_entries (user_id, savings_goal_id, type, amount, goal_name_snapshot, note, entry_date) VALUES (?, ?, 'withdrawal', ?, ?, ?, ?)");
                    $closingNote = $note !== '' ? $note : 'Penutupan tujuan dan pengembalian dana ke uang';
                    $stmt->execute([$userId, $goalId, $goalBalance, $goalRow['name'], $closingNote, $entryDate]);
                }

                $stmt = $pdo->prepare("UPDATE savings_goals SET status='archived' WHERE id=? AND user_id=?");
                $stmt->execute([$goalId, $userId]);
                $pdo->commit();

                flash('success', 'Tujuan tabungan “' . $goalRow['name'] . '” ditutup. ' . ($goalBalance > 0.00001 ? format_rupiah($goalBalance) . ' telah dikembalikan ke uang.' : 'Tujuan langsung diarsipkan karena saldonya sudah Rp 0.'));
                redirect('savings.php');
            }
        }
        if ($action === 'archive') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $pdo->beginTransaction();

            $goal = $pdo->prepare("SELECT id, name FROM savings_goals WHERE id=? AND user_id=? AND status='active' FOR UPDATE");
            $goal->execute([$goalId, $userId]);
            $goalRow = $goal->fetch();
            if (!$goalRow) {
                throw new RuntimeException('Tujuan tabungan tidak ditemukan.');
            }

            $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
            if ($goalBalance > 0.00001) {
                throw new RuntimeException('Tabungan masih berisi ' . format_rupiah($goalBalance) . '. Cairkan seluruh saldo sebelum mengarsipkan tujuan.');
            }

            $stmt = $pdo->prepare("UPDATE savings_goals SET status='archived' WHERE id=? AND user_id=?");
            $stmt->execute([$goalId, $userId]);
            $pdo->commit();

            flash('success', 'Tujuan tabungan “' . $goalRow['name'] . '” telah diarsipkan.');
            redirect('savings.php');
        }

        if ($action === 'delete') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $pdo->beginTransaction();

            $goal = $pdo->prepare("SELECT id, name, status FROM savings_goals WHERE id=? AND user_id=? AND status IN ('active','archived') FOR UPDATE");
            $goal->execute([$goalId, $userId]);
            $goalRow = $goal->fetch();
            if (!$goalRow) {
                throw new RuntimeException('Tujuan tabungan tidak ditemukan atau sudah dihapus dari daftar.');
            }

            $goalBalance = get_savings_goal_balance($pdo, $userId, $goalId);
            if ($goalBalance > 0.00001) {
                throw new RuntimeException('Tabungan masih berisi ' . format_rupiah($goalBalance) . '. Tutup tujuan atau cairkan seluruh saldo terlebih dahulu.');
            }

            // Pastikan semua histori lama memiliki snapshot nama sebelum tujuan disembunyikan.
            // Ini memperbaiki baris warisan yang snapshot-nya NULL atau string kosong.
            $snapshot = savings_goal_history_name($goalRow['name'], null, $goalId);
            $repair = $pdo->prepare("UPDATE savings_entries
                SET goal_name_snapshot=?
                WHERE savings_goal_id=?
                  AND user_id=?
                  AND (goal_name_snapshot IS NULL OR TRIM(goal_name_snapshot)='')");
            $repair->execute([$snapshot, $goalId, $userId]);

            // SadarBudget mempertahankan histori savings_entries.
            // Karena FK memakai ON DELETE RESTRICT, tujuan tidak dihapus secara fisik.
            // Status "deleted" menyembunyikannya dari aplikasi tanpa merusak laporan lama.
            $stmt = $pdo->prepare("UPDATE savings_goals
                SET name=?, status='deleted', deleted_at=COALESCE(deleted_at, NOW())
                WHERE id=? AND user_id=? AND status IN ('active','archived')");
            $stmt->execute([$snapshot, $goalId, $userId]);

            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Tujuan tabungan gagal dihapus dari daftar. Silakan muat ulang halaman.');
            }

            $pdo->commit();
            flash('success', 'Tujuan tabungan “' . $goalRow['name'] . '” dihapus dari daftar. Riwayat keuangannya tetap dipertahankan untuk laporan.');
            redirect('savings.php');
        }

        if ($action === 'restore') {
            $goalId = (int)($_POST['goal_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE savings_goals SET status='active' WHERE id=? AND user_id=? AND status='archived'");
            $stmt->execute([$goalId, $userId]);
            flash('success', 'Tujuan tabungan diaktifkan kembali.');
            redirect('savings.php#goal-' . $goalId);
        }
    } catch (RuntimeException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $error->getMessage();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = 'Operasi tabungan gagal diproses. Silakan coba lagi.';
    }
}

$position = $savingsUnavailable
    ? ['net_assets' => 0.0, 'savings_balance' => 0.0, 'available_cash' => 0.0]
    : get_financial_position($pdo, $userId);

$activeGoals = [];
$archivedGoals = [];
$recentEntries = [];
$expenseCategories = [];

if (!$savingsUnavailable) {
    $goalSql = "SELECT sg.*,
        COALESCE(summary.current_amount, 0) AS current_amount,
        COALESCE(summary.activity_count, 0) AS activity_count,
        summary.last_activity
        FROM savings_goals sg
        LEFT JOIN (
            SELECT savings_goal_id,
                SUM(CASE WHEN type='deposit' THEN amount WHEN type IN ('withdrawal','spend') THEN -amount ELSE 0 END) AS current_amount,
                COUNT(*) AS activity_count,
                MAX(entry_date) AS last_activity
            FROM savings_entries
            WHERE user_id=?
            GROUP BY savings_goal_id
        ) summary ON summary.savings_goal_id=sg.id
        WHERE sg.user_id=? AND sg.status=?
        ORDER BY (sg.target_date IS NULL), sg.target_date ASC, sg.created_at DESC";

    $stmt = $pdo->prepare($goalSql);
    $stmt->execute([$userId, $userId, 'active']);
    $activeGoals = $stmt->fetchAll();

    $stmt->execute([$userId, $userId, 'archived']);
    $archivedGoals = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT se.*,
        COALESCE(
            NULLIF(TRIM(se.goal_name_snapshot), ''),
            NULLIF(TRIM(sg.name), ''),
            CONCAT('Tujuan tabungan #', se.savings_goal_id)
        ) AS goal_name
        FROM savings_entries se
        LEFT JOIN savings_goals sg
            ON sg.id=se.savings_goal_id
           AND sg.user_id=se.user_id
        WHERE se.user_id=?
        ORDER BY se.entry_date DESC, se.created_at DESC, se.id DESC
        LIMIT 20");
    $stmt->execute([$userId]);
    $recentEntries = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM categories WHERE user_id=? AND type='expense' AND is_active=1 ORDER BY name");
    $stmt->execute([$userId]);
    $expenseCategories = $stmt->fetchAll();
}

$failedAction = (string)($_POST['action'] ?? '');
$failedGoalId = (int)($_POST['goal_id'] ?? 0);

$pageTitle = 'Tabungan';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading savings-heading">
    <div>
        <span class="eyebrow">Dana terencana</span>
        <h1>Tabungan</h1>
        <p class="muted">Pisahkan sebagian uang untuk dana darurat atau tujuan tertentu. Mutasinya tetap tampil di riwayat transaksi sebagai transfer internal.</p>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert error" role="alert"><span class="alert-dot"></span><span><?= e(implode(' ', $errors)) ?></span></div>
<?php endif; ?>

<?php if ($savingsUnavailable): ?>
<section class="card savings-empty-state">
    <span class="empty-illustration" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34"><path d="M12 9v4m0 4h.01M10.3 4.5 3.5 17a2 2 0 0 0 1.8 3h13.4a2 2 0 0 0 1.8-3L13.7 4.5a2 2 0 0 0-3.4 0Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
    <h2>Database tabungan belum siap</h2>
    <p class="muted">Impor <code>schema.sql</code> melalui phpMyAdmin, lalu muat ulang halaman ini.</p>
    <a class="btn primary" href="schema.sql" download>Unduh schema.sql</a>
</section>
<?php else: ?>

<section class="savings-summary-grid" aria-label="Ringkasan tabungan">
    <article class="saving-summary-card cash">
        <span class="saving-summary-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 7h16v11H4zM16 11h4v4h-4a2 2 0 0 1 0-4Z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
        </span>
        <div><span>Uang tersedia</span><strong><?= format_rupiah($position['available_cash']) ?></strong><small>Dapat dipakai atau dipindahkan ke tabungan.</small></div>
    </article>
    <article class="saving-summary-card saved">
        <span class="saving-summary-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="22" height="22"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </span>
        <div><span>Total tabungan</span><strong><?= format_rupiah($position['savings_balance']) ?></strong><small>Dana yang dipisahkan dari uang.</small></div>
    </article>
    <article class="saving-summary-card assets">
        <span class="saving-summary-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" width="21" height="21"><path d="M4 18V9m5 9V5m5 13v-6m5 6V3M3 21h18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        </span>
        <div><span>Total dana tercatat</span><strong><?= format_rupiah($position['net_assets']) ?></strong><small>Uang tersedia ditambah seluruh tabungan.</small></div>
    </article>
</section>

<section class="savings-plan-section" aria-labelledby="savingsPlansTitle">
    <div class="section-head savings-list-head savings-plan-toolbar">
        <div>
            <span class="section-kicker">Tujuan aktif</span>
            <h2 id="savingsPlansTitle">Rencana tabungan</h2>
            <p class="muted">Pantau progres secara ringkas. Form dan pengaturan dibuka saat diperlukan.</p>
        </div>
        <div class="savings-plan-toolbar-actions">
            <span class="savings-goal-count"><?= count($activeGoals) ?> tujuan</span>
            <button class="btn saving savings-new-goal-button" type="button" data-goal-modal-open="createGoalModal">
                <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                Buat tujuan
            </button>
        </div>
    </div>

    <?php if (!$activeGoals): ?>
        <div class="card savings-empty-state savings-plan-empty">
            <span class="empty-illustration" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="35" height="35"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </span>
            <div>
                <h3>Belum ada tujuan tabungan</h3>
                <p class="muted">Buat tujuan pertama, lalu pindahkan sebagian uang secara bertahap.</p>
            </div>
            <button class="btn saving" type="button" data-goal-modal-open="createGoalModal">Buat tujuan pertama</button>
        </div>
    <?php else: ?>
        <div class="savings-goal-list savings-goal-grid">
            <?php foreach ($activeGoals as $goal):
                $currentAmount = max(0, (float)$goal['current_amount']);
                $targetAmount = (float)$goal['target_amount'];
                $progress = savings_progress($currentAmount, $targetAmount);
                $remaining = savings_remaining_target($currentAmount, $targetAmount);
                $excessAmount = max(0, $currentAmount - $targetAmount);
                $isOverfunded = $excessAmount > 0.00001;
                $isComplete = $currentAmount + 0.00001 >= $targetAmount;
            ?>
                <article class="card savings-goal-card savings-goal-card-compact <?= $isComplete ? 'is-complete' : '' ?>" id="goal-<?= (int)$goal['id'] ?>">
                    <div class="goal-card-head">
                        <div class="goal-identity">
                            <span class="goal-icon" aria-hidden="true">
                                <svg viewBox="0 0 24 24" width="23" height="23"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            </span>
                            <div>
                                <h3><?= e($goal['name']) ?></h3>
                                <p><?= e($goal['description'] ?: 'Dana terencana') ?></p>
                            </div>
                        </div>
                        <span class="badge saving-status <?= $isComplete ? 'complete' : '' ?>"><?= $isOverfunded ? 'Melebihi target' : ($isComplete ? 'Target tercapai' : 'Berjalan') ?></span>
                    </div>

                    <div class="goal-amount-row compact">
                        <div><span>Saldo</span><strong><?= format_rupiah($currentAmount) ?></strong></div>
                        <div class="goal-target"><span>Target</span><strong><?= format_rupiah($targetAmount) ?></strong></div>
                    </div>

                    <div class="saving-progress-track" role="progressbar" aria-label="Progres <?= e($goal['name']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($progress) ?>">
                        <div class="saving-progress-bar" style="width: <?= number_format($progress, 2, '.', '') ?>%"></div>
                    </div>
                    <div class="goal-progress-label"><span><?= number_format($progress, 1, ',', '.') ?>% tercapai</span><span><?= $isOverfunded ? 'Lebih ' . format_rupiah($excessAmount) : ($remaining > 0 ? 'Kurang ' . format_rupiah($remaining) : 'Target terpenuhi') ?></span></div>

                    <div class="goal-meta-grid compact">
                        <div><span>Tenggat</span><strong><?= $goal['target_date'] ? e(format_date_id($goal['target_date'])) : 'Tanpa tenggat' ?></strong></div>
                        <div><span>Aktivitas</span><strong><?= (int)$goal['activity_count'] ?> kali</strong></div>
                        <div><span>Diperbarui</span><strong><?= $goal['last_activity'] ? e(format_date_id($goal['last_activity'])) : 'Belum ada' ?></strong></div>
                    </div>

                    <?php if ($isOverfunded): ?>
                        <div class="goal-target-overfunded compact" role="status">Saldo melebihi target <?= format_rupiah($excessAmount) ?>.</div>
                    <?php endif; ?>

                    <div class="goal-card-actions">
                        <button class="btn saving" type="button" data-goal-modal-open="goalModal-<?= (int)$goal['id'] ?>" data-goal-tab-target="deposit" <?= $isComplete ? 'disabled' : '' ?>>
                            <svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                            Tambah dana
                        </button>
                        <button class="btn secondary" type="button" data-goal-modal-open="goalModal-<?= (int)$goal['id'] ?>" data-goal-tab-target="overview">
                            Kelola tujuan
                            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="m9 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<div class="goal-modal-backdrop" id="createGoalModal" data-goal-modal data-goal-modal-auto-open="<?= $errors && $failedAction === 'create_goal' ? 'true' : 'false' ?>" hidden>
    <section class="goal-modal-card goal-create-modal" role="dialog" aria-modal="true" aria-labelledby="createGoalTitle" tabindex="-1">
        <header class="goal-modal-header">
            <div>
                <span class="section-kicker">Tujuan baru</span>
                <h2 id="createGoalTitle">Buat tujuan tabungan</h2>
                <p class="muted">Tujuan baru tidak mengurangi uang sampai setoran dibuat.</p>
            </div>
            <button class="goal-modal-close" type="button" data-goal-modal-close aria-label="Tutup form tujuan">&times;</button>
        </header>
        <div class="goal-modal-body">
            <form method="post" class="form-grid goal-modal-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_goal">
                <label>Nama tujuan
                    <input type="text" name="name" maxlength="100" required placeholder="Contoh: Dana Darurat" value="<?= e($failedAction === 'create_goal' ? ($_POST['name'] ?? '') : '') ?>">
                </label>
                <label>Target dana
                    <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="target_amount" inputmode="numeric" autocomplete="off" data-currency-input data-min="1" maxlength="19" pattern="[0-9.]+" required placeholder="10.000.000" value="<?= e(format_rupiah_input($failedAction === 'create_goal' ? ($_POST['target_amount'] ?? '') : '')) ?>"></div>
                </label>
                <label>Tanggal target <small class="optional-label">opsional</small>
                    <input type="date" name="target_date" value="<?= e($failedAction === 'create_goal' ? ($_POST['target_date'] ?? '') : '') ?>">
                </label>
                <label>Catatan <small class="optional-label">opsional</small>
                    <textarea name="description" rows="3" maxlength="255" placeholder="Tujuan dan rencana penggunaan dana"><?= e($failedAction === 'create_goal' ? ($_POST['description'] ?? '') : '') ?></textarea>
                </label>
                <div class="goal-modal-footer full">
                    <button class="btn secondary" type="button" data-goal-modal-close>Batal</button>
                    <button class="btn saving" type="submit">Buat tujuan tabungan</button>
                </div>
            </form>
        </div>
    </section>
</div>

<?php foreach ($activeGoals as $goal):
    $goalId = (int)$goal['id'];
    $currentAmount = max(0, (float)$goal['current_amount']);
    $targetAmount = (float)$goal['target_amount'];
    $targetInputDisplay = format_rupiah_input($targetAmount);
    $progress = savings_progress($currentAmount, $targetAmount);
    $remaining = savings_remaining_target($currentAmount, $targetAmount);
    $excessAmount = max(0, $currentAmount - $targetAmount);
    $isOverfunded = $excessAmount > 0.00001;
    $isComplete = $currentAmount + 0.00001 >= $targetAmount;
    $depositLimit = savings_max_deposit((float)$position['available_cash'], $currentAmount, $targetAmount);
    $failedThisGoal = $errors && $failedGoalId === $goalId;
    $defaultGoalTab = match ($failedAction) {
        'deposit' => 'deposit',
        'withdraw' => 'withdraw',
        'spend_savings' => 'spend',
        'update_goal' => 'edit',
        'close_goal', 'archive', 'delete' => 'manage',
        default => 'overview',
    };
?>
<div class="goal-modal-backdrop" id="goalModal-<?= $goalId ?>" data-goal-modal data-goal-modal-auto-open="<?= $failedThisGoal ? 'true' : 'false' ?>" data-goal-default-tab="<?= e($failedThisGoal ? $defaultGoalTab : 'overview') ?>" hidden>
    <section class="goal-modal-card goal-management-modal" role="dialog" aria-modal="true" aria-labelledby="goalModalTitle-<?= $goalId ?>" tabindex="-1">
        <header class="goal-modal-header goal-management-header">
            <div class="goal-modal-title-group">
                <span class="goal-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="23" height="23"><path d="M5 10.5C5 7.5 7.7 5 11 5h2c3.3 0 6 2.4 6 5.5V17a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2v-6.5Z" fill="none" stroke="currentColor" stroke-width="2"/><path d="M9 5V3h6v2M8 12h.01M19 11h2v4h-2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                <div>
                    <span class="section-kicker">Kelola tabungan</span>
                    <h2 id="goalModalTitle-<?= $goalId ?>"><?= e($goal['name']) ?></h2>
                    <p class="muted"><?= e($goal['description'] ?: 'Dana terencana') ?></p>
                </div>
            </div>
            <button class="goal-modal-close" type="button" data-goal-modal-close aria-label="Tutup pengelolaan <?= e($goal['name']) ?>">&times;</button>
        </header>

        <div class="goal-modal-summary">
            <div><span>Saldo</span><strong><?= format_rupiah($currentAmount) ?></strong></div>
            <div><span>Target</span><strong><?= format_rupiah($targetAmount) ?></strong></div>
            <div><span>Progres</span><strong><?= number_format($progress, 1, ',', '.') ?>%</strong></div>
        </div>

        <div class="goal-modal-tabs" role="tablist" aria-label="Menu <?= e($goal['name']) ?>">
            <button type="button" role="tab" data-goal-tab="overview" aria-controls="goalPanel-<?= $goalId ?>-overview">Ringkasan</button>
            <button type="button" role="tab" data-goal-tab="deposit" aria-controls="goalPanel-<?= $goalId ?>-deposit">Tambah</button>
            <button type="button" role="tab" data-goal-tab="withdraw" aria-controls="goalPanel-<?= $goalId ?>-withdraw">Cairkan</button>
            <button type="button" role="tab" data-goal-tab="spend" aria-controls="goalPanel-<?= $goalId ?>-spend">Gunakan</button>
            <button type="button" role="tab" data-goal-tab="edit" aria-controls="goalPanel-<?= $goalId ?>-edit">Edit</button>
            <button type="button" role="tab" data-goal-tab="manage" aria-controls="goalPanel-<?= $goalId ?>-manage">Lainnya</button>
        </div>

        <div class="goal-modal-body goal-tab-panels">
            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-overview" data-goal-panel="overview" role="tabpanel">
                <div class="goal-overview-progress">
                    <div class="saving-progress-track" role="progressbar" aria-label="Progres <?= e($goal['name']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)round($progress) ?>">
                        <div class="saving-progress-bar" style="width: <?= number_format($progress, 2, '.', '') ?>%"></div>
                    </div>
                    <div class="goal-progress-label"><span><?= number_format($progress, 1, ',', '.') ?>% tercapai</span><span><?= $isOverfunded ? 'Kelebihan ' . format_rupiah($excessAmount) : ($remaining > 0 ? 'Kurang ' . format_rupiah($remaining) : 'Target terpenuhi') ?></span></div>
                </div>
                <?php if ($isOverfunded): ?>
                    <div class="goal-target-overfunded" role="status">Saldo melebihi target sebesar <strong><?= format_rupiah($excessAmount) ?></strong>. Naikkan target atau cairkan/gunakan kelebihannya.</div>
                <?php endif; ?>
                <div class="goal-meta-grid goal-modal-meta">
                    <div><span>Tenggat</span><strong><?= $goal['target_date'] ? e(format_date_id($goal['target_date'])) : 'Tanpa tenggat' ?></strong></div>
                    <div><span>Aktivitas</span><strong><?= (int)$goal['activity_count'] ?> kali</strong></div>
                    <div><span>Terakhir diperbarui</span><strong><?= $goal['last_activity'] ? e(format_date_id($goal['last_activity'])) : 'Belum ada' ?></strong></div>
                </div>
                <div class="goal-overview-actions">
                    <button class="btn saving" type="button" data-goal-tab-target="deposit" <?= $isComplete ? 'disabled' : '' ?>>Tambah dana</button>
                    <button class="btn secondary" type="button" data-goal-tab-target="withdraw" <?= $currentAmount <= 0 ? 'disabled' : '' ?>>Cairkan dana</button>
                    <button class="btn warning" type="button" data-goal-tab-target="spend" <?= $currentAmount <= 0 || !$expenseCategories ? 'disabled' : '' ?>>Gunakan dana</button>
                </div>
            </section>

            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-deposit" data-goal-panel="deposit" role="tabpanel" hidden>
                <div class="goal-panel-heading"><h3>Tambah dana</h3><p class="muted">Pindahkan uang tersedia ke tujuan ini.</p></div>
                <form method="post" class="goal-modal-form form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="deposit">
                    <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                    <label>Nominal
                        <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="amount" inputmode="numeric" autocomplete="off" data-currency-input data-min="1" data-max="<?= max(0, floor($depositLimit)) ?>" maxlength="19" pattern="[0-9.]+" placeholder="<?= e(format_rupiah_input($remaining)) ?>" value="<?= e(format_rupiah_input($failedThisGoal && $failedAction === 'deposit' ? ($_POST['amount'] ?? '') : '')) ?>" required <?= $depositLimit <= 0 ? 'disabled' : '' ?>></div>
                    </label>
                    <label>Tanggal
                        <input type="date" name="entry_date" value="<?= e($failedThisGoal && $failedAction === 'deposit' ? ($_POST['entry_date'] ?? date('Y-m-d')) : date('Y-m-d')) ?>" required>
                    </label>
                    <label class="full">Catatan <small class="optional-label">opsional</small>
                        <input type="text" name="note" maxlength="255" placeholder="Contoh: Setoran awal" value="<?= e($failedThisGoal && $failedAction === 'deposit' ? ($_POST['note'] ?? '') : '') ?>">
                    </label>
                    <button class="btn saving full" type="submit" <?= $depositLimit <= 0 ? 'disabled' : '' ?>>Pindahkan ke tabungan</button>
                    <?php if ($isComplete): ?>
                        <small class="form-hint full goal-complete-note">Target sudah tercapai. Naikkan target terlebih dahulu untuk menambah dana.</small>
                    <?php elseif ($position['available_cash'] <= 0): ?>
                        <small class="form-hint full">Uang tersedia belum mencukupi untuk setoran.</small>
                    <?php else: ?>
                        <small class="form-hint full">Batas setoran: <?= format_rupiah($depositLimit) ?>.</small>
                    <?php endif; ?>
                </form>
            </section>

            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-withdraw" data-goal-panel="withdraw" role="tabpanel" hidden>
                <div class="goal-panel-heading"><h3>Cairkan dana</h3><p class="muted">Kembalikan saldo tabungan ke uang tersedia.</p></div>
                <form method="post" class="goal-modal-form form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="withdraw">
                    <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                    <label>Nominal
                        <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="amount" inputmode="numeric" autocomplete="off" data-currency-input data-min="1" data-max="<?= max(0, floor($currentAmount)) ?>" maxlength="19" pattern="[0-9.]+" placeholder="0" value="<?= e(format_rupiah_input($failedThisGoal && $failedAction === 'withdraw' ? ($_POST['amount'] ?? '') : '')) ?>" required></div>
                    </label>
                    <label>Tanggal
                        <input type="date" name="entry_date" value="<?= e($failedThisGoal && $failedAction === 'withdraw' ? ($_POST['entry_date'] ?? date('Y-m-d')) : date('Y-m-d')) ?>" required>
                    </label>
                    <label class="full">Catatan <small class="optional-label">opsional</small>
                        <input type="text" name="note" maxlength="255" placeholder="Contoh: Keperluan darurat" value="<?= e($failedThisGoal && $failedAction === 'withdraw' ? ($_POST['note'] ?? '') : '') ?>">
                    </label>
                    <button class="btn secondary full" type="submit" <?= $currentAmount <= 0 ? 'disabled' : '' ?>>Cairkan ke uang tersedia</button>
                </form>
            </section>

            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-spend" data-goal-panel="spend" role="tabpanel" hidden>
                <div class="goal-panel-heading"><h3>Gunakan dana</h3><p class="muted">Catat pengeluaran langsung dari saldo tabungan.</p></div>
                <form method="post" class="goal-modal-form form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="spend_savings">
                    <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                    <label>Nominal
                        <div class="money-input"><span>Rp</span><input class="currency-input" type="text" name="amount" inputmode="numeric" autocomplete="off" data-currency-input data-min="1" data-max="<?= max(0, floor($currentAmount)) ?>" maxlength="19" pattern="[0-9.]+" placeholder="0" value="<?= e(format_rupiah_input($failedThisGoal && $failedAction === 'spend_savings' ? ($_POST['amount'] ?? '') : '')) ?>" required></div>
                    </label>
                    <label>Tanggal
                        <input type="date" name="entry_date" value="<?= e($failedThisGoal && $failedAction === 'spend_savings' ? ($_POST['entry_date'] ?? date('Y-m-d')) : date('Y-m-d')) ?>" required>
                    </label>
                    <label>Kategori pengeluaran
                        <select name="category_id" required>
                            <option value="">Pilih kategori</option>
                            <?php foreach ($expenseCategories as $category): ?>
                                <option value="<?= (int)$category['id'] ?>" <?= $failedThisGoal && $failedAction === 'spend_savings' && (int)($_POST['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Keterangan
                        <input type="text" name="description" maxlength="255" placeholder="Contoh: Biaya rumah sakit" value="<?= e($failedThisGoal && $failedAction === 'spend_savings' ? ($_POST['description'] ?? '') : '') ?>" required>
                    </label>
                    <button class="btn warning full" type="submit" <?= $currentAmount <= 0 || !$expenseCategories ? 'disabled' : '' ?>>Gunakan dari tabungan</button>
                    <?php if (!$expenseCategories): ?><small class="form-hint full">Tambahkan kategori pengeluaran terlebih dahulu.</small><?php endif; ?>
                </form>
            </section>

            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-edit" data-goal-panel="edit" role="tabpanel" hidden>
                <div class="goal-panel-heading"><h3>Edit rencana tabungan</h3><p class="muted">Ubah nama, target, tenggat, atau catatan.</p></div>
                <form method="post" class="goal-modal-form form-grid">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_goal">
                    <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                    <label>Nama tujuan
                        <input type="text" name="name" maxlength="100" value="<?= e($failedThisGoal && $failedAction === 'update_goal' ? ($_POST['name'] ?? $goal['name']) : $goal['name']) ?>" required>
                    </label>
                    <label>Target dana
                        <?php $editTargetValue = $failedThisGoal && $failedAction === 'update_goal' ? format_rupiah_input($_POST['target_amount'] ?? $targetInputDisplay) : $targetInputDisplay; ?>
                        <div class="money-input"><span>Rp</span><input class="currency-input" type="text" id="goal-target-<?= $goalId ?>" name="target_amount" inputmode="numeric" autocomplete="off" data-currency-input data-server-currency="<?= e($editTargetValue) ?>" data-min="<?= max(1, (int)ceil($currentAmount)) ?>" maxlength="19" pattern="[0-9.]+" value="<?= e($editTargetValue) ?>" required></div>
                        <?php if ($currentAmount > 0): ?><small class="form-hint">Target minimum <?= format_rupiah($currentAmount) ?>.</small><?php endif; ?>
                    </label>
                    <label>Tanggal target <small class="optional-label">opsional</small>
                        <input type="date" name="target_date" value="<?= e($failedThisGoal && $failedAction === 'update_goal' ? ($_POST['target_date'] ?? '') : ($goal['target_date'] ?? '')) ?>">
                    </label>
                    <label>Catatan <small class="optional-label">opsional</small>
                        <input type="text" name="description" maxlength="255" value="<?= e($failedThisGoal && $failedAction === 'update_goal' ? ($_POST['description'] ?? '') : ($goal['description'] ?? '')) ?>">
                    </label>
                    <button class="btn primary full" type="submit">Simpan perubahan</button>
                </form>
            </section>

            <section class="goal-modal-panel" id="goalPanel-<?= $goalId ?>-manage" data-goal-panel="manage" role="tabpanel" hidden>
                <div class="goal-panel-heading"><h3>Pengaturan lainnya</h3><p class="muted">Tutup, arsipkan, atau hapus tujuan dengan perlindungan konfirmasi.</p></div>
                <div class="goal-manage-stack">
                    <form method="post" class="goal-modal-form form-grid goal-close-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="close_goal">
                        <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                        <div class="goal-danger-intro full">
                            <strong>Tutup tujuan</strong>
                            <span>Sisa saldo <?= format_rupiah($currentAmount) ?> akan dikembalikan ke uang tersedia, lalu tujuan diarsipkan.</span>
                        </div>
                        <label>Tanggal
                            <input type="date" name="entry_date" value="<?= e($failedThisGoal && $failedAction === 'close_goal' ? ($_POST['entry_date'] ?? date('Y-m-d')) : date('Y-m-d')) ?>" required>
                        </label>
                        <label>Catatan <small class="optional-label">opsional</small>
                            <input type="text" name="note" maxlength="255" placeholder="Alasan penutupan" value="<?= e($failedThisGoal && $failedAction === 'close_goal' ? ($_POST['note'] ?? '') : '') ?>">
                        </label>
                        <button class="btn danger full" type="submit" onclick="return confirm('Tutup tujuan ini? Jika masih ada saldo, seluruh dana akan dikembalikan ke uang tersedia dan tujuan diarsipkan.');">Tutup tujuan dan kembalikan dana</button>
                    </form>

                    <div class="goal-management-actions modal-management-actions">
                        <form method="post" onsubmit="return confirm('Arsipkan tujuan ini? Tujuan hanya dapat diarsipkan jika saldonya sudah Rp 0.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="archive">
                            <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                            <button class="btn secondary" type="submit" <?= $currentAmount > 0.00001 ? 'disabled' : '' ?>>Arsipkan tujuan</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Hapus tujuan ini dari daftar? Jika tujuan memiliki riwayat, riwayat tetap dipertahankan untuk laporan.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="goal_id" value="<?= $goalId ?>">
                            <button class="btn danger" type="submit" <?= $currentAmount > 0.00001 ? 'disabled' : '' ?>><?= (int)$goal['activity_count'] > 0 ? 'Hapus dari daftar' : 'Hapus permanen' ?></button>
                        </form>
                    </div>
                    <p class="goal-delete-hint">
                        <?= $currentAmount > 0.00001
                            ? 'Cairkan saldo atau gunakan tombol tutup tujuan sebelum mengarsipkan maupun menghapus.'
                            : ((int)$goal['activity_count'] > 0
                                ? 'Penghapusan menyembunyikan tujuan, tetapi riwayat keuangannya tetap dipertahankan.'
                                : 'Tujuan tanpa saldo dan tanpa aktivitas dapat dihapus permanen.') ?>
                    </p>
                </div>
            </section>
        </div>
    </section>
</div>
<?php endforeach; ?>


<section class="card savings-history-card">
    <div class="section-head">
        <div>
            <span class="section-kicker">Mutasi tabungan</span>
            <h2>Riwayat aktivitas</h2>
            <p class="muted">Setoran tampil sebagai transfer ke tabungan, sedangkan pencairan tampil sebagai dana kembali ke uang.</p>
        </div>
    </div>
    <div class="table-wrap responsive-table">
        <table>
            <thead><tr><th>Tanggal</th><th>Tujuan</th><th>Aktivitas</th><th>Catatan</th><th class="text-right">Nominal</th></tr></thead>
            <tbody>
                <?php if (!$recentEntries): ?>
                    <tr><td colspan="5" class="empty">Belum ada setoran atau pencairan tabungan.</td></tr>
                <?php else: foreach ($recentEntries as $entry): ?>
                    <tr>
                        <td data-label="Tanggal"><?= e(format_date_id($entry['entry_date'])) ?></td>
                        <td data-label="Tujuan"><span class="mobile-card-title"><?= e(savings_goal_history_name($entry['goal_name'] ?? null, null, (int)($entry['savings_goal_id'] ?? 0))) ?></span><span class="mobile-card-date"><?= e(format_date_id($entry['entry_date'])) ?></span></td>
                        <td data-label="Aktivitas" class="mobile-card-side">
                            <span class="mobile-card-side-amount amount saving-entry-amount <?= e($entry['type']) ?>"><?= $entry['type'] === 'deposit' ? '+' : '-' ?><?= format_rupiah($entry['amount']) ?></span>
                            <span class="badge saving-entry <?= e($entry['type']) ?>"><?= e(savings_entry_type_label($entry['type'])) ?></span>
                        </td>
                        <td data-label="Catatan"><?= e($entry['note'] ?: '-') ?></td>
                        <td data-label="Nominal" class="text-right amount desktop-card-amount saving-entry-amount <?= e($entry['type']) ?>"><?= $entry['type'] === 'deposit' ? '+' : '-' ?><?= format_rupiah($entry['amount']) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($archivedGoals): ?>
<section class="card archived-goals-card">
    <details>
        <summary>
            <span>
                <strong>Tujuan yang diarsipkan</strong>
                <small><?= count($archivedGoals) ?> tujuan</small>
            </span>
            <span class="summary-action summary-action-archive" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="15" height="15">
                    <path d="M4 7h16v13H4zM3 4h18v3H3zM9 11h6" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linejoin="round" stroke-linecap="round"/>
                </svg>
                <span class="summary-action-closed">Lihat arsip</span>
                <span class="summary-action-open">Sembunyikan</span>
            </span>
        </summary>
        <div class="archived-goal-list">
            <?php foreach ($archivedGoals as $goal): ?>
                <div class="archived-goal-item">
                    <div><strong><?= e($goal['name']) ?></strong><span class="muted">Target <?= format_rupiah($goal['target_amount']) ?></span></div>
                    <div class="archived-goal-actions">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                            <button class="btn secondary" type="submit">Aktifkan kembali</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Hapus tujuan ini dari daftar? Riwayat keuangan tetap dipertahankan untuk laporan.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>">
                            <button class="btn text-danger" type="submit">Hapus</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </details>
</section>
<?php endif; ?>

<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
