<?php

declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Pisahkan cookie sesi pelanggan dan administrator agar keduanya dapat
    // login bersamaan di browser/profil yang sama tanpa saling menimpa.
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isAdminRequest = preg_match('#/admin(?:/|$)#i', $scriptName) === 1;

    session_name($isAdminRequest
        ? 'SHOPFLOW_ADMIN_SESSID'
        : 'SHOPFLOW_CUSTOMER_SESSID'
    );

    ini_set('session.use_strict_mode', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}


function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function text_excerpt(string $value, int $maxLength = 120): string
{
    $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
    if (text_length($value) <= $maxLength) {
        return $value;
    }

    $excerpt = function_exists('mb_substr')
        ? mb_substr($value, 0, max(1, $maxLength - 3))
        : substr($value, 0, max(1, $maxLength - 3));
    return rtrim($excerpt) . '...';
}

function rupiah(float|int|string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function format_datetime(?string $value, string $fallback = '-'): string
{
    if ($value === null || trim($value) === '') {
        return $fallback;
    }

    $timestamp = strtotime($value);
    return $timestamp === false ? $fallback : date('d M Y, H:i', $timestamp);
}

function order_code(array|int $order): string
{
    if (is_array($order) && !empty($order['order_number'])) {
        return (string) $order['order_number'];
    }

    $id = is_array($order) ? (int) ($order['id'] ?? 0) : (int) $order;
    return 'SF-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function payment_category_label(string $category): string
{
    return match ($category) {
        'e_wallet' => 'E-Wallet',
        'transfer_bank' => 'Transfer Bank',
        'qris' => 'QRIS',
        'cod' => 'COD',
        'cod_check' => 'COD Cek Dulu',
        'card' => 'Kartu Kredit/Debit',
        default => 'Metode Pembayaran',
    };
}

function payment_method_label(string $method, ?string $detail = null): string
{
    $label = match ($method) {
        'dana' => 'DANA',
        'ovo' => 'OVO',
        'gopay' => 'GoPay',
        'seabank' => 'SeaBank',
        'mandiri' => 'Bank Mandiri',
        'bca' => 'BCA',
        'bri' => 'BRI',
        'bni' => 'BNI',
        'permata' => 'PermataBank',
        'bsi' => 'BSI',
        'cimb_niaga' => 'CIMB Niaga',
        'bank_lainnya' => 'Bank Lainnya',
        'qris' => 'QRIS',
        'cod' => 'Bayar di Tempat (COD)',
        'cod_check' => 'COD Cek Dulu',
        'credit_debit_card' => 'Kartu Kredit/Debit',
        default => 'Belum dipilih',
    };

    if ($method === 'bank_lainnya' && $detail !== null && trim($detail) !== '') {
        return $label . ' - ' . trim($detail);
    }

    return $label;
}

function payment_status_css_class(string $status): string
{
    return match ($status) {
        'Dibayar' => 'status-complete',
        'Gagal', 'Dikembalikan' => 'status-rejected',
        default => 'status-waiting',
    };
}

function is_cod_payment(string $paymentMethod): bool
{
    return in_array($paymentMethod, ['cod', 'cod_check'], true);
}

function cod_status_steps(): array
{
    return ['Menunggu Konfirmasi', 'Diproses', 'Dikirim', 'Selesai'];
}

function status_css_class(string $status): string
{
    return match ($status) {
        'Menunggu Pembayaran', 'Menunggu Konfirmasi' => 'status-waiting',
        'Diproses' => 'status-processing',
        'Dikirim' => 'status-shipping',
        'Selesai' => 'status-complete',
        'Ditolak', 'Dibatalkan' => 'status-rejected',
        default => 'status-neutral',
    };
}

function cod_status_description(string $status, string $paymentMethod): string
{
    return match ($status) {
        'Menunggu Konfirmasi' => 'Pesanan menunggu persetujuan dari toko.',
        'Diproses' => 'Pesanan sudah diterima dan sedang disiapkan.',
        'Dikirim' => $paymentMethod === 'cod_check'
            ? 'Paket sedang dikirim. Anda dapat memeriksa isi paket sebelum membayar.'
            : 'Paket sedang dikirim. Siapkan pembayaran saat paket diterima.',
        'Selesai' => 'Pesanan telah diterima dan transaksi selesai.',
        'Ditolak' => 'Pesanan tidak dapat diproses oleh toko.',
        'Dibatalkan' => 'Pesanan telah dibatalkan.',
        default => 'Status pesanan sedang diperbarui.',
    };
}

function record_order_status(PDO $pdo, int $orderId, string $status, ?string $note = null, ?int $changedBy = null): void
{
    $statement = $pdo->prepare(
        'INSERT INTO order_status_history (order_id, status, note, changed_by)
         VALUES (:order_id, :status, :note, :changed_by)'
    );
    $statement->execute([
        'order_id' => $orderId,
        'status' => $status,
        'note' => $note,
        'changed_by' => $changedBy,
    ]);
}

function record_stock_history(
    PDO $pdo,
    int $productId,
    string $type,
    int $quantity,
    int $before,
    int $after,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?string $notes = null,
    ?int $createdBy = null,
    ?int $variantId = null
): void {
    $statement = $pdo->prepare(
        'INSERT INTO stock_histories (
            product_id, variant_id, type, quantity, stock_before, stock_after,
            reference_type, reference_id, notes, created_by
         ) VALUES (
            :product_id, :variant_id, :type, :quantity, :stock_before, :stock_after,
            :reference_type, :reference_id, :notes, :created_by
         )'
    );
    $statement->execute([
        'product_id' => $productId,
        'variant_id' => $variantId,
        'type' => $type,
        'quantity' => abs($quantity),
        'stock_before' => $before,
        'stock_after' => $after,
        'reference_type' => $referenceType,
        'reference_id' => $referenceId,
        'notes' => $notes,
        'created_by' => $createdBy,
    ]);
}

function sync_product_variant_summary(PDO $pdo, int $productId): void
{
    $statement = $pdo->prepare(
        "SELECT MIN(price) AS minimum_price, COALESCE(SUM(stock), 0) AS total_stock
         FROM product_variants
         WHERE product_id = :product_id AND is_active = 1"
    );
    $statement->execute(['product_id' => $productId]);
    $summary = $statement->fetch() ?: [];

    $minimumPrice = (float) ($summary['minimum_price'] ?? 0);
    $totalStock = max(0, (int) ($summary['total_stock'] ?? 0));

    $update = $pdo->prepare(
        'UPDATE products
         SET price = CASE WHEN :minimum_price_check > 0 THEN :minimum_price_value ELSE price END,
             stock = :total_stock,
             updated_at = NOW()
         WHERE id = :product_id AND has_variants = 1'
    );
    $update->execute([
        'minimum_price_check' => $minimumPrice,
        'minimum_price_value' => $minimumPrice,
        'total_stock' => $totalStock,
        'product_id' => $productId,
    ]);
}

function log_admin_activity(PDO $pdo, string $type, string $description, ?int $adminId = null): void
{
    $adminId ??= (int) (current_user()['id'] ?? 0);
    if ($adminId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT INTO admin_activity_logs (admin_id, activity_type, description, ip_address)
         VALUES (:admin_id, :activity_type, :description, :ip_address)'
    );
    $statement->execute([
        'admin_id' => $adminId,
        'activity_type' => $type,
        'description' => $description,
        'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

function notification_url(?string $url, string $audience = 'customer'): ?string
{
    $url = trim((string) $url);
    if ($url === '') {
        return null;
    }

    // Hanya izinkan tautan internal relatif dan cegah open redirect.
    $url = preg_replace('#^\.\./#', '', $url) ?? '';
    if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n")
        || str_contains($url, '\\') || str_contains($url, '..')
        || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1
        || str_starts_with($url, '//')) {
        return null;
    }

    $url = ltrim($url, '/');
    if ($audience === 'admin') {
        $url = preg_replace('#^admin/#', '', $url) ?? $url;
    } else {
        $url = preg_replace('#^admin/#', '', $url) ?? $url;
    }

    return $url !== '' ? $url : null;
}

function notification_preference_enabled(PDO $pdo, int $userId, ?string $preferenceKey): bool
{
    if ($preferenceKey === null || $preferenceKey === '') {
        return true;
    }

    $allowed = [
        'order_updates',
        'shipping_updates',
        'promotion_updates',
        'wishlist_price_updates',
        'wishlist_stock_updates',
    ];
    if (!in_array($preferenceKey, $allowed, true)) {
        return true;
    }

    try {
        $pdo->prepare('INSERT IGNORE INTO notification_preferences (user_id) VALUES (:user_id)')
            ->execute(['user_id' => $userId]);
        $statement = $pdo->prepare("SELECT {$preferenceKey} FROM notification_preferences WHERE user_id = :user_id");
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn() === 1;
    } catch (Throwable) {
        // Tetap izinkan notifikasi inti sebelum migrasi notifikasi diimpor.
        return true;
    }
}

function insert_notification(
    PDO $pdo,
    int $userId,
    string $audience,
    string $title,
    string $message,
    ?string $url,
    string $type,
    ?string $notificationKey,
    ?string $relatedType,
    ?int $relatedId
): void {
    if ($userId <= 0) {
        return;
    }

    $title = substr(trim($title), 0, 150);
    $message = substr(trim($message), 0, 500);
    if ($title === '' || $message === '') {
        return;
    }

    $statement = $pdo->prepare(
        'INSERT IGNORE INTO notifications (
            user_id, audience, type, title, message, url,
            notification_key, related_type, related_id
         ) VALUES (
            :user_id, :audience, :type, :title, :message, :url,
            :notification_key, :related_type, :related_id
         )'
    );
    $statement->execute([
        'user_id' => $userId,
        'audience' => $audience,
        'type' => in_array($type, ['info', 'success', 'warning', 'danger', 'order', 'shipping', 'wishlist', 'promotion', 'stock', 'review', 'return'], true)
            ? $type : 'info',
        'title' => $title,
        'message' => $message,
        'url' => notification_url($url, $audience),
        'notification_key' => $notificationKey !== null ? substr($notificationKey, 0, 191) : null,
        'related_type' => $relatedType !== null ? substr($relatedType, 0, 50) : null,
        'related_id' => $relatedId,
    ]);
}

function notify_admins(
    PDO $pdo,
    string $title,
    string $message,
    ?string $url = null,
    string $type = 'info',
    ?string $notificationKey = null,
    ?string $relatedType = null,
    ?int $relatedId = null
): void {
    $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active'")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach ($admins as $adminId) {
        insert_notification(
            $pdo,
            (int) $adminId,
            'admin',
            $title,
            $message,
            $url,
            $type,
            $notificationKey,
            $relatedType,
            $relatedId
        );
    }
}

function create_notification(
    PDO $pdo,
    string $title,
    string $message,
    ?string $url = null,
    string $type = 'info',
    string $audience = 'admin',
    ?int $userId = null,
    ?string $notificationKey = null,
    ?string $relatedType = null,
    ?int $relatedId = null,
    ?string $preferenceKey = null
): void {
    if ($audience === 'admin' && ($userId === null || $userId <= 0)) {
        notify_admins(
            $pdo,
            $title,
            $message,
            $url,
            $type,
            $notificationKey,
            $relatedType,
            $relatedId
        );
        return;
    }

    if ($userId === null || $userId <= 0) {
        return;
    }
    if ($audience === 'customer' && !notification_preference_enabled($pdo, $userId, $preferenceKey)) {
        return;
    }

    insert_notification(
        $pdo,
        $userId,
        $audience,
        $title,
        $message,
        $url,
        $type,
        $notificationKey,
        $relatedType,
        $relatedId
    );
}

function unread_notification_count(PDO $pdo, int $userId, string $audience): int
{
    if ($userId <= 0) {
        return 0;
    }

    try {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM notifications
             WHERE user_id = :user_id AND audience = :audience AND is_read = 0'
        );
        $statement->execute(['user_id' => $userId, 'audience' => $audience]);
        return (int) $statement->fetchColumn();
    } catch (Throwable) {
        return 0;
    }
}

