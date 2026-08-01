<?php

declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
header('Location: reports_wishlist.php' . ($query !== '' ? '?' . $query : ''));
exit;
