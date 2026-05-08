<?php
// Expected: $stats (from Order::getStats()), $recentOrders (from Order::getRecentOrders())
// Session / auth guard should be applied in the controller before rendering this view.

$statusColors = [
    'pending'   => 'badge-warning',
    'confirmed' => 'badge-info',
    'preparing' => 'badge-primary',
    'ready'     => 'badge-success',
    'completed' => 'badge-secondary',
    'cancelled' => 'badge-danger',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — QR Order Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-layout">

<?php include __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Dashboard</h1>
            <p class="page-subtitle"><?= date('l, F j, Y') ?></p>
        </div>
    </div>

    <!-- ── Stat Cards ─────────────────────────────────────── -->
    <div class="stats-grid">

        <div class="stat-card stat-card--blue">
            <div class="stat-card__icon">🛒</div>
            <div class="stat-card__body">
                <span class="stat-card__value"><?= number_format((int)$stats['total_orders']) ?></span>
                <span class="stat-card__label">Total Orders</span>
            </div>
        </div>

        <div class="stat-card stat-card--yellow">
            <div class="stat-card__icon">⏳</div>
            <div class="stat-card__body">
                <span class="stat-card__value"><?= (int)$stats['pending'] ?></span>
                <span class="stat-card__label">Pending</span>
            </div>
        </div>

        <div class="stat-card stat-card--orange">
            <div class="stat-card__icon">🍳</div>
            <div class="stat-card__body">
                <span class="stat-card__value"><?= (int)$stats['preparing'] ?></span>
                <span class="stat-card__label">Preparing</span>
            </div>
        </div>

        <div class="stat-card stat-card--green">
            <div class="stat-card__icon">✅</div>
            <div class="stat-card__body">
                <span class="stat-card__value"><?= (int)$stats['completed'] ?></span>
                <span class="stat-card__label">Completed</span>
            </div>
        </div>

        <div class="stat-card stat-card--teal">
            <div class="stat-card__icon">📅</div>
            <div class="stat-card__body">
                <span class="stat-card__value"><?= (int)$stats['today_orders'] ?></span>
                <span class="stat-card__label">Today's Orders</span>
            </div>
        </div>

        <div class="stat-card stat-card--purple">
            <div class="stat-card__icon">💰</div>
            <div class="stat-card__body">
                <span class="stat-card__value">₱<?= number_format((float)$stats['today_revenue'], 2) ?></span>
                <span class="stat-card__label">Today's Revenue</span>
            </div>
        </div>

    </div><!-- /.stats-grid -->

    <!-- ── Recent Orders Table ────────────────────────────── -->
    <div class="card mt-6">
        <div class="card__header">
            <h2 class="card__title">Recent Orders</h2>
            <a href="/admin/orders" class="btn btn--sm btn--outline">View All</a>
        </div>

        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Type</th>
                        <th>Table</th>
                        <th>Customer</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Time</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($recentOrders)): ?>
                    <tr>
                        <td colspan="9" class="text-center text-muted py-6">No orders yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recentOrders as $order): ?>
                    <tr>
                        <td class="font-mono font-semibold">
                            <?= htmlspecialchars($order['order_number']) ?>
                        </td>
                        <td>
                            <span class="tag tag--<?= $order['order_type'] === 'dine_in' ? 'blue' : 'gray' ?>">
                                <?= $order['order_type'] === 'dine_in' ? 'Dine-In' : 'Takeout' ?>
                            </span>
                        </td>
                        <td><?= $order['table_number'] ?? '—' ?></td>
                        <td><?= htmlspecialchars($order['username'] ?? 'Guest') ?></td>
                        <td class="font-semibold">₱<?= number_format((float)$order['total'], 2) ?></td>
                        <td><?= ucfirst($order['payment_method'] ?? '—') ?></td>
                        <td>
                            <span class="badge <?= $statusColors[$order['status']] ?? '' ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </td>
                        <td class="text-muted text-sm">
                            <?= date('h:i A', strtotime($order['created_at'])) ?>
                        </td>
                        <td class="text-center">
                            <a href="/admin/orders/<?= $order['id'] ?>"
                               class="btn btn--sm btn--ghost">View</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-wrapper -->
    </div><!-- /.card -->

</main><!-- /.admin-main -->

<script src="/assets/js/admin.js"></script>
</body>
</html>