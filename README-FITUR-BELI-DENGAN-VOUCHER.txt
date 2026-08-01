FITUR BELI DENGAN VOUCHER DAN BELI SEKARANG - SHOPFLOW

ALUR BARU
1. Pada produk tersedia tombol + Keranjang dan Beli dengan Voucher.
2. Beli dengan Voucher membuka halaman pilihan voucher.
3. Pelanggan memilih voucher dan jumlah produk.
4. Tombol Beli Sekarang baru aktif setelah voucher dipilih/diisi.
5. Checkout hanya memproses produk tersebut.
6. Isi keranjang biasa tetap tersimpan.

FILE BARU
- buy_with_voucher.php
- buy_now_action.php
- cancel_direct_purchase.php

FILE DIPERBARUI
- index.php
- product_detail.php
- wishlist.php
- checkout.php
- apply_voucher.php
- remove_voucher.php
- includes/functions.php
- assets/style.css

CARA MEMASANG
1. Cadangkan folder ShopFlow dan database terlebih dahulu.
2. Salin seluruh isi paket ke:
   C:\xampp\htdocs\shopflow-php
3. Pilih Replace/Timpa ketika diminta.
4. Tidak perlu mengimpor SQL karena fitur memakai tabel voucher yang sudah ada.
5. Restart Apache lalu tekan Ctrl + F5.

PENGUJIAN
- Produk tanpa variasi: + Keranjang dan Beli dengan Voucher.
- Produk dengan variasi: pilih variasi lalu Beli dengan Voucher.
- Pilih voucher, ubah jumlah, klik Beli Sekarang.
- Pastikan checkout hanya berisi produk pembelian langsung.
- Pastikan isi keranjang lama tetap ada setelah pembelian langsung.

CATATAN
- Voucher tetap divalidasi ulang di checkout berdasarkan pembayaran dan kurir.
- Jika voucher memiliki minimum pembelian, jumlah produk harus memenuhi nilai minimum.
- functions.php pada paket ini juga mempertahankan perbaikan pencocokan wilayah COD Cek Dulu.
