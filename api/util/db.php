<?php
// Smart Air Desa - Database Connection
// Auto-detect environment: Supabase (Vercel) / XAMPP (local)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$isProduction = isset($_ENV['VERCEL']) || getenv('VERCEL');

if ($isProduction) {
    // Production: Supabase PostgreSQL (Using Explicit Pooler Host)
    $host = 'aws-0-ap-southeast-1.pooler.supabase.com';
    $port = '6543';
    $dbname = 'postgres';
    $username = 'postgres.ycbqadjsjphovxcbicvm';
    $password = getenv('SUPABASE_DB_PASS') ?: 'PprVXblC3jp6oBfi';
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;sslmode=require";
}
else {
    // Local: XAMPP MySQL
    $dsn = "mysql:host=localhost;dbname=smart_air_desa;charset=utf8mb4";
    $username = 'root';
    $password = '';
}

/**
 * Custom PDO wrapper for cross-database lastInsertId() compatibility.
 * PostgreSQL's PDO driver requires the sequence name for lastInsertId().
 * This wrapper auto-detects the table from the last INSERT and builds the sequence name.
 */
class SmartPDO extends PDO
{
    private $lastTable = null;
    private $isPostgres = false;

    public function __construct($dsn, $username, $password, $options)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->isPostgres = (strpos($dsn, 'pgsql:') === 0);
    }

    public function prepare($query, $options = [])
    {
        // Track table name from INSERT queries
        if (preg_match('/INSERT\s+INTO\s+[`"]?(\w+)[`"]?/i', $query, $m)) {
            $this->lastTable = $m[1];
        }
        return parent::prepare($query, $options);
    }

    public function lastInsertId($name = null)
    {
        if ($name === null && $this->isPostgres && $this->lastTable) {
            // PostgreSQL sequence naming convention: tablename_id_seq
            return parent::lastInsertId($this->lastTable . '_id_seq');
        }
        return parent::lastInsertId($name);
    }
}

try {
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new SmartPDO($dsn, $username, $password, $options);

    // Set timezone for PostgreSQL
    if ($isProduction) {
        $pdo->exec("SET timezone = 'Asia/Jakarta'");
    }
}
catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Database connection failed: ' . $e->getMessage()
    ]);
    exit();
}
?>
