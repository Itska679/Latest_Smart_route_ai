<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'rider') {
    http_response_code(403);
    echo json_encode(['error' => 'Rider access only']);
    exit;
}

$db = Database::getInstance();
$riderId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// 1. PING LOCATION
if ($action === 'ping') {
    $input = json_decode(file_get_contents('php://input'), true);
    $lat = $input['lat'] ?? null;
    $lng = $input['lng'] ?? null;
    $routeId = $input['route_id'] ?? null;

    if ($lat && $lng && $routeId) {
        $db->execute("UPDATE routes SET current_lat = ?, current_lng = ? WHERE id = ? AND rider_id = ?", 
                     [$lat, $lng, $routeId, $riderId]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Missing data']);
    }
    exit;
}

// 2. MARK DELIVERED
if ($action === 'deliver') {
    $input = json_decode(file_get_contents('php://input'), true);
    $stopId = $input['stop_id'] ?? null; // This is route_stops.id

    if ($stopId) {
        // Mark the stop as delivered
        $db->execute("UPDATE route_stops SET status = 'delivered', delivered_at = NOW() WHERE id = ?", [$stopId]);
        
        // Also update the main orders table
        $stop = $db->queryOne("SELECT tracking_number FROM route_stops WHERE id = ?", [$stopId]);
        if ($stop) {
            $db->execute("UPDATE orders SET status = 'delivered' WHERE tracking_number = ?", [$stop['tracking_number']]);
        }

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['error' => 'Missing stop ID']);
    }
    exit;
}