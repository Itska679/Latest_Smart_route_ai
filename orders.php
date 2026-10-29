<?php
session_start();
require_once 'includes/auth.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getInstance();
$message = '';

if (isset($_GET['delete'])) {
    $db->execute("DELETE FROM orders WHERE id = ?", [(int)$_GET['delete']]);
    $message = "Order deleted successfully.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tracking  = trim($_POST['tracking_number']);
    $recipient = trim($_POST['recipient_name']);
    $barangay  = trim($_POST['barangay']);
    $address   = trim($_POST['address']);
    $status    = $_POST['status'];

    $weight = (float)($_POST['weight_kg'] ?? 1.0);

    if (!empty($_POST['id'])) {
        $db->execute(
            "UPDATE orders SET tracking_number=?, recipient_name=?, barangay=?, address=?, status=?, weight_kg=? WHERE id=?",
            [$tracking, $recipient, $barangay, $address, $status, $weight, (int)$_POST['id']]
        );
    } else {
        $db->execute(
            "INSERT INTO orders (tracking_number, recipient_name, barangay, address, status, weight_kg) VALUES (?, ?, ?, ?, ?, ?)",
            [$tracking, $recipient, $barangay, $address, $status, $weight]
        );
    }
        $message = "Order saved successfully.";
}

$editOrder = null;
if (isset($_GET['edit'])) {
    $editOrder = $db->queryOne("SELECT * FROM orders WHERE id = ?", [(int)$_GET['edit']]);
}

function statusBg($s) {
    return [
        'pending'                => '#dbeafe',
        'in_transit'             => '#fef3c7',
        'delivered'              => '#d1fae5',
        'failed'                 => '#fee2e2',
        '1st_attempt'            => '#fef9c3',
        '2nd_attempt'            => '#fed7aa',
        '3rd_attempt'            => '#fecaca',
        'customer_not_available' => '#e0e7ff',
        'returned'               => '#1e293b',
    ][$s] ?? '#e2e8f0';
}
function statusFg($s) {
    return [
        'pending'                => '#1d4ed8',
        'in_transit'             => '#b45309',
        'delivered'              => '#047857',
        'failed'                 => '#b91c1c',
        '1st_attempt'            => '#a16207',
        '2nd_attempt'            => '#c2410c',
        '3rd_attempt'            => '#b91c1c',
        'customer_not_available' => '#4338ca',
        'returned'               => '#ffffff',
    ][$s] ?? '#475569';
}

// Filter by status if requested
$filterStatus = $_GET['status'] ?? '';
$where = '';
$params = [];
if ($filterStatus && in_array($filterStatus, [
    'pending', 'in_transit', 'delivered', 'failed',
    '1st_attempt', '2nd_attempt', '3rd_attempt',
    'customer_not_available', 'returned'
])) {
    $where = "WHERE status = ?";
    $params[] = $filterStatus;
}

$orders = $db->queryAll("SELECT * FROM orders $where ORDER BY created_at DESC", $params);
$barangays = $db->queryAll("SELECT name FROM barangays ORDER BY name");

// Count per status for the filter chips
$statusCounts = [];
$rows = $db->queryAll("SELECT status, COUNT(*) as cnt FROM orders GROUP BY status");
foreach ($rows as $r) {
    $statusCounts[$r['status']] = (int)$r['cnt'];
}
$totalOrders = array_sum($statusCounts);

// All possible statuses for the dropdown
$allStatuses = [
    'pending'                => '⏳ Pending',
    'in_transit'             => '🚚 In Transit',
    'delivered'              => '✅ Delivered',
    'failed'                 => '❌ Failed',
    '1st_attempt'            => '1️⃣ 1st Attempt (Not Home)',
    '2nd_attempt'            => '2️⃣ 2nd Attempt (Not Home)',
    '3rd_attempt'            => '3️⃣ 3rd Attempt (Not Home)',
    'customer_not_available' => '🚪 Customer Not Available',
    'returned'               => '↩️ Returned to Sender',
];

