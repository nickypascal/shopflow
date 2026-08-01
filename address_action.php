<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('addresses.php');
}

verify_csrf();

$user = current_user();
$addressId = (int) ($_POST['address_id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$returnTo = customer_return_path($_POST['return_to'] ?? null);

$statement = $pdo->prepare('SELECT id, label, is_primary FROM user_addresses WHERE id = :id AND user_id = :user_id LIMIT 1');
$statement->execute(['id' => $addressId, 'user_id' => (int) $user['id']]);
$address = $statement->fetch();

if (!$address) {
    flash('danger', 'Alamat tidak ditemukan atau bukan milik Anda.');
    redirect('addresses.php?return_to=' . urlencode($returnTo));
}

try {
    $pdo->beginTransaction();

    if ($action === 'set_primary') {
        $pdo->prepare('UPDATE user_addresses SET is_primary = 0 WHERE user_id = :user_id')
            ->execute(['user_id' => (int) $user['id']]);
        $pdo->prepare('UPDATE user_addresses SET is_primary = 1, updated_at = NOW() WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $addressId, 'user_id' => (int) $user['id']]);
        $message = 'Alamat ' . $address['label'] . ' berhasil dijadikan alamat utama.';
    } elseif ($action === 'delete') {
        $wasPrimary = (int) $address['is_primary'] === 1;
        $pdo->prepare('DELETE FROM user_addresses WHERE id = :id AND user_id = :user_id')
            ->execute(['id' => $addressId, 'user_id' => (int) $user['id']]);

        if ($wasPrimary) {
            $nextStatement = $pdo->prepare('SELECT id FROM user_addresses WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
            $nextStatement->execute(['user_id' => (int) $user['id']]);
            $nextId = (int) ($nextStatement->fetchColumn() ?: 0);
            if ($nextId > 0) {
                $pdo->prepare('UPDATE user_addresses SET is_primary = 1 WHERE id = :id AND user_id = :user_id')
                    ->execute(['id' => $nextId, 'user_id' => (int) $user['id']]);
            }
        }
        $message = 'Alamat berhasil dihapus.';
    } else {
        throw new RuntimeException('Tindakan alamat tidak dikenali.');
    }

    $pdo->commit();
    flash('success', $message);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    flash('danger', $exception instanceof RuntimeException ? $exception->getMessage() : 'Perubahan alamat gagal diproses.');
}

redirect('addresses.php?return_to=' . urlencode($returnTo));
