<?php
session_start();
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();
$auth->requireLogin();

$db = Database::getInstance();
$riderId = $_SESSION['user_id'];

// Get today's active route for this rider
$route = $db->queryOne("
    SELECT * FROM routes 
    WHERE rider_id = ? AND route_date = CURDATE() AND status IN ('planned', 'in_progress')
    ORDER BY id DESC LIMIT 1
", [$riderId]);

$pageTitle = 'Smart Navigation';
require_once __DIR__ . '/includes/header.php';
?>

<?php if (!$route): ?>
    <div class="panel" style="text-align:center; padding:50px;">
        <h3>No active route for today.</h3>
        <p>Please wait for the dispatcher to assign your route.</p>
    </div>
<?php else: 
    // Fetch all stops for this route
    $stops = $db->queryAll("SELECT * FROM route_stops WHERE route_id = ? ORDER BY sequence", [$route['id']]);
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="panel">
    <div class="panel-header" style="background:#ec008c; color:#fff;">
        <div>
            <h3 style="color:#fff;">🗺️ Route #<?= $route['id'] ?></h3>
            <p style="color:#fce7f3;"><?= htmlspecialchars($route['route_name']) ?> · <?= count($stops) ?> Stops</p>
        </div>
        <div style="background:#fff; color:#ec008c; padding:6px 12px; border-radius:20px; font-weight:bold;">
            📏 <?= number_format($route['total_distance'], 1) ?> km
        </div>
    </div>
    
    <!-- NEXT STOP DISPLAY -->
    <div id="nextStopBox" style="padding:20px; background:#f8fafc; border-bottom:1px solid #e2e8f0; text-align:center;">
        <p style="font-size:12px; text-transform:uppercase; color:#64748b; font-weight:bold; margin-bottom:8px;">Next Destination</p>
        <h2 id="nextStopName" style="font-size:24px; color:#0f172a; margin-bottom:4px;">Calculating...</h2>
        <p id="nextStopTracking" style="font-size:14px; color:#ec008c; font-weight:bold; font-family:monospace;">-</p>
        <div id="distanceBadge" style="display:none; margin-top:10px; font-size:13px; color:#475569;">
            📍 Distance to stop: <span id="distanceVal" style="font-weight:bold;">0.0</span> km
        </div>
        <button id="deliverBtn" onclick="markDelivered()" class="btn" style="margin-top:15px; background:#10b981; width:100%; max-width:300px; display:none;">
            ✅ Mark as Delivered
        </button>
    </div>

    <!-- MINI MAP -->
    <div id="navMap" style="height:300px; width:100%;"></div>
</div>

<script>
// Data from PHP
const routeId = <?= $route['id'] ?>;
let stops = <?= json_encode(array_map(function($s) {
    return [
        'id' => $s['id'], // route_stops.id
        'tracking' => $s['tracking_number'],
        'barangay' => $s['barangay'],
        'lat' => (float)$s['lat'],
        'lng' => (float)$s['lng'],
        'status' => $s['status']
    ];
}, $stops)) ?>;

// Map Setup
const DEPOT = [16.0433, 120.3333];
const map = L.map('navMap').setView(DEPOT, 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png').addTo(map);

let riderMarker = null;
let currentLat = null;
let currentLng = null;

// Haversine formula to calculate distance in km
function getDistance(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
}

// Draw all stops on map
const markers = {};
stops.forEach(s => {
    if (!s.lat || !s.lng) return;
    
    // Skip already delivered
    const isDone = s.status === 'delivered';
    const color = isDone ? '#94a3b8' : '#ec008c';
    
    markers[s.id] = L.circleMarker([s.lat, s.lng], {
        radius: 8,
        fillColor: color,
        color: '#fff',
        weight: 2,
        fillOpacity: 1
    }).addTo(map).bindPopup(`<b>${s.tracking}</b><br>${s.barangay}`);
});

// Function to find the smartest next stop
function updateSmartRoute() {
    // 1. Filter out delivered stops
    const pendingStops = stops.filter(s => s.status !== 'delivered' && s.lat && s.lng);
    
    if (pendingStops.length === 0) {
        document.getElementById('nextStopName').innerText = "All Done! 🎉";
        document.getElementById('nextStopTracking').innerText = "";
        document.getElementById('deliverBtn').style.display = 'none';
        document.getElementById('distanceBadge').style.display = 'none';
        return;
    }

    let nextStop = pendingStops[0]; // Default to lowest sequence

    // 2. SMART ROUTE LOGIC: If we have GPS, find the physically closest stop
    if (currentLat && currentLng) {
        let minDistance = Infinity;
        pendingStops.forEach(s => {
            const dist = getDistance(currentLat, currentLng, s.lat, s.lng);
            if (dist < minDistance) {
                minDistance = dist;
                nextStop = s;
            }
        });
        
        // Show distance to this stop
        document.getElementById('distanceBadge').style.display = 'block';
        document.getElementById('distanceVal').innerText = minDistance.toFixed(2);
    }

    // Update UI
    document.getElementById('nextStopName').innerText = nextStop.barangay;
    document.getElementById('nextStopTracking').innerText = "📦 " + nextStop.tracking;
    document.getElementById('deliverBtn').style.display = 'inline-block';
    document.getElementById('deliverBtn').onclick = () => markDelivered(nextStop.id);
    
    // Zoom map to show rider and next stop
    if (currentLat && currentLng) {
        map.fitBounds([[currentLat, currentLng], [nextStop.lat, nextStop.lng]], { padding: [50, 50], maxZoom: 16 });
    } else {
        map.panTo([nextStop.lat, nextStop.lng]);
    }
    
    // Highlight the next stop marker
    Object.values(markers).forEach(m => m.setStyle({ weight: 2, radius: 8 }));
    if (markers[nextStop.id]) {
        markers[nextStop.id].setStyle({ weight: 4, radius: 12 });
    }
}

// Start GPS Tracking
if (navigator.geolocation) {
    navigator.geolocation.watchPosition(pos => {
        currentLat = pos.coords.latitude;
        currentLng = pos.coords.longitude;

        // Draw rider position
        if (!riderMarker) {
            riderMarker = L.circleMarker([currentLat, currentLng], {
                radius: 8, fillColor: '#3b82f6', color: '#fff', weight: 3, fillOpacity: 1
            }).addTo(map).bindPopup("You are here");
        } else {
            riderMarker.setLatLng([currentLat, currentLng]);
        }

        // Ping server so Admin can see
        fetch('api/rider_action.php?action=ping', {
            method: 'POST',
            body: JSON.stringify({ lat: currentLat, lng: currentLng, route_id: routeId })
        });

        // Recalculate smart route
        updateSmartRoute();

    }, err => {
        console.error(err);
    }, { enableHighAccuracy: true });
}

// Mark as delivered
function markDelivered(stopId) {
    if (!stopId) return;
    if (!confirm("Confirm package delivered?")) return;

    fetch('api/rider_action.php?action=deliver', {
        method: 'POST',
        body: JSON.stringify({ stop_id: stopId })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Update local data
            const stop = stops.find(s => s.id === stopId);
            if (stop) stop.status = 'delivered';
            
            // Grey out marker
            if (markers[stopId]) markers[stopId].setStyle({ fillColor: '#94a3b8' });

            // Recalculate next stop
            updateSmartRoute();
        } else {
            alert("Error: " + data.error);
        }
    });
}

// Initial run
updateSmartRoute();
</script>

<?php endif; ?>
</main>
</body>
</html>