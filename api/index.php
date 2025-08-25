<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// api/index.php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json; charset=utf-8");



// --- DATABASE CONNECTION (parse DATABASE_URL if ada) ---
function get_db_pdo() {
    $databaseUrl = getenv('DATABASE_URL') ?: null;

    if ($databaseUrl) {
        $parts = parse_url($databaseUrl);
        $dbHost = $parts['host'] ?? 'postgres.railway.internal';
        $dbPort = $parts['port'] ?? 5432;
        $dbUser = $parts['user'] ?? 'postgres';
        $dbPass = $parts['pass'] ?? 'KmHgAwpftjhUqqfutZJanIWKsQbpAYIN';
        $dbName = isset($parts['path']) ? ltrim($parts['path'], '/') : 'postgres';
    } else {
        // fallback (set these env vars on Railway if you prefer)
        $dbHost = getenv('DB_HOST') ?: 'postgres.railway.internal';
        $dbPort = getenv('DB_PORT') ?: 5432;
        $dbUser = getenv('DB_USER') ?: 'postgres';
        $dbPass = getenv('DB_PASSWORD') ?: 'KmHgAwpftjhUqqfutZJanIWKsQbpAYIN';
        $dbName = getenv('DB_NAME') ?: 'postgres';
    }

    $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "DB connection error", "error" => $e->getMessage()]);
        exit;
    }
}

// Tambahkan fungsi ini untuk memastikan tabel ada
function initialize_db($pdo) {
    $createCommentsTable = "
        CREATE TABLE IF NOT EXISTS comments (
            id SERIAL PRIMARY KEY,
            own BOOLEAN DEFAULT FALSE,
            name VARCHAR(255) NOT NULL,
            comment TEXT NOT NULL,
            presence INTEGER DEFAULT 0,
            total_guest INTEGER DEFAULT 0,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
        );
    ";
    
    $createRepliesTable = "
        CREATE TABLE IF NOT EXISTS replies (
            id SERIAL PRIMARY KEY,
            comment_id INTEGER NOT NULL,
            name VARCHAR(255) NOT NULL,
            comment TEXT NOT NULL,
            created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (comment_id) REFERENCES comments (id) ON DELETE CASCADE
        );
    ";
    
    try {
        $pdo->exec($createCommentsTable);
        $pdo->exec($createRepliesTable);
    } catch (PDOException $e) {
        // Log the error but don't stop the script
        error_log("DB initialization error: " . $e->getMessage());
    }
}


// small helper formatting datetime
function fmt_time($dt) {
    if (!$dt) return '';
    $t = strtotime($dt);
    return date("Y-m-d H:i:s", $t);
}

// parse JSON body if sent as JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if (is_array($input)) {
        $_POST = $input;
    }
}

$pdo = get_db_pdo();
initialize_db($pdo); // Panggil fungsi inisialisasi di sini

// ---------- ROUTING / HANDLERS ----------
$method = $_SERVER['REQUEST_METHOD'];

