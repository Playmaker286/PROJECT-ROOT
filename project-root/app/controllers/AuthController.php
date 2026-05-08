<?php
// ============================================================
//  controllers/AuthController.php
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/functions.php';

class AuthController
{
    // ── Login ────────────────────────────────────────────────
    /**
     * POST /login
     * Expects: $_POST['username'], $_POST['password']
     */
    public function login(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        // Basic presence check
        if ($username === '' || $password === '') {
            setFlash('error', 'Username and password are required.');
            redirect('/login');
        }

        try {
            $stmt = Database::query(
                'SELECT id, username, password, role, is_active
                   FROM users
                  WHERE username = :username
                  LIMIT 1',
                [':username' => $username]
            );

            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                setFlash('error', 'Invalid credentials. Please try again.');
                redirect('/login');
            }

            if (!(bool)$user['is_active']) {
                setFlash('error', 'Your account has been deactivated.');
                redirect('/login');
            }

            // Regenerate session ID to prevent fixation
            session_regenerate_id(true);

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['role']      = $user['role'];
            $_SESSION['logged_in'] = true;

            // Rehash if cost factor changed
            if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                $this->updatePasswordHash((int)$user['id'], $password);
            }

            $this->redirectByRole($user['role']);

        } catch (PDOException $e) {
            error_log('AuthController::login — ' . $e->getMessage());
            setFlash('error', 'A server error occurred. Please try again.');
            redirect('/login');
        }
    }

    // ── Logout ───────────────────────────────────────────────
    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();
        redirect('/login');
    }

    // ── Register (admin-only staff creation) ─────────────────
    /**
     * POST /admin/users/create
     */
    public function register(): void
    {
        requireRole('admin');

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/admin/users');
        }

        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password']      ?? '';
        $role     = $_POST['role']          ?? 'staff';

        // Validation
        $errors = $this->validateRegistration($username, $email, $password, $role);

        if ($errors) {
            setFlash('error', implode(' ', $errors));
            redirect('/admin/users/create');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            Database::query(
                'INSERT INTO users (username, email, password, role)
                 VALUES (:username, :email, :password, :role)',
                [
                    ':username' => $username,
                    ':email'    => $email,
                    ':password' => $hash,
                    ':role'     => $role,
                ]
            );

            setFlash('success', "User '{$username}' created successfully.");
            redirect('/admin/users');

        } catch (PDOException $e) {
            // Duplicate entry
            if ($e->getCode() === '23000') {
                setFlash('error', 'Username or email already exists.');
            } else {
                error_log('AuthController::register — ' . $e->getMessage());
                setFlash('error', 'Could not create user. Please try again.');
            }
            redirect('/admin/users/create');
        }
    }

    // ── Change Password ──────────────────────────────────────
    /**
     * POST /account/password
     */
    public function changePassword(): void
    {
        requireAuth();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect('/account');
        }

        $currentPw  = $_POST['current_password']  ?? '';
        $newPw      = $_POST['new_password']       ?? '';
        $confirmPw  = $_POST['confirm_password']   ?? '';

        if ($newPw !== $confirmPw) {
            setFlash('error', 'New passwords do not match.');
            redirect('/account');
        }

        if (strlen($newPw) < 8) {
            setFlash('error', 'Password must be at least 8 characters.');
            redirect('/account');
        }

        try {
            $stmt = Database::query(
                'SELECT password FROM users WHERE id = :id LIMIT 1',
                [':id' => $_SESSION['user_id']]
            );
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPw, $user['password'])) {
                setFlash('error', 'Current password is incorrect.');
                redirect('/account');
            }

            $hash = password_hash($newPw, PASSWORD_BCRYPT, ['cost' => 12]);

            Database::query(
                'UPDATE users SET password = :password WHERE id = :id',
                [':password' => $hash, ':id' => $_SESSION['user_id']]
            );

            setFlash('success', 'Password updated successfully.');
            redirect('/account');

        } catch (PDOException $e) {
            error_log('AuthController::changePassword — ' . $e->getMessage());
            setFlash('error', 'Server error. Please try again.');
            redirect('/account');
        }
    }

    // ── Private helpers ──────────────────────────────────────

    private function redirectByRole(string $role): never
    {
        match ($role) {
            'admin', 'staff' => redirect('/admin/dashboard'),
            default          => redirect('/menu'),
        };
    }

    private function updatePasswordHash(int $userId, string $plainPassword): void
    {
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        Database::query(
            'UPDATE users SET password = :password WHERE id = :id',
            [':password' => $hash, ':id' => $userId]
        );
    }

    /**
     * @return string[]
     */
    private function validateRegistration(
        string $username,
        string $email,
        string $password,
        string $role
    ): array {
        $errors = [];
        $allowedRoles = ['admin', 'staff', 'customer'];

        if ($username === '' || strlen($username) < 3) {
            $errors[] = 'Username must be at least 3 characters.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email address.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!in_array($role, $allowedRoles, true)) {
            $errors[] = 'Invalid role selected.';
        }

        return $errors;
    }
}