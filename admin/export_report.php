<?php

declare(strict_types=1);

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/report_helpers.php';

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('PhpSpreadsheet belum terpasang. Jalankan: composer require phpoffice/phpspreadsheet');
}
require_once $autoload;

/**
 * Membuat judul sheet Excel yang aman dan maksimal 31 karakter.
 */
function report_excel_sheet_title(string $title): string
{
    $title = preg_replace('/[\\\\\/\?\*\[\]:]/', '', $title) ?: 'Laporan';
    return function_exists('mb_substr') ? mb_substr($title, 0, 31) : substr($title, 0, 31);
}

/**
 * Mengubah nilai tanggal database menjadi tanggal Excel.
 */
function report_excel_date_value(mixed $value): ?array
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $value = trim($value);
    foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $value);
        if ($date && $date->format($format) === $value) {
            return [
                'value' => ExcelDate::PHPToExcel($date),
                'format' => str_contains($format, 'H:i') ? 'dd/mm/yyyy hh:mm' : 'dd/mm/yyyy',
            ];
        }
    }

    return null;
}

/**
 * Menghitung lebar kolom yang tetap rapi dan tidak berlebihan.
 */
function report_excel_column_width(string $header, array $rows, int $index): float
{
    $length = function_exists('mb_strlen') ? mb_strlen($header) : strlen($header);

    foreach (array_slice($rows, 0, 300) as $row) {
        $value = $row[$index] ?? '';
        if ($value === null) {
            continue;
        }

        $text = (string) $value;
        $current = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        $length = max($length, min($current, 42));
    }

    if (in_array($header, ['Email', 'Catatan', 'Alasan', 'Produk', 'Pelanggan', 'Metode Pembayaran'], true)) {
        return min(max($length + 2, 22), 38);
    }

    if (in_array($header, ['Tanggal', 'Tanggal Daftar'], true)) {
        return 20;
    }

    return min(max($length + 2, 12), 30);
}

$period = report_period();
$type = report_safe_choice($_GET['type'] ?? 'sales', [
    'sales', 'orders', 'products', 'stock', 'customers', 'payments',
    'shipping', 'promotions', 'wishlist', 'reviews', 'returns',
], 'sales');
$params = [
    'from_start' => $period['from_start'],
    'to_end' => $period['to_end'],
];

$headers = [];
$dataRows = [];
$reportTitle = 'Laporan Penjualan';

