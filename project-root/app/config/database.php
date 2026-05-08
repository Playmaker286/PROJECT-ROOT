<?php
// ============================================================
//  config/database.php
//  Singleton PDO wrapper — one connection per request
// ============================================================

declare(strict_types=1);

class Database
{
    // ── connection settings ──────────────────────────────────
    private const HOST    = 'localhost';
    private const PORT    = 3306;
    private const DBNAME  = 'qr_ordering';
    private const USER    = 'root';          // change in production
    private const PASS    = '';              // change in production
    private const CHARSET = 'utf8mb4';

    private static ?PDO $instance = null;

    // Prevent instantiation
    private function __construct() {}
    private function __clone() {}

    // ── get shared PDO instance ──────────────────────────────
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                self::HOST,
                self::PORT,
                self::DBNAME,
                self::CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
            ];

            try {
                self::$instance = new PDO($dsn, self::USER, self::PASS, $options);
            } catch (PDOException $e) {
                // Never expose credentials in production — log instead
                error_log('DB Connection failed: ' . $e->getMessage());
                http_response_code(503);
                exit('Database unavailable. Please try again later.');
            }
        }

        return self::$instance;
    }

    // ── convenience: prepared query ──────────────────────────
    /**
     * @param  array<int|string, mixed> $params
     */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // ── last inserted id ─────────────────────────────────────
    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }

    // ── transaction helpers ──────────────────────────────────
    public static function beginTransaction(): void
    {
        self::getInstance()->beginTransaction();
    }

    public static function commit(): void
    {
        self::getInstance()->commit();
    }

    public static function rollBack(): void
    {
        self::getInstance()->rollBack();
    }
}