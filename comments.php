<?php
// Izinkan akses dari semua origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Untuk preflight request OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header("Content-Type: application/json");

// Lokasi file untuk simpan komentar
$file = "comments.json";

// Kalau file belum ada, buat default JSON
if (!file_exists($file)) {
    file_put_contents($file, json_encode([
        "data" => [],
        "total" => 0,
        "page" => 1,
        "per_page" => 10
    ], JSON_PRETTY_PRINT));
}

// Baca isi file
$json = file_get_contents($file);
$data = json_decode($json, true);

// Kalau gagal decode (file rusak/empty), reset
if ($data === null || !isset($data['data']) || !is_array($data['data'])) {
    $data = [
        "data" => [],
        "total" => 0,
        "page" => 1,
        "per_page" => 10
    ];
}

// 🔹 Support JSON body selain form-urlencoded
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
    $inputJSON = file_get_contents('php://input');
    $input = json_decode($inputJSON, true);
    if ($input) {
        $_POST = $input;
    }
}

// 🔹 Simpan komentar baru / balasan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name'] ?? 'Anonim');
    $presence    = intval($_POST['presence'] ?? 0);
    $total_guest = intval($_POST['total_guest'] ?? 0);
    $comment     = trim($_POST['comment'] ?? '');
    $parentUuid  = $_POST['parent_uuid'] ?? null;

    if ($comment !== '') {
        $newComment = [
            "uuid"    => uniqid(),
            "name"    => htmlspecialchars($name, ENT_QUOTES),
            "comment" => htmlspecialchars($comment, ENT_QUOTES),
            "time"    => date("Y-m-d H:i:s")
        ];

        if ($parentUuid) {
            // balasan komentar
            foreach ($data['data'] as &$c) {
                if ($c['uuid'] === $parentUuid) {
                    if (!isset($c['replies'])) $c['replies'] = [];
                    $c['replies'][] = $newComment;
                }
            }
        } else {
            // komentar utama
            $newComment["own"]         = uniqid();
            $newComment["presence"]    = $presence;
            $newComment["total_guest"] = $total_guest;
            array_unshift($data['data'], $newComment);
        }

        $data['total'] = count($data['data']);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));

        echo json_encode([
            "success" => true,
            "data"    => $newComment
        ]);
        exit;
    }
}

// 🔹 Hapus semua komentar
if (isset($_GET['action']) && $_GET['action'] === 'deleteAll') {
    $data = [
        "data" => [],
        "total" => 0,
        "page" => 1,
        "per_page" => 10
    ];
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    echo json_encode(["success" => true, "message" => "Semua komentar dihapus"]);
    exit;
}

// 🔹 Hapus komentar tertentu
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['uuid'])) {
    $uuid = $_GET['uuid'];
    if (isset($data['data']) && is_array($data['data'])) {
        $data['data'] = array_values(array_filter($data['data'], function($c) use ($uuid) {
            return $c['uuid'] !== $uuid;
        }));
        $data['total'] = count($data['data']);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        echo json_encode(["success" => true, "message" => "Komentar dihapus"]);
        exit;
    }
    echo json_encode(["success" => false, "message" => "Komentar tidak ditemukan"]);
    exit;
}




// 🔹 Default → balikin semua komentar
echo json_encode($data, JSON_PRETTY_PRINT);
