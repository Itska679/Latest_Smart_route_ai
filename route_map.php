<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

$routeId = (int)($_GET['id'] ?? 0);
$route = $db->queryOne("
    SELECT r.*, v.plate_number, u.username AS rider_name
    FROM routes r
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    LEFT JOIN users u ON r.rider_id = u.id
    WHERE r.id = ?
", [$routeId]);

if (!$route) {
    die("Route not found.");
}

$stops = $db->queryAll("
    SELECT * FROM route_stops
    WHERE route_id = ?
    ORDER BY sequence
", [$routeId]);

// Compute per-leg distances on the fly (Haversine)
function haversine_km($lat1, $lng1, $lat2, $lng2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return 2 * $r * asin(sqrt($a));
}

$depot = ['lat' => 16.0433, 'lng' => 120.3333];
$prevLat = $depot['lat'];
$prevLng = $depot['lng'];
foreach ($stops as &$s) {
    if ($s['lat'] && $s['lng']) {
        $s['leg_distance_km'] = round(haversine_km($prevLat, $prevLng, $s['lat'], $s['lng']), 2);
        $prevLat = $s['lat'];
        $prevLng = $s['lng'];
    } else {
        $s['leg_distance_km'] = 0;
    }
}
unset($s);

$pageTitle = 'Route Map — ' . $route['route_name'];
require_once 'includes/header.php';
?>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon indigo">📏</div>
        <div class="stat-details">
            <p>Total Distance</p>
            <h2><?= number_format($route['total_distance'], 2) ?> km</h2>
        </div>
    </div>
    <div class="stat-card">
    <div class="stat-icon yellow">📦</div>
    <div class="stat-details">
        <p>Stops</p>
        <h2><?= count($stops) ?></h2>
    </div>
</div>
<div class="stat-card">
    <div class="stat-icon indigo">⏱️</div>
    <div class="stat-details">
        <p>Est. Duration</p>
        <h2><?= gmdate('H:i', ($route['total_duration_minutes'] ?? 0) * 60) ?></h2>
    </div>
</div>
    <div class="stat-card">
        <div class="stat-icon green">🚚</div>
        <div class="stat-details">
            <p>Vehicle</p>
            <h2 style="font-size:16px;"><?= htmlspecialchars($route['plate_number'] ?? 'Unassigned') ?></h2>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">🏍️</div>
        <div class="stat-details">
            <p>Rider</p>
            <h2 style="font-size:16px;"><?= htmlspecialchars($route['rider_name'] ?? 'Unassigned') ?></h2>
        </div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>🗺️ <?= htmlspecialchars($route['route_name']) ?></h3>
            <p>Route date: <?= date('F j, Y', strtotime($route['route_date'])) ?> · Status: <?= str_replace('_',' ', $route['status']) ?></p>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="routes.php" class="btn-secondary">← Back to Routes</a>
            <a href="print_route_sheet.php?id=<?= $route['id'] ?>" target="_blank" class="btn">🖨️ Print Route Sheet</a>
        </div>
    </div>
    <div id="map" style="height:520px;width:100%;"></div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>Stop Sequence</h3>
            <p>Optimized visit order</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
    <tr>
        <th>#</th>
        <th>Tracking #</th>
        <th>Barangay</th>
        <th>Arrival</th>
        <th>Leg Distance</th>
    </tr>
	</thead>
	<tbody>
    		<?php foreach ($stops as $i => $s): ?>
        <tr>
            <td><strong><?= $i + 1 ?></strong></td>
            <td><?= htmlspecialchars($s['tracking_number']) ?></td>
            <td><?= htmlspecialchars($s['barangay'] ?? '—') ?></td>
            <td><?= sprintf('%02d:%02d', floor($s['arrival_minute'] / 60), $s['arrival_minute'] % 60) ?></td>
            <td><?= number_format($s['leg_distance_km'] ?? 0, 2) ?> km</td>
        </tr>
    <?php endforeach; ?>
</tbody>
        </table>
    </div>
</div>

<script>
// Depot — Dagupan City center
const DEPOT = [16.0433, 120.3333];

// Route stops from PHP
const stops = <?= json_encode(array_map(function($s) {
    return [
        'id' => $s['tracking_number'],
        'barangay' => $s['barangay'],
        'lat' => (float)$s['lat'],
        'lng' => (float)$s['lng'],
        'sequence' => (int)$s['sequence'],
        'arrival' => (int)$s['arrival_minute'],
    ];
}, $stops)) ?>;

// Init map
// Dagupan City bounding box
const DAGUPAN_BOUNDS = [
    [16.0150, 120.3050],
    [16.0750, 120.3850]
];

const map = L.map('map', {
    maxBounds: DAGUPAN_BOUNDS,
    maxBoundsViscosity: 1.0,
    minZoom: 13,
    maxZoom: 17
});
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Draw the polyline (depot → each stop → depot)
const path = [DEPOT];
stops.forEach(s => {
    if (s.lat && s.lng) path.push([s.lat, s.lng]);
});
path.push(DEPOT);

L.polyline(path, {
    color: '#ec008c',
    weight: 4,
    opacity: 0.8,
    dashArray: '8, 6'
}).addTo(map);

// Depot marker
L.marker(DEPOT, {
    icon: L.divIcon({
        className: 'custom-depot-icon',
        html: '<div style="background:#0f172a;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);">🏢</div>',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    })
}).addTo(map).bindPopup('<strong>🏢 Depot</strong><br>2GO Express Dagupan');

// Numbered stop markers
stops.forEach((s, i) => {
    if (!s.lat || !s.lng) return;

    const arrivalH = Math.floor(s.arrival / 60);
    const arrivalM = s.arrival % 60;
    const arrivalStr = String(arrivalH).padStart(2, '0') + ':' + String(arrivalM).padStart(2, '0');

    L.marker([s.lat, s.lng], {
        icon: L.divIcon({
            className: 'custom-stop-icon',
            html: `<div style="background:#ec008c;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.25);">${i + 1}</div>`,
            iconSize: [32, 32],
            iconAnchor: [16, 16]
        })
    }).addTo(map).bindPopup(
        `<strong>Stop #${i + 1}</strong><br>` +
        `📦 ${s.id}<br>` +
        `📍 ${s.barangay || '—'}<br>` +
        `⏰ Arrival: ${arrivalStr}`
    );
});

// Fit map to show all markers with padding
if (stops.length > 0) {
    const bounds = L.latLngBounds(path);
    // Expand route bounds slightly but never go wider than Dagupan
    const routeBounds = bounds.pad(0.15);
    if (DAGUPAN_BOUNDS[0][0] <= routeBounds.getSouth() &&
        routeBounds.getNorth() <= DAGUPAN_BOUNDS[1][0] &&
        DAGUPAN_BOUNDS[0][1] <= routeBounds.getWest() &&
        routeBounds.getEast() <= DAGUPAN_BOUNDS[1][1]) {
        map.fitBounds(routeBounds, { padding: [60, 60], maxZoom: 16 });
    } else {
        map.fitBounds(DAGUPAN_BOUNDS);
    }
} else {
    map.fitBounds(DAGUPAN_BOUNDS);
}
</script>

<?php require_once 'includes/footer.php'; ?>