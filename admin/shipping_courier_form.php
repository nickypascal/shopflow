<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'courier_id', FILTER_VALIDATE_INT);
$isEdit = (bool) $id;
$data = ['courier_code'=>'','courier_name'=>'','supports_cod'=>0,'supports_cod_check'=>0,'is_active'=>1];
if ($isEdit) {
    $statement = $pdo->prepare('SELECT * FROM shipping_couriers WHERE id = :id');
    $statement->execute(['id'=>$id]);
    $data = $statement->fetch() ?: [];
    if ($data === []) { flash('warning','Kurir tidak ditemukan.'); redirect('shipping_couriers.php'); }
}
$errors=[];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $data=[
        'courier_code'=>strtoupper(trim((string)($_POST['courier_code']??''))),
        'courier_name'=>trim((string)($_POST['courier_name']??'')),
        'supports_cod'=>isset($_POST['supports_cod'])?1:0,
        'supports_cod_check'=>isset($_POST['supports_cod_check'])?1:0,
        'is_active'=>isset($_POST['is_active'])?1:0,
    ];
    if (!preg_match('/^[A-Z0-9_-]{2,30}$/',$data['courier_code'])) $errors[]='Kode kurir harus 2–30 karakter huruf besar, angka, garis bawah, atau tanda hubung.';
    if (strlen($data['courier_name'])<2||strlen($data['courier_name'])>100) $errors[]='Nama kurir harus 2–100 karakter.';
    $check=$pdo->prepare('SELECT id FROM shipping_couriers WHERE courier_code=:code AND id<>:id');
    $check->execute(['code'=>$data['courier_code'],'id'=>(int)$id]);
    if ($check->fetch()) $errors[]='Kode kurir sudah digunakan.';
    if ($errors===[]) {
        if ($isEdit) {
            $stmt=$pdo->prepare('UPDATE shipping_couriers SET courier_code=:code,courier_name=:name,supports_cod=:cod,supports_cod_check=:cod_check,is_active=:active,updated_at=NOW() WHERE id=:id');
            $stmt->execute(['code'=>$data['courier_code'],'name'=>$data['courier_name'],'cod'=>$data['supports_cod'],'cod_check'=>$data['supports_cod_check'],'active'=>$data['is_active'],'id'=>$id]);
            log_admin_activity($pdo,'shipping_courier_update','Memperbarui kurir '.$data['courier_name']);
        } else {
            $stmt=$pdo->prepare('INSERT INTO shipping_couriers(courier_code,courier_name,supports_cod,supports_cod_check,is_active) VALUES(:code,:name,:cod,:cod_check,:active)');
            $stmt->execute(['code'=>$data['courier_code'],'name'=>$data['courier_name'],'cod'=>$data['supports_cod'],'cod_check'=>$data['supports_cod_check'],'active'=>$data['is_active']]);
            log_admin_activity($pdo,'shipping_courier_create','Menambahkan kurir '.$data['courier_name']);
        }
        flash('success','Data kurir berhasil disimpan.'); redirect('shipping_couriers.php');
    }
}
$pageTitle=$isEdit?'Edit Kurir':'Tambah Kurir'; $activeMenu='shipping'; require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1><?= e($pageTitle) ?></h1><p>Atur identitas kurir dan dukungan COD.</p></div><a class="admin-button admin-button-secondary" href="shipping_couriers.php">← Kembali</a></div>
<?php if($errors):?><ul class="admin-error-list"><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul><?php endif;?>
<form class="admin-card admin-form-stack" method="post">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($isEdit):?><input type="hidden" name="courier_id" value="<?=(int)$id?>"><?php endif;?>
<div class="admin-form-grid"><label class="admin-field"><span>Kode kurir</span><input class="admin-input" name="courier_code" maxlength="30" value="<?=e((string)$data['courier_code'])?>" required></label><label class="admin-field"><span>Nama kurir</span><input class="admin-input" name="courier_name" maxlength="100" value="<?=e((string)$data['courier_name'])?>" required></label></div>
<div class="admin-checkbox-grid"><label class="admin-check"><input type="checkbox" name="supports_cod" <?= (int)$data['supports_cod']===1?'checked':''?>><span>Mendukung COD</span></label><label class="admin-check"><input type="checkbox" name="supports_cod_check" <?= (int)$data['supports_cod_check']===1?'checked':''?>><span>Mendukung COD Cek Dulu</span></label><label class="admin-check"><input type="checkbox" name="is_active" <?= (int)$data['is_active']===1?'checked':''?>><span>Kurir aktif</span></label></div>
<div class="admin-form-actions"><button class="admin-button admin-button-primary" type="submit">Simpan Kurir</button></div>
</form>
<?php require __DIR__.'/includes/admin_footer.php';?>
