<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$pageTitle = 'Live Rider Tracking';
require_once 'includes/header.php';
?>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<div class="stats-grid" id="statsGrid">
    <div class="stat-card">
        <div class="stat-icon green">🟢</div>
        <div class="stat-details"><p>Online Now</p><h2 id="onlineCount">0</h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">🏍️</div>
        <div class="stat-details"><p>Total Riders</p><h2 id="totalCount">0</h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon indigo">🔄</div>
        <div class="stat-details"><p>Refresh</p><h2 style="font-size:14px;" id="refreshInfo">Auto · 10s</h2></div>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>📡 Live Rider Map — Dagupan City</h3>
            <p>Riders currently broadcasting their location</p>
        </div>
        <button class="btn" onclick="fetchRiders()">🔄 Refresh Now</button>
    </div>
    <div id="map" style="height:520px;width:100%;"></div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>Rider Status</h3>
            <p>Live list of all riders in the system</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Rider</th><th>Status</th><th>Last Update</th><th>Location</th></tr>
            </thead>
            <tbody id="riderTable">
                <tr><td colspan="4" class="table-empty">Loading riders…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<script>
// Dagupan City bounds
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
map.fitBounds(DAGUPAN_BOUNDS);

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

// Depot
L.marker([16.0433, 120.3333], {
    icon: L.divIcon({
        className: 'custom-depot-icon',
        html: '<div style="background:#0f172a;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:18px;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.3);">🏢</div>',
        iconSize: [36, 36],
        iconAnchor: [18, 18]
    })
}).addTo(map).bindPopup('<strong>🏢 2GO Express Depot</strong><br>Dagupan City');

// Track rider markers
const riderMarkers = {};

function riderIcon(username, status) {
    const initial = username.charAt(0).toUpperCase();
    const bg = status === 'offline' ? '#94a3b8' : '#ec008c';
    const pulse = status === 'offline' ? '' : 'animation: pulse 2s infinite;';

    return L.divIcon({
        className: 'rider-marker',
        html: `<div style="position:relative;">
            <div style="background:${bg};color:#fff;width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;border:3px solid #fff;box-shadow:0 2px 12px rgba(0,0,0,0.3);${pulse}">${initial}</div>
            <div style="position:absolute;bottom:-4px;right:-4px;background:${status === 'offline' ? '#64748b' : '#10b981'};width:14px;height:14px;border-radius:50%;border:2px solid #fff;"></div>
        </div>`,
        iconSize: [40, 40],
        iconAnchor: [20, 20]
    });
}

function secondsAgoText(seconds) {
    if (seconds === null) return '—';
    if (seconds < 60) return seconds + 's ago';
    if (seconds < 3600) return Math.floor(seconds / 60) + 'm ago';
    return Math.floor(seconds / 3600) + 'h ago';
}

function fetchRiders() {
    fetch('/smart-route/api/get_locations.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            const riders = data.riders || [];
            let onlineCount = 0;

            const tableRows = [];

            riders.forEach(r => {
                if (r.status !== 'offline') onlineCount++;

                // Update or create marker
                if (r.lat && r.lng) {
                    const popup = `
                        <strong>🏍️ ${r.username}</strong><br>
                        Status: <b style="color:${r.status === 'offline' ? '#64748b' : '#10b981'};">${r.status}</b><br>
                        Last ping: ${secondsAgoText(r.seconds_ago)}<br>
                        Accuracy: ${r.accuracy ? Math.round(r.accuracy) + ' m' : '—'}
                    `;

                    if (riderMarkers[r.rider_id]) {
                        riderMarkers[r.rider_id].setLatLng([r.lat, r.lng]);
                        riderMarkers[r.rider_id].setIcon(riderIcon(r.username, r.status));
                        riderMarkers[r.rider_id].setPopupContent(popup);
                    } else {
                        riderMarkers[r.rider_id] = L.marker([r.lat, r.lng], {
                            icon: riderIcon(r.username, r.status)
                        }).addTo(map).bindPopup(popup);
                    }
                } else if (riderMarkers[r.rider_id]) {
                    // No coordinates — remove from map
                    map.removeLayer(riderMarkers[r.rider_id]);
                    delete riderMarkers[r.rider_id];
                }

                // Table row
                const badgeClass = r.status === 'offline' ? 'failed' : 'delivered';
                tableRows.push(`
                    <tr>
                        <td><strong>${r.username}</strong></td>
                        <td><span class="badge ${badgeClass}">${r.status}</span></td>
                        <td>${secondsAgoText(r.seconds_ago)}</td>
                        <td>${r.lat ? r.lat.toFixed(5) + ', ' + r.lng.toFixed(5) : '—'}</td>
                    </tr>
                `);
            });

            document.getElementById('onlineCount').textContent = onlineCount;
            document.getElementById('totalCount').textContent = riders.length;
            document.getElementById('refreshInfo').textContent = 'Updated ' + new Date().toLocaleTimeString();

            document.getElementById('riderTable').innerHTML = tableRows.length
                ? tableRows.join('')
                : '<tr><td colspan="4" class="table-empty">No riders registered.</td></tr>';
        })
        .catch(err => {
            console.error(err);
        });
}

// Auto-refresh every 10 seconds
fetchRiders();
setInterval(fetchRiders, 10000);
</script>

<style>
@keyframes pulse {
    0%   { box-shadow: 0 0 0 0 rgba(236, 0, 140, 0.6); }
    70%  { box-shadow: 0 0 0 14px rgba(236, 0, 140, 0); }
    100% { box-shadow: 0 0 0 0 rgba(236, 0, 140, 0); }
}
</style>

<?php require_once 'includes/footer.php'; ?>