function notification_icon(string $type): string
{
    return match ($type) {
        'success' => '✅',
        'warning', 'stock' => '⚠️',
        'danger' => '❌',
        'order' => '🛒',
        'shipping' => '📦',
        'wishlist' => '❤️',
        'promotion' => '🏷️',
        'review' => '⭐',
        'return' => '↩️',
        default => '🔔',
    };
}

function notify_wishlist_price_drop(
    PDO $pdo,
    int $productId,
    string $productName,
    float $oldPrice,
    float $newPrice,
    ?int $variantId = null,
    ?string $variantName = null
): void {
    if ($newPrice <= 0 || $oldPrice <= 0 || $newPrice >= $oldPrice) {
        return;
    }

    $sql = 'SELECT DISTINCT wi.user_id
            FROM wishlist_items wi
            INNER JOIN users u ON u.id = wi.user_id AND u.status = \'active\'
            WHERE wi.product_id = :product_id';
    $params = ['product_id' => $productId];
    if ($variantId !== null && $variantId > 0) {
        $sql .= ' AND (wi.preferred_variant_id IS NULL OR wi.preferred_variant_id = :variant_id)';
        $params['variant_id'] = $variantId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $suffix = $variantName ? ' varian ' . $variantName : '';
    $drop = $oldPrice - $newPrice;
    $keySuffix = $variantId ? 'variant-' . $variantId : 'product-' . $productId;

    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        create_notification(
            $pdo,
            'Harga produk wishlist turun',
            $productName . $suffix . ' turun ' . rupiah($drop) . ' menjadi ' . rupiah($newPrice) . '.',
            'product_detail.php?id=' . $productId,
            'wishlist',
            'customer',
            (int) $userId,
            'wishlist-price-' . $keySuffix . '-' . number_format($newPrice, 2, '.', ''),
            'product',
            $productId,
            'wishlist_price_updates'
        );
    }
}

function notify_wishlist_restock(
    PDO $pdo,
    int $productId,
    string $productName,
    ?int $variantId = null,
    ?string $variantName = null
): void {
    $sql = 'SELECT DISTINCT wi.user_id
            FROM wishlist_items wi
            INNER JOIN users u ON u.id = wi.user_id AND u.status = \'active\'
            WHERE wi.product_id = :product_id';
    $params = ['product_id' => $productId];
    if ($variantId !== null && $variantId > 0) {
        $sql .= ' AND (wi.preferred_variant_id IS NULL OR wi.preferred_variant_id = :variant_id)';
        $params['variant_id'] = $variantId;
    }
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    $suffix = $variantName ? ' varian ' . $variantName : '';
    $eventKey = date('YmdHis');
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $userId) {
        create_notification(
            $pdo,
            'Stok wishlist tersedia kembali',
            $productName . $suffix . ' sudah tersedia kembali.',
            'product_detail.php?id=' . $productId,
            'wishlist',
            'customer',
            (int) $userId,
            'wishlist-restock-' . $productId . '-' . ($variantId ?: 0) . '-' . $eventKey,
            'product',
            $productId,
            'wishlist_stock_updates'
        );
    }
}


function review_status_label(string $status): string
{
    return match ($status) {
        'PENDING' => 'Menunggu Moderasi',
        'PUBLISHED' => 'Diterbitkan',
        'REJECTED' => 'Ditolak',
        'HIDDEN' => 'Disembunyikan',
        default => 'Tidak diketahui',
    };
}

function review_status_css_class(string $status): string
{
    return match ($status) {
        'PUBLISHED' => 'status-complete',
        'PENDING' => 'status-waiting',
        'REJECTED', 'HIDDEN' => 'status-rejected',
        default => 'status-neutral',
    };
}

function mask_customer_name(string $name): string
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
    if ($name === '') {
        return 'Pelanggan ShopFlow';
    }

    $parts = explode(' ', $name);
    $first = $parts[0];
    $firstLength = function_exists('mb_strlen') ? mb_strlen($first) : strlen($first);
    $prefixLength = min(3, max(1, $firstLength));
    $prefix = function_exists('mb_substr') ? mb_substr($first, 0, $prefixLength) : substr($first, 0, $prefixLength);
    $masked = $prefix . '***';

    if (count($parts) > 1) {
        $last = end($parts);
        $initial = function_exists('mb_substr') ? mb_substr((string) $last, 0, 1) : substr((string) $last, 0, 1);
        $masked .= ' ' . strtoupper($initial) . '.';
    }

    return $masked;
}

function recalculate_product_rating(PDO $pdo, int $productId): void
{
    if ($productId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        "SELECT COALESCE(AVG(rating), 0) AS average_rating, COUNT(*) AS review_count
         FROM product_reviews
         WHERE product_id = :product_id AND status = 'PUBLISHED'"
    );
    $statement->execute(['product_id' => $productId]);
    $summary = $statement->fetch() ?: [];

    $update = $pdo->prepare(
        'UPDATE products
         SET average_rating = :average_rating, review_count = :review_count
         WHERE id = :product_id'
    );
    $update->execute([
        'average_rating' => round((float) ($summary['average_rating'] ?? 0), 2),
        'review_count' => (int) ($summary['review_count'] ?? 0),
        'product_id' => $productId,
    ]);
}


function create_review_request_notifications(PDO $pdo, int $orderId, int $userId, string $orderCode): void
{
    if ($orderId <= 0 || $userId <= 0) {
        return;
    }

    $statement = $pdo->prepare(
        'SELECT oi.id AS order_item_id, oi.product_name
         FROM order_items oi
         LEFT JOIN product_reviews r ON r.order_item_id = oi.id
         WHERE oi.order_id = :order_id AND r.id IS NULL
         ORDER BY oi.id'
    );
    $statement->execute(['order_id' => $orderId]);

    foreach ($statement->fetchAll() as $item) {
        $orderItemId = (int) $item['order_item_id'];
        create_notification(
            $pdo,
            'Bagaimana produk yang Anda terima?',
            'Berikan ulasan untuk ' . (string) $item['product_name'] . ' dari pesanan ' . $orderCode . '.',
            'review_create.php?order_item=' . $orderItemId,
            'review',
            'customer',
            $userId,
            'review-request-order-item-' . $orderItemId,
            'order_item',
            $orderItemId,
            'order_updates'
        );
    }
}

