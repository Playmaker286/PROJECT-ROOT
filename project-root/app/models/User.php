<?php
// ============================================================
//  models/User.php
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class User
{
    // ── Constants ────────────────────────────────────────────
    public const ROLES = ['admin', 'staff', 'customer'];

    // ── Properties (mirrors DB columns) ──────────────────────
    public int    $id;
    public string $username;
    public string $email;
    public string $password;
    public string $role;
    public bool   $isActive;
    public string $createdAt;
    public string $updatedAt;

    // ── Constructor ──────────────────────────────────────────
    public function __construct(array $row = [])
    {
        $this->id        = (int)($row['id']         ?? 0);
        $this->username  = $row['username']          ?? '';
        $this->email     = $row['email']             ?? '';
        $this->password  = $row['password']          ?? '';
        $this->role      = $row['role']              ?? 'customer';
        $this->isActive  = (bool)($row['is_active']  ?? true);
        $this->createdAt = $row['created_at']        ?? '';
        $this->updatedAt = $row['updated_at']        ?? '';
    }

    // ── Finders ──────────────────────────────────────────────

    /**
     * Find a user by primary key.
     */
    public static function find(int $id): ?self
    {
        $row = Database::query(
            'SELECT * FROM users WHERE id = :id LIMIT 1',
            [':id' => $id]
        )->fetch();

        return $row ? new self($row) : null;
    }

    /**
     * Find a user by username (case-insensitive).
     */
    public static function findByUsername(string $username): ?self
    {
        $row = Database::query(
            'SELECT * FROM users WHERE LOWER(username) = LOWER(:username) LIMIT 1',
            [':username' => $username]
        )->fetch();

        return $row ? new self($row) : null;
    }

    /**
     * Find a user by email address.
     */
    public static function findByEmail(string $email): ?self
    {
        $row = Database::query(
            'SELECT * FROM users WHERE email = :email LIMIT 1',
            [':email' => strtolower($email)]
        )->fetch();

        return $row ? new self($row) : null;
    }

    /**
     * Paginated list of all users, optionally filtered by role or search term.
     *
     * @return array{users: self[], total: int, total_pages: int}
     */
    public static function paginate(
        int    $page    = 1,
        int    $perPage = 20,
        string $role    = '',
        string $search  = ''
    ): array {
        $where  = ['1=1'];
        $params = [];

        if ($role !== '' && in_array($role, self::ROLES, true)) {
            $where[]        = 'role = :role';
            $params[':role'] = $role;
        }

        if ($search !== '') {
            $where[]           = '(username LIKE :search OR email LIKE :search)';
            $params[':search'] = '%' . $search . '%';
        }

        $whereSQL = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        $total = (int)Database::query(
            "SELECT COUNT(*) FROM users WHERE {$whereSQL}",
            $params
        )->fetchColumn();

        $rows = Database::query(
            "SELECT * FROM users WHERE {$whereSQL}
             ORDER BY created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params
        )->fetchAll();

        return [
            'users'       => array_map(fn($r) => new self($r), $rows),
            'total'       => $total,
            'total_pages' => (int)ceil($total / $perPage),
        ];
    }

    // ── Persistence ──────────────────────────────────────────

    /**
     * Insert a new user row. Returns the new ID on success.
     *
     * @param array{
     *   username: string,
     *   email: string,
     *   password: string,
     *   role?: string
     * } $data  Plain-text password — hashed internally.
     */
    public static function create(array $data): int
    {
        self::assertUniqueUsername($data['username']);
        self::assertUniqueEmail($data['email']);

        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        $role = in_array($data['role'] ?? '', self::ROLES, true)
                  ? $data['role'] : 'customer';

        Database::query(
            'INSERT INTO users (username, email, password, role)
             VALUES (:username, :email, :password, :role)',
            [
                ':username' => trim($data['username']),
                ':email'    => strtolower(trim($data['email'])),
                ':password' => $hash,
                ':role'     => $role,
            ]
        );

        return (int)Database::lastInsertId();
    }

    /**
     * Update editable fields for this user instance.
     *
     * @param array{
     *   username?: string,
     *   email?: string,
     *   role?: string,
     *   is_active?: bool
     * } $data
     */
    public function update(array $data): bool
    {
        $username = trim($data['username'] ?? $this->username);
        $email    = strtolower(trim($data['email'] ?? $this->email));
        $role     = in_array($data['role'] ?? '', self::ROLES, true)
                      ? $data['role'] : $this->role;
        $isActive = (bool)($data['is_active'] ?? $this->isActive);

        // Uniqueness checks — skip if value hasn't changed
        if ($username !== $this->username) {
            self::assertUniqueUsername($username, $this->id);
        }
        if ($email !== $this->email) {
            self::assertUniqueEmail($email, $this->id);
        }

        Database::query(
            'UPDATE users
                SET username  = :username,
                    email     = :email,
                    role      = :role,
                    is_active = :active
              WHERE id = :id',
            [
                ':username' => $username,
                ':email'    => $email,
                ':role'     => $role,
                ':active'   => (int)$isActive,
                ':id'       => $this->id,
            ]
        );

        // Sync instance state
        $this->username = $username;
        $this->email    = $email;
        $this->role     = $role;
        $this->isActive = $isActive;

        return true;
    }

    /**
     * Update only the password for this user.
     * Accepts a plain-text password; hashes it internally.
     */
    public function updatePassword(string $plainPassword): void
    {
        if (strlen($plainPassword) < 8) {
            throw new InvalidArgumentException('Password must be at least 8 characters.');
        }

        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::query(
            'UPDATE users SET password = :password WHERE id = :id',
            [':password' => $hash, ':id' => $this->id]
        );

        $this->password = $hash;
    }

    /**
     * Soft-delete: deactivate the account instead of removing the row.
     * Preserves order history and foreign key integrity.
     */
    public function deactivate(): void
    {
        Database::query(
            'UPDATE users SET is_active = 0 WHERE id = :id',
            [':id' => $this->id]
        );
        $this->isActive = false;
    }

    /**
     * Re-activate a previously deactivated account.
     */
    public function activate(): void
    {
        Database::query(
            'UPDATE users SET is_active = 1 WHERE id = :id',
            [':id' => $this->id]
        );
        $this->isActive = true;
    }

    /**
     * Hard-delete. Use only if no referencing orders exist.
     */
    public function delete(): void
    {
        Database::query(
            'DELETE FROM users WHERE id = :id',
            [':id' => $this->id]
        );
    }

    // ── Authentication helpers ────────────────────────────────

    /**
     * Verify a plain-text password against this user's stored hash.
     */
    public function verifyPassword(string $plainPassword): bool
    {
        return password_verify($plainPassword, $this->password);
    }

    /**
     * Returns true if the stored hash should be upgraded
     * (e.g. cost factor changed).
     */
    public function needsRehash(): bool
    {
        return password_needs_rehash(
            $this->password,
            PASSWORD_BCRYPT,
            ['cost' => 12]
        );
    }

    // ── Role helpers ─────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return $this->role === 'staff';
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    /**
     * Check if this user has at least one of the given roles.
     */
    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    // ── Serialization ────────────────────────────────────────

    /**
     * Return a safe array representation (no password hash).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'username'   => $this->username,
            'email'      => $this->email,
            'role'       => $this->role,
            'is_active'  => $this->isActive,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }

    /**
     * Return JSON-safe representation (alias for API responses).
     *
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return $this->toArray();
    }

    // ── Private guards ────────────────────────────────────────

    /**
     * Throw if the username is already taken by another user.
     */
    private static function assertUniqueUsername(string $username, int $excludeId = 0): void
    {
        $count = (int)Database::query(
            'SELECT COUNT(*) FROM users
              WHERE LOWER(username) = LOWER(:username) AND id != :id',
            [':username' => $username, ':id' => $excludeId]
        )->fetchColumn();

        if ($count > 0) {
            throw new RuntimeException("Username '{$username}' is already taken.");
        }
    }

    /**
     * Throw if the email is already registered to another user.
     */
    private static function assertUniqueEmail(string $email, int $excludeId = 0): void
    {
        $count = (int)Database::query(
            'SELECT COUNT(*) FROM users
              WHERE email = :email AND id != :id',
            [':email' => strtolower($email), ':id' => $excludeId]
        )->fetchColumn();

        if ($count > 0) {
            throw new RuntimeException("Email '{$email}' is already registered.");
        }
    }
}