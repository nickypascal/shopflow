<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'index.php');
}

$errors = [];
$email = '';
$redirectTo = safe_customer_redirect(
    (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Masukkan alamat email yang valid.';
    }

    if ($password === '') {
        $errors[] = 'Kata sandi wajib diisi.';
    }

    if ($errors === []) {
        $statement = $pdo->prepare(
            "SELECT id, name, email, password, role, status
             FROM users
             WHERE email = :email
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();

        if ($user && password_verify($password, (string) $user['password'])) {
            if (($user['role'] ?? 'customer') === 'admin') {
                $errors[] = 'Akun administrator tidak dapat masuk melalui halaman pengguna. Gunakan Portal Admin ShopFlow.';
            } elseif (($user['status'] ?? 'active') !== 'active') {
                $errors[] = 'Akun Anda sedang dinonaktifkan. Hubungi administrator toko.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $user['id'],
                    'name' => (string) $user['name'],
                    'email' => (string) $user['email'],
                    'role' => 'customer',
                ];
                unset($_SESSION['admin_last_activity']);

                $pdo->prepare(
                    'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $user['id']]);

                flash('success', 'Login pengguna berhasil. Selamat datang, ' . $user['name'] . '!');
                redirect($redirectTo);
            }
        } else {
            $errors[] = 'Email atau kata sandi pengguna salah.';
        }
    }
}

$pageTitle = 'Masuk ke ShopFlow';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-section">
    <div class="container auth-container">
        <div class="auth-side">
            <span class="eyebrow">Area pelanggan</span>
            <h1>Masuk untuk melanjutkan belanja.</h1>
            <p>Masuk untuk checkout, mengelola keranjang, memantau pesanan, dan menikmati seluruh layanan ShopFlow.</p>
        </div>

        <div class="auth-card">
            <h2>Masuk ke ShopFlow</h2>
            <p>Masukkan email dan kata sandi akun pelanggan Anda.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form-stack" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">

                <label>
                    <span>Email Pengguna</span>
                    <input type="email" name="email" value="<?= e($email) ?>" placeholder="pelanggan@shopflow.id" required autocomplete="email">
                </label>

                <label>
                    <span>Kata Sandi</span>
                    <input type="password" name="password" placeholder="Masukkan kata sandi akun" required autocomplete="current-password">
                </label>

                <button class="button button-primary button-full" type="submit">Masuk sebagai Pengguna</button>
            </form>

            <p class="auth-link">Belum punya akun? <a href="register.php">Daftar sekarang</a></p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
