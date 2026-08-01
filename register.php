<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'index.php');
}

$errors = [];
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');

    if (strlen($name) < 3) {
        $errors[] = 'Nama minimal 3 karakter.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Masukkan alamat email yang valid.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Kata sandi minimal 8 karakter.';
    }

    if ($password !== $passwordConfirmation) {
        $errors[] = 'Konfirmasi kata sandi tidak sama.';
    }

    if ($errors === []) {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute(['email' => $email]);

        if ($check->fetch()) {
            $errors[] = 'Email sudah terdaftar.';
        } else {
            $statement = $pdo->prepare('INSERT INTO users (name, email, password) VALUES (:name, :email, :password)');
            $statement->execute([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            flash('success', 'Pendaftaran berhasil. Silakan login.');
            redirect('login.php');
        }
    }
}

$pageTitle = 'Daftar Akun';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-section">
    <div class="container auth-container">
        <div class="auth-side">
            <span class="eyebrow">Bergabung dengan ShopFlow</span>
            <h1>Buat akun dan mulai berbelanja.</h1>
            <p>Akun diperlukan untuk melakukan checkout dan melihat pesanan.</p>
        </div>

        <div class="auth-card">
            <h2>Daftar Akun</h2>
            <p>Lengkapi data berikut dengan benar.</p>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <?php foreach ($errors as $error): ?>
                        <div><?= e($error) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form class="form-stack" method="post" action="register.php">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

                <label>
                    <span>Nama Lengkap</span>
                    <input type="text" name="name" value="<?= e($name) ?>" placeholder="Nama Anda" required autocomplete="name">
                </label>

                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="<?= e($email) ?>" placeholder="nama@email.com" required autocomplete="email">
                </label>

                <label>
                    <span>Kata Sandi</span>
                    <input type="password" name="password" placeholder="Minimal 8 karakter" required autocomplete="new-password">
                </label>

                <label>
                    <span>Konfirmasi Kata Sandi</span>
                    <input type="password" name="password_confirmation" placeholder="Ulangi kata sandi" required autocomplete="new-password">
                </label>

                <button class="button button-primary button-full" type="submit">Daftar</button>
            </form>

            <p class="auth-link">Sudah punya akun? <a href="login.php">Masuk sekarang</a></p>
        </div>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
