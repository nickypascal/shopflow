# ShopFlow PHP — Dashboard Admin Lengkap

[![Pengujian Fungsional ShopFlow](https://github.com/nickypascal/shopflow/actions/workflows/playwright.yml/badge.svg)](https://github.com/nickypascal/shopflow/actions/workflows/playwright.yml)

Aplikasi e-commerce PHP + MySQL dengan halaman pelanggan dan dashboard admin terpisah.

## Fitur pelanggan

- Login dan registrasi
- Pencarian dan kategori produk
- Keranjang belanja
- Checkout dengan E-Wallet, transfer bank, QRIS, COD, COD Cek Dulu, dan kartu simulasi
- Riwayat pesanan dengan foto produk dan timeline status
- Pembatalan pesanan beserta alasan

## Fitur dashboard admin

Alamat dashboard:

```text
http://localhost/shopflow-php/admin/
```

Fitur yang tersedia:

- Ringkasan pendapatan, pesanan, pelanggan, produk, dan stok
- Penjualan tujuh hari terakhir
- Kelola semua pesanan dan detail transaksi
- Tahapan COD/COD Cek Dulu: Menunggu Konfirmasi → Diproses → Dikirim → Selesai
- Penolakan pesanan dan pengembalian stok otomatis
- Konfirmasi pembayaran simulasi untuk metode non-COD
- Timeline status dan catatan internal pesanan
- CRUD produk, foto, harga, status, produk unggulan, dan batas stok
- Penyesuaian stok serta riwayat stok
- CRUD kategori
- Daftar pelanggan, detail, riwayat pesanan, catatan, dan aktivasi akun
- Laporan penjualan, produk terlaris, metode pembayaran, pelanggan teraktif
- Ekspor CSV
- Notifikasi admin
- Riwayat aktivitas admin
- Pengaturan toko dan kebijakan COD
- Profil admin dan perubahan password
- Proteksi session admin, role, CSRF, PDO prepared statement, dan validasi upload

## Akun demo

### Admin

```text
Email: admin@shopflow.test
Password: admin123
```

### Pelanggan

```text
Email: pelanggan@shopflow.test
Password: password123
```

## Memasang pada aplikasi lama

Versi ini dibuat untuk ShopFlow yang sebelumnya sudah mendapat pembaruan COD Workflow.

1. Cadangkan folder aplikasi dan database terlebih dahulu.
2. Salin seluruh file pembaruan ke:

```text
C:\xampp\htdocs\shopflow-php
```

3. Buka phpMyAdmin dan pilih database `shopflow`.
4. Impor satu kali:

```text
database/update_admin_dashboard.sql
```

5. Logout dari akun yang sedang aktif, lalu login kembali sebagai admin.
6. Buka:

```text
http://localhost/shopflow-php/admin/
```

Jangan mengimpor `database/shopflow.sql` pada database lama karena file tersebut menghapus dan membuat ulang seluruh tabel.

## Instalasi baru

1. Salin folder ke `C:\xampp\htdocs\shopflow-php`.
2. Aktifkan Apache dan MySQL.
3. Impor `database/shopflow.sql` melalui phpMyAdmin.
4. Pastikan `pdo_mysql` aktif pada `C:\xampp\php\php.ini`.
5. Buka `http://localhost/shopflow-php/`.

## Upload produk

Foto produk disimpan di:

```text
uploads/products/
```

Pastikan folder tersebut dapat ditulis oleh PHP. Format yang diizinkan: JPG, PNG, WEBP, dan GIF; maksimal 2 MB.

## Pengujian Otomatis dan Continuous Integration

ShopFlow menggunakan **Playwright** untuk menjalankan pengujian fungsional otomatis pada browser Chromium.

Pengujian yang tersedia:

1. TC-01 — Login pengguna dengan akun valid
2. TC-02 — Login pengguna dengan kata sandi tidak valid
3. TC-03 — Pencarian produk tersedia
4. TC-04 — Pencarian produk tidak tersedia
5. TC-05 — Menambahkan produk ke keranjang
6. TC-06 — Mengubah jumlah produk di keranjang
7. TC-07 — Checkout dengan data lengkap
8. TC-08 — Checkout tanpa metode pembayaran

Menjalankan pengujian secara lokal:

```bash
npx playwright test --project=chromium
```

Membuka laporan HTML Playwright:

```bash
npx playwright show-report
```

Workflow GitHub Actions tersimpan di:

```text
.github/workflows/playwright.yml
```

Workflow dijalankan otomatis ketika:

- Terdapat `push` ke branch `main`
- Terdapat `pull_request` menuju branch `main`

Tahapan Continuous Integration meliputi:

- Menyiapkan PHP, Node.js, Composer, dan MySQL
- Mengimpor database khusus pengujian
- Menjalankan server PHP
- Menjalankan delapan pengujian Playwright pada Chromium
- Menyimpan laporan Playwright dan log server sebagai artifact

Branch `main` dilindungi dengan aturan berikut:

- Perubahan wajib melalui Pull Request
- Status check **Playwright Chromium** wajib berhasil
- Push langsung ke branch `main` tidak diperbolehkan

## Login pengguna dan admin terpisah

Versi ini menggunakan dua halaman autentikasi yang berbeda:

```text
Login pengguna: http://localhost/shopflow-php/login.php
Login admin   : http://localhost/shopflow-php/admin/login.php
```

Halaman login pengguna hanya menerima akun dengan peran `customer`, sedangkan halaman login admin hanya menerima akun dengan peran `admin`. Pembaruan ini tidak memerlukan perubahan database.

## Fitur Alamat Pengguna

Versi ini menambahkan pengelolaan beberapa alamat pelanggan, alamat utama, pilihan alamat pada checkout, snapshot alamat pada pesanan, serta tampilan informasi pengiriman pada pelanggan dan dashboard admin.

Untuk memperbarui database lama, impor:

```text
database/update_user_addresses.sql
```

Halaman alamat pengguna:

```text
http://localhost/shopflow-php/addresses.php
```

## Fitur Variasi dan Berat Produk

Versi ini mendukung produk tanpa variasi dan produk dengan variasi. Setiap variasi dapat mempunyai SKU, warna, ukuran, bahan, harga, stok, batas stok menipis, berat dalam gram, foto, dan status aktif.

Untuk pemasangan baru, impor `database/shopflow.sql`. Untuk memperbarui versi yang sudah memiliki fitur alamat pengguna, impor `database/update_product_variants.sql` satu kali lalu timpa file pembaruan.

Data contoh `Sneakers Urban` sudah mempunyai empat variasi untuk pengujian.

## Fitur Pengiriman dan Ongkos Kirim

Versi ini menambahkan perhitungan ongkos kirim manual dari database berdasarkan alamat tujuan dan total berat produk.

Fitur utama:

- Daftar kurir dan layanan pengiriman
- Tarif kilogram pertama dan kilogram berikutnya
- Filter dukungan COD dan COD Cek Dulu
- Biaya COD per tujuan
- Pembulatan berat pengiriman
- Pilihan kurir pada checkout
- Snapshot kurir, layanan, ongkir, dan estimasi pada pesanan
- Nomor resi wajib sebelum pesanan berstatus Dikirim
- Riwayat perjalanan paket
- Halaman pelacakan pelanggan
- Konfirmasi paket sudah diterima
- Pengelolaan kurir, layanan, dan tarif melalui dashboard admin

Untuk memperbarui aplikasi yang sudah memiliki fitur variasi produk dan berat, impor satu kali:

```text
database/update_shipping_system.sql
```

Halaman pengelolaan admin:

```text
http://localhost/shopflow-php/admin/shipping.php
```

Tarif contoh tersedia untuk alamat demo `Rembang, Jawa Tengah`. Untuk kota lain, admin harus menambahkan tarif melalui menu **Pengiriman → Tarif Ongkir**.

## Voucher & Promo

Versi ini mendukung voucher kode, promo otomatis, diskon persentase, potongan nominal, gratis ongkir, pembatasan produk/kategori/pembayaran/pengiriman, kuota penggunaan, dan riwayat promo. Untuk pembaruan aplikasi lama, impor `database/update_promotions.sql` satu kali.

## Fitur Notifikasi Internal

Versi ini menambahkan pusat notifikasi pelanggan dan admin tanpa layanan eksternal.

Fitur utama:

- Notifikasi pesanan baru, diproses, dikirim, selesai, ditolak, dan dibatalkan
- Notifikasi nomor resi dan pembaruan perjalanan paket
- Notifikasi stok menipis untuk admin
- Notifikasi harga wishlist turun dan stok tersedia kembali
- Pengumuman voucher/promo dari dashboard admin
- Ikon lonceng dan jumlah notifikasi belum dibaca
- Filter notifikasi, tandai dibaca, tandai semua dibaca, dan hapus
- Pengaturan preferensi notifikasi pelanggan
- Pencegahan notifikasi duplikat menggunakan `notification_key`
- Pemisahan notifikasi setiap pengguna dan setiap admin

Untuk memperbarui aplikasi yang sudah memiliki Wishlist, impor satu kali:

```text
database/update_notifications.sql
```

Halaman pelanggan:

```text
http://localhost/shopflow-php/notifications.php
```

Halaman admin:

```text
http://localhost/shopflow-php/admin/notifications.php
```

## Fitur Ulasan Produk

Versi ini menambahkan ulasan produk yang hanya dapat dibuat dari pesanan berstatus **Selesai**.

Fitur utama:

- Rating 1–5 bintang
- Judul dan isi ulasan
- Maksimal 3 foto, masing-masing maksimal 2 MB
- Label Pembelian Terverifikasi
- Snapshot variasi dan SKU yang dibeli
- Halaman Ulasan Saya
- Edit dan hapus ulasan oleh pemilik
- Ringkasan rating dan distribusi bintang pada detail produk
- Filter rating, foto, dan urutan ulasan
- Moderasi admin: menunggu, diterbitkan, ditolak, dan disembunyikan
- Balasan toko
- Notifikasi permintaan ulasan, moderasi, dan balasan admin
- Statistik ulasan pada dashboard admin

Untuk memperbarui aplikasi yang sudah memiliki fitur Notifikasi, impor satu kali:

```text
database/update_product_reviews.sql
```

Halaman pelanggan:

```text
http://localhost/shopflow-php/my_reviews.php
```

Halaman admin:

```text
http://localhost/shopflow-php/admin/reviews.php
```

Foto ulasan disimpan di:

```text
uploads/reviews/
```

## Fitur Retur Barang

Versi ini menambahkan alur retur setelah pesanan berstatus **Selesai**.

Fitur pelanggan:

- Pengajuan retur per produk dan jumlah
- Batas periode retur yang dapat diatur admin
- Pilihan pengembalian dana atau penggantian barang
- Pilihan alasan retur dan keterangan kondisi
- Maksimal 5 foto bukti, masing-masing maksimal 2 MB
- Halaman Retur Saya dan detail timeline
- Pembatalan pengajuan sebelum disetujui
- Pengisian kurir serta nomor resi pengembalian
- Notifikasi setiap perubahan penting

Fitur admin:

- Daftar dan filter seluruh retur
- Pemeriksaan, persetujuan, dan penolakan
- Penerimaan barang beserta foto pemeriksaan
- Pilihan barang layak dikembalikan ke stok atau tidak
- Pengembalian dana manual dan nomor referensi
- Pengiriman barang pengganti serta pengurangan stok
- Statistik retur pada dashboard dan laporan
- Riwayat aktivitas serta timeline status

Untuk memperbarui aplikasi yang sudah memiliki fitur Ulasan Produk, impor satu kali:

```text
database/update_product_returns.sql
```

Halaman pelanggan:

```text
http://localhost/shopflow-php/my_returns.php
```

Halaman admin:

```text
http://localhost/shopflow-php/admin/returns.php
```

Foto retur disimpan di:

```text
uploads/returns/
```

## Penyempurnaan Laporan Admin

Dashboard admin sekarang memiliki pusat laporan lengkap untuk penjualan, pesanan,
produk, stok, pelanggan, pembayaran, pengiriman, voucher, wishlist, ulasan, dan
retur. Setiap laporan mendukung filter tanggal, ekspor CSV, dan tampilan cetak.
Buka `admin/reports.php` setelah login sebagai admin.
