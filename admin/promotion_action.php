<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/admin_auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('promotions.php');
verify_csrf();
$id = filter_input(INPUT_POST, 'promotion_id', FILTER_VALIDATE_INT);
$action = trim((string)($_POST['action'] ?? ''));
if (!$id || !in_array($action, ['toggle','delete'], true)) { flash('warning','Tindakan promo tidak valid.'); redirect('promotions.php'); }
if ($action === 'toggle') {
    $pdo->prepare('UPDATE promotions SET is_active = IF(is_active=1,0,1), updated_at=NOW() WHERE id=:id')->execute(['id'=>$id]);
    log_admin_activity($pdo,'promotion_toggle','Mengubah status promo #'.$id);
    flash('success','Status promo berhasil diubah.');
} else {
    $check=$pdo->prepare('SELECT COUNT(*) FROM promotion_usages WHERE promotion_id=:id'); $check->execute(['id'=>$id]);
    if ((int)$check->fetchColumn()>0) { flash('warning','Promo yang sudah pernah digunakan tidak dapat dihapus. Nonaktifkan promo tersebut.'); }
    else { $pdo->prepare('DELETE FROM promotions WHERE id=:id')->execute(['id'=>$id]); log_admin_activity($pdo,'promotion_delete','Menghapus promo #'.$id); flash('success','Promo berhasil dihapus.'); }
}
redirect('promotions.php');
