<?php
declare(strict_types=1);
session_start();

/**
 * MicDog Finance Tracker API
 */

header_remove('X-Powered-By');

function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function text_response(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): void {
    http_response_code($status);
    header('Content-Type: ' . $contentType);
    echo $text;
    exit;
}

function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dataDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0775, true);
    }
    $dbPath = $dataDir . DIRECTORY_SEPARATOR . 'finance.sqlite';
    $needInit = !file_exists($dbPath);

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($needInit) {
        init_db($pdo);
    }

    return $pdo;
}

function init_db(PDO $pdo): void {
    $pdo->exec("
        PRAGMA journal_mode = WAL;
        CREATE TABLE IF NOT EXISTS transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            date TEXT NOT NULL,
            description TEXT NOT NULL,
            category TEXT NOT NULL,
            account TEXT NOT NULL,
            amount REAL NOT NULL,
            tags TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_transactions_date ON transactions(date);
        CREATE INDEX IF NOT EXISTS idx_transactions_category ON transactions(category);
        CREATE INDEX IF NOT EXISTS idx_transactions_amount ON transactions(amount);
    ");
}

function route_path(): string {
    $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $path = '/' . ltrim(substr($uri, strlen($base)), '/');
    $path = preg_replace('#/index\.php#', '', $path, 1);
    return $path === '' ? '/' : $path;
}

function method(): string {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function query(string $k, ?string $default = null): ?string {
    return isset($_GET[$k]) && $_GET[$k] !== '' ? (string)$_GET[$k] : $default;
}

function parse_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    return is_array($data) ? $data : [];
}

// CSRF
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
function require_csrf_for_non_get(): void {
    if (method() === 'GET') return;
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$hdr || !hash_equals($_SESSION['csrf'], $hdr)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

// Validation
function validate_transaction(array $t, bool $partial = false): array {
    $errors = [];

    $fields = ['date','description','category','account','amount','tags'];
    foreach ($fields as $f) {
        if (!$partial && !array_key_exists($f, $t)) $errors[$f] = 'Required';
    }

    if (isset($t['date']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$t['date'])) {
        $errors['date'] = 'Use YYYY-MM-DD';
    }
    if (isset($t['description']) && trim((string)$t['description']) === '') {
        $errors['description'] = 'Cannot be empty';
    }
    if (isset($t['category']) && trim((string)$t['category']) === '') {
        $errors['category'] = 'Cannot be empty';
    }
    if (isset($t['account']) && trim((string)$t['account']) === '') {
        $errors['account'] = 'Cannot be empty';
    }
    if (isset($t['amount']) && !is_numeric($t['amount'])) {
        $errors['amount'] = 'Must be numeric (positive=income, negative=expense)';
    }

    return $errors;
}

$path = route_path();
$method = method();

try {
    if ($path === '/' || $path === '') {
        json_response(['ok' => true, 'service' => 'MicDog Finance Tracker API']);
    }

    if ($path === '/csrf' && $method === 'GET') {
        json_response(['token' => $_SESSION['csrf']]);
    }

    if ($path === '/transactions') {
        if ($method === 'GET') {
            $pdo = get_db();
            $where = [];
            $params = [];

            if ($from = query('from')) { $where[] = 'date >= ?'; $params[] = $from; }
            if ($to = query('to'))     { $where[] = 'date <= ?'; $params[] = $to; }
            if ($cat = query('category')) { $where[] = 'category = ?'; $params[] = $cat; }
            if ($q = query('q')) {
                $where[] = '(description LIKE ? OR tags LIKE ? OR account LIKE ?)';
                $like = '%' . $q . '%';
                array_push($params, $like, $like, $like);
            }

            $sql = 'SELECT * FROM transactions';
            if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
            $sql .= ' ORDER BY date DESC, id DESC';

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            json_response(['items' => $rows]);
        }

        if ($method === 'POST') {
            require_csrf_for_non_get();
            $pdo = get_db();
            $data = parse_json();
            $errors = validate_transaction($data);
            if ($errors) json_response(['errors' => $errors], 422);

            $stmt = $pdo->prepare("
                INSERT INTO transactions (date, description, category, account, amount, tags)
                VALUES (:date, :description, :category, :account, :amount, :tags)
            ");
            $stmt->execute([
                ':date' => $data['date'],
                ':description' => $data['description'],
                ':category' => $data['category'],
                ':account' => $data['account'],
                ':amount' => (float)$data['amount'],
                ':tags' => $data['tags'] ?? null,
            ]);
            $id = (int)$pdo->lastInsertId();

            $row = $pdo->query("SELECT * FROM transactions WHERE id = $id")->fetch();
            json_response(['item' => $row], 201);
        }

        json_response(['error' => 'Method not allowed'], 405);
    }

    if (preg_match('#^/transactions/(\d+)$#', $path, $m)) {
        $id = (int)$m[1];
        $pdo = get_db();

        if ($method === 'PUT') {
            require_csrf_for_non_get();
            $data = parse_json();
            $errors = validate_transaction($data, true);
            if ($errors) json_response(['errors' => $errors], 422);

            $fields = [];
            $params = [];
            foreach (['date','description','category','account','amount','tags'] as $f) {
                if (array_key_exists($f, $data)) {
                    $fields[] = "$f = :$f";
                    $params[":$f"] = ($f === 'amount') ? (float)$data[$f] : $data[$f];
                }
            }
            if (!$fields) json_response(['error' => 'Nothing to update'], 400);

            $sql = "UPDATE transactions SET ".implode(',', $fields).", updated_at = datetime('now') WHERE id = :id";
            $params[':id'] = $id;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $row = $pdo->query("SELECT * FROM transactions WHERE id = $id")->fetch();
            json_response(['item' => $row]);
        }

        if ($method === 'DELETE') {
            require_csrf_for_non_get();
            $stmt = $pdo->prepare("DELETE FROM transactions WHERE id = ?");
            $stmt->execute([$id]);
            json_response(['deleted' => $id]);
        }

        if ($method === 'GET') {
            $row = get_db()->query("SELECT * FROM transactions WHERE id = $id")->fetch();
            if (!$row) json_response(['error' => 'Not found'], 404);
            json_response(['item' => $row]);
        }

        json_response(['error' => 'Method not allowed'], 405);
    }

    if ($path === '/stats' && $method === 'GET') {
        $pdo = get_db();
        $where = [];
        $params = [];

        if ($from = query('from')) { $where[] = 'date >= ?'; $params[] = $from; }
        if ($to = query('to'))     { $where[] = 'date <= ?'; $params[] = $to; }
        if ($cat = query('category')) { $where[] = 'category = ?'; $params[] = $cat; }
        if ($q = query('q')) {
            $where[] = '(description LIKE ? OR tags LIKE ? OR account LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $filterSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT 
            SUM(CASE WHEN amount >= 0 THEN amount ELSE 0 END) AS income,
            SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END)  AS expense
            FROM transactions $filterSql");
        $stmt->execute($params);
        $tot = $stmt->fetch() ?: ['income'=>0,'expense'=>0];

        $stmt = $pdo->prepare("
            SELECT substr(date,1,7) AS ym, SUM(amount) AS total
            FROM transactions $filterSql
            GROUP BY ym
            ORDER BY ym ASC
        ");
        $stmt->execute($params);
        $series = $stmt->fetchAll();

        $stmt = $pdo->prepare("
            SELECT category, SUM(amount) AS total
            FROM transactions $filterSql
            GROUP BY category
            ORDER BY total ASC
        ");
        $stmt->execute($params);
        $byCat = $stmt->fetchAll();

        json_response([
            'income' => (float)($tot['income'] ?? 0),
            'expense' => (float)($tot['expense'] ?? 0),
            'balance' => (float)($tot['income'] ?? 0) + (float)($tot['expense'] ?? 0),
            'series' => $series,
            'byCategory' => $byCat,
        ]);
    }

    if ($path === '/export' && $method === 'GET') {
        $pdo = get_db();
        $where = [];
        $params = [];

        if ($from = query('from')) { $where[] = 'date >= ?'; $params[] = $from; }
        if ($to = query('to'))     { $where[] = 'date <= ?'; $params[] = $to; }
        if ($cat = query('category')) { $where[] = 'category = ?'; $params[] = $cat; }
        if ($q = query('q')) {
            $where[] = '(description LIKE ? OR tags LIKE ? OR account LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = 'SELECT date, description, category, account, amount, tags FROM transactions';
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY date ASC, id ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=\"transactions.csv\"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['date','description','category','account','amount','tags']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['date'], $row['description'], $row['category'],
                $row['account'], (string)$row['amount'], $row['tags']
            ]);
        }
        fclose($out);
        exit;
    }

    if ($path === '/import' && $method === 'POST') {
        require_csrf_for_non_get();
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            json_response(['error' => 'File upload failed'], 400);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $fh = fopen($tmp, 'r');
        if (!$fh) json_response(['error' => 'Cannot open uploaded file'], 400);

        $pdo = get_db();
        $pdo->beginTransaction();
        $count = 0;

        $header = fgetcsv($fh);
        $expected = ['date','description','category','account','amount','tags'];
        $normalize = function($arr){ return array_map(function($s){ return strtolower(trim((string)$s)); }, $arr ?: []); };
        if ($normalize($header) !== $expected) {
            $pdo->rollBack();
            fclose($fh);
            json_response(['error' => 'CSV header must be: ' . implode(',', $expected)], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO transactions (date, description, category, account, amount, tags)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        while (($row = fgetcsv($fh)) !== false) {
            [$date,$desc,$cat,$acc,$amount,$tags] = $row;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;
            if (!is_numeric($amount)) continue;
            $stmt->execute([$date, $desc, $cat, $acc, (float)$amount, $tags]);
            $count++;
        }

        fclose($fh);
        $pdo->commit();
        json_response(['imported' => $count]);
    }

    json_response(['error' => 'Not found', 'path' => $path], 404);
} catch (Throwable $e) {
    json_response(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}