switch ($type) {
    case 'orders':
        $reportTitle = 'Laporan Pesanan';
        $status = trim((string) ($_GET['status'] ?? ''));
        $payment = trim((string) ($_GET['payment'] ?? ''));
        $courier = trim((string) ($_GET['courier'] ?? ''));
        $search = trim((string) ($_GET['search'] ?? ''));
        $where = ['o.created_at BETWEEN :from_start AND :to_end'];

        if ($status !== '') {
            $where[] = 'o.status = :status';
            $params['status'] = $status;
        }
        if ($payment !== '') {
            if (in_array($payment, ['cod', 'cod_check'], true)) {
                $where[] = 'o.payment_method = :payment';
            } else {
                $where[] = 'o.payment_category = :payment';
            }
            $params['payment'] = $payment;
        }
        if ($courier !== '') {
            $where[] = 'o.shipping_courier_code = :courier';
            $params['courier'] = $courier;
        }
        if ($search !== '') {
            $where[] = '(o.order_number LIKE :search_order OR u.name LIKE :search_name OR u.email LIKE :search_email)';
            $searchValue = '%' . $search . '%';
            $params['search_order'] = $searchValue;
            $params['search_name'] = $searchValue;
            $params['search_email'] = $searchValue;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT o.*, u.name AS customer_name, u.email AS customer_email,
                    COALESCE((SELECT SUM(oi.quantity) FROM order_items oi WHERE oi.order_id = o.id), 0) AS product_quantity
             FROM orders o
             INNER JOIN users u ON u.id = o.user_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY o.created_at",
            $params
        );

        $headers = [
            'Nomor Pesanan', 'Tanggal', 'Pelanggan', 'Email', 'Jumlah Produk',
            'Subtotal', 'Diskon Produk', 'Ongkir', 'Diskon Ongkir', 'Biaya COD',
            'Total', 'Metode Pembayaran', 'Status Pembayaran', 'Kurir', 'Layanan',
            'Nomor Resi', 'Status Pesanan',
        ];

        foreach ($rows as $row) {
            $dataRows[] = [
                order_code($row),
                $row['created_at'],
                $row['customer_name'],
                $row['customer_email'],
                (int) $row['product_quantity'],
                (float) $row['subtotal_amount'],
                (float) $row['product_discount'],
                (float) $row['shipping_cost'],
                (float) $row['shipping_discount'],
                (float) $row['cod_fee'],
                (float) $row['total_amount'],
                payment_method_label((string) $row['payment_method'], (string) ($row['payment_detail'] ?? '')),
                $row['payment_status'],
                $row['shipping_courier_name'],
                $row['shipping_service_name'],
                $row['tracking_number'],
                $row['status'],
            ];
        }
        break;

    case 'products':
        $reportTitle = 'Laporan Produk';
        $categoryId = max(0, (int) ($_GET['category_id'] ?? 0));
        $search = trim((string) ($_GET['search'] ?? ''));
        $where = ["o.status = 'Selesai'", 'o.created_at BETWEEN :from_start AND :to_end'];

        if ($categoryId > 0) {
            $where[] = 'p.category_id = :category_id';
            $params['category_id'] = $categoryId;
        }
        if ($search !== '') {
            $where[] = '(p.name LIKE :search_product OR oi.variant_name LIKE :search_variant OR oi.variant_sku LIKE :search_sku)';
            $searchValue = '%' . $search . '%';
            $params['search_product'] = $searchValue;
            $params['search_variant'] = $searchValue;
            $params['search_sku'] = $searchValue;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT oi.product_name,
                    COALESCE(oi.variant_name, '') AS variant_name,
                    COALESCE(oi.variant_sku, '') AS variant_sku,
                    COALESCE(c.name, p.category) AS category_name,
                    SUM(oi.quantity) AS sold_quantity,
                    SUM(oi.subtotal) AS gross_sales,
                    p.stock,
                    p.average_rating,
                    p.review_count,
                    (SELECT COUNT(*) FROM wishlist_items wi WHERE wi.product_id = p.id) AS wishlist_count,
                    (SELECT COALESCE(SUM(ri.return_quantity), 0)
                     FROM return_items ri
                     INNER JOIN return_requests rr ON rr.id = ri.return_request_id
                     WHERE ri.product_id = p.id AND rr.status NOT IN ('REJECTED', 'CANCELLED')) AS returned_quantity
             FROM order_items oi
             INNER JOIN orders o ON o.id = oi.order_id
             INNER JOIN products p ON p.id = oi.product_id
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY p.id, oi.variant_id, oi.product_name, oi.variant_name, oi.variant_sku,
                      c.name, p.category, p.stock, p.average_rating, p.review_count
             ORDER BY sold_quantity DESC",
            $params
        );

        $headers = [
            'Produk', 'Varian', 'SKU', 'Kategori', 'Jumlah Terjual', 'Penjualan Kotor',
            'Stok Saat Ini', 'Wishlist', 'Rating', 'Jumlah Ulasan', 'Jumlah Retur',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['product_name'], $row['variant_name'], $row['variant_sku'], $row['category_name'],
                (int) $row['sold_quantity'], (float) $row['gross_sales'], (int) $row['stock'],
                (int) $row['wishlist_count'], (float) $row['average_rating'],
                (int) $row['review_count'], (int) $row['returned_quantity'],
            ];
        }
        break;

    case 'stock':
        $reportTitle = 'Laporan Pergerakan Stok';
        $typeFilter = trim((string) ($_GET['movement_type'] ?? ''));
        $productId = max(0, (int) ($_GET['product_id'] ?? 0));
        $where = ['sh.created_at BETWEEN :from_start AND :to_end'];

        if ($typeFilter !== '') {
            $where[] = 'sh.type = :movement_type';
            $params['movement_type'] = $typeFilter;
        }
        if ($productId > 0) {
            $where[] = 'sh.product_id = :product_id';
            $params['product_id'] = $productId;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT sh.created_at, p.name AS product_name,
                    COALESCE(pv.variant_name, '') AS variant_name,
                    COALESCE(pv.sku, '') AS sku,
                    sh.type, sh.quantity, sh.stock_before, sh.stock_after,
                    sh.reference_type, sh.reference_id, sh.notes,
                    COALESCE(u.name, 'Sistem') AS actor
             FROM stock_histories sh
             INNER JOIN products p ON p.id = sh.product_id
             LEFT JOIN product_variants pv ON pv.id = sh.variant_id
             LEFT JOIN users u ON u.id = sh.created_by
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sh.created_at",
            $params
        );

        $headers = [
            'Tanggal', 'Produk', 'Varian', 'SKU', 'Jenis', 'Jumlah', 'Stok Sebelum',
            'Stok Sesudah', 'Jenis Referensi', 'ID Referensi', 'Catatan', 'Pelaku',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['created_at'], $row['product_name'], $row['variant_name'], $row['sku'],
                $row['type'], (int) $row['quantity'], (int) $row['stock_before'],
                (int) $row['stock_after'], $row['reference_type'], $row['reference_id'],
                $row['notes'], $row['actor'],
            ];
        }
        break;

    case 'customers':
        $reportTitle = 'Laporan Pelanggan';
        $search = trim((string) ($_GET['search'] ?? ''));
        $extra = '';
        $customerParams = [
            'from_orders' => $period['from_start'],
            'to_orders' => $period['to_end'],
            'from_returns' => $period['from_start'],
            'to_returns' => $period['to_end'],
        ];

        if ($search !== '') {
            $extra = ' AND (u.name LIKE :search_name OR u.email LIKE :search_email)';
            $searchValue = '%' . $search . '%';
            $customerParams['search_name'] = $searchValue;
            $customerParams['search_email'] = $searchValue;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT u.name, u.email, u.status, u.created_at,
                    COUNT(o.id) AS order_count,
                    SUM(o.status = 'Selesai') AS completed_count,
                    SUM(o.status = 'Dibatalkan') AS cancelled_count,
                    SUM(o.status = 'Ditolak') AS rejected_count,
                    COALESCE(SUM(CASE WHEN o.status = 'Selesai' THEN o.total_amount ELSE 0 END), 0) AS total_spent,
                    (SELECT COUNT(*) FROM return_requests rr
                     WHERE rr.user_id = u.id AND rr.created_at BETWEEN :from_returns AND :to_returns) AS return_count
             FROM users u
             LEFT JOIN orders o ON o.user_id = u.id AND o.created_at BETWEEN :from_orders AND :to_orders
             WHERE u.role = 'customer' {$extra}
             GROUP BY u.id, u.name, u.email, u.status, u.created_at
             ORDER BY total_spent DESC",
            $customerParams
        );

        $headers = [
            'Nama', 'Email', 'Status Akun', 'Tanggal Daftar', 'Jumlah Pesanan',
            'Pesanan Selesai', 'Total Belanja', 'Dibatalkan', 'Ditolak', 'Retur',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['name'], $row['email'], $row['status'], $row['created_at'],
                (int) $row['order_count'], (int) $row['completed_count'],
                (float) $row['total_spent'], (int) $row['cancelled_count'],
                (int) $row['rejected_count'], (int) $row['return_count'],
            ];
        }
        break;

    case 'payments':
        $reportTitle = 'Laporan Pembayaran';
        $rows = report_fetch_all(
            $pdo,
            "SELECT payment_category, payment_method, payment_detail,
                    COUNT(*) AS order_count,
                    SUM(status = 'Selesai') AS completed_count,
                    SUM(status = 'Dibatalkan') AS cancelled_count,
                    SUM(status = 'Ditolak') AS rejected_count,
                    COALESCE(SUM(CASE WHEN status = 'Selesai' THEN total_amount ELSE 0 END), 0) AS completed_value
             FROM orders
             WHERE created_at BETWEEN :from_start AND :to_end
             GROUP BY payment_category, payment_method, payment_detail
             ORDER BY order_count DESC",
            $params
        );

        $headers = [
            'Kategori', 'Metode', 'Detail', 'Jumlah Pesanan', 'Selesai',
            'Dibatalkan', 'Ditolak', 'Nilai Selesai', 'Keterangan',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                payment_category_label((string) $row['payment_category']),
                payment_method_label((string) $row['payment_method'], (string) ($row['payment_detail'] ?? '')),
                $row['payment_detail'],
                (int) $row['order_count'],
                (int) $row['completed_count'],
                (int) $row['cancelled_count'],
                (int) $row['rejected_count'],
                (float) $row['completed_value'],
                is_cod_payment((string) $row['payment_method']) ? 'Internal COD' : 'Simulasi',
            ];
        }
        break;

    case 'shipping':
        $reportTitle = 'Laporan Pengiriman';
        $courier = trim((string) ($_GET['courier'] ?? ''));
        $where = ['created_at BETWEEN :from_start AND :to_end'];
        if ($courier !== '') {
            $where[] = 'shipping_courier_code = :courier';
            $params['courier'] = $courier;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT shipping_courier_name, shipping_service_name,
                    COUNT(*) AS order_count,
                    SUM(status = 'Dikirim') AS shipped_count,
                    SUM(status = 'Selesai') AS completed_count,
                    COALESCE(SUM(shipping_cost), 0) AS shipping_cost,
                    COALESCE(SUM(shipping_discount), 0) AS shipping_discount,
                    COALESCE(SUM(cod_fee), 0) AS cod_fee,
                    SUM(status IN ('Diproses', 'Dikirim') AND (tracking_number IS NULL OR tracking_number = '')) AS missing_tracking
             FROM orders
             WHERE " . implode(' AND ', $where) . "
             GROUP BY shipping_courier_name, shipping_service_name
             ORDER BY order_count DESC",
            $params
        );

        $headers = [
            'Kurir', 'Layanan', 'Pesanan', 'Dikirim', 'Selesai', 'Ongkir',
            'Diskon Ongkir', 'Biaya COD', 'Belum Ada Resi',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['shipping_courier_name'], $row['shipping_service_name'],
                (int) $row['order_count'], (int) $row['shipped_count'],
                (int) $row['completed_count'], (float) $row['shipping_cost'],
                (float) $row['shipping_discount'], (float) $row['cod_fee'],
                (int) $row['missing_tracking'],
            ];
        }
        break;

    case 'promotions':
        $reportTitle = 'Laporan Voucher dan Promo';
        $rows = report_fetch_all(
            $pdo,
            "SELECT p.name, p.code, p.trigger_type, p.discount_type, p.discount_value,
                    p.total_usage_limit, p.current_usage,
                    SUM(pu.usage_status = 'USED') AS used_count,
                    SUM(pu.usage_status = 'RESTORED') AS restored_count,
                    COALESCE(SUM(CASE WHEN pu.usage_status = 'USED' THEN pu.discount_amount ELSE 0 END), 0) AS product_discount,
                    COALESCE(SUM(CASE WHEN pu.usage_status = 'USED' THEN pu.shipping_discount ELSE 0 END), 0) AS shipping_discount,
                    COALESCE(SUM(CASE WHEN pu.usage_status = 'USED' AND o.status = 'Selesai' THEN o.total_amount ELSE 0 END), 0) AS completed_sales
             FROM promotions p
             LEFT JOIN promotion_usages pu ON pu.promotion_id = p.id AND pu.used_at BETWEEN :from_start AND :to_end
             LEFT JOIN orders o ON o.id = pu.order_id
             GROUP BY p.id, p.name, p.code, p.trigger_type, p.discount_type,
                      p.discount_value, p.total_usage_limit, p.current_usage
             ORDER BY used_count DESC",
            $params
        );

        $headers = [
            'Promo', 'Kode', 'Pemicu', 'Jenis Diskon', 'Nilai Diskon', 'Kuota Total',
            'Pemakaian Saat Ini', 'Digunakan Periode', 'Dikembalikan', 'Diskon Produk',
            'Diskon Ongkir', 'Penjualan Selesai',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['name'], $row['code'], $row['trigger_type'], $row['discount_type'],
                (float) $row['discount_value'], (int) $row['total_usage_limit'],
                (int) $row['current_usage'], (int) $row['used_count'],
                (int) $row['restored_count'], (float) $row['product_discount'],
                (float) $row['shipping_discount'], (float) $row['completed_sales'],
            ];
        }
        break;

    case 'wishlist':
        $reportTitle = 'Laporan Wishlist';
        $rows = report_fetch_all(
            $pdo,
            "SELECT p.name,
                    COUNT(wi.id) AS saved_count,
                    COUNT(DISTINCT wi.user_id) AS customer_count,
                    SUM(wi.purchased_at IS NOT NULL) AS purchased_count,
                    COALESCE(AVG(wi.price_when_added), p.price) AS average_saved_price,
                    p.price AS current_price, p.stock, p.status, p.average_rating, p.review_count
             FROM wishlist_items wi
             INNER JOIN products p ON p.id = wi.product_id
             WHERE wi.created_at BETWEEN :from_start AND :to_end
             GROUP BY p.id, p.name, p.price, p.stock, p.status, p.average_rating, p.review_count
             ORDER BY saved_count DESC",
            $params
        );

        $headers = [
            'Produk', 'Disimpan', 'Pelanggan', 'Ditandai Dibeli', 'Konversi (%)',
            'Harga Saat Disimpan', 'Harga Saat Ini', 'Stok', 'Status', 'Rating',
            'Jumlah Ulasan',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['name'], (int) $row['saved_count'], (int) $row['customer_count'],
                (int) $row['purchased_count'],
                report_percent((int) $row['purchased_count'], (int) $row['saved_count']),
                (float) $row['average_saved_price'], (float) $row['current_price'],
                (int) $row['stock'], $row['status'], (float) $row['average_rating'],
                (int) $row['review_count'],
            ];
        }
        break;

    case 'reviews':
        $reportTitle = 'Laporan Ulasan Produk';
        $rows = report_fetch_all(
            $pdo,
            "SELECT p.name,
                    COUNT(pr.id) AS review_count,
                    COALESCE(AVG(pr.rating), 0) AS average_rating,
                    SUM(pr.rating = 5) AS five_star,
                    SUM(pr.rating <= 2) AS low_rating,
                    SUM(pr.admin_reply IS NOT NULL AND pr.admin_reply <> '') AS replied_count
             FROM product_reviews pr
             INNER JOIN products p ON p.id = pr.product_id
             WHERE pr.status = 'PUBLISHED' AND pr.created_at BETWEEN :from_start AND :to_end
             GROUP BY p.id, p.name
             ORDER BY average_rating DESC, review_count DESC",
            $params
        );

        $headers = [
            'Produk', 'Jumlah Ulasan', 'Rating Rata-rata', '5 Bintang',
            'Rating 1-2', 'Dibalas Admin', 'Rasio Balasan (%)',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['name'], (int) $row['review_count'], (float) $row['average_rating'],
                (int) $row['five_star'], (int) $row['low_rating'],
                (int) $row['replied_count'],
                report_percent((int) $row['replied_count'], (int) $row['review_count']),
            ];
        }
        break;

    case 'returns':
        $reportTitle = 'Laporan Retur Barang';
        $rows = report_fetch_all(
            $pdo,
            "SELECT rr.return_number, rr.created_at, u.name AS customer_name,
                    o.order_number, rr.resolution_type, rr.status, rr.reason_summary,
                    rr.refund_status, rr.refund_amount,
                    COALESCE(SUM(ri.return_quantity), 0) AS return_quantity,
                    COALESCE(SUM(ri.restocked_quantity), 0) AS restocked_quantity
             FROM return_requests rr
             INNER JOIN users u ON u.id = rr.user_id
             INNER JOIN orders o ON o.id = rr.order_id
             LEFT JOIN return_items ri ON ri.return_request_id = rr.id
             WHERE rr.created_at BETWEEN :from_start AND :to_end
             GROUP BY rr.id, rr.return_number, rr.created_at, u.name, o.order_number,
                      rr.resolution_type, rr.status, rr.reason_summary,
                      rr.refund_status, rr.refund_amount
             ORDER BY rr.created_at",
            $params
        );

        $headers = [
            'Nomor Retur', 'Tanggal', 'Pelanggan', 'Nomor Pesanan', 'Penyelesaian',
            'Status', 'Alasan', 'Status Refund', 'Nilai Refund', 'Jumlah Produk',
            'Dikembalikan ke Stok',
        ];
        foreach ($rows as $row) {
            $dataRows[] = [
                $row['return_number'], $row['created_at'], $row['customer_name'],
                $row['order_number'], $row['resolution_type'], $row['status'],
                $row['reason_summary'], $row['refund_status'], (float) $row['refund_amount'],
                (int) $row['return_quantity'], (int) $row['restocked_quantity'],
            ];
        }
        break;

    default:
        $reportTitle = 'Laporan Penjualan';
        $payment = trim((string) ($_GET['payment'] ?? ''));
        $where = ["o.status = 'Selesai'", 'o.created_at BETWEEN :from_start AND :to_end'];

        if ($payment !== '') {
            if (in_array($payment, ['cod', 'cod_check'], true)) {
                $where[] = 'o.payment_method = :payment';
            } else {
                $where[] = 'o.payment_category = :payment';
            }
            $params['payment'] = $payment;
        }

        $rows = report_fetch_all(
            $pdo,
            "SELECT DATE(o.created_at) AS report_date,
                    COUNT(*) AS completed_orders,
                    COALESCE(SUM(o.subtotal_amount), 0) AS gross_product_sales,
                    COALESCE(SUM(o.product_discount), 0) AS product_discount,
                    COALESCE(SUM(o.shipping_cost), 0) AS shipping_cost,
                    COALESCE(SUM(o.shipping_discount), 0) AS shipping_discount,
                    COALESCE(SUM(o.cod_fee), 0) AS cod_fee,
                    COALESCE(SUM(o.total_amount), 0) AS total_payment
             FROM orders o
             WHERE " . implode(' AND ', $where) . "
             GROUP BY DATE(o.created_at)
             ORDER BY report_date",
            $params
        );

        $headers = [
            'Tanggal', 'Pesanan Selesai', 'Penjualan Produk Kotor', 'Diskon Produk',
            'Penjualan Produk Bersih', 'Ongkir', 'Diskon Ongkir', 'Biaya COD',
            'Total Pembayaran',
        ];
        foreach ($rows as $row) {
            $grossSales = (float) $row['gross_product_sales'];
            $productDiscount = (float) $row['product_discount'];
            $dataRows[] = [
                $row['report_date'],
                (int) $row['completed_orders'],
                $grossSales,
                $productDiscount,
                max(0, $grossSales - $productDiscount),
                (float) $row['shipping_cost'],
                (float) $row['shipping_discount'],
                (float) $row['cod_fee'],
                (float) $row['total_payment'],
            ];
        }
        break;
}

