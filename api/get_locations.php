<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Admin only']);
    exit;
}

$db = Database::getInstance();

// Latest ping per rider within last 5 minutes
$rows = $db->queryAll("
    SELECT
        u.id AS rider_id,
        u.username,
        u.last_seen,
        u.is_tracking,
        rl.lat,
        rl.lng,
        rl.accuracy,
        rl.status,
        rl.created_at AS ping_time,
        TIMESTAMPDIFF(SECOND, rl.created_at, NOW()) AS seconds_ago
    FROM users u
    LEFT JOIN rider_locations rl ON rl.id = (
        SELECT id FROM rider_locations
        WHERE rider_id = u.id
        ORDER BY created_at DESC
        LIMIT 1
    )
    WHERE u.role = 'rider'
    ORDER BY u.username
");

// Compute status per rider
$riders = [];
foreach ($rows as $r) {
    $isOnline = $r['seconds_ago'] !== null && $r['seconds_ago'] < 120; // 2-min window

    $riders[] = [
        'rider_id'    => (int)$r['rider_id'],
        'username'    => $r['username'],
        'lat'         => $r['lat'] ? (float)$r['lat'] : null,
        'lng'         => $r['lng'] ? (float)$r['lng'] : null,
        'accuracy'    => $r['accuracy'] ? (float)$r['accuracy'] : null,
        'status'      => $isOnline ? ($r['status'] ?? 'online') : 'offline',
        'seconds_ago' => $r['seconds_ago'] !== null ? (int)$r['seconds_ago'] : null,
        'last_seen'   => $r['last_seen'],
    ];
}

echo json_encode([
    'riders' => $riders,
    'server_time' => date('c')
]);