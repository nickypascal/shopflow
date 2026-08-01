<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$adminId = (int) $adminUser['id'];
$statement = $pdo->prepare("SELECT * FROM users WHERE id = :id AND role = 'admin'");
$statement->execute(['id' => $adminId]);
$admin = $statement->fetch();
if (!$admin) {
    flash('danger', 'Data admin tidak ditemukan.');
    redirect('logout.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = trim((string) ($_POST['action'] ?? 'profile'));

    if ($action === 'profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));

        if (strlen($name) < 3) $errors[] = 'Nama admin minimal 3 karakter.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email tidak valid.';
        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id');
        $check->execute(['email' => $email, 'id' => $adminId]);
        if ($check->fetch()) $errors[] = 'Email sudah digunakan.';

        $avatar = (string) ($admin['avatar'] ?? '');
        if ($errors === []) {
            try {
                $newAvatar = save_uploaded_image($_FILES['avatar_file'] ?? [], 'avatars', 'admin-avatar');
                if ($newAvatar !== null) {
                    if (str_starts_with($avatar, 'uploads/avatars/')) {
                        $oldFile = __DIR__ . '/../' . $avatar;
                        if (is_file($oldFile)) @unlink($oldFile);
                    }
                    $avatar = $newAvatar;
                }

                $pdo->prepare(
                    'UPDATE users SET name=:name, email=:email, phone=:phone, avatar=:avatar, updated_at=NOW() WHERE id=:id'
                )->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone ?: null,
                    'avatar' => $avatar ?: null,
                    'id' => $adminId,
                ]);
                $_SESSION['user']['name'] = $name;
                $_SESSION['user']['email'] = $email;
                log_admin_activity($pdo, 'profile_update', 'Memperbarui profil admin.');
                flash('success', 'Profil admin berhasil diperbarui.');
                redirect('profile.php');
            } catch (Throwable $exception) {
                $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'Profil gagal diperbarui.';
            }
        }

        $admin['name'] = $name;
        $admin['email'] = $email;
        $admin['phone'] = $phone;
        $admin['avatar'] = $avatar;
    } elseif ($action === 'password') {
        $oldPassword = (string) ($_POST['old_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmation = (string) ($_POST['confirm_password'] ?? '');

        if (!password_verify($oldPassword, (string) $admin['password'])) $errors[] = 'Password lama tidak sesuai.';
        if (strlen($newPassword) < 8) $errors[] = 'Password baru minimal 8 karakter.';
        if ($newPassword !== $confirmation) $errors[] = 'Konfirmasi password baru tidak sama.';

        if ($errors === []) {
            $pdo->prepare('UPDATE users SET password=:password, updated_at=NOW() WHERE id=:id')->execute([
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $adminId,
            ]);
            log_admin_activity($pdo, 'password_update', 'Mengubah password admin.');
            flash('success', 'Password berhasil diubah.');
            redirect('profile.php');
        }
    }
}

$pageTitle = 'Profil Admin';
$activeMenu = 'profile';
require __DIR__ . '/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1>Profil Admin</h1><p>Kelola identitas akun dan keamanan password administrator.</p></div></div>
<?php if ($errors): ?><ul class="admin-error-list"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul><?php endif; ?>
<div class="admin-grid admin-grid-2" style="align-items:start">
    <section class="admin-card">
        <div class="admin-card-head"><h2>Informasi Profil</h2></div>
        <form class="admin-form-stack" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="profile">
            <div class="admin-image-editor">
                <img class="admin-image-preview" data-image-preview src="<?= !empty($admin['avatar']) ? '../' . e((string) $admin['avatar']) : '../assets/images/bag.svg' ?>" alt="Foto admin">
                <label class="admin-field"><span>Foto profil</span><input class="admin-input" data-image-input type="file" name="avatar_file" accept="image/jpeg,image/png,image/webp,image/gif"><small>Maksimal 2 MB.</small></label>
            </div>
            <label class="admin-field"><span>Nama admin</span><input class="admin-input" type="text" name="name" value="<?= e((string) $admin['name']) ?>" required></label>
            <label class="admin-field"><span>Email</span><input class="admin-input" type="email" name="email" value="<?= e((string) $admin['email']) ?>" required></label>
            <label class="admin-field"><span>Nomor telepon</span><input class="admin-input" type="text" name="phone" value="<?= e((string) ($admin['phone'] ?? '')) ?>"></label>
            <div class="admin-detail-grid"><div class="admin-detail-item"><span>Login terakhir</span><strong><?= format_datetime($admin['last_login_at']) ?></strong></div><div class="admin-detail-item"><span>Akun dibuat</span><strong><?= format_datetime((string) $admin['created_at']) ?></strong></div></div>
            <button class="admin-button admin-button-primary" type="submit">Simpan Profil</button>
        </form>
    </section>

    <section class="admin-card">
        <div class="admin-card-head"><h2>Ubah Password</h2></div>
        <form class="admin-form-stack" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="password">
            <label class="admin-field"><span>Password lama</span><input class="admin-input" type="password" name="old_password" required autocomplete="current-password"></label>
            <label class="admin-field"><span>Password baru</span><input class="admin-input" type="password" name="new_password" minlength="8" required autocomplete="new-password"></label>
            <label class="admin-field"><span>Konfirmasi password baru</span><input class="admin-input" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label>
            <button class="admin-button admin-button-primary" type="submit">Ubah Password</button>
        </form>
    </section>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
