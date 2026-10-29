<?php
session_start();
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Only riders can post location
if ($_SESSION['role'] !== 'rider') {
    http_response_code(403);
    echo json_encode(['error' => 'Only riders can update location']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$lat      = $input['lat'] ?? null;
$lng      = $input['lng'] ?? null;
$accuracy = $input['accuracy'] ?? null;
$status   = $input['status'] ?? 'online';

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing lat/lng']);
    exit;
}

if (!is_numeric($lat) || !is_numeric($lng)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid lat/lng']);
    exit;
}

// Sanity check: must be within Dagupan area
if ($lat < 15.9 || $lat > 16.2 || $lng < 120.2 || $lng > 120.5) {
    http_response_code(400);
    echo json_encode(['error' => 'Location outside Dagupan City bounds']);
    exit;
}

$db = Database::getInstance();

// Insert ping
$db->execute(
    "INSERT INTO rider_locations (rider_id, lat, lng, accuracy, status) VALUES (?, ?, ?, ?, ?)",
    [$_SESSION['user_id'], $lat, $lng, $accuracy, $status]
);

// Update user's last_seen + tracking flag
$db->execute(
    "UPDATE users SET last_seen = NOW(), is_tracking = 1 WHERE id = ?",
    [$_SESSION['user_id']]
);

// Keep only last 200 pings per rider (housekeeping)
$db->execute("
    DELETE FROM rider_locations
    WHERE rider_id = ?
      AND id NOT IN (
        SELECT id FROM (
          SELECT id FROM rider_locations
          WHERE rider_id = ?
          ORDER BY created_at DESC
          LIMIT 200
        ) as keep_ids
      )
", [$_SESSION['user_id'], $_SESSION['user_id']]);

echo json_encode([
    'success' => true,
    'lat' => $lat,
    'lng' => $lng,
    'timestamp' => date('c')
]);