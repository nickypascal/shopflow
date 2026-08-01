<?php

declare(strict_types=1);
require_once __DIR__ . '/includes/admin_auth.php';

$id = filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST,'promotion_id',FILTER_VALIDATE_INT);
$isEdit = (bool)$id;
$defaults = [
    'name'=>'','code'=>'','description'=>'','trigger_type'=>'CODE','discount_type'=>'PERCENTAGE',
    'discount_value'=>'10','maximum_discount'=>'','minimum_purchase'=>'0','maximum_shipping_discount'=>'',
    'total_usage_limit'=>'','usage_limit_per_user'=>'1','start_at'=>date('Y-m-d\TH:i'),
    'end_at'=>date('Y-m-d\TH:i',strtotime('+30 days')),'is_active'=>1,
];
$data=$defaults; $selectedProducts=[];$selectedCategories=[];$selectedPayments=[];$selectedServices=[];
if($isEdit){
    $stmt=$pdo->prepare('SELECT * FROM promotions WHERE id=:id');$stmt->execute(['id'=>$id]);$row=$stmt->fetch();
    if(!$row){flash('warning','Promo tidak ditemukan.');redirect('promotions.php');}
    $data=array_merge($defaults,$row);
    $data['start_at']=date('Y-m-d\TH:i',strtotime((string)$row['start_at']));
    $data['end_at']=date('Y-m-d\TH:i',strtotime((string)$row['end_at']));
    foreach(['promotion_products'=>'product_id','promotion_categories'=>'category_id','promotion_payment_methods'=>'payment_method','promotion_shipping_services'=>'shipping_service_id'] as $table=>$column){
        $q=$pdo->prepare("SELECT {$column} FROM {$table} WHERE promotion_id=:id");$q->execute(['id'=>$id]);$values=$q->fetchAll(PDO::FETCH_COLUMN);
        if($table==='promotion_products')$selectedProducts=array_map('intval',$values);
        elseif($table==='promotion_categories')$selectedCategories=array_map('intval',$values);
        elseif($table==='promotion_payment_methods')$selectedPayments=array_map('strval',$values);
        else $selectedServices=array_map('intval',$values);
    }
}
$products=$pdo->query("SELECT id,name,category FROM products ORDER BY name")->fetchAll();
$categories=$pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll();
$services=$pdo->query("SELECT s.id,s.service_code,s.service_name,c.courier_name FROM shipping_services s INNER JOIN shipping_couriers c ON c.id=s.courier_id ORDER BY c.courier_name,s.service_name")->fetchAll();
$paymentMethods=['dana'=>'DANA','ovo'=>'OVO','gopay'=>'GoPay','seabank'=>'SeaBank','mandiri'=>'Mandiri','bca'=>'BCA','bri'=>'BRI','bni'=>'BNI','permata'=>'PermataBank','bsi'=>'BSI','cimb_niaga'=>'CIMB Niaga','bank_lainnya'=>'Bank Lainnya','qris'=>'QRIS','cod'=>'COD','cod_check'=>'COD Cek Dulu','credit_debit_card'=>'Kartu Kredit/Debit'];
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf();
    $data=[
        'name'=>trim((string)($_POST['name']??'')),
        'code'=>normalize_voucher_code($_POST['code']??''),
        'description'=>trim((string)($_POST['description']??'')),
        'trigger_type'=>trim((string)($_POST['trigger_type']??'CODE')),
        'discount_type'=>trim((string)($_POST['discount_type']??'PERCENTAGE')),
        'discount_value'=>trim((string)($_POST['discount_value']??'0')),
        'maximum_discount'=>trim((string)($_POST['maximum_discount']??'')),
        'minimum_purchase'=>trim((string)($_POST['minimum_purchase']??'0')),
        'maximum_shipping_discount'=>trim((string)($_POST['maximum_shipping_discount']??'')),
        'total_usage_limit'=>trim((string)($_POST['total_usage_limit']??'')),
        'usage_limit_per_user'=>trim((string)($_POST['usage_limit_per_user']??'1')),
        'start_at'=>trim((string)($_POST['start_at']??'')),
        'end_at'=>trim((string)($_POST['end_at']??'')),
        'is_active'=>isset($_POST['is_active'])?1:0,
    ];
    $selectedProducts=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['product_ids']??[])))));
    $selectedCategories=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['category_ids']??[])))));
    $selectedPayments=array_values(array_unique(array_filter(array_map('strval',(array)($_POST['payment_methods']??[])))));
    $selectedServices=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['shipping_service_ids']??[])))));
    if(strlen($data['name'])<3||strlen($data['name'])>150)$errors[]='Nama promo harus 3–150 karakter.';
    if(!in_array($data['trigger_type'],['CODE','AUTOMATIC'],true))$errors[]='Jenis penerapan promo tidak valid.';
    if($data['trigger_type']==='CODE'&&!preg_match('/^[A-Z0-9_-]{3,50}$/',$data['code']))$errors[]='Kode voucher harus 3–50 karakter huruf besar, angka, garis bawah, atau tanda hubung.';
    if($data['trigger_type']==='AUTOMATIC')$data['code']='';
    if(!in_array($data['discount_type'],['PERCENTAGE','FIXED_AMOUNT','FREE_SHIPPING'],true))$errors[]='Jenis diskon tidak valid.';
    $discountValue=(float)$data['discount_value'];$minimum=max(0,(float)$data['minimum_purchase']);
    $maxDiscount=$data['maximum_discount']===''?null:max(0,(float)$data['maximum_discount']);
    $maxShipping=$data['maximum_shipping_discount']===''?null:max(0,(float)$data['maximum_shipping_discount']);
    $totalLimit=$data['total_usage_limit']===''?null:(int)$data['total_usage_limit'];$perUser=(int)$data['usage_limit_per_user'];
    if($data['discount_type']==='PERCENTAGE'&&($discountValue<=0||$discountValue>100))$errors[]='Diskon persentase harus lebih dari 0 dan maksimal 100.';
    if($data['discount_type']==='FIXED_AMOUNT'&&$discountValue<=0)$errors[]='Potongan nominal harus lebih dari 0.';
    if($data['discount_type']==='FREE_SHIPPING'&&($maxShipping===null||$maxShipping<=0))$errors[]='Batas potongan ongkir wajib lebih dari 0.';
    if($totalLimit!==null&&$totalLimit<1)$errors[]='Kuota keseluruhan minimal 1 atau dikosongkan.';
    if($perUser<1)$errors[]='Batas penggunaan per akun minimal 1.';
    $start=strtotime($data['start_at']);$end=strtotime($data['end_at']);
    if($start===false||$end===false||$end<=$start)$errors[]='Periode promo tidak valid.';
    if($data['trigger_type']==='CODE'){
        $check=$pdo->prepare('SELECT id FROM promotions WHERE code=:code AND id<>:id');$check->execute(['code'=>$data['code'],'id'=>(int)$id]);if($check->fetch())$errors[]='Kode voucher sudah digunakan.';
    }
    foreach($selectedPayments as $method){if(!isset($paymentMethods[$method])){$errors[]='Metode pembayaran promo tidak valid.';break;}}
    if($errors===[]){
        try{$pdo->beginTransaction();
            $params=['name'=>$data['name'],'code'=>$data['code']!==''?$data['code']:null,'description'=>$data['description']?:null,'trigger'=>$data['trigger_type'],'discount_type'=>$data['discount_type'],'discount_value'=>$discountValue,'maximum_discount'=>$maxDiscount,'minimum_purchase'=>$minimum,'maximum_shipping_discount'=>$maxShipping,'total_usage_limit'=>$totalLimit,'usage_limit_per_user'=>$perUser,'start_at'=>date('Y-m-d H:i:s',$start),'end_at'=>date('Y-m-d H:i:s',$end),'active'=>$data['is_active']];
            if($isEdit){$params['id']=$id;$sql='UPDATE promotions SET name=:name,code=:code,description=:description,trigger_type=:trigger,discount_type=:discount_type,discount_value=:discount_value,maximum_discount=:maximum_discount,minimum_purchase=:minimum_purchase,maximum_shipping_discount=:maximum_shipping_discount,total_usage_limit=:total_usage_limit,usage_limit_per_user=:usage_limit_per_user,start_at=:start_at,end_at=:end_at,is_active=:active,updated_at=NOW() WHERE id=:id';$pdo->prepare($sql)->execute($params);$promotionId=(int)$id;}
            else{$sql='INSERT INTO promotions(name,code,description,trigger_type,discount_type,discount_value,maximum_discount,minimum_purchase,maximum_shipping_discount,total_usage_limit,usage_limit_per_user,start_at,end_at,is_active) VALUES(:name,:code,:description,:trigger,:discount_type,:discount_value,:maximum_discount,:minimum_purchase,:maximum_shipping_discount,:total_usage_limit,:usage_limit_per_user,:start_at,:end_at,:active)';$pdo->prepare($sql)->execute($params);$promotionId=(int)$pdo->lastInsertId();}
            foreach(['promotion_products','promotion_categories','promotion_payment_methods','promotion_shipping_services'] as $table)$pdo->prepare("DELETE FROM {$table} WHERE promotion_id=:id")->execute(['id'=>$promotionId]);
            $ins=$pdo->prepare('INSERT INTO promotion_products(promotion_id,product_id) VALUES(:promotion_id,:value)');foreach($selectedProducts as $value)$ins->execute(['promotion_id'=>$promotionId,'value'=>$value]);
            $ins=$pdo->prepare('INSERT INTO promotion_categories(promotion_id,category_id) VALUES(:promotion_id,:value)');foreach($selectedCategories as $value)$ins->execute(['promotion_id'=>$promotionId,'value'=>$value]);
            $ins=$pdo->prepare('INSERT INTO promotion_payment_methods(promotion_id,payment_method) VALUES(:promotion_id,:value)');foreach($selectedPayments as $value)$ins->execute(['promotion_id'=>$promotionId,'value'=>$value]);
            $ins=$pdo->prepare('INSERT INTO promotion_shipping_services(promotion_id,shipping_service_id) VALUES(:promotion_id,:value)');foreach($selectedServices as $value)$ins->execute(['promotion_id'=>$promotionId,'value'=>$value]);
            log_admin_activity($pdo,$isEdit?'promotion_update':'promotion_create',($isEdit?'Memperbarui':'Menambahkan').' promo '.$data['name']);$pdo->commit();flash('success','Promo berhasil disimpan.');redirect('promotions.php');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$errors[]='Promo gagal disimpan: '.$e->getMessage();}
    }
}
$pageTitle=$isEdit?'Edit Promo':'Tambah Promo';$activeMenu='promotions';require __DIR__.'/includes/admin_header.php';
?>
<div class="admin-page-head"><div><h1><?=e($pageTitle)?></h1><p>Atur nilai diskon, periode, kuota, produk, pembayaran, dan pengiriman.</p></div><a class="admin-button admin-button-secondary" href="promotions.php">← Kembali</a></div>
<?php if($errors):?><ul class="admin-error-list"><?php foreach($errors as $error):?><li><?=e($error)?></li><?php endforeach;?></ul><?php endif;?>
<form class="admin-card admin-form-stack" method="post" id="promotion-form">
<input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>"><?php if($isEdit):?><input type="hidden" name="promotion_id" value="<?=(int)$id?>"><?php endif;?>
<div class="admin-form-grid"><label class="admin-field"><span>Nama promo</span><input class="admin-input" name="name" maxlength="150" value="<?=e((string)$data['name'])?>" required></label><label class="admin-field"><span>Jenis penerapan</span><select class="admin-select" name="trigger_type" id="trigger-type"><option value="CODE" <?=$data['trigger_type']==='CODE'?'selected':''?>>Voucher Kode</option><option value="AUTOMATIC" <?=$data['trigger_type']==='AUTOMATIC'?'selected':''?>>Promo Otomatis</option></select></label></div>
<label class="admin-field" id="code-field"><span>Kode voucher</span><input class="admin-input" name="code" maxlength="50" value="<?=e((string)$data['code'])?>" placeholder="SHOPFLOW10"></label>
<label class="admin-field"><span>Deskripsi</span><textarea class="admin-textarea" name="description" rows="3" maxlength="1000"><?=e((string)$data['description'])?></textarea></label>
<div class="admin-form-grid"><label class="admin-field"><span>Jenis diskon</span><select class="admin-select" name="discount_type" id="discount-type"><option value="PERCENTAGE" <?=$data['discount_type']==='PERCENTAGE'?'selected':''?>>Persentase</option><option value="FIXED_AMOUNT" <?=$data['discount_type']==='FIXED_AMOUNT'?'selected':''?>>Potongan Nominal</option><option value="FREE_SHIPPING" <?=$data['discount_type']==='FREE_SHIPPING'?'selected':''?>>Gratis Ongkir</option></select></label><label class="admin-field"><span>Nilai diskon</span><input class="admin-input" type="number" min="0" step="0.01" name="discount_value" value="<?=e((string)$data['discount_value'])?>"><small>Isi 10 untuk diskon 10%, atau nominal rupiah.</small></label></div>
<div class="admin-form-grid"><label class="admin-field"><span>Maksimal diskon produk</span><input class="admin-input" type="number" min="0" name="maximum_discount" value="<?=e((string)$data['maximum_discount'])?>" placeholder="Kosong = tanpa batas"></label><label class="admin-field"><span>Maksimal potongan ongkir</span><input class="admin-input" type="number" min="0" name="maximum_shipping_discount" value="<?=e((string)$data['maximum_shipping_discount'])?>" placeholder="Wajib untuk gratis ongkir"></label></div>
<div class="admin-form-grid"><label class="admin-field"><span>Minimal pembelian</span><input class="admin-input" type="number" min="0" name="minimum_purchase" value="<?=e((string)$data['minimum_purchase'])?>"></label><label class="admin-field"><span>Kuota keseluruhan</span><input class="admin-input" type="number" min="1" name="total_usage_limit" value="<?=e((string)$data['total_usage_limit'])?>" placeholder="Kosong = tidak dibatasi"></label><label class="admin-field"><span>Batas per akun</span><input class="admin-input" type="number" min="1" name="usage_limit_per_user" value="<?=e((string)$data['usage_limit_per_user'])?>" required></label></div>
<div class="admin-form-grid"><label class="admin-field"><span>Mulai</span><input class="admin-input" type="datetime-local" name="start_at" value="<?=e((string)$data['start_at'])?>" required></label><label class="admin-field"><span>Berakhir</span><input class="admin-input" type="datetime-local" name="end_at" value="<?=e((string)$data['end_at'])?>" required></label></div>
<div class="admin-promo-scope-grid">
<section><h3>Produk tertentu</h3><p>Kosongkan produk dan kategori agar berlaku untuk semua produk.</p><div class="admin-check-list"><?php foreach($products as $product):?><label class="admin-check"><input type="checkbox" name="product_ids[]" value="<?=(int)$product['id']?>" <?=in_array((int)$product['id'],$selectedProducts,true)?'checked':''?>><span><?=e((string)$product['name'])?> <small><?=e((string)$product['category'])?></small></span></label><?php endforeach;?></div></section>
<section><h3>Kategori tertentu</h3><div class="admin-check-list"><?php foreach($categories as $category):?><label class="admin-check"><input type="checkbox" name="category_ids[]" value="<?=(int)$category['id']?>" <?=in_array((int)$category['id'],$selectedCategories,true)?'checked':''?>><span><?=e((string)$category['name'])?></span></label><?php endforeach;?></div></section>
<section><h3>Metode pembayaran</h3><p>Kosongkan agar berlaku untuk semua metode.</p><div class="admin-check-list"><?php foreach($paymentMethods as $value=>$label):?><label class="admin-check"><input type="checkbox" name="payment_methods[]" value="<?=e($value)?>" <?=in_array($value,$selectedPayments,true)?'checked':''?>><span><?=e($label)?></span></label><?php endforeach;?></div></section>
<section><h3>Layanan pengiriman</h3><p>Kosongkan agar berlaku untuk semua layanan.</p><div class="admin-check-list"><?php foreach($services as $service):?><label class="admin-check"><input type="checkbox" name="shipping_service_ids[]" value="<?=(int)$service['id']?>" <?=in_array((int)$service['id'],$selectedServices,true)?'checked':''?>><span><?=e((string)$service['courier_name'].' '.(string)$service['service_code'])?> <small><?=e((string)$service['service_name'])?></small></span></label><?php endforeach;?></div></section>
</div>
<label class="admin-check"><input type="checkbox" name="is_active" <?= (int)$data['is_active']===1?'checked':''?>><span>Promo aktif</span></label>
<div class="admin-form-actions"><button class="admin-button admin-button-primary" type="submit">Simpan Promo</button></div>
</form>
<script>(()=>{const trigger=document.getElementById('trigger-type'),field=document.getElementById('code-field');const update=()=>field.hidden=trigger.value==='AUTOMATIC';trigger.addEventListener('change',update);update();})();</script>
<?php require __DIR__.'/includes/admin_footer.php';?>
