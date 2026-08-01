<?php

declare(strict_types=1);

/**
 * Helper bersama untuk seluruh halaman laporan admin ShopFlow.
 */

function report_valid_date(?string $value, string $fallback): string
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return $fallback;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : $fallback;
}

function report_period(): array
{
    $preset = trim((string) ($_GET['period'] ?? 'month'));
    $today = new DateTimeImmutable('today');

    [$defaultFrom, $defaultTo] = match ($preset) {
        'today' => [$today, $today],
        'yesterday' => [$today->modify('-1 day'), $today->modify('-1 day')],
        '7days' => [$today->modify('-6 days'), $today],
        '30days' => [$today->modify('-29 days'), $today],
        'last_month' => [$today->modify('first day of last month'), $today->modify('last day of last month')],
        'year' => [$today->modify('first day of January'), $today],
        default => [$today->modify('first day of this month'), $today],
    };

    $from = report_valid_date($_GET['date_from'] ?? null, $defaultFrom->format('Y-m-d'));
    $to = report_valid_date($_GET['date_to'] ?? null, $defaultTo->format('Y-m-d'));

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [
        'from' => $from,
        'to' => $to,
        'preset' => $preset,
        'from_start' => $from . ' 00:00:00',
        'to_end' => $to . ' 23:59:59',
    ];
}

function report_previous_period(string $from, string $to): array
{
    $fromDate = new DateTimeImmutable($from);
    $toDate = new DateTimeImmutable($to);
    $days = ((int) $fromDate->diff($toDate)->format('%a')) + 1;
    $previousTo = $fromDate->modify('-1 day');
    $previousFrom = $previousTo->modify('-' . max(0, $days - 1) . ' days');

    return [
        'from' => $previousFrom->format('Y-m-d'),
        'to' => $previousTo->format('Y-m-d'),
        'from_start' => $previousFrom->format('Y-m-d') . ' 00:00:00',
        'to_end' => $previousTo->format('Y-m-d') . ' 23:59:59',
    ];
}

function report_period_label(string $from, string $to): string
{
    if ($from === $to) {
        return date('d M Y', strtotime($from));
    }

    return date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
}

function report_growth(float $current, float $previous): float
{
    if ($previous == 0.0) {
        return $current > 0 ? 100.0 : 0.0;
    }

    return (($current - $previous) / abs($previous)) * 100;
}

function report_percent(float|int $part, float|int $total): float
{
    return (float) $total > 0 ? ((float) $part / (float) $total) * 100 : 0.0;
}

function report_query(array $overrides = [], array $remove = []): string
{
    $params = $_GET;
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }

    return http_build_query($params);
}

function report_safe_choice(?string $value, array $allowed, string $default = ''): string
{
    $value = trim((string) $value);
    return in_array($value, $allowed, true) ? $value : $default;
}

function report_fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetch() ?: [];
}

