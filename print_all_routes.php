<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();

$date = $_GET['date'] ?? date('Y-m-d');

$routes = $db->queryAll("
    SELECT r.*, v.plate_number, u.username AS rider_name
    FROM routes r
    LEFT JOIN vehicles v ON r.vehicle_id = v.id
    LEFT JOIN users u ON r.rider_id = u.id
    WHERE r.route_date = ?
    ORDER BY r.route_name
", [$date]);

// Fetch all stops for each route
foreach ($routes as &$r) {
    $r['stops'] = $db->queryAll("
        SELECT * FROM route_stops
        WHERE route_id = ?
        ORDER BY sequence
    ", [$r['id']]);
}
unset($r);

$totalOrders = 0;
$totalDistance = 0;
foreach ($routes as $r) {
    $totalOrders += $r['total_orders'];
    $totalDistance += $r['total_distance'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>All Routes — <?= htmlspecialchars($date) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            padding: 24px;
            color: #1e293b;
            font-size: 13px;
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
        .logo-box { width: 70px; height: 70px; display: flex; align-items: center; justify-content: center; }
        .logo-box img { max-width: 100%; max-height: 100%; }
        .header h1 { font-size: 20px; color: #0f172a; }
        .header h2 { font-size: 13px; color: #64748b; font-weight: 500; margin-top: 2px; }
        .header-meta { text-align: right; font-size: 12px; color: #64748b; }
        .header-meta strong { color: #1e293b; display: block; font-size: 14px; }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
            padding: 14px;
            background: #f8fafc;
            border-radius: 8px;
        }
        .summary-item .label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
            font-weight: 700;
        }
        .summary-item .value {
            font-size: 18px;
            font-weight: 700;
            color: #0f172a;
            margin-top: 4px;
        }

        .route-block {
            margin-bottom: 24px;
            page-break-inside: avoid;
        }
        .route-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #ec008c;
            color: #fff;
            padding: 10px 14px;
            border-radius: 6px 6px 0 0;
        }
        .route-header h3 { font-size: 14px; }
        .route-header .meta { font-size: 11.5px; opacity: 0.9; }

        table { width: 100%; border-collapse: collapse; font-size: 11.5px; }
        th {
            text-align: left;
            padding: 7px 10px;
            background: #f1f5f9;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
        }
        td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
        tbody tr:nth-child(even) { background: #fafbfc; }
        td.seq { font-weight: 700; color: #ec008c; width: 30px; }
        td.tracking { font-family: 'Courier New', monospace; font-weight: 600; }

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

        .empty {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }

        @media print {
            .toolbar { display: none; }
            body { padding: 10px; font-size: 11px; }
            .route-block { margin-bottom: 16px; page-break-inside: avoid; }
            .route-header { padding: 8px 12px; }
            table { font-size: 10.5px; }
            th, td { padding: 6px 8px; }
            @page { size: A4 portrait; margin: 10mm; }
        }
    </style>
</head>
<body>

<div class="toolbar">
    <a href="routes.php" class="secondary">← Back</a>
    <button onclick="window.print()">🖨️ Print All</button>
</div>

<div class="header">
    <div class="header-left">
        <div class="logo-box">
            <img src="assets/img/2go-logo.png" alt="2GO Express">
        </div>
        <div>
            <h1>2GO Express — Dagupan City</h1>
            <h2>Master Route Sheet</h2>
        </div>
    </div>
    <div class="header-meta">
        <strong><?= date('F j, Y', strtotime($date)) ?></strong>
        Generated: <?= date('M j, Y · g:i A') ?>
    </div>
</div>

<div class="summary">
    <div class="summary-item">
        <div class="label">Total Routes</div>
        <div class="value"><?= count($routes) ?></div>
    </div>
    <div class="summary-item">
        <div class="label">Total Stops</div>
        <div class="value"><?= $totalOrders ?></div>
    </div>
    <div class="summary-item">
        <div class="label">Total Distance</div>
        <div class="value"><?= number_format($totalDistance, 2) ?> km</div>
    </div>
</div>

<?php if (empty($routes)): ?>
    <div class="empty">
        <h2>No routes scheduled for this date.</h2>
        <p>Pick a different date or create routes first.</p>
    </div>
<?php else: ?>
    <?php foreach ($routes as $r): ?>
        <div class="route-block">
            <div class="route-header">
                <h3><?= htmlspecialchars($r['route_name']) ?></h3>
                <div class="meta">
                    🚚 <?= htmlspecialchars($r['plate_number'] ?? 'Unassigned') ?> ·
                    🏍️ <?= htmlspecialchars($r['rider_name'] ?? 'Unassigned') ?> ·
                    📦 <?= count($r['stops']) ?> stops ·
                    📏 <?= number_format($r['total_distance'], 2) ?> km
                </div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th style="width:30px;">#</th>
                        <th>Tracking #</th>
                        <th>Barangay</th>
                        <th style="width:60px;">Arrival</th>
                        <th>Signature</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($r['stops'])): ?>
                        <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:16px;">No stops recorded.</td></tr>
                    <?php else: foreach ($r['stops'] as $i => $s): ?>
                        <tr>
                            <td class="seq"><?= $i + 1 ?></td>
                            <td class="tracking"><?= htmlspecialchars($s['tracking_number']) ?></td>
                            <td><?= htmlspecialchars($s['barangay'] ?? '—') ?></td>
                            <td><?= sprintf('%02d:%02d', floor($s['arrival_minute'] / 60), $s['arrival_minute'] % 60) ?></td>
                            <td style="color:#cbd5e1;">&nbsp;</td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<div style="text-align:center;font-size:10.5px;color:#94a3b8;margin-top:20px;padding-top:14px;border-top:1px solid #e2e8f0;">
    Smart-Route AI · 2GO Express Dagupan · Generated <?= date('Y-m-d H:i:s') ?>
</div>

</body>
</html>