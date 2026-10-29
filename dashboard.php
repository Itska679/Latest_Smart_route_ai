<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

// ===== STATS =====
$totalOrders     = $db->queryOne("SELECT COUNT(*) as count FROM orders")['count'];
$delivered       = $db->queryOne("SELECT COUNT(*) as count FROM orders WHERE status = 'delivered'")['count'];
$inTransit       = $db->queryOne("SELECT COUNT(*) as count FROM orders WHERE status = 'in_transit'")['count'];
$pending         = $db->queryOne("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")['count'];
$failed          = $db->queryOne("SELECT COUNT(*) as count FROM orders WHERE status = 'failed'")['count'];
$totalVehicles   = $db->queryOne("SELECT COUNT(*) as count FROM vehicles")['count'];
$totalBarangays  = $db->queryOne("SELECT COUNT(*) as count FROM barangays")['count'];
$totalFloodZones = $db->queryOne("SELECT COUNT(*) as count FROM flood_zones")['count'];
$activeRoutes    = $db->queryOne("SELECT COUNT(*) as count FROM routes WHERE status IN ('planned','in_progress')")['count'];

// ===== DELIVERY TRENDS (last 7 days) =====
$trendRows = $db->queryAll("
    SELECT DATE(created_at) as day, COUNT(*) as total
    FROM orders
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day
");

// Fill missing days with 0
$trendMap = [];
foreach ($trendRows as $r) { $trendMap[$r['day']] = (int)$r['total']; }
$trendLabels = [];
$trendData   = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('D, M j', strtotime($day));
    $trendData[]   = $trendMap[$day] ?? 0;
}

