<?php
// ============================================================
//  helpers/functions.php
//  Utility functions for QR Ordering System
// ============================================================

declare(strict_types=1);

// ============================================================
// SESSION & AUTHENTICATION MIDDLEWARE
// ============================================================

/**
 * Start session if not already started
 */
function start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

/**
 * Require user to be authenticated
 */
function requireAuth(): void
{
    start_session();
    
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in'])) {
        redirect('/login');
    }
}

/**
 * Require specific role(s) - accepts multiple roles
 */
function requireRole(...$roles): void
{
    start_session();
    requireAuth();
    
    $user_role = $_SESSION['role'] ?? null;
    
    if (!in_array($user_role, $roles, true)) {
        http_response_code(403);
        exit('Access Denied. Insufficient permissions.');
    }
}

// ============================================================
// VALIDATION & SANITIZATION
// ============================================================

/**
 * Validate email format
 */
function is_valid_email(string $email): bool
{
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate username (alphanumeric + underscore, 3-80 chars)
 */
function is_valid_username(string $username): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9_]{3,80}$/', $username);
}

/**
 * Validate password strength
 * Requires: min 8 chars, 1 uppercase, 1 lowercase, 1 digit, 1 special char
 */
function is_valid_password(string $password): bool
{
    return (bool) preg_match(
        '/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/',
        $password
    );
}

/**
 * Sanitize string input
 */
function sanitize_string(string $input): string
{
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Sanitize integer input
 */
function sanitize_int($input): int
{
    return (int) filter_var($input, FILTER_SANITIZE_NUMBER_INT);
}

/**
 * Sanitize float input
 */
function sanitize_float($input): float
{
    return (float) filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, 
        FILTER_FLAG_ALLOW_FRACTION);
}

// ============================================================
// PASSWORD & HASHING
// ============================================================

/**
 * Hash password using bcrypt (cost 12)
 */
function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Verify password against bcrypt hash
 */
function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/**
 * Check if password needs rehashing (cost changed or algorithm updated)
 */
function needs_password_rehash(string $hash): bool
{
    return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
}

// ============================================================
// SLUG & URL GENERATION
// ============================================================

/**
 * Convert string to URL-friendly slug
 * Example: "Hello World!" → "hello-world"
 */
function generate_slug(string $text): string
{
    // Convert to lowercase
    $text = strtolower($text);
    
    // Replace non-alphanumeric characters with hyphens
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    
    // Remove leading/trailing hyphens
    $text = trim($text, '-');
    
    return $text;
}

/**
 * Check if slug already exists in database
 */
function slug_exists(string $table, string $slug, ?int $exclude_id = null): bool
{
    $sql = "SELECT COUNT(*) as count FROM $table WHERE slug = ?";
    $params = [$slug];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $result = Database::query($sql, $params)->fetch();
    return $result['count'] > 0;
}

/**
 * Generate unique slug with auto-increment if duplicate exists
 * Example: "hello-world" → "hello-world-2" if "hello-world" exists
 */
function generate_unique_slug(string $table, string $text, ?int $exclude_id = null): string
{
    $slug = generate_slug($text);
    
    if (!slug_exists($table, $slug, $exclude_id)) {
        return $slug;
    }
    
    $counter = 2;
    while (slug_exists($table, "$slug-$counter", $exclude_id)) {
        $counter++;
    }
    
    return "$slug-$counter";
}

// ============================================================
// QR CODE & TOKEN GENERATION
// ============================================================

/**
 * Generate secure random token (64 chars hex)
 * Used for QR codes, password resets, etc.
 */
function generate_secure_token(int $length = 64): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate order number with format: ORD-YYYYMMDD-0001
 */
function generate_order_number(): string
{
    $date = date('Ymd');
    
    // Count orders created today
    $result = Database::query(
        "SELECT COUNT(*) as count FROM orders 
         WHERE DATE(created_at) = CURDATE()"
    )->fetch();
    
    $sequence = ($result['count'] + 1);
    
    return sprintf('ORD-%s-%04d', $date, $sequence);
}

/**
 * Generate QR URL for a table or takeout
 * Example: https://example.com/qr/abc123xyz...
 */
function generate_qr_url(string $token, string $base_url = null): string
{
    if ($base_url === null) {
        $base_url = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_url = 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $base_url;
    }
    
    return rtrim($base_url, '/') . '/qr/' . $token;
}

// ============================================================
// FORMATTING & DISPLAY
// ============================================================

/**
 * Format price as Philippine Peso
 * Example: 1500.50 → ₱1,500.50
 */
function format_currency(float $amount): string
{
    return '₱' . number_format($amount, 2, '.', ',');
}

/**
 * Format price for database storage (no currency symbol)
 */
function format_price(float $amount): string
{
    return number_format($amount, 2, '.', '');
}

/**
 * Format date for display
 * Example: 2026-05-08 13:45:00 → May 08, 2026
 */
function format_date(string $date_string, string $format = 'M d, Y'): string
{
    return date($format, strtotime($date_string));
}

