<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/admin_auth.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') redirect('shipping_couriers.php');
verify_csrf();
$id=filter_input(INPUT_POST,'courier_id',FILTER_VALIDATE_INT); $action=(string)($_POST['action']??'');
if(!$id||$action!=='toggle'){flash('warning','Tindakan kurir tidak valid.');redirect('shipping_couriers.php');}
$stmt=$pdo->prepare('UPDATE shipping_couriers SET is_active=IF(is_active=1,0,1),updated_at=NOW() WHERE id=:id');$stmt->execute(['id'=>$id]);
log_admin_activity($pdo,'shipping_courier_toggle','Mengubah status kurir #'.$id);flash('success','Status kurir berhasil diubah.');redirect('shipping_couriers.php');
