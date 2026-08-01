<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'unread_count' => 0]);
    exit;
}

echo json_encode([
    'success' => true,
    'unread_count' => unread_notification_count($pdo, (int) $user['id'], 'customer'),
]);
