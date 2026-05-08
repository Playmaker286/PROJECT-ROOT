<?php
// ============================================================
//  controllers/OrderController.php
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/functions.php';

class OrderController
{
    // ── Customer: Place Order ────────────────────────────────
    /**
     * POST /checkout
     * Expects cart in $_SESSION['cart'] and form fields
     */
    public function place(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/menu');
        }

        $cart        = $_SESSION['cart'] ?? [];
        $orderType   = $_POST['order_type']    ?? 'dine_in';
        $tableNumber = (int)($_POST['table_number'] ?? 0);
        $payMethod   = $_POST['payment_method'] ?? 'cash';
        $amountPaid  = (float)($_POST['amount_paid'] ?? 0);
        $userId      = $_SESSION['user_id'] ?? null;

        if (empty($cart)) {
            setFlash('error', 'Your cart is empty.');
            redirect('/cart');
        }

        $allowedTypes   = ['dine_in', 'takeout'];
        $allowedMethods = ['cash', 'gcash', 'card'];

        if (!in_array($orderType, $allowedTypes, true)) {
            setFlash('error', 'Invalid order type.');
            redirect('/checkout');
        }
        if (!in_array($payMethod, $allowedMethods, true)) {
            setFlash('error', 'Invalid payment method.');
            redirect('/checkout');
        }

