<aside class="admin-sidebar" data-sidebar>
    <div class="admin-sidebar-brand">
        <a href="index.php"><span><?php if ($storeLogo !== ''): ?><img src="../<?= e($storeLogo) ?>" alt="Logo toko"><?php else: ?>S<?php endif; ?></span><strong><?= e($storeName) ?></strong></a>
        <small>Admin Dashboard</small>
    </div>
    <nav class="admin-nav">
        <a class="<?= $activeMenu === 'dashboard' ? 'active' : '' ?>" href="index.php"><span>▦</span> Dashboard</a>
        <a class="<?= $activeMenu === 'orders' ? 'active' : '' ?>" href="orders.php"><span>📦</span> Pesanan</a>
        <a class="<?= $activeMenu === 'shipping' ? 'active' : '' ?>" href="shipping.php"><span>🚚</span> Pengiriman</a>
        <a class="<?= $activeMenu === 'promotions' ? 'active' : '' ?>" href="promotions.php"><span>🎟️</span> Voucher & Promo</a>
        <a class="<?= $activeMenu === 'products' ? 'active' : '' ?>" href="products.php"><span>🛍️</span> Produk</a>
        <a class="<?= $activeMenu === 'categories' ? 'active' : '' ?>" href="categories.php"><span>🏷️</span> Kategori</a>
        <a class="<?= $activeMenu === 'stock' ? 'active' : '' ?>" href="stock.php"><span>📊</span> Stok</a>
        <a class="<?= $activeMenu === 'customers' ? 'active' : '' ?>" href="customers.php"><span>👥</span> Pelanggan</a>
        <a class="<?= $activeMenu === 'wishlist' ? 'active' : '' ?>" href="reports_wishlist.php"><span>♥</span> Wishlist</a>
        <a class="<?= $activeMenu === 'reviews' ? 'active' : '' ?>" href="reviews.php"><span>⭐</span> Ulasan Produk</a>
        <a class="<?= $activeMenu === 'returns' ? 'active' : '' ?>" href="returns.php"><span>↩️</span> Retur Barang</a>
        <a class="<?= $activeMenu === 'reports' ? 'active' : '' ?>" href="reports.php"><span>📈</span> Laporan</a>
        <a class="<?= $activeMenu === 'notifications' ? 'active' : '' ?>" href="notifications.php"><span>🔔</span> Notifikasi<?php if ($unreadNotifications > 0): ?><b><?= $unreadNotifications ?></b><?php endif; ?></a>
        <a class="<?= $activeMenu === 'activities' ? 'active' : '' ?>" href="activities.php"><span>🧾</span> Aktivitas</a>
        <a class="<?= $activeMenu === 'settings' ? 'active' : '' ?>" href="settings.php"><span>⚙️</span> Pengaturan</a>
        <a class="<?= $activeMenu === 'profile' ? 'active' : '' ?>" href="profile.php"><span>👤</span> Profil Admin</a>
    </nav>
    <div class="admin-sidebar-bottom">
        <a href="../index.php">← Lihat Toko</a>
        <a href="logout.php">Keluar</a>
    </div>
</aside>
<div class="admin-sidebar-overlay" data-sidebar-overlay></div>