function review_upload_directory(): string
{
    return dirname(__DIR__) . '/uploads/reviews';
}

function review_delete_image_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/reviews/')) {
        return;
    }

    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function store_review_images(PDO $pdo, int $reviewId, array $files, int $existingCount = 0): array
{
    if ($reviewId <= 0 || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $maxImages = 3;
    $maxBytes = 2 * 1024 * 1024;
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $remaining = max(0, $maxImages - $existingCount);
    if ($remaining <= 0) {
        return ['Maksimal tiga foto untuk setiap ulasan.'];
    }

    $directory = review_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['Folder foto ulasan tidak dapat dibuat.'];
    }

    $errors = [];
    $storedPaths = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $totalFiles = count($files['name']);

    for ($index = 0; $index < $totalFiles; $index++) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (count($storedPaths) >= $remaining) {
            $errors[] = 'Maksimal tiga foto untuk setiap ulasan.';
            break;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Salah satu foto gagal diunggah.';
            continue;
        }

        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        $size = (int) ($files['size'][$index] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = 'Berkas unggahan foto tidak valid.';
            continue;
        }
        if ($size <= 0 || $size > $maxBytes) {
            $errors[] = 'Ukuran setiap foto maksimal 2 MB.';
            continue;
        }

        $mime = (string) $finfo->file($tmpName);
        if (!isset($allowedMimeTypes[$mime])) {
            $errors[] = 'Foto harus berformat JPG, PNG, atau WEBP.';
            continue;
        }

        $filename = 'review_' . $reviewId . '_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mime];
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($tmpName, $target)) {
            $errors[] = 'Foto ulasan gagal disimpan.';
            continue;
        }
        $storedPaths[] = 'uploads/reviews/' . $filename;
    }

    if ($storedPaths !== []) {
        $orderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM product_review_images WHERE review_id = :review_id');
        $orderStatement->execute(['review_id' => $reviewId]);
        $sortOrder = (int) $orderStatement->fetchColumn() + 1;
        $insert = $pdo->prepare(
            'INSERT INTO product_review_images (review_id, image_path, sort_order)
             VALUES (:review_id, :image_path, :sort_order)'
        );
        foreach ($storedPaths as $path) {
            $insert->execute([
                'review_id' => $reviewId,
                'image_path' => $path,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    return array_values(array_unique($errors));
}

function setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $statement = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = :key LIMIT 1');
        $statement->execute(['key' => $key]);
        $value = $statement->fetchColumn();
        $cache[$key] = $value === false ? $default : (string) $value;
    } catch (Throwable) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Permintaan tidak valid. Silakan muat ulang halaman dan coba lagi.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']) && is_array($_SESSION['user']);
}

function current_user(): ?array
{
    return is_logged_in() ? $_SESSION['user'] : null;
}

function is_admin(): bool
{
    $user = current_user();
    return is_array($user) && ($user['role'] ?? 'customer') === 'admin';
}

function customer_id(): int
{
    $user = current_user();
    if (!is_array($user) || ($user['role'] ?? 'customer') !== 'customer') {
        return 0;
    }

    return max(0, (int) ($user['id'] ?? 0));
}

function safe_customer_redirect(?string $path, string $fallback = 'index.php'): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return $fallback;
    }

    if (str_contains($path, "\r")
        || str_contains($path, "\n")
        || str_contains($path, '\\')
        || str_contains($path, '..')
        || str_starts_with($path, '/')) {
        return $fallback;
    }

    $allowed = '(?:index|product_detail|buy_with_voucher|wishlist|cart|checkout|orders|promotions|addresses|add_address|edit_address|notifications|notification_preferences|review_create|review_edit|my_reviews|my_returns|return_create|return_detail)\.php';
    if (preg_match('/^' . $allowed . '(?:\?[^#]*)?(?:#[A-Za-z0-9_-]+)?$/', $path) !== 1) {
        return $fallback;
    }

    return $path;
}

function wishlist_count(PDO $pdo, ?int $userId = null): int
{
    $userId ??= customer_id();
    if ($userId <= 0) {
        return 0;
    }

    try {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM wishlist_items WHERE user_id = :user_id');
        $statement->execute(['user_id' => $userId]);
        return (int) $statement->fetchColumn();
    } catch (Throwable) {
        // Tetap izinkan halaman lama dibuka sebelum update_wishlist.sql diimpor.
        return 0;
    }
}