        try {
            Database::beginTransaction();

            // 1. Validate products & compute totals
            [$items, $subtotal] = $this->resolveCartItems($cart);

            $total     = $subtotal; // extend here for discounts / tax
            $changeDue = max(0, $amountPaid - $total);

            // 2. Create order
            $orderNumber = $this->generateOrderNumber();

            Database::query(
                'INSERT INTO orders
                    (order_number, user_id, table_number, order_type, subtotal, total)
                 VALUES
                    (:num, :uid, :table, :type, :sub, :total)',
                [
                    ':num'   => $orderNumber,
                    ':uid'   => $userId,
                    ':table' => ($orderType === 'dine_in' && $tableNumber > 0)
                                  ? $tableNumber : null,
                    ':type'  => $orderType,
                    ':sub'   => $subtotal,
                    ':total' => $total,
                ]
            );
            $orderId = (int)Database::lastInsertId();

            // 3. Insert order items & decrement stock
            foreach ($items as $item) {
                Database::query(
                    'INSERT INTO order_items
                        (order_id, product_id, quantity, unit_price, subtotal)
                     VALUES (:oid, :pid, :qty, :price, :sub)',
                    [
                        ':oid'   => $orderId,
                        ':pid'   => $item['product_id'],
                        ':qty'   => $item['quantity'],
                        ':price' => $item['unit_price'],
                        ':sub'   => $item['subtotal'],
                    ]
                );

                // Decrement stock (skip if unlimited: stock = -1)
                Database::query(
                    'UPDATE products
                        SET stock = stock - :qty
                      WHERE id = :id AND stock > 0',
                    [':qty' => $item['quantity'], ':id' => $item['product_id']]
                );
            }

            // 4. Create payment record
            Database::query(
                'INSERT INTO payments
                    (order_id, method, amount_paid, change_due, status)
                 VALUES (:oid, :method, :paid, :change, :status)',
                [
                    ':oid'    => $orderId,
                    ':method' => $payMethod,
                    ':paid'   => $amountPaid,
                    ':change' => $changeDue,
                    ':status' => ($payMethod === 'cash') ? 'pending' : 'paid',
                ]
            );

            Database::commit();

            // Clear cart
            unset($_SESSION['cart']);

            setFlash('success', "Order #{$orderNumber} placed successfully!");
            redirect('/tracking/' . $orderNumber);

        } catch (Throwable $e) {
            Database::rollBack();
            error_log('OrderController::place — ' . $e->getMessage());
            setFlash('error', 'Could not place your order. Please try again.');
            redirect('/checkout');
        }
    }

    // ── Admin: List Orders ───────────────────────────────────
    /**
     * GET /admin/orders
     */
    public function index(): void
    {
        requireRole('admin', 'staff');

        $status  = $_GET['status']  ?? '';
        $type    = $_GET['type']    ?? '';
        $search  = trim($_GET['q']  ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 20;
        $offset  = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];

        if ($status !== '') {
            $where[]           = 'o.status = :status';
            $params[':status'] = $status;
        }
        if ($type !== '') {
            $where[]         = 'o.order_type = :type';
            $params[':type'] = $type;
        }
        if ($search !== '') {
            $where[]        = 'o.order_number LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);

        $total = (int)Database::query(
            "SELECT COUNT(*) FROM orders o WHERE {$whereSQL}",
            $params
        )->fetchColumn();

        $orders = Database::query(
            "SELECT o.*, p.method AS pay_method, p.status AS pay_status
               FROM orders o
          LEFT JOIN payments p ON p.order_id = o.id
              WHERE {$whereSQL}
           ORDER BY o.created_at DESC
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        $totalPages = (int)ceil($total / $perPage);

        require __DIR__ . '/../views/admin/orders.php';
    }

    // ── Admin: Update Status ─────────────────────────────────
    /**
     * POST /admin/orders/{id}/status
     */
    public function updateStatus(int $id): void
    {
        requireRole('admin', 'staff');

        $allowed = ['pending','confirmed','preparing','ready','completed','cancelled'];
        $status  = $_POST['status'] ?? '';

        if (!in_array($status, $allowed, true)) {
            jsonResponse(['error' => 'Invalid status.'], 422);
        }

        try {
            Database::query(
                'UPDATE orders SET status = :status WHERE id = :id',
                [':status' => $status, ':id' => $id]
            );

            // Mark payment as paid when completing cash orders
            if ($status === 'completed') {
                Database::query(
                    "UPDATE payments
                        SET status = 'paid', paid_at = NOW()
                      WHERE order_id = :id AND method = 'cash' AND status = 'pending'",
                    [':id' => $id]
                );
            }

            jsonResponse(['success' => true, 'status' => $status]);

        } catch (PDOException $e) {
            error_log('OrderController::updateStatus — ' . $e->getMessage());
            jsonResponse(['error' => 'Server error.'], 500);
        }
    }

    // ── Customer: Track Order ────────────────────────────────
    /**
     * GET /tracking/{orderNumber}
     */
    public function track(string $orderNumber): void
    {
        $orderNumber = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($orderNumber));

        $order = Database::query(
            'SELECT o.*, p.method, p.status AS pay_status, p.change_due
               FROM orders o
          LEFT JOIN payments p ON p.order_id = o.id
              WHERE o.order_number = :num
              LIMIT 1',
            [':num' => $orderNumber]
        )->fetch();

        if (!$order) {
            http_response_code(404);
            require __DIR__ . '/../views/errors/404.php';
            return;
        }

        $items = Database::query(
            'SELECT oi.*, pr.name, pr.image
               FROM order_items oi
               JOIN products pr ON pr.id = oi.product_id
              WHERE oi.order_id = :id',
            [':id' => $order['id']]
        )->fetchAll();

        require __DIR__ . '/../views/customer/tracking.php';
    }

    // ── Admin: Dashboard summary ─────────────────────────────
    /**
     * GET /admin/dashboard  (called from DashboardController or inline)
     */
    public function summary(): array
    {
        requireRole('admin', 'staff');

        $row = Database::query(
            "SELECT
                COUNT(*)                                         AS total,
                SUM(status = 'pending')                         AS pending,
                SUM(status = 'preparing')                       AS preparing,
                SUM(status = 'completed')                       AS completed,
                SUM(status = 'cancelled')                       AS cancelled,
                COALESCE(SUM(CASE WHEN status = 'completed'
                             THEN total END), 0)                AS revenue
             FROM orders
             WHERE DATE(created_at) = CURDATE()"
        )->fetch();

        return $row ?: [];
    }

    // ── Private helpers ──────────────────────────────────────

    /**
     * Re-fetch product data for cart items and compute subtotals.
     *
     * @param  array<int, array{product_id:int, quantity:int}> $cart
     * @return array{0: list<array<string,mixed>>, 1: float}
     */
    private function resolveCartItems(array $cart): array
    {
        $ids = array_column($cart, 'product_id');

        if (empty($ids)) {
            return [[], 0.0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $products = Database::query(
            "SELECT id, name, price, stock, is_available
               FROM products
              WHERE id IN ({$placeholders}) AND is_available = 1",
            array_values($ids)
        )->fetchAll(PDO::FETCH_UNIQUE);

        $items    = [];
        $subtotal = 0.0;

        foreach ($cart as $cartItem) {
            $pid = (int)$cartItem['product_id'];
            $qty = max(1, (int)$cartItem['quantity']);

            if (!isset($products[$pid])) {
                throw new RuntimeException("Product #{$pid} is unavailable.");
            }

            $p = $products[$pid];

            if ($p['stock'] !== -1 && $p['stock'] < $qty) {
                throw new RuntimeException("Insufficient stock for '{$p['name']}'.");
            }

            $lineTotal  = round((float)$p['price'] * $qty, 2);
            $subtotal  += $lineTotal;

            $items[] = [
                'product_id' => $pid,
                'quantity'   => $qty,
                'unit_price' => (float)$p['price'],
                'subtotal'   => $lineTotal,
            ];
        }

        return [$items, round($subtotal, 2)];
    }

    private function generateOrderNumber(): string
    {
        // ORD-YYYYMMDD-NNNN (sequential per day)
        $date  = date('Ymd');
        $count = (int)Database::query(
            "SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()"
        )->fetchColumn();

        return sprintf('ORD-%s-%04d', $date, $count + 1);
    }
}