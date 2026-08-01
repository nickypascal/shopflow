<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (is_logged_in()) {
    if (is_admin()) {
        redirect('index.php');
    }

    flash('warning', 'Anda masih login sebagai pengguna. Keluar dari akun pengguna sebelum masuk sebagai admin.');
    redirect('../index.php');
}

$errors = [];
$email = '';
$redirectTo = (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? 'index.php');
$allowedRedirects = [
    'index.php',
    'orders.php',
    'products.php',
    'categories.php',
    'stock.php',
    'customers.php',
    'reports.php',
    'notifications.php',
    'activities.php',
    'settings.php',
    'profile.php',
];
if (!in_array($redirectTo, $allowedRedirects, true)) {
    $redirectTo = 'index.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Masukkan alamat email admin yang valid.';
    }

    if ($password === '') {
        $errors[] = 'Kata sandi admin wajib diisi.';
    }

    if ($errors === []) {
        $statement = $pdo->prepare(
            "SELECT id, name, email, password, role, status
             FROM users
             WHERE email = :email
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $admin = $statement->fetch();

        if ($admin && password_verify($password, (string) $admin['password'])) {
            if (($admin['role'] ?? 'customer') !== 'admin') {
                $errors[] = 'Akun pengguna tidak dapat masuk ke Dashboard Admin.';
            } elseif (($admin['status'] ?? 'active') !== 'active') {
                $errors[] = 'Akun administrator sedang dinonaktifkan.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id' => (int) $admin['id'],
                    'name' => (string) $admin['name'],
                    'email' => (string) $admin['email'],
                    'role' => 'admin',
                ];
                $_SESSION['admin_last_activity'] = time();

                $pdo->prepare(
                    'UPDATE users SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id'
                )->execute(['id' => $admin['id']]);

                try {
                    log_admin_activity(
                        $pdo,
                        'admin_login',
                        'Administrator masuk melalui halaman login admin ShopFlow.',
                        (int) $admin['id']
                    );
                } catch (Throwable) {
                    // Login tetap dilanjutkan apabila tabel log belum tersedia.
                }

                flash('success', 'Login admin berhasil. Selamat datang, ' . $admin['name'] . '!');
                redirect($redirectTo);
            }
        } else {
            $errors[] = 'Email atau kata sandi admin salah.';
        }
    }
}

$flashMessage = get_flash();
$storeName = setting($pdo, 'store_name', 'ShopFlow');
$storeLogo = setting($pdo, 'store_logo', '');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Halaman login administrator untuk mengelola <?= e($storeName) ?>">
    <title>Masuk Administrator | <?= e($storeName) ?></title>
    <link rel="stylesheet" href="assets/admin.css">
</head>
<body class="admin-login-body">
<main class="admin-login-page">
    <section class="admin-login-panel" aria-labelledby="admin-login-title">
        <div class="admin-login-intro">
            <a class="admin-login-brand" href="../index.php">
                <span>
                    <?php if ($storeLogo !== ''): ?>
                        <img src="../<?= e($storeLogo) ?>" alt="Logo <?= e($storeName) ?>">
                    <?php else: ?>
                        S
                    <?php endif; ?>
                </span>
                <strong><?= e($storeName) ?></strong>
            </a>
            <span class="admin-login-badge">Akses Administrator</span>
            <h1 id="admin-login-title">Kelola toko melalui dashboard khusus admin.</h1>
            <p>Halaman ini hanya menerima akun administrator resmi ShopFlow. Akun pelanggan harus masuk melalui halaman Masuk ke ShopFlow.</p>
        </div>

        <div class="admin-login-card">
            <div class="admin-login-card-head">
                <span>🔐</span>
                <div>
                    <h2>Masuk Administrator</h2>
                    <p>Masukkan kredensial administrator yang terdaftar.</p>
                </div>
            </div>

            <?php if ($flashMessage): ?>
                <div class="admin-alert admin-alert-<?= e((string) $flashMessage['type']) ?>">
                    <?= e((string) $flashMessage['message']) ?>
                </div>
            <?php endif; ?>

            <?php if ($errors): ?>
                <div class="admin-alert admin-alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="admin-login-form" method="post" action="login.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="redirect" value="<?= e($redirectTo) ?>">

                <label class="admin-field">
                    <span>Email Admin</span>
                    <input class="admin-input" type="email" name="email" value="<?= e($email) ?>" placeholder="administrator@shopflow.id" required autocomplete="username">
                </label>

                <label class="admin-field">
                    <span>Kata Sandi Admin</span>
                    <input class="admin-input" type="password" name="password" placeholder="Masukkan kata sandi administrator" required autocomplete="current-password">
                </label>

                <button class="admin-button admin-button-primary admin-button-block" type="submit">Masuk ke Dashboard</button>
            </form>

            <div class="admin-login-links">
                <a href="../index.php">← Kembali ke toko</a>
                <a href="../login.php">Masuk ke ShopFlow</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