// ===== TOP BARANGAYS BY VOLUME =====
$topBarangays = $db->queryAll("
    SELECT barangay, COUNT(*) as total
    FROM orders
    WHERE barangay IS NOT NULL AND barangay != ''
    GROUP BY barangay
    ORDER BY total DESC
    LIMIT 8
");

// ===== STATUS BREAKDOWN =====
$statusBreakdown = [
    'Delivered' => (int)$delivered,
    'In Transit' => (int)$inTransit,
    'Pending'    => (int)$pending,
    'Failed'     => (int)$failed
];

$recentOrders = $db->queryAll("SELECT * FROM orders ORDER BY created_at DESC LIMIT 6");

$pageTitle = 'Dashboard Overview';
require_once 'includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon indigo">📦</div>
        <div class="stat-details"><p>Total Orders</p><h2><?= number_format($totalOrders) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">✅</div>
        <div class="stat-details"><p>Delivered</p><h2><?= number_format($delivered) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">🚚</div>
        <div class="stat-details"><p>In Transit</p><h2><?= number_format($inTransit) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">⏳</div>
        <div class="stat-details"><p>Pending</p><h2><?= number_format($pending) ?></h2></div>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon indigo">🚐</div>
        <div class="stat-details"><p>Fleet Size</p><h2><?= number_format($totalVehicles) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon green">📍</div>
        <div class="stat-details"><p>Barangays Covered</p><h2><?= number_format($totalBarangays) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon red">🌊</div>
        <div class="stat-details"><p>Flood Zones</p><h2><?= number_format($totalFloodZones) ?></h2></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon yellow">🗺️</div>
        <div class="stat-details"><p>Active Routes</p><h2><?= number_format($activeRoutes) ?></h2></div>
    </div>
</div>

<!-- CHARTS ROW -->
<div class="charts-grid">
    <div class="panel">
        <div class="panel-header">
            <div>
                <h3>📈 Delivery Trend (Last 7 Days)</h3>
                <p>Number of orders created per day</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="chart-wrap">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h3>📊 Order Status Breakdown</h3>
                <p>Distribution of current statuses</p>
            </div>
        </div>
        <div class="panel-body">
            <div class="chart-wrap">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- TOP BARANGAYS -->
<div class="panel">
    <div class="panel-header">
        <div>
            <h3>🏆 Top Barangays by Volume</h3>
            <p>Where most deliveries are going</p>
        </div>
    </div>
    <div class="panel-body">
        <?php if (empty($topBarangays)): ?>
            <div class="empty-state">
                <div class="icon">📊</div>
                <h4>No order data yet</h4>
                <p>Add some orders to see barangay rankings.</p>
            </div>
        <?php else: ?>
            <div class="chart-wrap" style="height:320px;">
                <canvas id="barangayChart"></canvas>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- MULTI-ROUTE MAP PANEL -->
<div class="panel">
    <div class="panel-header">
        <div>
            <h3>🗺️ Route Operations Map — Dagupan City</h3>
            <p>All saved routes from the last <span id="daysLabel">7</span> days · click a route to see details</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn-sm" onclick="setDays(1)">Today</button>
            <button class="btn-sm" onclick="setDays(7)">7 Days</button>
            <button class="btn-sm" onclick="setDays(30)">30 Days</button>
            <button class="btn-sm" onclick="reloadRoutes()">🔄 Refresh</button>
        </div>
    </div>
    <div style="display:flex;">
        <div id="map" style="height:520px;flex:1;"></div>
        <div id="routeLegend" style="width:260px;padding:16px;overflow-y:auto;border-left:1px solid #e2e8f0;background:#fafbfc;max-height:520px;">
            <h4 style="font-size:12px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;margin-bottom:12px;">Routes</h4>
            <div id="legendList" style="font-size:13px;color:#94a3b8;text-align:center;padding-top:20px;">Loading…</div>
        </div>
    </div>
</div>

<!-- RECENT ORDERS -->
<div class="panel">
    <div class="panel-header">
        <div>
            <h3>Recent Orders</h3>
            <p>Latest deliveries entering the system</p>
        </div>
        <a href="orders.php" class="btn">View All Orders</a>
    </div>
    <div class="table-wrap">
        <?php if (empty($recentOrders)): ?>
            <div class="empty-state">
                <div class="icon">📭</div>
                <h4>No orders yet</h4>
                <p>Add your first order to get started.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Tracking #</th><th>Recipient</th><th>Barangay</th>
                        <th>Status</th><th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $o): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($o['tracking_number']) ?></strong></td>
                            <td><?= htmlspecialchars($o['recipient_name']) ?></td>
                            <td><?= htmlspecialchars($o['barangay']) ?></td>
                            <td><span class="badge <?= $o['status'] ?>"><?= str_replace('_',' ', $o['status']) ?></span></td>
                            <td><?= date('M j, Y g:i A', strtotime($o['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Leaflet -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<script>
// ========== CHARTS ==========
const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: { font: { family: 'inherit', size: 12 }, color: '#475569' }
        }
    }
};

// Delivery Trend (line chart)
const trendCtx = document.getElementById('trendChart').getContext('2d');
const gradient = trendCtx.createLinearGradient(0, 0, 0, 300);
gradient.addColorStop(0, 'rgba(236, 0, 140, 0.25)');
gradient.addColorStop(1, 'rgba(236, 0, 140, 0.0)');

new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($trendLabels) ?>,
        datasets: [{
            label: 'Orders',
            data: <?= json_encode($trendData) ?>,
            borderColor: '#ec008c',
            backgroundColor: gradient,
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#ec008c',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7
        }]
    },
    options: {
        ...chartDefaults,
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0, color: '#64748b' },
                grid: { color: '#f1f5f9' }
            },
            x: {
                ticks: { color: '#64748b' },
                grid: { display: false }
            }
        }
    }
});

// Status Breakdown (doughnut)
const statusCtx = document.getElementById('statusChart').getContext('2d');
new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($statusBreakdown)) ?>,
        datasets: [{
            data: <?= json_encode(array_values($statusBreakdown)) ?>,
            backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#ef4444'],
            borderColor: '#fff',
            borderWidth: 3,
            hoverOffset: 8
        }]
    },
    options: {
        ...chartDefaults,
        cutout: '65%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: { padding: 16, font: { size: 12 }, color: '#475569' }
            }
        }
    }
});

