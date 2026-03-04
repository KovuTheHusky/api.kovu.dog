<?php
ini_set("display_errors", 1);
ini_set("display_startup_errors", 1);
error_reporting(E_ALL);
// api.php

// 1. Setup CORS (Adjust the origin to your actual frontend domain in production)
// header("Access-Control-Allow-Origin: https://kovu.dog");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

// Handle preflight OPTIONS request
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

// 2. Database Connection (Creates the file if it doesn't exist)
$dbFile = __DIR__ . "/nyan.sqlite";
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// 3. Initialize Tables
$pdo->exec("
    CREATE TABLE IF NOT EXISTS statistics (
        id INTEGER PRIMARY KEY,
        best_time REAL DEFAULT 0,
        total_time REAL DEFAULT 0
    );
    CREATE TABLE IF NOT EXISTS users (
        username TEXT PRIMARY KEY,
        best_time REAL DEFAULT 0,
        total_time REAL DEFAULT 0
    );
");

// Ensure statistics has exactly one row
$stmt = $pdo->query("SELECT COUNT(*) FROM statistics");
if ($stmt->fetchColumn() == 0) {
    $pdo->exec(
        "INSERT INTO statistics (id, best_time, total_time) VALUES (1, 0, 0)",
    );
}

// 4. Handle Requests
$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    if (isset($_GET["dump"])) {
        $stats = $pdo
            ->query("SELECT * FROM statistics")
            ->fetchAll(PDO::FETCH_ASSOC);
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);

        // JSON_PRETTY_PRINT makes it easily readable in the browser
        echo json_encode(
            [
                "statistics" => $stats,
                "users" => $users,
            ],
            JSON_PRETTY_PRINT,
        );
        exit();
    }

    $username = isset($_GET["username"]) ? trim($_GET["username"]) : "";

    // Get Global
    $globalStmt = $pdo->query(
        "SELECT best_time, total_time FROM statistics WHERE id = 1",
    );
    $global = $globalStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        "global_best" => (float) $global["best_time"],
        "global_total" => (float) $global["total_time"],
        "user_best" => 0,
        "user_total" => 0,
    ];

    // Get User (if provided)
    if ($username !== "") {
        $userStmt = $pdo->prepare(
            "SELECT best_time, total_time FROM users WHERE username = :username",
        );
        $userStmt->execute([":username" => $username]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $response["user_best"] = (float) $user["best_time"];
            $response["user_total"] = (float) $user["total_time"];
        }
    }

    echo json_encode($response);
    exit();
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    $username = isset($input["username"]) ? trim($input["username"]) : "";
    $sessionBest = isset($input["best"]) ? (float) $input["best"] : 0;
    $addedTime = isset($input["addedTime"]) ? (float) $input["addedTime"] : 0;

    if ($addedTime < 0) {
        $addedTime = 0;
    }

    // Update Global Stats (with CAST to prevent the string comparison bug)
    $updateGlobal = $pdo->prepare("
        UPDATE statistics 
        SET best_time = MAX(best_time, CAST(:best AS REAL)), 
            total_time = total_time + CAST(:addedTime AS REAL) 
        WHERE id = 1
    ");
    $updateGlobal->execute([
        ":best" => $sessionBest,
        ":addedTime" => $addedTime,
    ]);

    // Update User Stats (if username is provided)
    if ($username !== "") {
        $updateUser = $pdo->prepare("
            INSERT INTO users (username, best_time, total_time)
            VALUES (:username, CAST(:best AS REAL), CAST(:addedTime AS REAL))
            ON CONFLICT(username) DO UPDATE SET
                best_time = MAX(best_time, CAST(:best AS REAL)),
                total_time = total_time + CAST(:addedTime AS REAL)
        ");
        $updateUser->execute([
            ":username" => $username,
            ":best" => $sessionBest,
            ":addedTime" => $addedTime,
        ]);
    }

    echo json_encode(["success" => true]);
    exit();
}

// Fallback
http_response_code(405);
echo json_encode(["error" => "Method Not Allowed"]);