$storeName = setting($pdo, 'store_name', 'ShopFlow');
$adminName = (string) ($adminUser['name'] ?? 'Admin');
$filename = 'laporan-' . str_replace('_', '-', $type) . '-' . $period['from'] . '-' . $period['to'] . '.xlsx';

log_admin_activity(
    $pdo,
    'EXPORT_REPORT',
    'Mengekspor laporan ' . $type . ' ke Excel periode ' . report_period_label($period['from'], $period['to']) . '.'
);

$spreadsheet = new Spreadsheet();
$spreadsheet->getProperties()
    ->setCreator($adminName)
    ->setLastModifiedBy($adminName)
    ->setTitle($reportTitle)
    ->setSubject('Laporan Administrasi ' . $storeName)
    ->setDescription($reportTitle . ' periode ' . report_period_label($period['from'], $period['to']))
    ->setKeywords('ShopFlow laporan admin excel')
    ->setCategory('Laporan Administrasi');

$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle(report_excel_sheet_title($reportTitle));

$headerRow = 7;
$dataStartRow = $headerRow + 1;
$columnCount = max(1, count($headers));
$lastColumn = Coordinate::stringFromColumnIndex($columnCount);

$sheet->mergeCells("A1:{$lastColumn}1");
$sheet->mergeCells("A2:{$lastColumn}2");
$sheet->setCellValue('A1', $storeName);
$sheet->setCellValue('A2', $reportTitle);
$sheet->setCellValue('A3', 'Periode');
$sheet->setCellValue('B3', report_period_label($period['from'], $period['to']));
$sheet->setCellValue('A4', 'Dicetak');
$sheet->setCellValue('B4', date('d/m/Y H:i'));
$sheet->setCellValue('A5', 'Administrator');
$sheet->setCellValue('B5', $adminName);

