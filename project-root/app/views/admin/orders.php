<?php
// Expected variables injected by OrderController:
//   $orders      – array of paginated orders
//   $totalOrders – int, total count for pagination
//   $page        – int, current page
//   $perPage     – int
//   $filters     – array ['status'=>, 'order_type'=>, 'date'=>, 'search'=>]

$totalPages = (int) ceil($totalOrders / $perPage);

$statusColors = [
    'pending'   => 'badge-warning',
    'confirmed' => 'badge-info',
    'preparing' => 'badge-primary',
    'ready'     => 'badge-success',
    'completed' => 'badge-secondary',
    'cancelled' => 'badge-danger',
];

$statusOptions = ['', 'pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
$typeOptions   = ['' => 'All Types', 'dine_in' => 'Dine-In', 'takeout' => 'Takeout'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Orders — QR Order Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-layout">

<?php include __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">Orders</h1>
        <span class="text-muted"><?= number_format($totalOrders) ?> total</span>
    </div>

    <!-- ── Filters ────────────────────────────────────────── -->
    <form method="GET" action="/admin/orders" class="filter-bar">
        <input
            type="text"
            name="search"
            placeholder="Search order # or customer…"
            value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
            class="form-input filter-bar__search"
        >

        <select name="status" class="form-select">
            <?php foreach ($statusOptions as $val): ?>
            <option value="<?= $val ?>"
                <?= ($filters['status'] ?? '') === $val ? 'selected' : '' ?>>
                <?= $val === '' ? 'All Statuses' : ucfirst($val) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="order_type" class="form-select">
            <?php foreach ($typeOptions as $val => $label): ?>
            <option value="<?= $val ?>"
                <?= ($filters['order_type'] ?? '') === $val ? 'selected' : '' ?>>
                <?= $label ?>
            </option>
            <?php endforeach; ?>
        </select>

        <input
            type="date"
            name="date"
            value="<?= htmlspecialchars($filters['date'] ?? '') ?>"
            class="form-input"
        >

        <button type="submit" class="btn btn--primary">Filter</button>
        <a href="/admin/orders" class="btn btn--ghost">Clear</a>
    </form>

    <!-- ── Orders Table ───────────────────────────────────── -->
    <div class="card">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Type</th>
                        <th>Table</th>
                        <th>Customer</th>
                        <th>Subtotal</th>
                        <th>Discount</th>
                        <th>Total</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($orders)): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted py-6">No orders found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($orders as $o): ?>
                    <tr>
                        <td class="font-mono font-semibold text-sm">
                            <?= htmlspecialchars($o['order_number']) ?>
                        </td>
                        <td>
                            <span class="tag tag--<?= $o['order_type'] === 'dine_in' ? 'blue' : 'gray' ?>">
                                <?= $o['order_type'] === 'dine_in' ? 'Dine-In' : 'Takeout' ?>
                            </span>
                        </td>
                        <td><?= $o['table_number'] ?? '—' ?></td>
                        <td><?= htmlspecialchars($o['username'] ?? 'Guest') ?></td>
                        <td>₱<?= number_format((float)$o['subtotal'], 2) ?></td>
                        <td class="text-danger">
                            <?= (float)$o['discount'] > 0
                                ? '−₱' . number_format((float)$o['discount'], 2)
                                : '—' ?>
                        </td>
                        <td class="font-semibold">₱<?= number_format((float)$o['total'], 2) ?></td>
                        <td><?= ucfirst($o['payment_method'] ?? '—') ?></td>
                        <td>
                            <!-- Inline status update form -->
                            <form method="POST" action="/admin/orders/<?= $o['id'] ?>/status"
                                  class="inline-form">
                                <?php // CSRF token should be output here via a helper, e.g.: ?>
                                <?= csrf_field() ?>
                                <select name="status"
                                        class="status-select status-select--<?= $o['status'] ?>"
                                        onchange="this.form.submit()">
                                    <?php foreach (array_slice($statusOptions, 1) as $s): ?>
                                    <option value="<?= $s ?>"
                                        <?= $o['status'] === $s ? 'selected' : '' ?>>
                                        <?= ucfirst($s) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        </td>
                        <td class="text-sm text-muted">
                            <?= date('M d, Y h:i A', strtotime($o['created_at'])) ?>
                        </td>
                        <td class="text-center">
                            <a href="/admin/orders/<?= $o['id'] ?>"
                               class="btn btn--sm btn--outline"
                               title="View details">👁</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-wrapper -->

        <!-- ── Pagination ─────────────────────────────────── -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $queryBase = http_build_query(array_merge($filters, ['page' => '']));
            for ($p = 1; $p <= $totalPages; $p++):
            ?>
                <a href="?<?= $queryBase . $p ?>"
                   class="pagination__item <?= $p === $page ? 'pagination__item--active' : '' ?>">
                    <?= $p ?>
                </a>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.card -->

</main><!-- /.admin-main -->

<script src="/assets/js/admin.js"></script>
</body>
</html>