function report_fetch_all(PDO $pdo, string $sql, array $params = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function report_nav(string $active, array $period): void
{
    $items = [
        'overview' => ['reports.php', 'Ringkasan'],
        'sales' => ['reports_sales.php', 'Penjualan'],
        'orders' => ['reports_orders.php', 'Pesanan'],
        'products' => ['reports_products.php', 'Produk'],
        'stock' => ['reports_stock.php', 'Stok'],
        'customers' => ['reports_customers.php', 'Pelanggan'],
        'payments' => ['reports_payments.php', 'Pembayaran'],
        'shipping' => ['reports_shipping.php', 'Pengiriman'],
        'promotions' => ['reports_promotions.php', 'Promo'],
        'wishlist' => ['reports_wishlist.php', 'Wishlist'],
        'reviews' => ['reports_reviews.php', 'Ulasan'],
        'returns' => ['reports_returns.php', 'Retur'],
    ];

    $base = [
        'date_from' => $period['from'],
        'date_to' => $period['to'],
        'period' => $period['preset'],
    ];

    echo '<nav class="report-nav" aria-label="Navigasi laporan">';
    foreach ($items as $key => [$url, $label]) {
        $class = $active === $key ? ' active' : '';
        echo '<a class="' . $class . '" href="' . e($url . '?' . http_build_query($base)) . '">' . e($label) . '</a>';
    }
    echo '</nav>';
}

function report_filter_form(array $period, array $extraFields = []): void
{
    $presets = [
        'today' => 'Hari ini',
        'yesterday' => 'Kemarin',
        '7days' => '7 hari',
        '30days' => '30 hari',
        'month' => 'Bulan ini',
        'last_month' => 'Bulan lalu',
        'year' => 'Tahun ini',
        'custom' => 'Rentang tanggal',
    ];
    ?>
    <div class="admin-filter-card report-filter-card">
        <form class="report-filter-form" method="get" data-report-filter>
            <label class="admin-field">
                <span>Periode cepat</span>
                <select class="admin-select" name="period" data-report-period>
                    <?php foreach ($presets as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $period['preset'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="admin-field">
                <span>Dari tanggal</span>
                <input class="admin-input" type="date" name="date_from" value="<?= e($period['from']) ?>" required>
            </label>
            <label class="admin-field">
                <span>Sampai tanggal</span>
                <input class="admin-input" type="date" name="date_to" value="<?= e($period['to']) ?>" required>
            </label>
            <?php foreach ($extraFields as $field): ?>
                <label class="admin-field">
                    <span><?= e((string) $field['label']) ?></span>
                    <?php if (($field['type'] ?? 'select') === 'text'): ?>
                        <input class="admin-input" type="text" name="<?= e((string) $field['name']) ?>" value="<?= e((string) ($field['value'] ?? '')) ?>" placeholder="<?= e((string) ($field['placeholder'] ?? '')) ?>">
                    <?php else: ?>
                        <select class="admin-select" name="<?= e((string) $field['name']) ?>">
                            <?php foreach (($field['options'] ?? []) as $value => $label): ?>
                                <option value="<?= e((string) $value) ?>" <?= (string) ($field['value'] ?? '') === (string) $value ? 'selected' : '' ?>><?= e((string) $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </label>
            <?php endforeach; ?>
            <div class="report-filter-actions">
                <button class="admin-button admin-button-primary" type="submit">Terapkan Filter</button>
                <a class="admin-button admin-button-secondary" href="<?= e(basename((string) ($_SERVER['PHP_SELF'] ?? 'reports.php'))) ?>">Reset</a>
            </div>
        </form>
    </div>
    <?php
}

function report_growth_badge(float $growth, bool $lowerIsBetter = false): string
{
    $positive = $lowerIsBetter ? $growth <= 0 : $growth >= 0;
    $class = $positive ? 'report-growth-positive' : 'report-growth-negative';
    $arrow = $growth > 0 ? '↑' : ($growth < 0 ? '↓' : '→');
    return '<span class="report-growth ' . $class . '">' . $arrow . ' ' . e(number_format(abs($growth), 1, ',', '.')) . '%</span>';
}

function report_export_url(string $type, array $period, array $extra = []): string
{
    return 'export_report.php?' . http_build_query(array_merge([
        'type' => $type,
        'date_from' => $period['from'],
        'date_to' => $period['to'],
    ], $extra));
}

function report_print_url(string $type, array $period, array $extra = []): string
{
    return 'report_print.php?' . http_build_query(array_merge([
        'type' => $type,
        'date_from' => $period['from'],
        'date_to' => $period['to'],
    ], $extra));
}

function report_order_date_sql(string $alias = 'o'): string
{
    return "{$alias}.created_at";
}

function report_completed_refund(PDO $pdo, array $period): float
{
    $statement = $pdo->prepare(
        "SELECT COALESCE(SUM(refund_amount), 0)
         FROM return_requests
         WHERE refund_status = 'COMPLETED'
           AND COALESCE(refunded_at, completed_at, updated_at) BETWEEN :from_start AND :to_end"
    );
    $statement->execute([
        'from_start' => $period['from_start'],
        'to_end' => $period['to_end'],
    ]);
    return (float) $statement->fetchColumn();
}