// ---- POST: create comment or reply ----
if ($method === 'POST') {
    $name = trim($_POST['name'] ?? 'Anonim');
    $presence = intval($_POST['presence'] ?? 0);
    $total_guest = intval($_POST['total_guest'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');
    $parentUuid = $_POST['parent_uuid'] ?? null;

    if ($comment === '') {
        echo json_encode(["success" => false, "message" => "Empty comment"]);
        exit;
    }

    try {
        if ($parentUuid) {
            // insert reply
            $stmt = $pdo->prepare("INSERT INTO replies (comment_id, name, comment) VALUES (:cid, :name, :comment) RETURNING id, comment_id, name, comment, created_at");
            $stmt->execute([':cid' => $parentUuid, ':name' => $name, ':comment' => $comment]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // format response to match old structure for replies
            $newReply = [
                "uuid" => $row['id'],
                "name" => $row['name'],
                "comment" => $row['comment'],
                "time" => fmt_time($row['created_at'])
            ];

            http_response_code(201);
            echo json_encode(["success" => true, "data" => $newReply, "code" => 201]);
            exit;
        } else {
            // insert parent comment
            $stmt = $pdo->prepare("INSERT INTO comments (name, comment, presence, total_guest) VALUES (:name, :comment, :presence, :total_guest) RETURNING id, own, name, comment, presence, total_guest, created_at");
            $stmt->execute([':name' => $name, ':comment' => $comment, ':presence' => $presence, ':total_guest' => $total_guest]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            $newComment = [
                "uuid" => $row['id'],
                "own" => $row['own'],
                "name" => $row['name'],
                "comment" => $row['comment'],
                "time" => fmt_time($row['created_at']),
                "presence" => intval($row['presence']),
                "total_guest" => intval($row['total_guest']),
                "replies" => []
            ];

            http_response_code(201);
            echo json_encode(["success" => true, "data" => $newComment, "code" => 201]);
            exit;
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "DB insert error", "error" => $e->getMessage()]);
        exit;
    }
}

// ---- Delete all or delete one ----
if ($method === 'GET' && isset($_GET['action'])) {
    $action = $_GET['action'];
    if ($action === 'deleteAll') {
        try {
            // delete all comments (replies cascade if FK set)
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM replies");
            $pdo->exec("DELETE FROM comments");
            $pdo->commit();
            echo json_encode(["success" => true, "message" => "Semua komentar dihapus"]);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "DB deleteAll error", "error" => $e->getMessage()]);
            exit;
        }
    } elseif ($action === 'delete' && isset($_GET['uuid'])) {
        $uuid = $_GET['uuid'];
        try {
            $stmt = $pdo->prepare("DELETE FROM comments WHERE id = :id");
            $stmt->execute([':id' => $uuid]);
            echo json_encode(["success" => true, "message" => "Komentar dihapus"]);
            exit;
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(["success" => false, "message" => "DB delete error", "error" => $e->getMessage()]);
            exit;
        }
    }
}

// ---- Default: GET all comments (with pagination) ----
if ($method === 'GET') {
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(1, intval($_GET['per_page'] ?? 10));
    $offset = ($page - 1) * $per_page;

    try {
        // total count
        $countStmt = $pdo->query("SELECT COUNT(*) FROM comments");
        $total = (int)$countStmt->fetchColumn();

        // fetch comments page
        $stmt = $pdo->prepare("SELECT id, own, name, comment, presence, total_guest, created_at FROM comments ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ids = array_column($comments, 'id');

        $replies = [];
        if (count($ids) > 0) {
            // dynamic placeholders
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt2 = $pdo->prepare("SELECT id, comment_id, name, comment, created_at FROM replies WHERE comment_id IN ($placeholders) ORDER BY created_at ASC");
            $stmt2->execute($ids);
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $replies[$r['comment_id']][] = [
                    "uuid" => $r['id'],
                    "name" => $r['name'],
                    "comment" => $r['comment'],
                    "time" => fmt_time($r['created_at'])
                ];
            }
        }

        // build result JSON same shape as old comments.json
        $out = [];
        foreach ($comments as $c) {
            $out[] = [
                "uuid" => $c['id'],
                "name" => $c['name'],
                "comment" => $c['comment'],
                "time" => fmt_time($c['created_at']),
                "own" => $c['own'],
                "presence" => intval($c['presence']),
                "total_guest" => intval($c['total_guest']),
                "replies" => $replies[$c['id']] ?? []
            ];
        }

        echo json_encode([
            "data" => $out,
            "total" => $total,
            "page" => $page,
            "per_page" => $per_page
        ], JSON_PRETTY_PRINT);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "DB select error", "error" => $e->getMessage()]);
        exit;
    }
}

// fallback
http_response_code(405);
echo json_encode(["success" => false, "message" => "Method not allowed"]);
exit;