function wishlist_product_map(PDO $pdo, ?int $userId = null): array
{
    $userId ??= customer_id();
    if ($userId <= 0) {
        return [];
    }

    try {
        $statement = $pdo->prepare(
            'SELECT id, product_id, preferred_variant_id, price_when_added, created_at
             FROM wishlist_items
             WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        $result = [];
        foreach ($statement->fetchAll() as $row) {
            $result[(int) $row['product_id']] = $row;
        }
        return $result;
    } catch (Throwable) {
        return [];
    }
}

function wishlist_price_message(float $priceWhenAdded, float $currentPrice): array
{
    if ($priceWhenAdded <= 0 || $currentPrice <= 0) {
        return ['label' => 'Harga mengikuti harga terbaru', 'class' => 'neutral'];
    }

    $difference = round($priceWhenAdded - $currentPrice, 2);
    if ($difference > 0) {
        return ['label' => 'Harga turun ' . rupiah($difference), 'class' => 'down'];
    }
    if ($difference < 0) {
        return ['label' => 'Harga naik ' . rupiah(abs($difference)), 'class' => 'up'];
    }

    return ['label' => 'Harga belum berubah', 'class' => 'neutral'];
}

function cart_key(int $productId, int $variantId = 0): string
{
    return 'p' . $productId . '_v' . max(0, $variantId);
}

function normalize_cart(mixed $rawCart): array
{
    if (!is_array($rawCart)) {
        return [];
    }

    $normalized = [];
    foreach ($rawCart as $key => $value) {
        $productId = 0;
        $variantId = 0;
        $quantity = 0;

        if (is_array($value)) {
            $productId = (int) ($value['product_id'] ?? 0);
            $variantId = max(0, (int) ($value['variant_id'] ?? 0));
            $quantity = (int) ($value['quantity'] ?? 0);
        } elseif (is_numeric($key)) {
            // Mendukung isi keranjang versi lama: product_id => quantity.
            $productId = (int) $key;
            $quantity = (int) $value;
        } elseif (is_string($key) && preg_match('/^p(\d+)_v(\d+)$/', $key, $matches)) {
            $productId = (int) $matches[1];
            $variantId = (int) $matches[2];
            $quantity = (int) $value;
        }

        if ($productId <= 0 || $quantity <= 0) {
            continue;
        }

        $cartKey = cart_key($productId, $variantId);
        $normalized[$cartKey] = [
            'product_id' => $productId,
            'variant_id' => $variantId,
            'quantity' => min(999, $quantity),
        ];
    }

    return $normalized;
}

function cart_count(): int
{
    $cart = normalize_cart($_SESSION['cart'] ?? []);
    return array_sum(array_map(static fn (array $item): int => (int) $item['quantity'], $cart));
}

function weight_label(int $grams): string
{
    $grams = max(0, $grams);
    if ($grams >= 1000) {
        $kilograms = number_format($grams / 1000, 2, ',', '.');
        $kilograms = rtrim(rtrim($kilograms, '0'), ',');
        return $kilograms . ' kg';
    }

    return number_format($grams, 0, ',', '.') . ' gram';
}

function charged_weight_kg(int $totalWeightGrams, int $roundingGrams = 1000): int
{
    $roundingGrams = max(1, $roundingGrams);
    return max(1, (int) ceil(max(0, $totalWeightGrams) / $roundingGrams));
}

function shipping_estimation_label(int $minimumDays, int $maximumDays): string
{
    $minimumDays = max(0, $minimumDays);
    $maximumDays = max($minimumDays, $maximumDays);

    if ($minimumDays === $maximumDays) {
        return $minimumDays === 0 ? 'Tiba hari ini' : $minimumDays . ' hari';
    }

    return $minimumDays . '–' . $maximumDays . ' hari';
}

function calculate_shipping_cost(array $rate, int $chargedWeightKg): float
{
    $chargedWeightKg = max(1, $chargedWeightKg);
    $firstKg = max(0.0, (float) ($rate['first_kg_price'] ?? 0));
    $nextKg = max(0.0, (float) ($rate['next_kg_price'] ?? 0));
    return $firstKg + max(0, $chargedWeightKg - 1) * $nextKg;
}

function shipping_payment_group(string $paymentMethod): string
{
    return match ($paymentMethod) {
        'cod' => 'cod',
        'cod_check' => 'cod_check',
        default => 'online',
    };
}

function shipping_rate_matches_payment(array $rate, string $paymentMethod): bool
{
    return match (shipping_payment_group($paymentMethod)) {
        'cod' => (int) ($rate['courier_supports_cod'] ?? 0) === 1
            && (int) ($rate['rate_supports_cod'] ?? 0) === 1,
        'cod_check' => (int) ($rate['courier_supports_cod_check'] ?? 0) === 1
            && (int) ($rate['rate_supports_cod_check'] ?? 0) === 1,
        default => true,
    };
}

/**
 * Menyamakan penulisan wilayah agar variasi seperti:
 * "Kabupaten Rembang", "Kab. Rembang", dan "Rembang"
 * diperlakukan sebagai wilayah yang sama.
 */
function normalize_shipping_region(string $value, string $type): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    $value = preg_replace('/[.,()\-\/]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    $value = trim($value);

    $prefixPatterns = match ($type) {
        'province' => [
            '/^(provinsi|prov)\s+/u',
        ],
        'city' => [
            '/^(kabupaten|kab|kota administrasi|kota adm|kota)\s+/u',
        ],
        'district' => [
            '/^(kecamatan|kec)\s+/u',
        ],
        default => [],
    };

    foreach ($prefixPatterns as $pattern) {
        $value = preg_replace($pattern, '', $value) ?? $value;
    }

    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function get_shipping_options(
    PDO $pdo,
    array $address,
    int $totalWeightGrams,
    string $paymentMethod = 'dana'
): array {
    $province = trim((string) ($address['province'] ?? $address['shipping_province'] ?? ''));
    $city = trim((string) ($address['city'] ?? $address['shipping_city'] ?? ''));
    $district = trim((string) ($address['district'] ?? $address['shipping_district'] ?? ''));

    $normalizedProvince = normalize_shipping_region($province, 'province');
    $normalizedCity = normalize_shipping_region($city, 'city');
    $normalizedDistrict = normalize_shipping_region($district, 'district');

    if ($normalizedProvince === '' || $normalizedCity === '') {
        return [];
    }

    /*
     * Ambil seluruh tarif aktif lalu cocokkan wilayah di PHP.
     * Pendekatan ini lebih tahan terhadap perbedaan penulisan
     * "Kabupaten", "Kab.", "Kota", "Kecamatan", dan "Kec.".
     */
    $statement = $pdo->query(
        "SELECT r.id AS rate_id, r.shipping_service_id, r.province, r.city, r.district,
                r.first_kg_price, r.next_kg_price, r.cod_fee,
                r.supports_cod AS rate_supports_cod,
                r.supports_cod_check AS rate_supports_cod_check,
                s.id AS service_id, s.service_code, s.service_name, s.description,
                s.min_delivery_days, s.max_delivery_days,
                c.id AS courier_id, c.courier_code, c.courier_name, c.logo,
                c.supports_cod AS courier_supports_cod,
                c.supports_cod_check AS courier_supports_cod_check
         FROM shipping_rates r
         INNER JOIN shipping_services s ON s.id = r.shipping_service_id
         INNER JOIN shipping_couriers c ON c.id = s.courier_id
         WHERE r.is_active = 1
           AND s.is_active = 1
           AND c.is_active = 1
         ORDER BY c.courier_name, s.service_name,
                  CASE WHEN r.district IS NULL OR TRIM(r.district) = '' THEN 1 ELSE 0 END"
    );

    $rounding = (int) setting($pdo, 'shipping_rounding_grams', '1000');
    $chargedWeight = charged_weight_kg($totalWeightGrams, $rounding);
    $bestByService = [];

    foreach ($statement->fetchAll() as $row) {
        $rateProvince = normalize_shipping_region((string) ($row['province'] ?? ''), 'province');
        $rateCity = normalize_shipping_region((string) ($row['city'] ?? ''), 'city');
        $rateDistrictRaw = trim((string) ($row['district'] ?? ''));
        $rateDistrict = normalize_shipping_region($rateDistrictRaw, 'district');

        if ($rateProvince !== $normalizedProvince || $rateCity !== $normalizedCity) {
            continue;
        }

        if ($rateDistrict !== '' && $rateDistrict !== $normalizedDistrict) {
            continue;
        }

        if (!shipping_rate_matches_payment($row, $paymentMethod)) {
            continue;
        }

        $serviceId = (int) $row['service_id'];
        $isSpecific = $rateDistrict !== '';

        /*
         * Tarif kecamatan lebih diprioritaskan daripada tarif umum kota.
         */
        if (isset($bestByService[$serviceId])) {
            $currentSpecific = (bool) ($bestByService[$serviceId]['is_specific_district'] ?? false);

            if ($currentSpecific && !$isSpecific) {
                continue;
            }

            if ($currentSpecific === $isSpecific) {
                continue;
            }
        }

        $shippingCost = calculate_shipping_cost($row, $chargedWeight);
        $codFee = is_cod_payment($paymentMethod)
            ? max(0.0, (float) $row['cod_fee'])
            : 0.0;

        $bestByService[$serviceId] = [
            'rate_id' => (int) $row['rate_id'],
            'courier_id' => (int) $row['courier_id'],
            'courier_code' => (string) $row['courier_code'],
            'courier_name' => (string) $row['courier_name'],
            'courier_logo' => (string) ($row['logo'] ?? ''),
            'service_id' => $serviceId,
            'service_code' => (string) $row['service_code'],
            'service_name' => (string) $row['service_name'],
            'description' => (string) ($row['description'] ?? ''),
            'estimation' => shipping_estimation_label(
                (int) $row['min_delivery_days'],
                (int) $row['max_delivery_days']
            ),
            'charged_weight_kg' => $chargedWeight,
            'shipping_cost' => $shippingCost,
            'cod_fee' => $codFee,
            'total_delivery_cost' => $shippingCost + $codFee,
            'destination' => implode(', ', array_filter([$district, $city, $province])),
            'is_specific_district' => $isSpecific,
        ];
    }

    $options = array_values($bestByService);

    foreach ($options as &$option) {
        unset($option['is_specific_district']);
    }
    unset($option);

    usort(
        $options,
        static fn (array $a, array $b): int =>
            [$a['total_delivery_cost'], $a['courier_name'], $a['service_name']]
            <=> [$b['total_delivery_cost'], $b['courier_name'], $b['service_name']]
    );

    return $options;
}

function find_shipping_option(
    PDO $pdo,
    int $rateId,
    array $address,
    int $totalWeightGrams,
    string $paymentMethod
): ?array {
    foreach (get_shipping_options($pdo, $address, $totalWeightGrams, $paymentMethod) as $option) {
        if ((int) $option['rate_id'] === $rateId) {
            return $option;
        }
    }

    return null;
}

function normalize_voucher_code(?string $code): string
{
    return strtoupper(trim((string) $code));
}

function promotion_discount_type_label(string $type): string
{
    return match ($type) {
        'PERCENTAGE' => 'Diskon Persentase',
        'FIXED_AMOUNT' => 'Potongan Nominal',
        'FREE_SHIPPING' => 'Gratis Ongkir',
        default => 'Promo',
    };
}

function promotion_trigger_label(string $trigger): string
{
    return $trigger === 'AUTOMATIC' ? 'Promo Otomatis' : 'Voucher Kode';
}

function promotion_value_label(array $promotion): string
{
    return match ((string) ($promotion['discount_type'] ?? '')) {
        'PERCENTAGE' => number_format((float) ($promotion['discount_value'] ?? 0), 0, ',', '.') . '%',
        'FIXED_AMOUNT' => rupiah((float) ($promotion['discount_value'] ?? 0)),
        'FREE_SHIPPING' => 'Gratis Ongkir',
        default => 'Promo',
    };
}

function get_promotion_rules(PDO $pdo, int $promotionId): array
{
    $productStatement = $pdo->prepare('SELECT product_id FROM promotion_products WHERE promotion_id = :id');
    $productStatement->execute(['id' => $promotionId]);

    $categoryStatement = $pdo->prepare('SELECT category_id FROM promotion_categories WHERE promotion_id = :id');
    $categoryStatement->execute(['id' => $promotionId]);

    $paymentStatement = $pdo->prepare('SELECT payment_method FROM promotion_payment_methods WHERE promotion_id = :id');
    $paymentStatement->execute(['id' => $promotionId]);

    $shippingStatement = $pdo->prepare('SELECT shipping_service_id FROM promotion_shipping_services WHERE promotion_id = :id');
    $shippingStatement->execute(['id' => $promotionId]);

    return [
        'product_ids' => array_map('intval', $productStatement->fetchAll(PDO::FETCH_COLUMN)),
        'category_ids' => array_map('intval', $categoryStatement->fetchAll(PDO::FETCH_COLUMN)),
        'payment_methods' => array_map('strval', $paymentStatement->fetchAll(PDO::FETCH_COLUMN)),
        'shipping_service_ids' => array_map('intval', $shippingStatement->fetchAll(PDO::FETCH_COLUMN)),
    ];
}

function promotion_eligible_subtotal(array $items, array $rules): float
{
    $productIds = $rules['product_ids'] ?? [];
    $categoryIds = $rules['category_ids'] ?? [];
    $hasProductRule = $productIds !== [];
    $hasCategoryRule = $categoryIds !== [];

    if (!$hasProductRule && !$hasCategoryRule) {
        return array_reduce(
            $items,
            static fn (float $sum, array $item): float => $sum + (float) ($item['subtotal'] ?? 0),
            0.0
        );
    }

    $eligible = 0.0;
    foreach ($items as $item) {
        $productMatch = $hasProductRule && in_array((int) ($item['product_id'] ?? $item['id'] ?? 0), $productIds, true);
        $categoryMatch = $hasCategoryRule && in_array((int) ($item['category_id'] ?? 0), $categoryIds, true);
        if ($productMatch || $categoryMatch) {
            $eligible += (float) ($item['subtotal'] ?? 0);
        }
    }

    return $eligible;
}

function promotion_user_usage_count(PDO $pdo, int $promotionId, int $userId): int
{
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM promotion_usages
         WHERE promotion_id = :promotion_id
           AND user_id = :user_id
           AND usage_status = 'USED'"
    );
    $statement->execute([
        'promotion_id' => $promotionId,
        'user_id' => $userId,
    ]);
    return (int) $statement->fetchColumn();
}

function evaluate_promotion(
    array $promotion,
    array $rules,
    int $userUsageCount,
    array $items,
    float $subtotal,
    float $shippingCost,
    string $paymentMethod,
    int $shippingServiceId
): array {
    $now = time();
    $start = strtotime((string) ($promotion['start_at'] ?? ''));
    $end = strtotime((string) ($promotion['end_at'] ?? ''));

    if ((int) ($promotion['is_active'] ?? 0) !== 1) {
        return ['valid' => false, 'error' => 'Promo sedang tidak aktif.'];
    }
    if ($start !== false && $now < $start) {
        return ['valid' => false, 'error' => 'Promo belum dapat digunakan.'];
    }
    if ($end !== false && $now > $end) {
        return ['valid' => false, 'error' => 'Promo telah berakhir.'];
    }

    $totalUsageLimit = $promotion['total_usage_limit'] === null ? null : (int) $promotion['total_usage_limit'];
    if ($totalUsageLimit !== null && (int) ($promotion['current_usage'] ?? 0) >= $totalUsageLimit) {
        return ['valid' => false, 'error' => 'Kuota promo telah habis.'];
    }

    $perUserLimit = max(1, (int) ($promotion['usage_limit_per_user'] ?? 1));
    if ($userUsageCount >= $perUserLimit) {
        return ['valid' => false, 'error' => 'Batas penggunaan promo untuk akun Anda telah tercapai.'];
    }

    if ($subtotal < (float) ($promotion['minimum_purchase'] ?? 0)) {
        return [
            'valid' => false,
            'error' => 'Minimal pembelian ' . rupiah((float) ($promotion['minimum_purchase'] ?? 0)) . ' belum terpenuhi.',
        ];
    }

    $paymentMethods = $rules['payment_methods'] ?? [];
    if ($paymentMethods !== [] && !in_array($paymentMethod, $paymentMethods, true)) {
        return ['valid' => false, 'error' => 'Promo tidak berlaku untuk metode pembayaran yang dipilih.'];
    }

    $shippingServiceIds = $rules['shipping_service_ids'] ?? [];
    if ($shippingServiceIds !== [] && !in_array($shippingServiceId, $shippingServiceIds, true)) {
        return ['valid' => false, 'error' => 'Promo tidak berlaku untuk layanan pengiriman yang dipilih.'];
    }

    $eligibleSubtotal = promotion_eligible_subtotal($items, $rules);
    if ($eligibleSubtotal <= 0) {
        return ['valid' => false, 'error' => 'Tidak ada produk dalam keranjang yang memenuhi promo.'];
    }

    $productDiscount = 0.0;
    $shippingDiscount = 0.0;
    $discountType = (string) ($promotion['discount_type'] ?? '');

    if ($discountType === 'PERCENTAGE') {
        $productDiscount = $eligibleSubtotal * max(0.0, (float) ($promotion['discount_value'] ?? 0)) / 100;
        if ($promotion['maximum_discount'] !== null) {
            $productDiscount = min($productDiscount, max(0.0, (float) $promotion['maximum_discount']));
        }
        $productDiscount = min($productDiscount, $eligibleSubtotal);
    } elseif ($discountType === 'FIXED_AMOUNT') {
        $productDiscount = min(max(0.0, (float) ($promotion['discount_value'] ?? 0)), $eligibleSubtotal);
    } elseif ($discountType === 'FREE_SHIPPING') {
        $maximumShipping = $promotion['maximum_shipping_discount'] === null
            ? $shippingCost
            : max(0.0, (float) $promotion['maximum_shipping_discount']);
        $shippingDiscount = min(max(0.0, $shippingCost), $maximumShipping);
    } else {
        return ['valid' => false, 'error' => 'Jenis promo tidak didukung.'];
    }

    $benefit = $productDiscount + $shippingDiscount;
    if ($benefit <= 0) {
        return ['valid' => false, 'error' => 'Promo belum menghasilkan potongan untuk pilihan checkout ini.'];
    }

    return [
        'valid' => true,
        'error' => null,
        'eligible_subtotal' => $eligibleSubtotal,
        'product_discount' => round($productDiscount, 2),
        'shipping_discount' => round($shippingDiscount, 2),
        'benefit' => round($benefit, 2),
    ];
}

function resolve_checkout_promotion(
    PDO $pdo,
    int $userId,
    array $items,
    float $subtotal,
    float $shippingCost,
    string $paymentMethod,
    int $shippingServiceId,
    ?string $voucherCode = null,
    bool $lock = false
): ?array {
    $voucherCode = normalize_voucher_code($voucherCode);
    $suffix = $lock ? ' FOR UPDATE' : '';

    if ($voucherCode !== '') {
        $statement = $pdo->prepare(
            "SELECT * FROM promotions
             WHERE UPPER(TRIM(code)) = :code
             LIMIT 1{$suffix}"
        );
        $statement->execute(['code' => $voucherCode]);
        $promotion = $statement->fetch();
        if (!$promotion) {
            throw new RuntimeException('Kode voucher tidak ditemukan.');
        }

        $rules = get_promotion_rules($pdo, (int) $promotion['id']);
        $evaluation = evaluate_promotion(
            $promotion,
            $rules,
            promotion_user_usage_count($pdo, (int) $promotion['id'], $userId),
            $items,
            $subtotal,
            $shippingCost,
            $paymentMethod,
            $shippingServiceId
        );
        if (!$evaluation['valid']) {
            throw new RuntimeException((string) $evaluation['error']);
        }

        return array_merge($promotion, $evaluation, ['rules' => $rules]);
    }

    $statement = $pdo->query(
        "SELECT * FROM promotions
         WHERE trigger_type = 'AUTOMATIC'
           AND is_active = 1
           AND start_at <= NOW()
           AND end_at >= NOW()
         ORDER BY id ASC" . $suffix
    );

    $best = null;
    foreach ($statement->fetchAll() as $promotion) {
        $rules = get_promotion_rules($pdo, (int) $promotion['id']);
        $evaluation = evaluate_promotion(
            $promotion,
            $rules,
            promotion_user_usage_count($pdo, (int) $promotion['id'], $userId),
            $items,
            $subtotal,
            $shippingCost,
            $paymentMethod,
            $shippingServiceId
        );
        if (!$evaluation['valid']) {
            continue;
        }

        $candidate = array_merge($promotion, $evaluation, ['rules' => $rules]);
        if ($best === null || (float) $candidate['benefit'] > (float) $best['benefit']) {
            $best = $candidate;
        }
    }

    return $best;
}

function promotion_preview_data(PDO $pdo, int $userId, array $items, ?string $voucherCode = null): array
{
    $voucherCode = normalize_voucher_code($voucherCode);
    $parameters = [];
    $where = "is_active = 1 AND start_at <= NOW() AND end_at >= NOW()";
    if ($voucherCode !== '') {
        $where .= ' AND UPPER(TRIM(code)) = :code';
        $parameters['code'] = $voucherCode;
    } else {
        $where .= " AND trigger_type = 'AUTOMATIC'";
    }

    $statement = $pdo->prepare("SELECT * FROM promotions WHERE {$where} ORDER BY id ASC");
    $statement->execute($parameters);

    $rows = [];
    foreach ($statement->fetchAll() as $promotion) {
        $rules = get_promotion_rules($pdo, (int) $promotion['id']);
        $eligibleSubtotal = promotion_eligible_subtotal($items, $rules);
        $rows[] = [
            'id' => (int) $promotion['id'],
            'name' => (string) $promotion['name'],
            'code' => (string) ($promotion['code'] ?? ''),
            'trigger_type' => (string) $promotion['trigger_type'],
            'discount_type' => (string) $promotion['discount_type'],
            'discount_value' => (float) $promotion['discount_value'],
            'maximum_discount' => $promotion['maximum_discount'] === null ? null : (float) $promotion['maximum_discount'],
            'minimum_purchase' => (float) $promotion['minimum_purchase'],
            'maximum_shipping_discount' => $promotion['maximum_shipping_discount'] === null ? null : (float) $promotion['maximum_shipping_discount'],
            'eligible_subtotal' => $eligibleSubtotal,
            'payment_methods' => $rules['payment_methods'],
            'shipping_service_ids' => $rules['shipping_service_ids'],
            'usage_available' => (
                ($promotion['total_usage_limit'] === null || (int) $promotion['current_usage'] < (int) $promotion['total_usage_limit'])
                && promotion_user_usage_count($pdo, (int) $promotion['id'], $userId) < max(1, (int) $promotion['usage_limit_per_user'])
            ),
        ];
    }

    return $rows;
}

function restore_promotion_usage(PDO $pdo, int $orderId): bool
{
    $statement = $pdo->prepare(
        "SELECT id, promotion_id
         FROM promotion_usages
         WHERE order_id = :order_id AND usage_status = 'USED'
         LIMIT 1 FOR UPDATE"
    );
    $statement->execute(['order_id' => $orderId]);
    $usage = $statement->fetch();
    if (!$usage) {
        return false;
    }

    $update = $pdo->prepare(
        "UPDATE promotion_usages
         SET usage_status = 'RESTORED', restored_at = NOW()
         WHERE id = :id AND usage_status = 'USED'"
    );
    $update->execute(['id' => (int) $usage['id']]);
    if ($update->rowCount() !== 1) {
        return false;
    }

    $pdo->prepare(
        'UPDATE promotions
         SET current_usage = GREATEST(current_usage - 1, 0), updated_at = NOW()
         WHERE id = :id'
    )->execute(['id' => (int) $usage['promotion_id']]);

    return true;
}

function promotion_product_badges(PDO $pdo, array $products): array
{
    if ($products === []) {
        return [];
    }

    $activePromotions = $pdo->query(
        "SELECT * FROM promotions
         WHERE is_active = 1 AND start_at <= NOW() AND end_at >= NOW()
         ORDER BY trigger_type = 'AUTOMATIC' DESC, id DESC"
    )->fetchAll();

    $badges = [];
    foreach ($activePromotions as $promotion) {
        $rules = get_promotion_rules($pdo, (int) $promotion['id']);
        $hasRules = $rules['product_ids'] !== [] || $rules['category_ids'] !== [];

        foreach ($products as $product) {
            $productId = (int) ($product['id'] ?? 0);
            $categoryId = (int) ($product['category_id'] ?? 0);
            $matches = !$hasRules
                || in_array($productId, $rules['product_ids'], true)
                || in_array($categoryId, $rules['category_ids'], true);
            if (!$matches || isset($badges[$productId])) {
                continue;
            }

            $badges[$productId] = [
                'label' => (string) $promotion['discount_type'] === 'FREE_SHIPPING'
                    ? 'Gratis Ongkir'
                    : promotion_value_label($promotion),
                'code' => (string) ($promotion['code'] ?? ''),
                'name' => (string) $promotion['name'],
            ];
        }
    }

    return $badges;
}

function record_shipment_history(
    PDO $pdo,
    int $orderId,
    string $status,
    ?string $description = null,
    ?string $location = null,
    ?int $changedBy = null
): void {
    $statement = $pdo->prepare(
        'INSERT INTO shipment_histories (order_id, status, description, location, changed_by)
         VALUES (:order_id, :status, :description, :location, :changed_by)'
    );
    $statement->execute([
        'order_id' => $orderId,
        'status' => $status,
        'description' => $description,
        'location' => $location,
        'changed_by' => $changedBy,
    ]);
}

function variant_description(array $item): string
{
    $parts = [];
    foreach (['variant_color', 'variant_size', 'variant_material'] as $key) {
        $value = trim((string) ($item[$key] ?? ''));
        if ($value !== '' && !in_array($value, $parts, true)) {
            $parts[] = $value;
        }
    }

    if ($parts !== []) {
        return implode(' / ', $parts);
    }

    return trim((string) ($item['variant_name'] ?? ''));
}

function category_icon(string $category): string
{
    return match (strtolower($category)) {
        'elektronik' => '💻',
        'fashion' => '👟',
        'aksesoris' => '🎧',
        'rumah tangga' => '🏠',
        'makanan dan minuman' => '🍱',
        'kecantikan' => '✨',
        default => '🛍️',
    };
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '';
    return trim($value, '-') ?: 'item';
}

function save_uploaded_image(array $file, string $subdirectory, string $prefix = 'image', int $maxBytes = 2097152): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Unggahan gambar gagal.');
    }
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        throw new RuntimeException('Ukuran gambar maksimal 2 MB.');
    }

    $temporaryFile = (string) ($file['tmp_name'] ?? '');
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($temporaryFile);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('Format gambar harus JPG, PNG, WEBP, atau GIF.');
    }

    $subdirectory = trim($subdirectory, '/');
    $relativeDirectory = 'uploads/' . $subdirectory;
    $absoluteDirectory = dirname(__DIR__) . '/' . $relativeDirectory;
    if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0775, true) && !is_dir($absoluteDirectory)) {
        throw new RuntimeException('Folder unggahan tidak dapat dibuat.');
    }

    $fileName = slugify($prefix) . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    $destination = $absoluteDirectory . '/' . $fileName;
    if (!move_uploaded_file($temporaryFile, $destination)) {
        throw new RuntimeException('Gambar gagal disimpan.');
    }

    return $relativeDirectory . '/' . $fileName;
}