$pageTitle = 'Orders';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert success">✅ <?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- STATUS FILTER CHIPS -->
<div class="panel">
    <div class="panel-body" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
        <a href="orders.php" class="btn-sm <?= $filterStatus === '' ? '' : 'btn-secondary' ?>"
           style="<?= $filterStatus === '' ? 'background:#ec008c;color:#fff;' : '' ?>">
            All (<?= $totalOrders ?>)
        </a>
        <?php foreach ($allStatuses as $key => $label): ?>
            <?php if (($statusCounts[$key] ?? 0) > 0 || $filterStatus === $key): ?>
                <a href="?status=<?= $key ?>"
                   class="btn-sm <?= $filterStatus === $key ? '' : 'btn-secondary' ?>"
                   style="<?= $filterStatus === $key ? 'background:#ec008c;color:#fff;' : '' ?>">
                    <?= $label ?> (<?= $statusCounts[$key] ?? 0 ?>)
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3><?= $editOrder ? 'Edit Order' : 'Add New Order' ?></h3>
            <p><?= $editOrder ? 'Update the details of this delivery' : 'Register a new package for delivery' ?></p>
        </div>
    </div>
    <div class="panel-body">
        <form method="POST" class="form-grid">
            <input type="hidden" name="id" value="<?= $editOrder['id'] ?? '' ?>">

            <div class="form-field">
                <label>Tracking Number</label>
                <input type="text" name="tracking_number" required placeholder="e.g., 2GO-00123"
                       value="<?= htmlspecialchars($editOrder['tracking_number'] ?? '') ?>">
            </div>

            <div class="form-field">
                <label>Recipient Name</label>
                <input type="text" name="recipient_name" required placeholder="Full name"
                       value="<?= htmlspecialchars($editOrder['recipient_name'] ?? '') ?>">
            </div>

            <div class="form-field">
                <label>Barangay</label>
                <select name="barangay" required>
                    <option value="">Select barangay…</option>
                    <?php foreach ($barangays as $b): ?>
                        <option value="<?= htmlspecialchars($b['name']) ?>"
                            <?= ($editOrder['barangay'] ?? '') === $b['name'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($b['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-field">
                <label>Street Address</label>
                <input type="text" name="address" placeholder="House no., street"
                       value="<?= htmlspecialchars($editOrder['address'] ?? '') ?>">
            </div>

            <div class="form-field">
                <label>Weight (kg)</label>
                <input type="number" step="0.1" name="weight_kg" required placeholder="e.g., 5.5"
                    value="<?= htmlspecialchars($editOrder['weight_kg'] ?? '1.0') ?>">
            </div>

            <div class="form-field">
                <label>Status</label>
                <select name="status" required>
                    <?php foreach ($allStatuses as $key => $label): ?>
                        <option value="<?= $key ?>"
                            <?= ($editOrder['status'] ?? 'pending') === $key ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn"><?= $editOrder ? '💾 Update Order' : '➕ Create Order' ?></button>
                <?php if ($editOrder): ?>
                    <a href="orders.php" class="btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel-header">
        <div>
            <h3>All Orders</h3>
            <p><?= count($orders) ?> order(s)<?= $filterStatus ? ' · filtered by <strong>' . htmlspecialchars(str_replace('_',' ', $filterStatus)) . '</strong>' : '' ?></p>
        </div>
        <?php if ($filterStatus): ?>
            <a href="orders.php" class="btn-secondary">Clear Filter</a>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="icon">📦</div>
                <h4>No orders found</h4>
                <p><?= $filterStatus ? 'Try a different filter.' : 'Add your first order using the form above.' ?></p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Tracking #</th>
                        <th>Recipient</th>
                        <th>Barangay</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $o): ?>
                        <tr>
                            <td>#<?= $o['id'] ?></td>
                            <td><strong><?= htmlspecialchars($o['tracking_number']) ?></strong></td>
                            <td><?= htmlspecialchars($o['recipient_name']) ?></td>
                            <td><?= htmlspecialchars($o['barangay']) ?></td>
                            <td>
                                <span class="badge <?= $o['status'] ?>">
                                    <?= $allStatuses[$o['status']] ?? htmlspecialchars($o['status']) ?>
                                </span>
                            </td>
                            <td>
                                <a href="?edit=<?= $o['id'] ?>" class="btn-sm">✏️ Edit</a>
                                <a href="print_receipt.php?id=<?= $o['id'] ?>" target="_blank" class="btn-sm">🖨️ Print</a>
                                <a href="?delete=<?= $o['id'] ?>" class="btn-sm danger"
                                   onclick="return confirm('Delete this order?')">🗑️ Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>