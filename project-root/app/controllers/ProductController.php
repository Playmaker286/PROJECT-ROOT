<?php
// ============================================================
//  controllers/ProductController.php
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/functions.php';

class ProductController
{
    private const UPLOAD_DIR    = __DIR__ . '/../assets/images/products/';
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_FILE_SIZE = 2 * 1024 * 1024; // 2 MB

    // ── Admin: List Products ─────────────────────────────────
    /**
     * GET /admin/products
     */
    public function index(): void
    {
        requireRole('admin', 'staff');

        $search   = trim($_GET['q']           ?? '');
        $catId    = (int)($_GET['category_id'] ?? 0);
        $page     = max(1, (int)($_GET['page'] ?? 1));
        $perPage  = 20;
        $offset   = ($page - 1) * $perPage;

        $where  = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[]         = 'p.name LIKE :search';
            $params[':search'] = '%' . $search . '%';
        }
        if ($catId > 0) {
            $where[]       = 'p.category_id = :cat';
            $params[':cat'] = $catId;
        }

        $whereSQL = implode(' AND ', $where);

        $total = (int)Database::query(
            "SELECT COUNT(*) FROM products p WHERE {$whereSQL}",
            $params
        )->fetchColumn();

        $products = Database::query(
            "SELECT p.*, c.name AS category_name
               FROM products p
               JOIN categories c ON c.id = p.category_id
              WHERE {$whereSQL}
           ORDER BY c.sort_order, p.name
              LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        $categories = $this->allCategories();
        $totalPages = (int)ceil($total / $perPage);

        require __DIR__ . '/../views/admin/products.php';
    }

    // ── Admin: Show create form ──────────────────────────────
    public function create(): void
    {
        requireRole('admin');
        $categories = $this->allCategories();
        require __DIR__ . '/../views/admin/product_form.php';
    }

    // ── Admin: Store new product ─────────────────────────────
    /**
     * POST /admin/products
     */
    public function store(): void
    {
        requireRole('admin');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/admin/products/create');
        }

        $data   = $this->sanitizeFormData($_POST);
        $errors = $this->validate($data);