<?php if (!empty($topBarangays)): ?>
// Top Barangays (horizontal bar)
const barangayCtx = document.getElementById('barangayChart').getContext('2d');
new Chart(barangayCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($topBarangays, 'barangay')) ?>,
        datasets: [{
            label: 'Orders',
            data: <?= json_encode(array_map('intval', array_column($topBarangays, 'total'))) ?>,
            backgroundColor: [
    		'#ec008c','#f472b6','#fb7fb5','#fca5c4',
    		'#fbcfe0','#c70077','#a30061','#7a0048'
		],
            borderRadius: 8,
            barThickness: 26
        }]
    },
    options: {
        ...chartDefaults,
        indexAxis: 'y',
        plugins: {
            legend: { display: false }
        },
        scales: {
            x: {
                beginAtZero: true,
                ticks: { precision: 0, color: '#64748b' },
                grid: { color: '#f1f5f9' }
            },
            y: {
                ticks: { color: '#1e293b', font: { weight: '600' } },
                grid: { display: false }
            }
        }
    }
});
<?php endif; ?>

// ========== MULTI-ROUTE LEAFLET MAP ==========
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

// Colors for routes — cycling palette
const ROUTE_COLORS = [
    '#ec008c', '#4f46e5', '#059669', '#dc2626', '#d97706',
    '#0891b2', '#7c3aed', '#be185d', '#65a30d', '#ea580c'
];

// Current state
let currentDays = 7;
let routeLayers = {}; // id → { polyline, markers[], data }

// Status badge colors
const STATUS_COLORS = {
    'planned':     '#dbeafe',
    'in_progress': '#fef3c7',
    'completed':   '#d1fae5'
};
const STATUS_TEXT = {
    'planned':     '#1d4ed8',
    'in_progress': '#b45309',
    'completed':   '#047857'
};

function clearRoutes() {
    Object.values(routeLayers).forEach(item => {
        if (item.polyline) map.removeLayer(item.polyline);
        item.markers.forEach(m => map.removeLayer(m));
    });
    routeLayers = {};
}