function get_cart_details(PDO $pdo): array
{
    $cart = normalize_cart($_SESSION['cart'] ?? []);
    $_SESSION['cart'] = $cart;

    if ($cart === []) {
        return [
            'items' => [], 'subtotal' => 0.0, 'shipping' => 0.0,
            'total' => 0.0, 'total_weight_grams' => 0,
        ];
    }

    $productStatement = $pdo->prepare(
        "SELECT id, name, description, category, category_id, price, stock, image, status,
                has_variants, default_weight_grams
         FROM products WHERE id = :id LIMIT 1"
    );
    $variantStatement = $pdo->prepare(
        "SELECT id, product_id, sku, variant_name, color, size, material,
                price, stock, weight_grams, image, is_active
         FROM product_variants
         WHERE id = :id AND product_id = :product_id LIMIT 1"
    );

    $items = [];
    $subtotal = 0.0;
    $totalWeight = 0;

    foreach ($cart as $cartKey => $entry) {
        $productStatement->execute(['id' => (int) $entry['product_id']]);
        $product = $productStatement->fetch();
        if (!$product || (string) $product['status'] !== 'active') {
            continue;
        }

        $variant = null;
        $hasVariants = (int) $product['has_variants'] === 1;
        if ($hasVariants) {
            if ((int) $entry['variant_id'] <= 0) {
                continue;
            }
            $variantStatement->execute([
                'id' => (int) $entry['variant_id'],
                'product_id' => (int) $product['id'],
            ]);
            $variant = $variantStatement->fetch();
            if (!$variant || (int) $variant['is_active'] !== 1) {
                continue;
            }
        }

        $quantity = max(1, (int) $entry['quantity']);
        $price = $hasVariants ? (float) $variant['price'] : (float) $product['price'];
        $stock = $hasVariants ? (int) $variant['stock'] : (int) $product['stock'];
        $weight = $hasVariants ? (int) $variant['weight_grams'] : (int) $product['default_weight_grams'];
        $image = $hasVariants && trim((string) ($variant['image'] ?? '')) !== ''
            ? (string) $variant['image']
            : (string) $product['image'];
        $itemSubtotal = $price * $quantity;
        $itemWeight = max(0, $weight) * $quantity;

        $items[] = [
            'cart_key' => $cartKey,
            'id' => (int) $product['id'],
            'product_id' => (int) $product['id'],
            'name' => (string) $product['name'],
            'description' => (string) $product['description'],
            'category' => (string) $product['category'],
            'category_id' => (int) ($product['category_id'] ?? 0),
            'has_variants' => $hasVariants ? 1 : 0,
            'variant_id' => $hasVariants ? (int) $variant['id'] : 0,
            'variant_name' => $hasVariants ? (string) $variant['variant_name'] : null,
            'variant_sku' => $hasVariants ? (string) $variant['sku'] : null,
            'variant_color' => $hasVariants ? (string) ($variant['color'] ?? '') : null,
            'variant_size' => $hasVariants ? (string) ($variant['size'] ?? '') : null,
            'variant_material' => $hasVariants ? (string) ($variant['material'] ?? '') : null,
            'price' => $price,
            'stock' => $stock,
            'image' => $image,
            'quantity' => $quantity,
            'subtotal' => $itemSubtotal,
            'weight_grams' => max(0, $weight),
            'total_weight_grams' => $itemWeight,
        ];
        $subtotal += $itemSubtotal;
        $totalWeight += $itemWeight;
    }

    return [
        'items' => $items,
        'subtotal' => $subtotal,
        'shipping' => 0.0,
        'total' => $subtotal,
        'total_weight_grams' => $totalWeight,
    ];
}