        $imageName = null;
        if (!empty($_FILES['image']['name'])) {
            [$imageName, $uploadError] = $this->handleUpload($_FILES['image']);
            if ($uploadError) {
                $errors['image'] = $uploadError;
            }
        }

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect('/admin/products/create');
        }

        try {
            Database::query(
                'INSERT INTO products
                    (category_id, name, slug, description, price, stock, image)
                 VALUES
                    (:cat, :name, :slug, :desc, :price, :stock, :image)',
                [
                    ':cat'   => $data['category_id'],
                    ':name'  => $data['name'],
                    ':slug'  => $this->slugify($data['name']),
                    ':desc'  => $data['description'],
                    ':price' => $data['price'],
                    ':stock' => $data['stock'],
                    ':image' => $imageName,
                ]
            );

            setFlash('success', "Product '{$data['name']}' created.");
            redirect('/admin/products');

        } catch (PDOException $e) {
            if ($imageName) {
                @unlink(self::UPLOAD_DIR . $imageName);
            }
            error_log('ProductController::store — ' . $e->getMessage());
            setFlash('error', 'Could not save product. Please try again.');
            redirect('/admin/products/create');
        }
    }

    // ── Admin: Show edit form ────────────────────────────────
    public function edit(int $id): void
    {
        requireRole('admin');

        $product = $this->findOrFail($id);
        $categories = $this->allCategories();

        require __DIR__ . '/../views/admin/product_form.php';
    }

    // ── Admin: Update product ────────────────────────────────
    /**
     * POST /admin/products/{id}/update
     */
    public function update(int $id): void
    {
        requireRole('admin');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect("/admin/products/{$id}/edit");
        }

        $product = $this->findOrFail($id);
        $data    = $this->sanitizeFormData($_POST);
        $errors  = $this->validate($data);

        $imageName = $product['image']; // keep existing by default
        if (!empty($_FILES['image']['name'])) {
            [$newImage, $uploadError] = $this->handleUpload($_FILES['image']);
            if ($uploadError) {
                $errors['image'] = $uploadError;
            } else {
                $imageName = $newImage;
            }
        }

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect("/admin/products/{$id}/edit");
        }

        try {
            Database::query(
                'UPDATE products
                    SET category_id = :cat,
                        name        = :name,
                        slug        = :slug,
                        description = :desc,
                        price       = :price,
                        stock       = :stock,
                        image       = :image,
                        is_available = :avail
                  WHERE id = :id',
                [
                    ':cat'   => $data['category_id'],
                    ':name'  => $data['name'],
                    ':slug'  => $this->slugify($data['name']),
                    ':desc'  => $data['description'],
                    ':price' => $data['price'],
                    ':stock' => $data['stock'],
                    ':image' => $imageName,
                    ':avail' => (int)($data['is_available'] ?? 1),
                    ':id'    => $id,
                ]
            );

            // Delete old image only after a successful DB update
            if ($imageName !== $product['image'] && $product['image']) {
                @unlink(self::UPLOAD_DIR . $product['image']);
            }

            setFlash('success', "Product '{$data['name']}' updated.");
            redirect('/admin/products');

        } catch (PDOException $e) {
            error_log('ProductController::update — ' . $e->getMessage());
            setFlash('error', 'Could not update product. Please try again.');
            redirect("/admin/products/{$id}/edit");
        }
    }

    // ── Admin: Delete product ────────────────────────────────
    /**
     * POST /admin/products/{id}/delete
     */
    public function delete(int $id): void
    {
        requireRole('admin');

        $product = $this->findOrFail($id);

        try {
            Database::query('DELETE FROM products WHERE id = :id', [':id' => $id]);

            if ($product['image']) {
                @unlink(self::UPLOAD_DIR . $product['image']);
            }

            setFlash('success', 'Product deleted.');

        } catch (PDOException $e) {
            error_log('ProductController::delete — ' . $e->getMessage());
            setFlash('error', 'Cannot delete product (may have existing orders).');
        }

        redirect('/admin/products');
    }

    // ── Customer: Browse Menu ────────────────────────────────
    /**
     * GET /menu
     */
    public function menu(): void
    {
        $catSlug = $_GET['category'] ?? '';

        $categories = $this->allCategories();

        $params = [];
        $where  = ['p.is_available = 1'];

        if ($catSlug !== '') {
            $where[]       = 'c.slug = :slug';
            $params[':slug'] = $catSlug;
        }

        $whereSQL = implode(' AND ', $where);

        $products = Database::query(
            "SELECT p.*, c.name AS category_name, c.slug AS category_slug
               FROM products p
               JOIN categories c ON c.id = p.category_id
              WHERE {$whereSQL}
           ORDER BY c.sort_order, p.name",
            $params
        )->fetchAll();

        require __DIR__ . '/../views/customer/menu.php';
    }

    // ── Private helpers ──────────────────────────────────────

    private function findOrFail(int $id): array
    {
        $product = Database::query(
            'SELECT * FROM products WHERE id = :id LIMIT 1',
            [':id' => $id]
        )->fetch();

        if (!$product) {
            http_response_code(404);
            exit('Product not found.');
        }

        return $product;
    }

    private function allCategories(): array
    {
        return Database::query(
            'SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order'
        )->fetchAll();
    }

    /**
     * @param  array<string, mixed> $post
     * @return array<string, mixed>
     */
    private function sanitizeFormData(array $post): array
    {
        return [
            'category_id'  => (int)($post['category_id']  ?? 0),
            'name'         => trim($post['name']           ?? ''),
            'description'  => trim($post['description']    ?? ''),
            'price'        => round((float)($post['price'] ?? 0), 2),
            'stock'        => (int)($post['stock']         ?? 0),
            'is_available' => (int)($post['is_available']  ?? 1),
        ];
    }

    /**
     * @param  array<string, mixed> $data
     * @return array<string, string>
     */
    private function validate(array $data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors['name'] = 'Product name is required.';
        }
        if ($data['category_id'] <= 0) {
            $errors['category_id'] = 'Please select a category.';
        }
        if ($data['price'] <= 0) {
            $errors['price'] = 'Price must be greater than zero.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed> $file  $_FILES['image']
     * @return array{0: string|null, 1: string|null}
     */
    private function handleUpload(array $file): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [null, 'File upload failed (code ' . $file['error'] . ').'];
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return [null, 'Image must be under 2 MB.'];
        }

        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, self::ALLOWED_TYPES, true)) {
            return [null, 'Only JPEG, PNG, and WebP images are allowed.'];
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = bin2hex(random_bytes(12)) . '.' . strtolower($ext);
        $dest     = self::UPLOAD_DIR . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return [null, 'Could not save the uploaded file.'];
        }

        return [$filename, null];
    }

    private function slugify(string $text): string
    {
        $text = strtolower(trim($text));
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim($text, '-');
    }
}