async function loadRoutes() {
    clearRoutes();
    document.getElementById('legendList').innerHTML = '<div style="text-align:center;color:#94a3b8;padding-top:20px;">Loading…</div>';
    document.getElementById('daysLabel').textContent = currentDays;

    try {
        const res = await fetch(`api/get_routes.php?days=${currentDays}`, { credentials: 'same-origin' });
        const data = await res.json();

        if (!data.routes || data.routes.length === 0) {
            document.getElementById('legendList').innerHTML =
                '<div style="text-align:center;color:#94a3b8;padding-top:20px;">No routes in this period.</div>';
            return;
        }

        const legend = [];

        data.routes.forEach((route, idx) => {
            const color = ROUTE_COLORS[idx % ROUTE_COLORS.length];

            // Build path: depot → stops → depot
            const path = [[16.0433, 120.3333]];
            route.stops.forEach(s => {
                if (s.lat && s.lng) path.push([s.lat, s.lng]);
            });
            if (path.length > 1) path.push([16.0433, 120.3333]);

            // Skip routes with no valid coordinates
            if (path.length < 3) return;

            // Polyline
            const polyline = L.polyline(path, {
                color: color,
                weight: 4,
                opacity: 0.75,
                dashArray: route.status === 'completed' ? null : '8, 6'
            }).addTo(map);

            // Popup content
            const popup = `
                <strong style="color:${color};">● ${route.route_name}</strong><br>
                📅 ${route.route_date}<br>
                🚚 ${route.plate_number || 'Unassigned'}<br>
                🏍️ ${route.rider_name || 'Unassigned'}<br>
                📦 ${route.total_orders} stops · 📏 ${route.total_distance.toFixed(2)} km<br>
                <span style="background:${STATUS_COLORS[route.status]};color:${STATUS_TEXT[route.status]};padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">${route.status.replace('_',' ')}</span>
            `;
            polyline.bindPopup(popup);

            // Add click handler to highlight
            polyline.on('click', () => {
                polyline.setStyle({ weight: 7, opacity: 1 });
                setTimeout(() => polyline.setStyle({ weight: 4, opacity: 0.75 }), 1500);
            });

            // Stop markers
            const markers = [];
            route.stops.forEach(s => {
                if (!s.lat || !s.lng) return;
                const marker = L.marker([s.lat, s.lng], {
                    icon: L.divIcon({
                        className: 'stop-marker',
                        html: `<div style="background:${color};color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:11px;border:2px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.25);">${s.sequence + 1}</div>`,
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    })
                }).addTo(map);
                marker.bindPopup(`
                    <strong>Stop #${s.sequence + 1}</strong><br>
                    📦 ${s.tracking}<br>
                    📍 ${s.barangay || '—'}<br>
                    ⏰ ${String(Math.floor(s.arrival/60)).padStart(2,'0')}:${String(s.arrival%60).padStart(2,'0')}
                `);
                markers.push(marker);
            });

            routeLayers[route.id] = { polyline, markers, data: route, color };

            // Legend entry
            legend.push(`
                <div class="legend-item" data-route-id="${route.id}" style="padding:10px;margin-bottom:8px;border-radius:6px;background:#fff;border:1px solid #e2e8f0;cursor:pointer;transition:all 0.15s;">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                        <div style="width:12px;height:12px;border-radius:3px;background:${color};flex-shrink:0;"></div>
                        <strong style="font-size:12.5px;color:#1e293b;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${route.route_name}</strong>
                    </div>
                    <div style="font-size:11px;color:#64748b;padding-left:20px;">
                        ${route.route_date}<br>
                        ${route.total_orders} stops · ${route.total_distance.toFixed(2)} km
                    </div>
                    <div style="padding-left:20px;margin-top:4px;">
                        <span style="background:${STATUS_COLORS[route.status]};color:${STATUS_TEXT[route.status]};padding:2px 8px;border-radius:10px;font-size:10px;font-weight:600;">${route.status.replace('_',' ')}</span>
                    </div>
                </div>
            `);
        });

        document.getElementById('legendList').innerHTML = legend.join('') ||
            '<div style="text-align:center;color:#94a3b8;padding-top:20px;">No routes with valid coordinates.</div>';

        // Wire up legend click → zoom to route
        document.querySelectorAll('.legend-item').forEach(el => {
            el.addEventListener('click', () => {
                const id = el.dataset.routeId;
                const item = routeLayers[id];
                if (!item) return;

                // Zoom to route bounds
                const bounds = item.polyline.getBounds();
                if (bounds.isValid()) {
                    map.fitBounds(bounds.pad(0.15), { maxZoom: 16 });
                }

                // Open popup at route midpoint
                item.polyline.openPopup(item.polyline.getCenter());

                // Highlight the polyline
                Object.values(routeLayers).forEach(r => r.polyline.setStyle({ weight: 4, opacity: 0.75 }));
                item.polyline.setStyle({ weight: 7, opacity: 1 });

                // Visual highlight of legend item
                document.querySelectorAll('.legend-item').forEach(l => l.style.background = '#fff');
                el.style.background = '#f1f5f9';
            });
        });

    } catch (err) {
        console.error(err);
        document.getElementById('legendList').innerHTML =
            '<div style="text-align:center;color:#dc2626;padding-top:20px;">Failed to load routes.</div>';
    }
}

function setDays(d) {
    currentDays = d;
    loadRoutes();
}

function reloadRoutes() {
    loadRoutes();
}

// Initial load
loadRoutes();

<?php require_once 'includes/footer.php'; ?>