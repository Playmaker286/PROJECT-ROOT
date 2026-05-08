<?php
// Expected variables injected by ProductController:
//   $products      – array of paginated products
//   $totalProducts – int
//   $categories    – array from Category::getAll()
//   $page          – int
//   $perPage       – int
//   $filters       – array ['category_id'=>, 'is_available'=>, 'search'=>]

$totalPages = (int) ceil($totalProducts / $perPage);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Products — QR Order Admin</title>
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="admin-layout">

<?php include __DIR__ . '/../../includes/admin_sidebar.php'; ?>

<main class="admin-main">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">Products</h1>
            <span class="text-muted"><?= number_format($totalProducts) ?> total</span>
        </div>
        <button class="btn btn--primary" onclick="openModal('modal-add-product')">
            + Add Product
        </button>
    </div>

    <!-- ── Filters ────────────────────────────────────────── -->
    <form method="GET" action="/admin/products" class="filter-bar">
        <input
            type="text"
            name="search"
            placeholder="Search product name…"
            value="<?= htmlspecialchars($filters['search'] ?? '') ?>"
            class="form-input filter-bar__search"
        >

        <select name="category_id" class="form-select">
            <option value="">All Categories</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= $cat['id'] ?>"
                <?= ($filters['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($cat['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <select name="is_available" class="form-select">
            <option value="">All</option>
            <option value="1" <?= ($filters['is_available'] ?? '') === '1' ? 'selected' : '' ?>>Available</option>
            <option value="0" <?= ($filters['is_available'] ?? '') === '0' ? 'selected' : '' ?>>Hidden</option>
        </select>

        <button type="submit" class="btn btn--primary">Filter</button>
        <a href="/admin/products" class="btn btn--ghost">Clear</a>
    </form>

    <!-- ── Products Table ─────────────────────────────────── -->
    <div class="card">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width:60px">Image</th>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Price</th>
                        <th>Stock</th>
                        <th>Available</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-6">No products found.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($products as $p): ?>
                    <tr data-id="<?= $p['id'] ?>">
                        <td>
                            <?php if ($p['image']): ?>
                                <img src="/assets/images/products/<?= htmlspecialchars($p['image']) ?>"
                                     alt="<?= htmlspecialchars($p['name']) ?>"
                                     class="product-thumb">
                            <?php else: ?>
                                <div class="product-thumb product-thumb--placeholder">🍽</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="font-semibold"><?= htmlspecialchars($p['name']) ?></div>
                            <?php if ($p['description']): ?>
                            <div class="text-sm text-muted truncate" style="max-width:220px">
                                <?= htmlspecialchars($p['description']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['category_name']) ?></td>
                        <td class="font-semibold">₱<?= number_format((float)$p['price'], 2) ?></td>
                        <td>
                            <?php if ($p['stock'] == -1): ?>
                                <span class="tag tag--green">Unlimited</span>
                            <?php elseif ($p['stock'] <= 0): ?>
                                <span class="tag tag--red">Out of Stock</span>
                            <?php elseif ($p['stock'] <= 5): ?>
                                <span class="tag tag--yellow"><?= $p['stock'] ?> left</span>
                            <?php else: ?>
                                <span><?= $p['stock'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <!-- Toggle availability via AJAX -->
                            <label class="toggle">
                                <input type="checkbox"
                                       class="toggle__input js-toggle-available"
                                       data-id="<?= $p['id'] ?>"
                                       <?= $p['is_available'] ? 'checked' : '' ?>>
                                <span class="toggle__slider"></span>
                            </label>
                        </td>
                        <td class="text-center">
                            <button class="btn btn--sm btn--outline js-edit-product"
                                    data-id="<?= $p['id'] ?>"
                                    title="Edit">✏️</button>
                            <form method="POST"
                                  action="/admin/products/<?= $p['id'] ?>/delete"
                                  class="inline-form"
                                  onsubmit="return confirm('Delete this product?')">
                                <?= csrf_field() ?>
                                <button type="submit"
                                        class="btn btn--sm btn--danger"
                                        title="Delete">🗑</button>
                            </form>
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

<!-- ══════════════════════════════════════════════════════════
     ADD / EDIT Product Modal
════════════════════════════════════════════════════════════ -->
<div id="modal-add-product" class="modal" aria-hidden="true">
    <div class="modal__backdrop" onclick="closeModal('modal-add-product')"></div>
    <div class="modal__dialog">
        <div class="modal__header">
            <h2 class="modal__title" id="modal-product-title">Add Product</h2>
            <button class="modal__close" onclick="closeModal('modal-add-product')">✕</button>
        </div>

        <form id="form-product"
              method="POST"
              action="/admin/products"
              enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="_method"     value="POST">
            <input type="hidden" name="product_id"  id="input-product-id" value="">

            <div class="modal__body">

                <div class="form-group">
                    <label class="form-label" for="input-name">Name <span class="required">*</span></label>
                    <input type="text" id="input-name" name="name"
                           class="form-input" required maxlength="150">
                </div>

                <div class="form-group">
                    <label class="form-label" for="input-category">Category <span class="required">*</span></label>
                    <select id="input-category" name="category_id" class="form-select" required>
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>">
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="input-price">Price (₱) <span class="required">*</span></label>
                        <input type="number" id="input-price" name="price"
                               class="form-input" min="0" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="input-stock">
                            Stock <small class="text-muted">(−1 = unlimited)</small>
                        </label>
                        <input type="number" id="input-stock" name="stock"
                               class="form-input" min="-1" value="0">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="input-description">Description</label>
                    <textarea id="input-description" name="description"
                              class="form-textarea" rows="3" maxlength="1000"></textarea>
                </div>

                <div class="form-group">
                    <label class="form-label" for="input-image">Product Image</label>
                    <input type="file" id="input-image" name="image"
                           class="form-input" accept="image/jpeg,image/png,image/webp">
                    <div id="image-preview" class="image-preview mt-2 hidden"></div>
                </div>

                <div class="form-group">
                    <label class="toggle">
                        <input type="checkbox" name="is_available" id="input-available"
                               class="toggle__input" value="1" checked>
                        <span class="toggle__slider"></span>
                        <span class="toggle__label">Available on menu</span>
                    </label>
                </div>

            </div><!-- /.modal__body -->

            <div class="modal__footer">
                <button type="button"
                        class="btn btn--ghost"
                        onclick="closeModal('modal-add-product')">Cancel</button>
                <button type="submit" class="btn btn--primary">Save Product</button>
            </div>
        </form>
    </div><!-- /.modal__dialog -->
</div><!-- /#modal-add-product -->

<script src="/assets/js/admin.js"></script>
<script>
// ── Image preview ───────────────────────────────────────────
document.getElementById('input-image').addEventListener('change', function () {
    const preview = document.getElementById('image-preview');
    if (this.files && this.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            preview.innerHTML = `<img src="${e.target.result}" alt="Preview" class="product-thumb product-thumb--lg">`;
            preview.classList.remove('hidden');
        };
        reader.readAsDataURL(this.files[0]);
    }
});

// ── Toggle availability (AJAX) ──────────────────────────────
document.querySelectorAll('.js-toggle-available').forEach(cb => {
    cb.addEventListener('change', async function () {
        const id       = this.dataset.id;
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        try {
            const res = await fetch(`/admin/products/${id}/toggle`, {
                method : 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfMeta ? csrfMeta.content : '',
                },
            });
            if (!res.ok) throw new Error('Request failed');
        } catch {
            this.checked = !this.checked; // revert on error
            alert('Could not update availability. Please try again.');
        }
    });
});

// ── Edit product — populate modal ──────────────────────────
document.querySelectorAll('.js-edit-product').forEach(btn => {
    btn.addEventListener('click', async function () {
        const id  = this.dataset.id;
        const res = await fetch(`/admin/products/${id}/json`);
        const p   = await res.json();

        document.getElementById('modal-product-title').textContent  = 'Edit Product';
        document.getElementById('input-product-id').value           = p.id;
        document.getElementById('input-name').value                 = p.name;
        document.getElementById('input-category').value             = p.category_id;
        document.getElementById('input-price').value                = p.price;
        document.getElementById('input-stock').value                = p.stock;
        document.getElementById('input-description').value          = p.description ?? '';
        document.getElementById('input-available').checked          = p.is_available == 1;

        // Switch form to PUT/PATCH via hidden _method
        document.querySelector('#form-product [name="_method"]').value = 'PUT';
        document.getElementById('form-product').action = `/admin/products/${p.id}`;

        openModal('modal-add-product');
    });
});
</script>
</body>
</html>