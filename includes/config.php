<?php
// =============================================================
// InvenIQ — Database Configuration
// Edit DB_PASS if you have set a MySQL password in XAMPP
// =============================================================

define('DB_HOST',    getenv('DB_HOST') ?: 'localhost');
define('DB_USER',    getenv('DB_USER') ?: 'root');
define('DB_PASS',    getenv('DB_PASS') ?: '');          // XAMPP default: empty
define('DB_NAME',    getenv('DB_NAME') ?: 'inveniq');
define('DB_CHARSET', 'utf8mb4');

define('BASE_PATH', '/inveniq');   // URL prefix — matches your htdocs folder name
define('APP_NAME',  'InvenIQ');
define('CURRENCY',  '₹');

// ---- Machine-learning connector ----------------------------
// The PHP app loads demand predictions from the Python model (model.pkl)
// through these. Flask is tried first, then `python predict.py`.
define('ML_FLASK_URL',      getenv('ML_FLASK_URL') ?: 'http://127.0.0.1:5000'); // run: python ml/predict_service.py
define('ML_PYTHON',         'python');                // change to 'py' or a full path if needed
define('ML_PREDICT_SCRIPT', __DIR__ . '/../ml/predict.py');
define('ML_CACHE_TTL',      900);                     // cache predictions for 15 minutes

// ---- AI Assistant (Anthropic Claude) ------------------------
// Get a key at https://console.anthropic.com/ (Settings -> API Keys).
// Prefer a real environment variable over hardcoding it here, especially
// if this folder is ever pushed to a public repo.
define('ANTHROPIC_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '');
define('ANTHROPIC_MODEL',   getenv('ANTHROPIC_MODEL') ?: 'claude-3-5-haiku-20241022');        // fast + cheap; swap for a Sonnet model for stronger answers

// Singleton PDO connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error'  => 'Database connection failed.',
                'detail' => $e->getMessage(),
                'hint'   => 'Check DB_HOST/DB_USER/DB_PASS/DB_NAME in includes/config.php'
            ]);
            exit;
        }
    }
    return $pdo;
}

function jsonResponse(mixed $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function daysBetween(string $from, string $to = ''): int {
    if (!$to) $to = date('Y-m-d');
    return (int) round((strtotime($to) - strtotime($from)) / 86400);
}

function today(): string { return date('Y-m-d'); }
