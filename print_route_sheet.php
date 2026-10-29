<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

$routeId = (int)($_GET['id'] ?? 0);
$route = $db->queryOne("
    SELECT r.*, v.plate_number, v.vehicle_type, u.username AS rider_name
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

// Compute per-leg distances (Haversine)
function haversine_km($lat1, $lng1, $lat2, $lng2) {
    $r = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) ** 2;
    return 2 * $r * asin(sqrt($a));
}

$depot = ['lat' => 16.0433, 'lng' => 120.3333];
$prevLat = $depot['lat'];
$prevLng = $depot['lng'];
$cumulativeDist = 0;
foreach ($stops as &$s) {
    if ($s['lat'] && $s['lng']) {
        $leg = haversine_km($prevLat, $prevLng, $s['lat'], $s['lng']);
        $s['leg_distance_km'] = round($leg, 2);
        $cumulativeDist += $leg;
        $prevLat = $s['lat'];
        $prevLng = $s['lng'];
    } else {
        $s['leg_distance_km'] = 0;
    }
}
unset($s);

// Return-to-depot leg
$returnLeg = haversine_km($prevLat, $prevLng, $depot['lat'], $depot['lng']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Route Sheet — <?= htmlspecialchars($route['route_name']) ?></title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            padding: 24px;
            color: #1e293b;
            font-size: 13px;
            background: #fff;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 3px solid #ec008c;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .header-left { display: flex; align-items: center; gap: 14px; }
        .logo-box {
            width: 70px;
            height: 70px;
            border-radius: 8px;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo-box img { max-width: 100%; max-height: 100%; }
        .header h1 { font-size: 20px; color: #0f172a; }
        .header h2 { font-size: 13px; color: #64748b; font-weight: 500; margin-top: 2px; }
        .header-meta { text-align: right; font-size: 12px; color: #64748b; }
        .header-meta strong { color: #1e293b; display: block; font-size: 14px; }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 18px;
        }
        .info-card {
            padding: 12px 14px;
            background: #f8fafc;
            border-radius: 6px;
            border-left: 3px solid #ec008c;
        }
        .info-card .label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 700;
        }
        .info-card .value {
            font-size: 15px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 4px;
        }

        #map {
            height: 340px;
            width: 100%;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            margin-bottom: 18px;
        }

        h3 {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 10px;
            color: #0f172a;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 18px;
        }
        th {
            text-align: left;
            padding: 8px 10px;
            background: #f1f5f9;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
        }
        td {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        tbody tr:nth-child(even) { background: #fafbfc; }
        td.seq {
            font-weight: 700;
            color: #ec008c;
            width: 34px;
        }
        td.tracking { font-family: 'Courier New', monospace; font-weight: 600; }

        .signature {
            display: flex;
            justify-content: space-between;
            gap: 40px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #cbd5e1;
        }
        .signature-block {
            flex: 1;
            border-top: 1px solid #94a3b8;
            padding-top: 6px;
            font-size: 11px;
            color: #475569;
        }
        .signature-block strong { display: block; margin-bottom: 2px; }

        .footer {
            text-align: center;
            font-size: 10.5px;
            color: #94a3b8;
            margin-top: 20px;
            padding-top: 14px;
            border-top: 1px solid #e2e8f0;
        }

        .toolbar {
            position: fixed;
            top: 12px;
            right: 12px;
            display: flex;
            gap: 8px;
            z-index: 1000;
        }
        .toolbar button, .toolbar a {
            padding: 10px 18px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            background: #ec008c;
            color: #fff;
        }
        .toolbar .secondary { background: #e2e8f0; color: #1e293b; }

        @media print {
            .toolbar { display: none; }
            body { padding: 12px; font-size: 11px; }
            .header { margin-bottom: 12px; padding-bottom: 10px; }
            .header h1 { font-size: 17px; }
            .info-grid { gap: 8px; margin-bottom: 12px; }
            .info-card { padding: 8px 10px; }
            .info-card .value { font-size: 13px; }
            #map { height: 300px; margin-bottom: 12px; }
            table { font-size: 10.5px; }
            th { padding: 6px 8px; }
            td { padding: 6px 8px; }
            .signature { margin-top: 22px; }
            .footer { margin-top: 14px; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="route_map.php?id=<?= $route['id'] ?>" class="secondary">← Back to Route</a>
    <button onclick="window.print()">🖨️ Print</button>
</div>

<!-- HEADER -->
<div class="header">
    <div class="header-left">
        <div class="logo-box">
            <img src="assets/img/2go-logo.png" alt="2GO Express">
        </div>
        <div>
            <h1>2GO Express — Dagupan City</h1>
            <h2>Daily Route Sheet</h2>
        </div>
    </div>
    <div class="header-meta">
        <strong><?= htmlspecialchars($route['route_name']) ?></strong>
        Date: <?= date('F j, Y', strtotime($route['route_date'])) ?><br>
        Generated: <?= date('M j, Y · g:i A') ?>
    </div>
</div>

<!-- INFO GRID -->
<div class="info-grid">
    <div class="info-card">
        <div class="label">Vehicle</div>
        <div class="value"><?= htmlspecialchars($route['plate_number'] ?? 'Unassigned') ?></div>
    </div>
    <div class="info-card">
        <div class="label">Rider</div>
        <div class="value"><?= htmlspecialchars($route['rider_name'] ?? 'Unassigned') ?></div>
    </div>
    <div class="info-card">
        <div class="label">Total Stops</div>
        <div class="value"><?= count($stops) ?></div>
    </div>
    <div class="info-card">
        <div class="label">Route Status</div>
        <div class="value"><?= ucfirst(str_replace('_', ' ', $route['status'])) ?></div>
    </div>
</div>

<div class="info-grid">
    <div class="info-card">
        <div class="label">Distance (saved)</div>
        <div class="value"><?= number_format($route['total_distance'], 2) ?> km</div>
    </div>
    <div class="info-card">
        <div class="label">Distance (computed)</div>
        <div class="value"><?= number_format($cumulativeDist + $returnLeg, 2) ?> km</div>
    </div>
    <div class="info-card">
        <div class="label">Duration (saved)</div>
        <div class="value"><?= $route['total_duration_minutes'] ?? 0 ?> min</div>
    </div>
    <div class="info-card">
        <div class="label">Coverage Area</div>
        <div class="value">Dagupan City</div>
    </div>
</div>

<!-- MAP -->
<div id="map"></div>

<!-- STOPS TABLE -->
<h3>Stop Sequence (<?= count($stops) ?> stops)</h3>
<table>
    <thead>
        <tr>
            <th style="width:34px;">#</th>
            <th>Tracking #</th>
            <th>Barangay</th>
            <th style="width:70px;">Arrival</th>
            <th style="width:80px;text-align:right;">Leg Dist.</th>
            <th>Remarks / Signature</th>
        </tr>
    </thead>
    <tbody>
        <?php $cumulative = 0; foreach ($stops as $i => $s):
            $cumulative += $s['leg_distance_km'];
        ?>
            <tr>
                <td class="seq"><?= $i + 1 ?></td>
                <td class="tracking"><?= htmlspecialchars($s['tracking_number']) ?></td>
                <td><?= htmlspecialchars($s['barangay'] ?? '—') ?></td>
                <td><?= sprintf('%02d:%02d', floor($s['arrival_minute'] / 60), $s['arrival_minute'] % 60) ?></td>
                <td style="text-align:right;"><?= number_format($s['leg_distance_km'], 2) ?> km</td>
                <td style="color:#cbd5e1;">&nbsp;</td>
            </tr>
        <?php endforeach; ?>
        <tr style="background:#f8fafc;font-weight:600;">
            <td colspan="4" style="text-align:right;padding-right:14px;">Return to depot:</td>
            <td style="text-align:right;"><?= number_format($returnLeg, 2) ?> km</td>
            <td></td>
        </tr>
        <tr style="background:#f1f5f9;font-weight:700;">
            <td colspan="4" style="text-align:right;padding-right:14px;">TOTAL:</td>
            <td style="text-align:right;"><?= number_format($cumulative + $returnLeg, 2) ?> km</td>
            <td></td>
        </tr>
    </tbody>
</table>

<!-- SIGNATURES -->
<div class="signature">
    <div class="signature-block">
        <strong>Dispatcher</strong>
        Signature / Date
    </div>
    <div class="signature-block">
        <strong>Rider</strong>
        Signature / Date
    </div>
    <div class="signature-block">
        <strong>Supervisor</strong>
        Signature / Date
    </div>
</div>

<div class="footer">
    Smart-Route AI · 2GO Express Dagupan · Route #<?= $route['id'] ?> · Generated <?= date('Y-m-d H:i:s') ?>
</div>

<!-- Leaflet -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const DAGUPAN_BOUNDS = [
    [16.0150, 120.3050],
    [16.0750, 120.3850]
];

const map = L.map('map', {
    maxBounds: DAGUPAN_BOUNDS,
    maxBoundsViscosity: 1.0,
    minZoom: 13,
    maxZoom: 17,
    zoomControl: true
});
map.fitBounds(DAGUPAN_BOUNDS);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap'
}).addTo(map);

const DEPOT = [16.0433, 120.3333];
const stops = <?= json_encode(array_map(function($s) {
    return [
        'tracking' => $s['tracking_number'],
        'barangay' => $s['barangay'],
        'lat' => (float)$s['lat'],
        'lng' => (float)$s['lng'],
        'sequence' => (int)$s['sequence'],
        'arrival' => (int)$s['arrival_minute'],
        'leg' => (float)($s['leg_distance_km'] ?? 0),
    ];
}, $stops)) ?>;

// Polyline
const path = [DEPOT];
stops.forEach(s => { if (s.lat && s.lng) path.push([s.lat, s.lng]); });
path.push(DEPOT);

L.polyline(path, {
    color: '#ec008c',
    weight: 3.5,
    opacity: 0.85,
    dashArray: '8, 6'
}).addTo(map);

// Depot
L.marker(DEPOT, {
    icon: L.divIcon({
        className: 'depot-icon',
        html: '<div style="background:#0f172a;color:#fff;width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);">🏢</div>',
        iconSize: [32, 32],
        iconAnchor: [16, 16]
    })
}).addTo(map).bindPopup('<strong>🏢 Depot</strong><br>2GO Express Dagupan');

// Stop markers
stops.forEach((s, i) => {
    if (!s.lat || !s.lng) return;
    const arrival = String(Math.floor(s.arrival/60)).padStart(2,'0') + ':' + String(s.arrival%60).padStart(2,'0');
    L.marker([s.lat, s.lng], {
        icon: L.divIcon({
            className: 'stop-icon',
            html: `<div style="background:#ec008c;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.25);">${i + 1}</div>`,
            iconSize: [26, 26],
            iconAnchor: [13, 13]
        })
    }).addTo(map).bindPopup(
        `<strong>Stop #${i+1}</strong><br>📦 ${s.tracking}<br>📍 ${s.barangay || '—'}<br>⏰ ${arrival}`
    );
});

// Fit to route
if (stops.length > 0) {
    map.fitBounds(L.latLngBounds(path).pad(0.15), { maxZoom: 16 });
}

// Force a redraw before print (Leaflet sometimes has sizing issues)
window.addEventListener('beforeprint', () => map.invalidateSize());
window.addEventListener('afterprint', () => map.invalidateSize());
</script>

</body>
</html>