/**
 * Format datetime for display
 * Example: 2026-05-08 13:45:00 → May 08, 2026 1:45 PM
 */
function format_datetime(string $datetime_string): string
{
    return date('M d, Y g:i A', strtotime($datetime_string));
}

/**
 * Get order status badge HTML
 */
function get_status_badge(string $status): string
{
    $badges = [
        'pending'    => '<span class="badge bg-warning">Pending</span>',
        'confirmed'  => '<span class="badge bg-info">Confirmed</span>',
        'preparing'  => '<span class="badge bg-primary">Preparing</span>',
        'ready'      => '<span class="badge bg-success">Ready</span>',
        'completed'  => '<span class="badge bg-success">Completed</span>',
        'cancelled'  => '<span class="badge bg-danger">Cancelled</span>',
    ];
    
    return $badges[$status] ?? '<span class="badge bg-secondary">Unknown</span>';
}

/**
 * Get user role badge HTML
 */
function get_role_badge(string $role): string
{
    $badges = [
        'admin'    => '<span class="badge bg-danger">Admin</span>',
        'staff'    => '<span class="badge bg-info">Staff</span>',
        'customer' => '<span class="badge bg-secondary">Customer</span>',
    ];
    
    return $badges[$role] ?? '<span class="badge bg-secondary">Unknown</span>';
}

/**
 * Truncate text to specified length with ellipsis
 */
function truncate_text(string $text, int $length = 50, string $suffix = '...'): string
{
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
}

// ============================================================
// USER & AUTHENTICATION
// ============================================================

/**
 * Get user by ID
 */
