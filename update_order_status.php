<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

flash('info', 'Pengelolaan status pesanan telah dipindahkan ke Dashboard Admin.');
redirect('admin/orders.php');