foreach ($headers as $index => $header) {
    $column = Coordinate::stringFromColumnIndex($index + 1);
    $sheet->setCellValueExplicit($column . $headerRow, $header, DataType::TYPE_STRING);
}

$currencyHeaders = [
    'Subtotal', 'Diskon Produk', 'Ongkir', 'Diskon Ongkir', 'Biaya COD', 'Total',
    'Penjualan Kotor', 'Total Belanja', 'Nilai Selesai', 'Harga Saat Disimpan',
    'Harga Saat Ini', 'Nilai Refund', 'Penjualan Produk Kotor',
    'Penjualan Produk Bersih', 'Total Pembayaran', 'Penjualan Selesai',
];
$percentageHeaders = ['Konversi (%)', 'Rasio Balasan (%)'];
$ratingHeaders = ['Rating', 'Rating Rata-rata'];
$dateHeaders = ['Tanggal', 'Tanggal Daftar'];
$forcedTextHeaders = ['Nomor Pesanan', 'Nomor Retur', 'Nomor Resi', 'SKU', 'Kode', 'ID Referensi'];

foreach ($dataRows as $rowIndex => $row) {
    $excelRow = $dataStartRow + $rowIndex;

    foreach ($headers as $columnIndex => $header) {
        $column = Coordinate::stringFromColumnIndex($columnIndex + 1);
        $coordinate = $column . $excelRow;
        $value = $row[$columnIndex] ?? '';

        if (in_array($header, $dateHeaders, true)) {
            $dateValue = report_excel_date_value($value);
            if ($dateValue !== null) {
                $sheet->setCellValue($coordinate, $dateValue['value']);
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($dateValue['format']);
                continue;
            }
        }

        if (in_array($header, $forcedTextHeaders, true)) {
            $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
            continue;
        }

        if (is_int($value) || is_float($value)) {
            $sheet->setCellValue($coordinate, $value);
        } elseif (is_numeric($value) && $value !== '') {
            $sheet->setCellValue($coordinate, (float) $value);
        } else {
            $sheet->setCellValueExplicit($coordinate, (string) ($value ?? ''), DataType::TYPE_STRING);
        }

        if (in_array($header, $currencyHeaders, true)) {
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('"Rp" #,##0');
        } elseif (in_array($header, $percentageHeaders, true)) {
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('0.00"%"');
        } elseif (in_array($header, $ratingHeaders, true)) {
            $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('0.00');
        }
    }
}

