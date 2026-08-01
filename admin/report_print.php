<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$period=report_period();
$type=report_safe_choice($_GET['type']??'overview',['overview','sales','orders','products','stock','customers','payments','shipping','promotions','wishlist','reviews','returns'],'overview');
$params=['from_start'=>$period['from_start'],'to_end'=>$period['to_end']];
$title='Ringkasan Laporan';$headers=[];$rows=[];$summary=[];

switch($type){
case 'sales':
    $title='Laporan Penjualan';
    $payment=trim((string)($_GET['payment']??''));
    $salesWhere=["status='Selesai'",'created_at BETWEEN :from_start AND :to_end'];
    if($payment!==''){if(in_array($payment,['cod','cod_check'],true))$salesWhere[]='payment_method=:payment';else $salesWhere[]='payment_category=:payment';$params['payment']=$payment;}
    $salesWhereSql=implode(' AND ',$salesWhere);
    $summary=report_fetch_one($pdo,"SELECT COUNT(*) AS completed_orders,COALESCE(SUM(subtotal_amount),0) AS gross_sales,COALESCE(SUM(product_discount),0) AS product_discount,COALESCE(SUM(total_amount),0) AS total_payment FROM orders WHERE {$salesWhereSql}",$params);
    $headers=['Tanggal','Pesanan Selesai','Penjualan Produk','Diskon Produk','Total Pembayaran'];
    $data=report_fetch_all($pdo,"SELECT DATE(created_at) AS report_date,COUNT(*) AS completed_orders,SUM(subtotal_amount) AS gross_sales,SUM(product_discount) AS product_discount,SUM(total_amount) AS total_payment FROM orders WHERE {$salesWhereSql} GROUP BY DATE(created_at) ORDER BY report_date",$params);
    foreach($data as $r)$rows[]=[$r['report_date'],$r['completed_orders'],rupiah($r['gross_sales']),rupiah($r['product_discount']),rupiah($r['total_payment'])];
    break;
case 'orders':
    $title='Laporan Pesanan';$headers=['Pesanan','Tanggal','Pelanggan','Pembayaran','Total','Status'];
    $orderWhere=['o.created_at BETWEEN :from_start AND :to_end'];
    $status=trim((string)($_GET['status']??''));$payment=trim((string)($_GET['payment']??''));$courier=trim((string)($_GET['courier']??''));$search=trim((string)($_GET['search']??''));
    if($status!==''){$orderWhere[]='o.status=:status';$params['status']=$status;}
    if($payment!==''){if(in_array($payment,['cod','cod_check'],true))$orderWhere[]='o.payment_method=:payment';else $orderWhere[]='o.payment_category=:payment';$params['payment']=$payment;}
    if($courier!==''){$orderWhere[]='o.shipping_courier_code=:courier';$params['courier']=$courier;}
    if($search!==''){$orderWhere[]='(o.order_number LIKE :search_order OR u.name LIKE :search_name OR u.email LIKE :search_email)';$sv='%'.$search.'%';$params['search_order']=$sv;$params['search_name']=$sv;$params['search_email']=$sv;}
    $data=report_fetch_all($pdo,"SELECT o.*,u.name AS customer_name FROM orders o INNER JOIN users u ON u.id=o.user_id WHERE ".implode(' AND ',$orderWhere)." ORDER BY o.created_at DESC LIMIT 200",$params);
    foreach($data as $r)$rows[]=[order_code($r),format_datetime($r['created_at']),$r['customer_name'],payment_method_label($r['payment_method'],$r['payment_detail']??null),rupiah($r['total_amount']),$r['status']];
    break;
case 'products':
    $title='Laporan Produk';$headers=['Produk','Varian','Terjual','Penjualan Kotor','Rating','Retur'];
    $productWhere=["o.status='Selesai'",'o.created_at BETWEEN :from_start AND :to_end'];$categoryId=max(0,(int)($_GET['category_id']??0));$search=trim((string)($_GET['search']??''));
    if($categoryId>0){$productWhere[]='p.category_id=:category_id';$params['category_id']=$categoryId;}
    if($search!==''){$productWhere[]='(p.name LIKE :search_product OR oi.variant_name LIKE :search_variant OR oi.variant_sku LIKE :search_sku)';$sv='%'.$search.'%';$params['search_product']=$sv;$params['search_variant']=$sv;$params['search_sku']=$sv;}
    $data=report_fetch_all($pdo,"SELECT oi.product_name,COALESCE(oi.variant_name,'') AS variant_name,SUM(oi.quantity) AS sold,SUM(oi.subtotal) AS revenue,p.average_rating,(SELECT COALESCE(SUM(ri.return_quantity),0) FROM return_items ri INNER JOIN return_requests rr ON rr.id=ri.return_request_id WHERE ri.product_id=p.id AND rr.status NOT IN ('REJECTED','CANCELLED')) AS returned FROM order_items oi INNER JOIN orders o ON o.id=oi.order_id INNER JOIN products p ON p.id=oi.product_id WHERE ".implode(' AND ',$productWhere)." GROUP BY p.id,oi.variant_id,oi.product_name,oi.variant_name,p.average_rating ORDER BY sold DESC LIMIT 100",$params);
    foreach($data as $r)$rows[]=[$r['product_name'],$r['variant_name'],$r['sold'],rupiah($r['revenue']),number_format((float)$r['average_rating'],1,',','.'),$r['returned']];
    break;
case 'stock':
    $title='Laporan Pergerakan Stok';$headers=['Tanggal','Produk','Varian','Jenis','Jumlah','Sebelum','Sesudah'];
    $stockWhere=['sh.created_at BETWEEN :from_start AND :to_end'];$movementType=trim((string)($_GET['movement_type']??''));$productId=max(0,(int)($_GET['product_id']??0));
    if($movementType!==''){$stockWhere[]='sh.type=:movement_type';$params['movement_type']=$movementType;}if($productId>0){$stockWhere[]='sh.product_id=:product_id';$params['product_id']=$productId;}
    $data=report_fetch_all($pdo,"SELECT sh.*,p.name AS product_name,COALESCE(pv.variant_name,'') AS variant_name FROM stock_histories sh INNER JOIN products p ON p.id=sh.product_id LEFT JOIN product_variants pv ON pv.id=sh.variant_id WHERE ".implode(' AND ',$stockWhere)." ORDER BY sh.created_at DESC LIMIT 250",$params);
    foreach($data as $r)$rows[]=[format_datetime($r['created_at']),$r['product_name'],$r['variant_name'],$r['type'],$r['quantity'],$r['stock_before'],$r['stock_after']];
    break;
case 'customers':
    $title='Laporan Pelanggan';$headers=['Pelanggan','Email','Pesanan','Selesai','Total Belanja','Batal','Retur'];
    $customerParams=['from_orders'=>$period['from_start'],'to_orders'=>$period['to_end'],'from_returns'=>$period['from_start'],'to_returns'=>$period['to_end']];$customerExtra='';$search=trim((string)($_GET['search']??''));
    if($search!==''){$customerExtra=' AND (u.name LIKE :search_name OR u.email LIKE :search_email)';$sv='%'.$search.'%';$customerParams['search_name']=$sv;$customerParams['search_email']=$sv;}
    $data=report_fetch_all($pdo,"SELECT u.id,u.name,u.email,COUNT(o.id) AS orders,SUM(o.status='Selesai') AS completed,COALESCE(SUM(CASE WHEN o.status='Selesai' THEN o.total_amount ELSE 0 END),0) AS spent,SUM(o.status='Dibatalkan') AS cancelled,(SELECT COUNT(*) FROM return_requests rr WHERE rr.user_id=u.id AND rr.created_at BETWEEN :from_returns AND :to_returns) AS returns_count FROM users u LEFT JOIN orders o ON o.user_id=u.id AND o.created_at BETWEEN :from_orders AND :to_orders WHERE u.role='customer' {$customerExtra} GROUP BY u.id,u.name,u.email ORDER BY spent DESC LIMIT 200",$customerParams);
    foreach($data as $r)$rows[]=[$r['name'],$r['email'],$r['orders'],$r['completed'],rupiah($r['spent']),$r['cancelled'],$r['returns_count']];
    break;
case 'payments':
    $title='Laporan Pembayaran';$headers=['Kategori','Metode','Pesanan','Selesai','Nilai Selesai','Keterangan'];
    $data=report_fetch_all($pdo,"SELECT payment_category,payment_method,payment_detail,COUNT(*) AS orders,SUM(status='Selesai') AS completed,COALESCE(SUM(CASE WHEN status='Selesai' THEN total_amount ELSE 0 END),0) AS value FROM orders WHERE created_at BETWEEN :from_start AND :to_end GROUP BY payment_category,payment_method,payment_detail ORDER BY orders DESC",$params);
    foreach($data as $r)$rows[]=[payment_category_label($r['payment_category']),payment_method_label($r['payment_method'],$r['payment_detail']??null),$r['orders'],$r['completed'],rupiah($r['value']),is_cod_payment($r['payment_method'])?'Internal COD':'Simulasi'];
    break;
case 'shipping':
    $title='Laporan Pengiriman';$headers=['Kurir','Layanan','Pesanan','Selesai','Ongkir','Diskon Ongkir','Biaya COD'];
    $shippingWhere=['created_at BETWEEN :from_start AND :to_end'];$courier=trim((string)($_GET['courier']??''));if($courier!==''){$shippingWhere[]='shipping_courier_code=:courier';$params['courier']=$courier;}
    $data=report_fetch_all($pdo,"SELECT shipping_courier_name,shipping_service_name,COUNT(*) AS orders,SUM(status='Selesai') AS completed,SUM(shipping_cost) AS shipping,SUM(shipping_discount) AS shipping_discount,SUM(cod_fee) AS cod_fee FROM orders WHERE ".implode(' AND ',$shippingWhere)." GROUP BY shipping_courier_name,shipping_service_name ORDER BY orders DESC",$params);
    foreach($data as $r)$rows[]=[$r['shipping_courier_name'],$r['shipping_service_name'],$r['orders'],$r['completed'],rupiah($r['shipping']),rupiah($r['shipping_discount']),rupiah($r['cod_fee'])];
    break;
case 'promotions':
    $title='Laporan Voucher & Promo';$headers=['Promo','Kode','Digunakan','Dikembalikan','Total Diskon','Penjualan Selesai'];
    $data=report_fetch_all($pdo,"SELECT p.name,p.code,SUM(pu.usage_status='USED') AS used_count,SUM(pu.usage_status='RESTORED') AS restored_count,SUM(CASE WHEN pu.usage_status='USED' THEN pu.discount_amount+pu.shipping_discount ELSE 0 END) AS discount,SUM(CASE WHEN pu.usage_status='USED' AND o.status='Selesai' THEN o.total_amount ELSE 0 END) AS sales FROM promotions p LEFT JOIN promotion_usages pu ON pu.promotion_id=p.id AND pu.used_at BETWEEN :from_start AND :to_end LEFT JOIN orders o ON o.id=pu.order_id GROUP BY p.id,p.name,p.code ORDER BY used_count DESC",$params);
    foreach($data as $r)$rows[]=[$r['name'],$r['code']?:'Otomatis',$r['used_count'],$r['restored_count'],rupiah($r['discount']??0),rupiah($r['sales']??0)];
    break;
case 'wishlist':
    $title='Laporan Wishlist';$headers=['Produk','Disimpan','Pelanggan','Dibeli','Konversi','Harga Saat Ini','Stok'];
    $data=report_fetch_all($pdo,"SELECT p.name,p.price,p.stock,COUNT(wi.id) AS saved,COUNT(DISTINCT wi.user_id) AS customers,SUM(wi.purchased_at IS NOT NULL) AS purchased FROM wishlist_items wi INNER JOIN products p ON p.id=wi.product_id WHERE wi.created_at BETWEEN :from_start AND :to_end GROUP BY p.id,p.name,p.price,p.stock ORDER BY saved DESC",$params);
    foreach($data as $r)$rows[]=[$r['name'],$r['saved'],$r['customers'],$r['purchased'],number_format(report_percent((int)$r['purchased'],(int)$r['saved']),1,',','.') . '%',rupiah($r['price']),$r['stock']];
    break;
case 'reviews':
    $title='Laporan Ulasan Produk';$headers=['Produk','Ulasan','Rating','5 Bintang','Rating Rendah','Dibalas'];
    $data=report_fetch_all($pdo,"SELECT p.name,COUNT(pr.id) AS reviews,AVG(pr.rating) AS rating,SUM(pr.rating=5) AS five_star,SUM(pr.rating<=2) AS low_rating,SUM(pr.admin_reply IS NOT NULL AND pr.admin_reply<>'') AS replied FROM product_reviews pr INNER JOIN products p ON p.id=pr.product_id WHERE pr.status='PUBLISHED' AND pr.created_at BETWEEN :from_start AND :to_end GROUP BY p.id,p.name ORDER BY rating DESC,reviews DESC",$params);
    foreach($data as $r)$rows[]=[$r['name'],$r['reviews'],number_format((float)$r['rating'],2,',','.'),$r['five_star'],$r['low_rating'],$r['replied']];
    break;
case 'returns':
    $title='Laporan Retur Barang';$headers=['Nomor Retur','Tanggal','Pelanggan','Pesanan','Jenis','Status','Produk','Refund'];
    $data=report_fetch_all($pdo,"SELECT rr.*,u.name AS customer_name,o.order_number,COALESCE(SUM(ri.return_quantity),0) AS product_qty FROM return_requests rr INNER JOIN users u ON u.id=rr.user_id INNER JOIN orders o ON o.id=rr.order_id LEFT JOIN return_items ri ON ri.return_request_id=rr.id WHERE rr.created_at BETWEEN :from_start AND :to_end GROUP BY rr.id,u.name,o.order_number ORDER BY rr.created_at DESC",$params);
    foreach($data as $r)$rows[]=[$r['return_number'],format_datetime($r['created_at']),$r['customer_name'],$r['order_number'],$r['resolution_type'],return_status_label($r['status']),$r['product_qty'],rupiah($r['refund_amount'])];
    break;
default:
    $title='Ringkasan Laporan Admin';$headers=['Indikator','Nilai'];
    $data=report_fetch_one($pdo,"SELECT COUNT(*) AS orders,SUM(status='Selesai') AS completed,SUM(status='Dibatalkan') AS cancelled,SUM(status='Ditolak') AS rejected,SUM(CASE WHEN status='Selesai' THEN subtotal_amount-product_discount ELSE 0 END) AS product_sales,SUM(CASE WHEN status='Selesai' THEN total_amount ELSE 0 END) AS payment_value FROM orders WHERE created_at BETWEEN :from_start AND :to_end",$params);
    $refund=report_completed_refund($pdo,$period);$rows=[['Total pesanan',(int)($data['orders']??0)],['Pesanan selesai',(int)($data['completed']??0)],['Dibatalkan',(int)($data['cancelled']??0)],['Ditolak',(int)($data['rejected']??0)],['Penjualan produk setelah diskon',rupiah($data['product_sales']??0)],['Refund selesai',rupiah($refund)],['Penjualan produk bersih',rupiah(max(0,(float)($data['product_sales']??0)-$refund))],['Nilai pembayaran selesai',rupiah($data['payment_value']??0)]];
}

