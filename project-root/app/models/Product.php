<?php

class Product {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // -------------------------------------------------------
    //  CREATE
    // -------------------------------------------------------

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO products
                (category_id, name, slug, description, price, stock, image, is_available)
            VALUES
                (:category_id, :name, :slug, :description, :price, :stock, :image, :is_available)
        ");
        $stmt->execute([
            ':category_id'  => $data['category_id'],
            ':name'         => $data['name'],
            ':slug'         => $this->makeSlug($data['name']),
            ':description'  => $data['description'] ?? null,
            ':price'        => $data['price'],
            ':stock'        => $data['stock']        ?? 0,
            ':image'        => $data['image']        ?? null,
            ':is_available' => $data['is_available'] ?? 1,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------
    //  READ — single row
    // -------------------------------------------------------

    public function findById(int $id): array|false {
        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            JOIN   categories c ON c.id = p.category_id
            WHERE  p.id = :id
            LIMIT  1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findBySlug(string $slug): array|false {
        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            JOIN   categories c ON c.id = p.category_id
            WHERE  p.slug = :slug
            LIMIT  1
        ");
        $stmt->execute([':slug' => $slug]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------
    //  READ — collections
    // -------------------------------------------------------

    /**
     * All products for admin table with optional filters.
     */
    public function getAll(array $filters = [], int $limit = 20, int $offset = 0): array {
        $where  = ['1=1'];
        $params = [];

        if (isset($filters['is_available']) && $filters['is_available'] !== '') {
            $where[]                = 'p.is_available = :is_available';
            $params[':is_available'] = (int) $filters['is_available'];
        }
        if (!empty($filters['category_id'])) {
            $where[]               = 'p.category_id = :category_id';
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['search'])) {
            $where[]          = 'p.name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->db->prepare("
            SELECT p.*, c.name AS category_name
            FROM   products p
            JOIN   categories c ON c.id = p.category_id
            WHERE  " . implode(' AND ', $where) . "
            ORDER  BY c.sort_order, p.name
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

        if (isset($filters['is_available']) && $filters['is_available'] !== '') {
            $where[]                = 'p.is_available = :is_available';
            $params[':is_available'] = (int) $filters['is_available'];
        }
        if (!empty($filters['category_id'])) {
            $where[]               = 'p.category_id = :category_id';
            $params[':category_id'] = $filters['category_id'];
        }
        if (!empty($filters['search'])) {
            $where[]          = 'p.name LIKE :search';
            $params[':search'] = '%' . $filters['search'] . '%';
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM products p
            WHERE " . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Available products grouped by category for the customer menu.
     */
    public function getMenuGrouped(): array {
        $stmt = $this->db->query("
            SELECT p.id, p.name, p.slug, p.description, p.price,
                   p.stock, p.image, p.category_id,
                   c.name AS category_name, c.sort_order
            FROM   products p
            JOIN   categories c ON c.id = p.category_id
            WHERE  p.is_available = 1
              AND  c.is_active    = 1
              AND  (p.stock > 0 OR p.stock = -1)
            ORDER  BY c.sort_order, p.name
        ");
        $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['category_name']][] = $row;
        }
        return $grouped;
    }

    // -------------------------------------------------------
    //  UPDATE
    // -------------------------------------------------------

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];

        $allowed = ['category_id','name','description','price','stock','image','is_available'];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $data)) {
                $fields[]        = "{$col} = :{$col}";
                $params[":{$col}"] = $data[$col];
            }
        }
        if (isset($data['name'])) {
            $fields[]       = 'slug = :slug';
            $params[':slug'] = $this->makeSlug($data['name']);
        }
        if (empty($fields)) return false;

        $stmt = $this->db->prepare("
            UPDATE products SET " . implode(', ', $fields) . " WHERE id = :id
        ");
        return $stmt->execute($params);
    }

    public function decrementStock(int $id, int $qty = 1): bool {
        $stmt = $this->db->prepare("
            UPDATE products
            SET    stock = stock - :qty
            WHERE  id    = :id
              AND  stock  > 0     -- prevents going below 0 (stock = -1 skipped)
              AND  stock != -1
        ");
        return $stmt->execute([':qty' => $qty, ':id' => $id]);
    }

    public function toggleAvailability(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE products
            SET    is_available = 1 - is_available
            WHERE  id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------
    //  DELETE
    // -------------------------------------------------------

    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM products WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------

    private function makeSlug(string $name): string {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        // Ensure uniqueness
        $base  = $slug;
        $i     = 1;
        while ($this->slugExists($slug)) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }

    private function slugExists(string $slug): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM products WHERE slug = :slug");
        $stmt->execute([':slug' => $slug]);
        return (int)$stmt->fetchColumn() > 0;
    }
}