function get_user_by_id(int $id): ?array
{
    $result = Database::query(
        "SELECT * FROM users WHERE id = ?",
        [$id]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get user by username
 */
function get_user_by_username(string $username): ?array
{
    $result = Database::query(
        "SELECT * FROM users WHERE username = ?",
        [$username]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get user by email
 */
function get_user_by_email(string $email): ?array
{
    $result = Database::query(
        "SELECT * FROM users WHERE email = ?",
        [$email]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Check if username exists
 */
function username_exists(string $username, ?int $exclude_id = null): bool
{
    $sql = "SELECT COUNT(*) as count FROM users WHERE username = ?";
    $params = [$username];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $result = Database::query($sql, $params)->fetch();
    return $result['count'] > 0;
}

/**
 * Check if email exists
 */
function email_exists(string $email, ?int $exclude_id = null): bool
{
    $sql = "SELECT COUNT(*) as count FROM users WHERE email = ?";
    $params = [$email];
    
    if ($exclude_id !== null) {
        $sql .= " AND id != ?";
        $params[] = $exclude_id;
    }
    
    $result = Database::query($sql, $params)->fetch();
    return $result['count'] > 0;
}

/**
 * Check if user has a specific role
 */
function user_has_role(int $user_id, string $role): bool
{
    $result = Database::query(
        "SELECT role FROM users WHERE id = ?",
        [$user_id]
    )->fetch();
    
    return $result && $result['role'] === $role;
}

/**
 * Check if user is admin
 */
function is_admin(int $user_id): bool
{
    return user_has_role($user_id, 'admin');
}

/**
 * Check if user is staff
 */
function is_staff(int $user_id): bool
{
    return user_has_role($user_id, 'staff');
}

// ============================================================
// ORDER HELPERS
// ============================================================

/**
 * Get order by ID with all details
 */
function get_order(int $id): ?array
{
    $order = Database::query(
        "SELECT * FROM orders WHERE id = ?",
        [$id]
    )->fetch();
    
    if (!$order) {
        return null;
    }
    
    // Add order items
    $order['items'] = get_order_items($id);
    
    // Add payment info if exists
    $payment = Database::query(
        "SELECT * FROM payments WHERE order_id = ?",
        [$id]
    )->fetch();
    $order['payment'] = $payment ?: null;
    
    return $order;
}

/**
 * Get order by order_number
 */
function get_order_by_number(string $order_number): ?array
{
    $order = Database::query(
        "SELECT * FROM orders WHERE order_number = ?",
        [$order_number]
    )->fetch();
    
    return $order ? get_order($order['id']) : null;
}

/**
 * Get all items in an order with product details
 */
function get_order_items(int $order_id): array
{
    return Database::query(
        "SELECT oi.*, p.name as product_name, p.image 
         FROM order_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = ?
         ORDER BY oi.id ASC",
        [$order_id]
    )->fetchAll();
}

/**
 * Calculate order totals (subtotal, tax, total)
 */
function calculate_order_totals(int $order_id): array
{
    $result = Database::query(
        "SELECT 
            SUM(subtotal) as subtotal,
            (SELECT discount FROM orders WHERE id = ?) as discount
         FROM order_items
         WHERE order_id = ?",
        [$order_id, $order_id]
    )->fetch();
    
    $subtotal = (float) ($result['subtotal'] ?? 0);
    $discount = (float) ($result['discount'] ?? 0);
    $total = $subtotal - $discount;
    
    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total'    => $total,
    ];
}

/**
 * Count orders by status
 */
function count_orders_by_status(string $status): int
{
    $result = Database::query(
        "SELECT COUNT(*) as count FROM orders WHERE status = ?",
        [$status]
    )->fetch();
    
    return (int) $result['count'];
}

/**
 * Get recent orders (limit 10)
 */
function get_recent_orders(int $limit = 10): array
{
    return Database::query(
        "SELECT * FROM orders 
         ORDER BY created_at DESC 
         LIMIT ?",
        [$limit]
    )->fetchAll();
}

// ============================================================
// PRODUCT & CATEGORY HELPERS
// ============================================================

/**
 * Get product by ID
 */
function get_product(int $id): ?array
{
    $result = Database::query(
        "SELECT * FROM products WHERE id = ?",
        [$id]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get product by slug
 */
function get_product_by_slug(string $slug): ?array
{
    $result = Database::query(
        "SELECT * FROM products WHERE slug = ?",
        [$slug]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get category by ID
 */
function get_category(int $id): ?array
{
    $result = Database::query(
        "SELECT * FROM categories WHERE id = ?",
        [$id]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get category by slug
 */
function get_category_by_slug(string $slug): ?array
{
    $result = Database::query(
        "SELECT * FROM categories WHERE slug = ?",
        [$slug]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Get all active categories sorted by order
 */
function get_all_categories(): array
{
    return Database::query(
        "SELECT * FROM categories 
         WHERE is_active = 1
         ORDER BY sort_order ASC, name ASC"
    )->fetchAll();
}

/**
 * Get products by category
 */
function get_products_by_category(int $category_id): array
{
    return Database::query(
        "SELECT * FROM products 
         WHERE category_id = ? AND is_available = 1
         ORDER BY name ASC",
        [$category_id]
    )->fetchAll();
}

/**
 * Check if product is in stock
 */
function is_in_stock(int $product_id): bool
{
    $result = Database::query(
        "SELECT stock FROM products WHERE id = ?",
        [$product_id]
    )->fetch();
    
    if (!$result) {
        return false;
    }
    
    // -1 means unlimited stock
    return $result['stock'] === -1 || $result['stock'] > 0;
}

// ============================================================
// QR CODE & FLASH MESSAGE HELPERS
// ============================================================

/**
 * Get QR code by token
 */
function get_qr_code(string $token): ?array
{
    $result = Database::query(
        "SELECT * FROM qr_codes WHERE token = ? AND is_active = 1",
        [$token]
    )->fetch();
    
    return $result ?: null;
}

/**
 * Set session flash message
 */
function set_flash(string $type, string $message): void
{
    start_session();
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * camelCase alias for set_flash()
 */
function setFlash(string $type, string $message): void
{
    set_flash($type, $message);
}

/**
 * Get and clear session flash message
 */
function get_flash(): ?array
{
    start_session();
    
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    
    return $flash;
}

/**
 * Destroy session and unset all data
 */
function destroy_session(): void
{
    start_session();
    session_destroy();
    $_SESSION = [];
}

// ============================================================
// RESPONSE HELPERS
// ============================================================

/**
 * Return JSON response with status code
 */
function jsonResponse(array $data, int $status_code = 200): void
{
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/**
 * Redirect to URL
 */
function redirect(string $url): void
{
    header("Location: $url");
    exit;
}

/**
 * Redirect with flash message
 */
function redirect_with_message(string $url, string $type, string $message): void
{
    set_flash($type, $message);
    redirect($url);
}

// ============================================================
// UTILITY & HELPERS
// ============================================================

/**
 * Get client's real IP address
 */
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    return trim($ip);
}

/**
 * Check if request is AJAX
 */
function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Log message to file
 */
function log_message(string $message, string $level = 'INFO'): void
{
    $timestamp = date('Y-m-d H:i:s');
    $log_dir = __DIR__ . '/../logs';
    
    // Create logs directory if it doesn't exist
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $log_file = $log_dir . '/app.log';
    $log_entry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Pretty print variable for debugging
 */
function debug_print($var, bool $exit = false): void
{
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    
    if ($exit) {
        exit;
    }
}

/**
 * Get environment variable with default fallback
 */
function get_env(string $key, $default = null)
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

/**
 * Check current request method
 */
function is_get(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'GET';
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function is_put(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'PUT';
}

function is_delete(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'DELETE';
}

/**
 * Get GET parameter safely
 */
function get_get(string $key, $default = null)
{
    return isset($_GET[$key]) ? sanitize_string((string) $_GET[$key]) : $default;
}

/**
 * Get POST parameter safely
 */
function get_post(string $key, $default = null)
{
    return isset($_POST[$key]) ? sanitize_string((string) $_POST[$key]) : $default;
}

/**
 * Get REQUEST parameter (GET or POST)
 */
function get_request(string $key, $default = null)
{
    return get_post($key) ?? get_get($key) ?? $default;
}