/**
 * Mengambil detail satu produk untuk mode pembelian langsung.
 * Bentuk hasil dibuat sama seperti get_cart_details() agar checkout lama
 * dapat digunakan tanpa membuat tabel baru dan tanpa mengganggu keranjang.
 */
function get_direct_purchase_details(PDO $pdo, ?array $purchase = null): array
{
    $purchase ??= is_array($_SESSION['direct_purchase'] ?? null)
        ? $_SESSION['direct_purchase']
        : [];

    $productId = max(0, (int) ($purchase['product_id'] ?? 0));
    $variantId = max(0, (int) ($purchase['variant_id'] ?? 0));
    $quantity = max(1, min(999, (int) ($purchase['quantity'] ?? 1)));

    $empty = [
        'items' => [],
        'subtotal' => 0.0,
        'shipping' => 0.0,
        'total' => 0.0,
        'total_weight_grams' => 0,
    ];

    if ($productId <= 0) {
        return $empty;
    }

    $productStatement = $pdo->prepare(
        "SELECT id, name, description, category, category_id, price, stock, image, status,
                has_variants, default_weight_grams
         FROM products
         WHERE id = :id
         LIMIT 1"
    );
    $productStatement->execute(['id' => $productId]);
    $product = $productStatement->fetch();

    if (!$product || (string) ($product['status'] ?? 'inactive') !== 'active') {
        return $empty;
    }

    $hasVariants = (int) $product['has_variants'] === 1;
    $variant = null;

    if ($hasVariants) {
        if ($variantId <= 0) {
            return $empty;
        }

        $variantStatement = $pdo->prepare(
            "SELECT id, product_id, sku, variant_name, color, size, material,
                    price, stock, weight_grams, image, is_active
             FROM product_variants
             WHERE id = :id AND product_id = :product_id
             LIMIT 1"
        );
        $variantStatement->execute([
            'id' => $variantId,
            'product_id' => $productId,
        ]);
        $variant = $variantStatement->fetch();

        if (!$variant || (int) $variant['is_active'] !== 1) {
            return $empty;
        }
    } else {
        $variantId = 0;
    }

    $stock = $hasVariants ? (int) $variant['stock'] : (int) $product['stock'];
    if ($stock <= 0) {
        return $empty;
    }
    $quantity = min($quantity, $stock);

    $price = $hasVariants ? (float) $variant['price'] : (float) $product['price'];
    $weight = $hasVariants ? (int) $variant['weight_grams'] : (int) $product['default_weight_grams'];
    if ($weight <= 0) {
        return $empty;
    }

    $image = $hasVariants && trim((string) ($variant['image'] ?? '')) !== ''
        ? (string) $variant['image']
        : (string) $product['image'];
    $subtotal = $price * $quantity;
    $totalWeight = $weight * $quantity;

    return [
        'items' => [[
            'cart_key' => 'direct_' . $productId . '_' . $variantId,
            'id' => $productId,
            'product_id' => $productId,
            'name' => (string) $product['name'],
            'description' => (string) $product['description'],
            'category' => (string) $product['category'],
            'category_id' => (int) ($product['category_id'] ?? 0),
            'has_variants' => $hasVariants ? 1 : 0,
            'variant_id' => $hasVariants ? (int) $variant['id'] : 0,
            'variant_name' => $hasVariants ? (string) $variant['variant_name'] : null,
            'variant_sku' => $hasVariants ? (string) $variant['sku'] : null,
            'variant_color' => $hasVariants ? (string) ($variant['color'] ?? '') : null,
            'variant_size' => $hasVariants ? (string) ($variant['size'] ?? '') : null,
            'variant_material' => $hasVariants ? (string) ($variant['material'] ?? '') : null,
            'price' => $price,
            'stock' => $stock,
            'image' => $image,
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'weight_grams' => $weight,
            'total_weight_grams' => $totalWeight,
        ]],
        'subtotal' => $subtotal,
        'shipping' => 0.0,
        'total' => $subtotal,
        'total_weight_grams' => $totalWeight,
    ];
}