log_admin_activity($pdo,'PRINT_REPORT','Mencetak ' . $title . ' periode ' . report_period_label($period['from'],$period['to']) . '.');
$storeName=setting($pdo,'store_name','ShopFlow');$storeAddress=setting($pdo,'store_full_address',setting($pdo,'store_address',''));
?>
<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> | <?=e($storeName)?></title><style>
body{font-family:Arial,sans-serif;color:#172033;margin:32px;font-size:12px}.head{display:flex;justify-content:space-between;gap:24px;border-bottom:2px solid #172033;padding-bottom:14px;margin-bottom:20px}.head h1{margin:0 0 6px;font-size:22px}.head p{margin:3px 0;color:#566174}.meta{text-align:right}.summary{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:18px}.summary div{border:1px solid #d8dee8;border-radius:8px;padding:10px}.summary span{display:block;color:#667085;font-size:10px;margin-bottom:5px}.summary strong{font-size:15px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #d7dce5;padding:8px;text-align:left;vertical-align:top}th{background:#eef2f7;font-size:11px}tr:nth-child(even) td{background:#fafbfc}.footer{display:flex;justify-content:space-between;margin-top:20px;padding-top:10px;border-top:1px solid #d7dce5;color:#667085}.no-print{margin-bottom:16px}.no-print button{padding:9px 15px;border:0;border-radius:7px;background:#1d4ed8;color:white;font-weight:bold;cursor:pointer}@media print{body{margin:12mm}.no-print{display:none}.head{break-after:avoid}table{font-size:10px}tr{break-inside:avoid}}
</style></head><body>
<div class="no-print"><button type="button" onclick="window.print()">Cetak Sekarang</button></div>
<header class="head"><div><h1><?=e($storeName)?></h1><p><?=e($title)?></p><?php if($storeAddress!==''):?><p><?=e($storeAddress)?></p><?php endif;?></div><div class="meta"><strong>Periode</strong><p><?=e(report_period_label($period['from'],$period['to']))?></p><p>Dicetak <?=e(date('d M Y, H:i'))?></p><p>Admin: <?=e((string)($adminUser['name']??'Admin'))?></p></div></header>
<?php if($summary!==[]):?><section class="summary"><?php foreach($summary as $key=>$value):?><div><span><?=e(ucwords(str_replace('_',' ',(string)$key)))?></span><strong><?=is_numeric($value)?e(number_format((float)$value,0,',','.')):e((string)$value)?></strong></div><?php endforeach;?></section><?php endif;?>
<?php if($rows===[]):?><p>Tidak ada data pada periode yang dipilih.</p><?php else:?><table><thead><tr><?php foreach($headers as $header):?><th><?=e($header)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($row as $cell):?><td><?=e((string)$cell)?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table><?php endif;?>
<footer class="footer"><span><?= e($storeName) ?> — Laporan Internal</span><span><?= e($storeName) ?> — Laporan Administrasi</span></footer>
</body></html>
