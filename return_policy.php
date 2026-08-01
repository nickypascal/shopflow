<?php

declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Kebijakan Retur';
$period = return_period_days($pdo);
$policy = setting($pdo, 'return_policy', 'Retur dapat diajukan untuk produk yang memenuhi syarat.');
require __DIR__ . '/includes/header.php';
?>
<section class="page-header"><div class="container"><span class="eyebrow">Informasi layanan</span><h1>Kebijakan Retur ShopFlow</h1><p>Ketentuan pengembalian dana dan penggantian barang.</p></div></section>
<section class="section"><div class="container policy-page"><section><h2>Ketentuan Utama</h2><p><?= nl2br(e($policy)) ?></p><ul><li>Pengajuan maksimal <?= $period ?> hari setelah pesanan selesai.</li><li>Produk wajib merupakan bagian dari pesanan pengguna yang sedang login.</li><li>Jumlah retur tidak boleh melebihi jumlah pembelian.</li><li>Foto bukti wajib untuk produk rusak, salah, kurang, pecah, atau tidak sesuai.</li><li>Barang dikirim kembali setelah pengajuan disetujui admin.</li><li>Pengembalian dana diproses manual ke rekening atau e-wallet pelanggan.</li><li>Barang rusak berat tidak selalu dikembalikan ke stok.</li></ul></section><section><h2>Produk yang Dapat Diretur</h2><p>Produk rusak, tidak berfungsi, tidak sesuai deskripsi, salah produk atau variasi, kurang lengkap, atau rusak dalam pengiriman dapat diajukan untuk pemeriksaan.</p></section><section><h2>Produk yang Dapat Ditolak</h2><p>Retur dapat ditolak jika melewati batas waktu, bukti tidak cukup, produk telah digunakan berlebihan, kelengkapan tidak sesuai, atau kerusakan terjadi akibat penggunaan pelanggan.</p></section><a class="button button-primary" href="orders.php">Buka Pesanan Saya</a></div></section>
<?php require __DIR__ . '/includes/footer.php'; ?>