function clear_direct_purchase(): void
{
    unset($_SESSION['direct_purchase']);
}

function address_icon(string $label): string
{
    return match (strtolower(trim($label))) {
        'rumah', 'rumah orang tua' => '🏠',
        'kantor' => '🏢',
        'kos', 'kontrakan' => '🛏️',
        default => '📍',
    };
}

function address_region_text(array $address, bool $orderSnapshot = false): string
{
    $prefix = $orderSnapshot ? 'shipping_' : '';
    $parts = [];

    foreach (['village', 'district', 'city', 'province'] as $key) {
        $value = trim((string) ($address[$prefix . $key] ?? ''));
        if ($value !== '') {
            $parts[] = $value;
        }
    }

    $postalCode = trim((string) ($address[$prefix . 'postal_code'] ?? ''));
    if ($postalCode !== '') {
        $parts[] = $postalCode;
    }

    return implode(', ', $parts);
}

function validate_address_input(array $source): array
{
    $data = [
        'label' => trim((string) ($source['label'] ?? '')),
        'recipient_name' => trim((string) ($source['recipient_name'] ?? '')),
        'phone' => trim((string) ($source['phone'] ?? '')),
        'province' => trim((string) ($source['province'] ?? '')),
        'city' => trim((string) ($source['city'] ?? '')),
        'district' => trim((string) ($source['district'] ?? '')),
        'village' => trim((string) ($source['village'] ?? '')),
        'postal_code' => trim((string) ($source['postal_code'] ?? '')),
        'full_address' => trim((string) ($source['full_address'] ?? '')),
        'courier_note' => trim((string) ($source['courier_note'] ?? '')),
        'is_primary' => isset($source['is_primary']) && (string) $source['is_primary'] === '1' ? 1 : 0,
    ];

    $errors = [];

    if (strlen($data['label']) < 2 || strlen($data['label']) > 50) {
        $errors[] = 'Label alamat harus terdiri dari 2 sampai 50 karakter.';
    }
    if (strlen($data['recipient_name']) < 3 || strlen($data['recipient_name']) > 100) {
        $errors[] = 'Nama penerima harus terdiri dari 3 sampai 100 karakter.';
    }
    if (!preg_match('/^[0-9+\-\s]{8,20}$/', $data['phone'])) {
        $errors[] = 'Nomor telepon harus terdiri dari 8 sampai 20 angka.';
    }
    if (strlen($data['province']) < 2 || strlen($data['province']) > 100) {
        $errors[] = 'Provinsi wajib diisi dengan benar.';
    }
    if (strlen($data['city']) < 2 || strlen($data['city']) > 100) {
        $errors[] = 'Kota atau kabupaten wajib diisi dengan benar.';
    }
    if (strlen($data['district']) < 2 || strlen($data['district']) > 100) {
        $errors[] = 'Kecamatan wajib diisi dengan benar.';
    }
    if (strlen($data['village']) > 100) {
        $errors[] = 'Kelurahan atau desa maksimal 100 karakter.';
    }
    if (!preg_match('/^[0-9]{5}$/', $data['postal_code'])) {
        $errors[] = 'Kode pos harus terdiri dari tepat 5 angka.';
    }
    if (strlen($data['full_address']) < 10 || strlen($data['full_address']) > 1000) {
        $errors[] = 'Alamat lengkap harus terdiri dari 10 sampai 1000 karakter.';
    }
    if (strlen($data['courier_note']) > 255) {
        $errors[] = 'Catatan untuk kurir maksimal 255 karakter.';
    }

    return [$data, $errors];
}


function return_status_label(string $status): string
{
    return match ($status) {
        'SUBMITTED' => 'Diajukan',
        'UNDER_REVIEW' => 'Sedang Ditinjau',
        'APPROVED' => 'Disetujui',
        'CUSTOMER_SHIPPED' => 'Barang Dikirim Pelanggan',
        'STORE_RECEIVED' => 'Barang Diterima Toko',
        'PROCESSING' => 'Sedang Diproses',
        'COMPLETED' => 'Selesai',
        'REJECTED' => 'Ditolak',
        'CANCELLED' => 'Dibatalkan Pelanggan',
        default => 'Status Tidak Diketahui',
    };
}

function return_status_css_class(string $status): string
{
    return match ($status) {
        'SUBMITTED', 'UNDER_REVIEW' => 'status-waiting',
        'APPROVED', 'CUSTOMER_SHIPPED', 'STORE_RECEIVED', 'PROCESSING' => 'status-processing',
        'COMPLETED' => 'status-complete',
        'REJECTED', 'CANCELLED' => 'status-rejected',
        default => 'status-neutral',
    };
}

function return_resolution_label(string $resolution): string
{
    return match ($resolution) {
        'REFUND' => 'Pengembalian Dana',
        'REPLACEMENT' => 'Penggantian Barang',
        default => 'Penyelesaian Retur',
    };
}

function return_refund_status_label(string $status): string
{
    return match ($status) {
        'PENDING' => 'Menunggu Diproses',
        'PROCESSING' => 'Sedang Diproses',
        'COMPLETED' => 'Selesai',
        'FAILED' => 'Gagal',
        default => 'Tidak Diperlukan',
    };
}

