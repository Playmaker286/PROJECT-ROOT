<?php

class Category {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // -------------------------------------------------------
    //  CREATE
    // -------------------------------------------------------

    public function create(array $data): int {
        $stmt = $this->db->prepare("
            INSERT INTO categories (name, slug, sort_order, is_active)
            VALUES (:name, :slug, :sort_order, :is_active)
        ");
        $stmt->execute([
            ':name'       => $data['name'],
            ':slug'       => $this->makeSlug($data['name']),
            ':sort_order' => $data['sort_order'] ?? 0,
            ':is_active'  => $data['is_active']  ?? 1,
        ]);
        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------
    //  READ
    // -------------------------------------------------------

    public function findById(int $id): array|false {
        $stmt = $this->db->prepare("
            SELECT * FROM categories WHERE id = :id LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getAll(bool $activeOnly = false): array {
        $sql = "SELECT * FROM categories";
        if ($activeOnly) $sql .= " WHERE is_active = 1";
        $sql .= " ORDER BY sort_order, name";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Each category with its product count.
     */
    public function getAllWithCounts(): array {
        return $this->db->query("
            SELECT c.*,
                   COUNT(p.id)                          AS product_count,
                   SUM(p.is_available = 1)              AS available_count
            FROM   categories c
            LEFT JOIN products p ON p.category_id = c.id
            GROUP  BY c.id
            ORDER  BY c.sort_order, c.name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------
    //  UPDATE
    // -------------------------------------------------------

    public function update(int $id, array $data): bool {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[]       = 'name = :name';
            $params[':name'] = $data['name'];
            $fields[]       = 'slug = :slug';
            $params[':slug'] = $this->makeSlug($data['name'], $id);
        }
        if (isset($data['sort_order'])) {
            $fields[]             = 'sort_order = :sort_order';
            $params[':sort_order'] = $data['sort_order'];
        }
        if (isset($data['is_active'])) {
            $fields[]            = 'is_active = :is_active';
            $params[':is_active'] = $data['is_active'];
        }
        if (empty($fields)) return false;

        $stmt = $this->db->prepare("
            UPDATE categories SET " . implode(', ', $fields) . " WHERE id = :id
        ");
        return $stmt->execute($params);
    }

    public function toggleActive(int $id): bool {
        $stmt = $this->db->prepare("
            UPDATE categories SET is_active = 1 - is_active WHERE id = :id
        ");
        return $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------
    //  DELETE
    // -------------------------------------------------------

    /**
     * Only deletes if no products are linked (FK RESTRICT).
     */
    public function delete(int $id): bool {
        $stmt = $this->db->prepare("DELETE FROM categories WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    // -------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------

    private function makeSlug(string $name, int $excludeId = 0): string {
        $base = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        $slug = $base;
        $i    = 1;
        while ($this->slugExists($slug, $excludeId)) {
            $slug = "{$base}-{$i}";
            $i++;
        }
        return $slug;
    }

    private function slugExists(string $slug, int $excludeId = 0): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM categories
            WHERE  slug = :slug AND id != :exclude_id
        ");
        $stmt->execute([':slug' => $slug, ':exclude_id' => $excludeId]);
        return (int)$stmt->fetchColumn() > 0;
    }
}