$lastDataRow = max($headerRow, $dataStartRow + count($dataRows) - 1);

if ($dataRows === []) {
    $sheet->mergeCells("A{$dataStartRow}:{$lastColumn}{$dataStartRow}");
    $sheet->setCellValue("A{$dataStartRow}", 'Tidak ada data pada periode yang dipilih.');
    $sheet->getStyle("A{$dataStartRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $lastDataRow = $dataStartRow;
}

$sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
    'font' => ['bold' => true, 'size' => 18, 'color' => ['ARGB' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(1)->setRowHeight(30);

$sheet->getStyle("A2:{$lastColumn}2")->applyFromArray([
    'font' => ['bold' => true, 'size' => 14, 'color' => ['ARGB' => 'FF1E3A8A']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$sheet->getRowDimension(2)->setRowHeight(24);

$sheet->getStyle('A3:A5')->getFont()->setBold(true);
$sheet->getStyle("A3:{$lastColumn}5")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

$sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->applyFromArray([
    'font' => ['bold' => true, 'color' => ['ARGB' => 'FFFFFFFF']],
    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['ARGB' => 'FF2563EB']],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER,
        'wrapText' => true,
    ],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['ARGB' => 'FFCBD5E1']],
    ],
]);
$sheet->getRowDimension($headerRow)->setRowHeight(32);

$sheet->getStyle("A{$dataStartRow}:{$lastColumn}{$lastDataRow}")->applyFromArray([
    'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    'borders' => [
        'allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['ARGB' => 'FFE2E8F0']],
    ],
]);

for ($rowNumber = $dataStartRow; $rowNumber <= $lastDataRow; $rowNumber++) {
    if (($rowNumber - $dataStartRow) % 2 === 1) {
        $sheet->getStyle("A{$rowNumber}:{$lastColumn}{$rowNumber}")
            ->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFF8FAFC');
    }
}

foreach ($headers as $index => $header) {
    $column = Coordinate::stringFromColumnIndex($index + 1);
    $sheet->getColumnDimension($column)->setWidth(report_excel_column_width($header, $dataRows, $index));

    if (in_array($header, $currencyHeaders, true)) {
        $sheet->getStyle("{$column}{$dataStartRow}:{$column}{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('"Rp" #,##0');
    } elseif (in_array($header, $percentageHeaders, true)) {
        $sheet->getStyle("{$column}{$dataStartRow}:{$column}{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('0.00"%"');
    } elseif (in_array($header, $ratingHeaders, true)) {
        $sheet->getStyle("{$column}{$dataStartRow}:{$column}{$lastDataRow}")
            ->getNumberFormat()
            ->setFormatCode('0.00');
    }
}

$sheet->freezePane('A' . $dataStartRow);
if ($dataRows !== []) {
    $sheet->setAutoFilter("A{$headerRow}:{$lastColumn}{$lastDataRow}");
}

$sheet->getPageSetup()
    ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(PageSetup::PAPERSIZE_A4)
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sheet->getPageMargins()
    ->setTop(0.5)
    ->setRight(0.35)
    ->setBottom(0.5)
    ->setLeft(0.35);
$sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, $headerRow);
$sheet->getHeaderFooter()->setOddFooter('&L' . $storeName . ' — Laporan Administrasi&RHalaman &P dari &N');
$sheet->setSelectedCell('A1');

// Bersihkan seluruh output sebelumnya agar file XLSX tidak rusak.
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->setPreCalculateFormulas(false);
$writer->save('php://output');
$spreadsheet->disconnectWorksheets();
exit;