function return_reasons(): array
{
    return [
        'damaged' => 'Produk rusak',
        'not_working' => 'Produk tidak berfungsi',
        'not_as_described' => 'Produk tidak sesuai deskripsi',
        'wrong_product' => 'Salah produk',
        'wrong_variant' => 'Salah ukuran atau warna',
        'missing_quantity' => 'Jumlah produk kurang',
        'incomplete' => 'Produk tidak lengkap',
        'expired' => 'Produk kedaluwarsa',
        'broken_shipping' => 'Barang pecah saat pengiriman',
        'quality' => 'Kualitas produk tidak sesuai',
        'other' => 'Alasan lainnya',
    ];
}

function return_reason_requires_image(string $reasonCode): bool
{
    return in_array($reasonCode, [
        'damaged', 'not_working', 'not_as_described', 'wrong_product',
        'missing_quantity', 'incomplete', 'expired', 'broken_shipping', 'quality',
    ], true);
}

function return_period_days(PDO $pdo): int
{
    return max(1, min(30, (int) setting($pdo, 'return_period_days', '7')));
}

function return_number(PDO $pdo): string
{
    $check = $pdo->prepare('SELECT COUNT(*) FROM return_requests WHERE return_number = :number');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $number = 'RTN-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $check->execute(['number' => $number]);
        if ((int) $check->fetchColumn() === 0) {
            return $number;
        }
    }

    return 'RTN-' . date('YmdHis') . '-' . random_int(1000, 9999);
}

function record_return_history(
    PDO $pdo,
    int $returnRequestId,
    string $status,
    string $title,
    ?string $description = null,
    ?int $changedBy = null
): void {
    $statement = $pdo->prepare(
        'INSERT INTO return_histories (
            return_request_id, status, title, description, changed_by
         ) VALUES (
            :return_request_id, :status, :title, :description, :changed_by
         )'
    );
    $statement->execute([
        'return_request_id' => $returnRequestId,
        'status' => $status,
        'title' => $title,
        'description' => $description,
        'changed_by' => $changedBy,
    ]);
}

function return_upload_directory(): string
{
    return dirname(__DIR__) . '/uploads/returns';
}

function return_delete_image_file(?string $relativePath): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '' || !str_starts_with($relativePath, 'uploads/returns/')) {
        return;
    }

    $fullPath = dirname(__DIR__) . '/' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function store_return_images(
    PDO $pdo,
    int $returnRequestId,
    ?int $returnItemId,
    array $files,
    string $imageType = 'EVIDENCE',
    int $existingCount = 0
): array {
    if ($returnRequestId <= 0 || !isset($files['name']) || !is_array($files['name'])) {
        return [];
    }

    $maxImages = 5;
    $maxBytes = 2 * 1024 * 1024;
    $allowedMimeTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $remaining = max(0, $maxImages - $existingCount);
    if ($remaining <= 0) {
        return ['Maksimal lima foto untuk setiap pengajuan retur.'];
    }

    $directory = return_upload_directory();
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        return ['Folder foto retur tidak dapat dibuat.'];
    }

    $errors = [];
    $storedPaths = [];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $totalFiles = count($files['name']);

    for ($index = 0; $index < $totalFiles; $index++) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if (count($storedPaths) >= $remaining) {
            $errors[] = 'Maksimal lima foto untuk setiap pengajuan retur.';
            break;
        }
        if ($error !== UPLOAD_ERR_OK) {
            $errors[] = 'Salah satu foto retur gagal diunggah.';
            continue;
        }

        $tmpName = (string) ($files['tmp_name'][$index] ?? '');
        $size = (int) ($files['size'][$index] ?? 0);
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $errors[] = 'Berkas unggahan foto retur tidak valid.';
            continue;
        }
        if ($size <= 0 || $size > $maxBytes) {
            $errors[] = 'Ukuran setiap foto retur maksimal 2 MB.';
            continue;
        }

        $mime = (string) $finfo->file($tmpName);
        if (!isset($allowedMimeTypes[$mime])) {
            $errors[] = 'Foto retur harus berformat JPG, PNG, atau WEBP.';
            continue;
        }

        $filename = 'return_' . $returnRequestId . '_' . bin2hex(random_bytes(12)) . '.' . $allowedMimeTypes[$mime];
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($tmpName, $target)) {
            $errors[] = 'Foto retur gagal disimpan.';
            continue;
        }
        $storedPaths[] = 'uploads/returns/' . $filename;
    }

    if ($errors !== []) {
        foreach ($storedPaths as $path) {
            return_delete_image_file($path);
        }
        return array_values(array_unique($errors));
    }

    if ($storedPaths !== []) {
        $orderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM return_images WHERE return_request_id = :return_request_id');
        $orderStatement->execute(['return_request_id' => $returnRequestId]);
        $sortOrder = (int) $orderStatement->fetchColumn() + 1;
        $insert = $pdo->prepare(
            'INSERT INTO return_images (
                return_request_id, return_item_id, image_path, image_type, sort_order
             ) VALUES (
                :return_request_id, :return_item_id, :image_path, :image_type, :sort_order
             )'
        );
        foreach ($storedPaths as $path) {
            $insert->execute([
                'return_request_id' => $returnRequestId,
                'return_item_id' => $returnItemId,
                'image_path' => $path,
                'image_type' => substr($imageType, 0, 50),
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    return array_values(array_unique($errors));
}

function returned_quantity_for_order_item(PDO $pdo, int $orderItemId, bool $lock = false): int
{
    $sql = "SELECT COALESCE(SUM(ri.return_quantity), 0)
            FROM return_items ri
            INNER JOIN return_requests rr ON rr.id = ri.return_request_id
            WHERE ri.order_item_id = :order_item_id
              AND rr.status NOT IN ('REJECTED', 'CANCELLED')";
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $statement = $pdo->prepare($sql);
    $statement->execute(['order_item_id' => $orderItemId]);
    return max(0, (int) $statement->fetchColumn());
}

function return_deadline(?string $completedAt, int $periodDays): ?DateTimeImmutable
{
    if ($completedAt === null || trim($completedAt) === '') {
        return null;
    }
    try {
        return (new DateTimeImmutable($completedAt))->modify('+' . max(1, $periodDays) . ' days');
    } catch (Throwable) {
        return null;
    }
}

function return_is_open(?string $completedAt, int $periodDays): bool
{
    $deadline = return_deadline($completedAt, $periodDays);
    return $deadline !== null && new DateTimeImmutable() <= $deadline;
}

function restock_return_item(PDO $pdo, array $item, int $adminId): bool
{
    if ((string) ($item['restock_status'] ?? '') !== 'PENDING') {
        return false;
    }

    $quantity = max(0, (int) ($item['return_quantity'] ?? 0));
    $productId = (int) ($item['product_id'] ?? 0);
    $variantId = (int) ($item['variant_id'] ?? 0);
    if ($quantity <= 0 || $productId <= 0) {
        return false;
    }

    if ($variantId > 0) {
        $select = $pdo->prepare('SELECT stock FROM product_variants WHERE id = :id AND product_id = :product_id FOR UPDATE');
        $select->execute(['id' => $variantId, 'product_id' => $productId]);
        $before = $select->fetchColumn();
        if ($before === false) {
            return false;
        }
        $after = (int) $before + $quantity;
        $pdo->prepare('UPDATE product_variants SET stock = :stock, updated_at = NOW() WHERE id = :id')
            ->execute(['stock' => $after, 'id' => $variantId]);
        sync_product_variant_summary($pdo, $productId);
        record_stock_history(
            $pdo, $productId, 'RETURN_RESTOCK', $quantity, (int) $before, $after,
            'return_request', (int) ($item['return_request_id'] ?? 0),
            'Barang retur dikembalikan ke stok.', $adminId, $variantId
        );
    } else {
        $select = $pdo->prepare('SELECT stock FROM products WHERE id = :id FOR UPDATE');
        $select->execute(['id' => $productId]);
        $before = $select->fetchColumn();
        if ($before === false) {
            return false;
        }
        $after = (int) $before + $quantity;
        $pdo->prepare('UPDATE products SET stock = :stock, updated_at = NOW() WHERE id = :id')
            ->execute(['stock' => $after, 'id' => $productId]);
        record_stock_history(
            $pdo, $productId, 'RETURN_RESTOCK', $quantity, (int) $before, $after,
            'return_request', (int) ($item['return_request_id'] ?? 0),
            'Barang retur dikembalikan ke stok.', $adminId, null
        );
    }

    $pdo->prepare(
        "UPDATE return_items
         SET restock_status = 'RESTOCKED', restocked_quantity = :quantity, restocked_at = NOW()
         WHERE id = :id AND restock_status = 'PENDING'"
    )->execute(['quantity' => $quantity, 'id' => (int) $item['id']]);

    return true;
}

function customer_return_path(?string $value, string $default = 'addresses.php'): string
{
    return in_array($value, ['addresses.php', 'checkout.php'], true) ? (string) $value : $default;
}
