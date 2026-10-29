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

// Optional filter: days back (default 7)
$days = isset($_GET['days']) ? max(1, (int)$_GET['days']) : 7;

// Fetch routes within the period
$routes = $db->queryAll("
    SELECT
        r.id,
        r.route_name,
        r.route_date,
        r.total_orders,
        r.total_distance,
        r.total_duration_minutes,
        r.status,
        v.plate_number,
        u.username AS rider_name
    FROM routes r
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    LEFT JOIN users u ON r.rider_id = u.id
    WHERE r.route_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY r.route_date DESC, r.id DESC
    LIMIT 25
", [$days]);

// Attach stops to each route
foreach ($routes as &$route) {
    $stops = $db->queryAll("
        SELECT tracking_number, barangay, lat, lng, sequence, arrival_minute
        FROM route_stops
        WHERE route_id = ?
        ORDER BY sequence
    ", [$route['id']]);

    $route['stops'] = array_map(function($s) {
        return [
            'tracking' => $s['tracking_number'],
            'barangay' => $s['barangay'],
            'lat'      => $s['lat'] ? (float)$s['lat'] : null,
            'lng'      => $s['lng'] ? (float)$s['lng'] : null,
            'sequence' => (int)$s['sequence'],
            'arrival'  => (int)$s['arrival_minute'],
        ];
    }, $stops);

    $route['total_distance'] = (float)$route['total_distance'];
    $route['total_orders']   = (int)$route['total_orders'];
    $route['total_duration_minutes'] = (int)($route['total_duration_minutes'] ?? 0);
}

echo json_encode([
    'routes' => $routes,
    'days' => $days,
    'server_time' => date('c'),
]);