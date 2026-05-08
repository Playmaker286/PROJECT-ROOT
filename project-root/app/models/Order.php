<?php

class Order {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // -------------------------------------------------------
    //  CREATE
    // -------------------------------------------------------

    /**
     * Insert a new order and its items inside a transaction.
     *
     * @param array $data   Order header fields
     * @param array $items  [['product_id'=>, 'quantity'=>, 'unit_price'=>, 'notes'=>], ...]
     * @return int          New order ID
     */
    public function create(array $data, array $items): int {
        $this->db->beginTransaction();
        try {
            $orderNumber = $this->generateOrderNumber();
            $subtotal    = array_sum(array_map(
                fn($i) => $i['unit_price'] * $i['quantity'], $items
            ));
            $discount = $data['discount'] ?? 0.00;
            $total    = $subtotal - $discount;

            $stmt = $this->db->prepare("
                INSERT INTO orders
                    (order_number, user_id, table_number, order_type,
                     subtotal, discount, total, status, notes)
                VALUES
                    (:order_number, :user_id, :table_number, :order_type,
                     :subtotal, :discount, :total, 'pending', :notes)
            ");
            $stmt->execute([
                ':order_number' => $orderNumber,
                ':user_id'      => $data['user_id']      ?? null,
                ':table_number' => $data['table_number'] ?? null,
                ':order_type'   => $data['order_type']   ?? 'dine_in',
                ':subtotal'     => $subtotal,
                ':discount'     => $discount,
                ':total'        => $total,
                ':notes'        => $data['notes']        ?? null,
            ]);
            $orderId = (int) $this->db->lastInsertId();

            $this->insertItems($orderId, $items);

            // Create a pending payment record
            $pmStmt = $this->db->prepare("
                INSERT INTO payments (order_id, method, amount_paid, status)
                VALUES (:order_id, :method, 0.00, 'pending')
            ");
            $pmStmt->execute([
                ':order_id' => $orderId,
                ':method'   => $data['payment_method'] ?? 'cash',
            ]);

            $this->db->commit();
            return $orderId;

        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------
    //  READ — single row
    // -------------------------------------------------------

    public function findById(int $id): array|false {
        $stmt = $this->db->prepare("
            SELECT o.*, u.username,
                   p.method AS payment_method, p.status AS payment_status,
                   p.amount_paid, p.change_due, p.reference_no
            FROM   orders o
            LEFT JOIN users    u ON u.id = o.user_id
            LEFT JOIN payments p ON p.order_id = o.id
            WHERE  o.id = :id
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByOrderNumber(string $orderNumber): array|false {
        $stmt = $this->db->prepare("
            SELECT o.*, p.method AS payment_method, p.status AS payment_status,
                   p.amount_paid, p.change_due, p.reference_no
            FROM   orders o
            LEFT JOIN payments p ON p.order_id = o.id
            WHERE  o.order_number = :order_number
            LIMIT  1
        ");
        $stmt->execute([':order_number' => $orderNumber]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------
    //  READ — collections
    // -------------------------------------------------------

    /**
     * Paginated list for admin order management.
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]          = 'o.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['order_type'])) {
            $where[]              = 'o.order_type = :order_type';
            $params[':order_type'] = $filters['order_type'];
        }
        if (!empty($filters['date'])) {
            $where[]        = 'DATE(o.created_at) = :date';
            $params[':date'] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $where[]          = '(o.order_number LIKE :search OR u.username LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT o.id, o.order_number, o.order_type, o.table_number,
                   o.subtotal, o.discount, o.total, o.status,
                   o.created_at, u.username,
                   p.method AS payment_method, p.status AS payment_status
            FROM   orders o
            LEFT JOIN users    u ON u.id = o.user_id
            LEFT JOIN payments p ON p.order_id = o.id
            WHERE  {$whereClause}
            ORDER  BY o.created_at DESC
            LIMIT  :limit OFFSET :offset
        ");
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countAll(array $filters = []): int {
        $where  = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]          = 'o.status = :status';
            $params[':status'] = $filters['status'];
        }
        if (!empty($filters['order_type'])) {
            $where[]              = 'o.order_type = :order_type';
            $params[':order_type'] = $filters['order_type'];
        }
        if (!empty($filters['date'])) {
            $where[]        = 'DATE(o.created_at) = :date';
            $params[':date'] = $filters['date'];
        }
        if (!empty($filters['search'])) {
            $where[]          = '(o.order_number LIKE :search OR u.username LIKE :search)';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM orders o
            LEFT JOIN users u ON u.id = o.user_id
            WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function getItemsByOrderId(int $orderId): array {
        $stmt = $this->db->prepare("
            SELECT oi.*, p.name AS product_name, p.image
            FROM   order_items oi
            JOIN   products p ON p.id = oi.product_id
            WHERE  oi.order_id = :order_id
        ");
        $stmt->execute([':order_id' => $orderId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------
    //  Dashboard stats
    // -------------------------------------------------------

    public function getStats(): array {
        $stmt = $this->db->query("
            SELECT
                COUNT(*)                                               AS total_orders,
                SUM(status = 'pending')                                AS pending,
                SUM(status = 'preparing')                              AS preparing,
                SUM(status = 'ready')                                  AS ready,
                SUM(status = 'completed')                              AS completed,
                SUM(status = 'cancelled')                              AS cancelled,
                COALESCE(SUM(CASE WHEN status = 'completed'
                                  THEN total END), 0)                  AS total_revenue,
                COUNT(CASE WHEN DATE(created_at) = CURDATE() END)      AS today_orders,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURDATE()
                                   AND status = 'completed'
                                  THEN total END), 0)                  AS today_revenue
            FROM orders
        ");
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getRecentOrders(int $limit = 10): array {
        $stmt = $this->db->prepare("
            SELECT o.id, o.order_number, o.order_type, o.table_number,
                   o.total, o.status, o.created_at, u.username,
                   p.method AS payment_method
            FROM   orders o
            LEFT JOIN users    u ON u.id = o.user_id
            LEFT JOIN payments p ON p.order_id = o.id
            ORDER  BY o.created_at DESC
            LIMIT  :limit
        ");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------
    //  UPDATE
    // -------------------------------------------------------

    public function updateStatus(int $id, string $status): bool {
        $allowed = ['pending','confirmed','preparing','ready','completed','cancelled'];
        if (!in_array($status, $allowed, true)) return false;

        $stmt = $this->db->prepare("
            UPDATE orders SET status = :status WHERE id = :id
        ");
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    public function markPaymentPaid(int $orderId, float $amountPaid, ?string $referenceNo = null): bool {
        $order = $this->findById($orderId);
        if (!$order) return false;

        $changeDue = max(0, $amountPaid - (float)$order['total']);

        $stmt = $this->db->prepare("
            UPDATE payments
            SET    amount_paid  = :amount_paid,
                   change_due   = :change_due,
                   reference_no = :reference_no,
                   status       = 'paid',
                   paid_at      = NOW()
            WHERE  order_id = :order_id
        ");
        return $stmt->execute([
            ':amount_paid'  => $amountPaid,
            ':change_due'   => $changeDue,
            ':reference_no' => $referenceNo,
            ':order_id'     => $orderId,
        ]);
    }

    // -------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------

    private function generateOrderNumber(): string {
        $date   = date('Ymd');
        $stmt   = $this->db->prepare("
            SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $seq = (int)$stmt->fetchColumn() + 1;
        return sprintf('ORD-%s-%04d', $date, $seq);
    }

    private function insertItems(int $orderId, array $items): void {
        $stmt = $this->db->prepare("
            INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, notes)
            VALUES (:order_id, :product_id, :quantity, :unit_price, :subtotal, :notes)
        ");
        foreach ($items as $item) {
            $stmt->execute([
                ':order_id'   => $orderId,
                ':product_id' => $item['product_id'],
                ':quantity'   => $item['quantity'],
                ':unit_price' => $item['unit_price'],
                ':subtotal'   => $item['unit_price'] * $item['quantity'],
                ':notes'      => $item['notes'] ?? null,
            ]);
        }
    }
}