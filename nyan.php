<?php
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

$dbFile = __DIR__ . "/nyan.sqlite";
$pdo = new PDO("sqlite:" . $dbFile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

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

$cols = $pdo
    ->query("PRAGMA table_info(statistics)")
    ->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, "name");
if (!in_array("wiggles", $colNames)) {
    $pdo->exec("ALTER TABLE statistics ADD COLUMN wiggles INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE statistics ADD COLUMN spins INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE users ADD COLUMN wiggles INTEGER DEFAULT 0");
    $pdo->exec("ALTER TABLE users ADD COLUMN spins INTEGER DEFAULT 0");
}

$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_column($cols, "name");
if (!in_array("last_seen", $colNames)) {
    $pdo->exec("ALTER TABLE users ADD COLUMN last_seen INTEGER DEFAULT 0");
}

$stmt = $pdo->query("SELECT COUNT(*) FROM statistics");
if ($stmt->fetchColumn() == 0) {
    $pdo->exec(
        "INSERT INTO statistics (id, best_time, total_time, wiggles, spins) VALUES (1, 0, 0, 0, 0)",
    );
}

$method = $_SERVER["REQUEST_METHOD"];

if ($method === "GET") {
    if (isset($_GET["dump"])) {
        $stats = $pdo
            ->query("SELECT * FROM statistics")
            ->fetchAll(PDO::FETCH_ASSOC);
        $users = $pdo->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(
            [
                "server_time" => time(),
                "statistics" => $stats,
                "users" => $users,
            ],
            JSON_PRETTY_PRINT,
        );
        exit();
    }

    $username = isset($_GET["username"])
        ? strtolower(trim($_GET["username"]))
        : "";

    $globalStmt = $pdo->query(
        "SELECT best_time, total_time, wiggles, spins FROM statistics WHERE id = 1",
    );
    $global = $globalStmt->fetch(PDO::FETCH_ASSOC);

    $response = [
        "global_best" => (float) $global["best_time"],
        "global_total" => (float) $global["total_time"],
        "global_wiggles" => (int) $global["wiggles"],
        "global_spins" => (int) $global["spins"],
        "user_best" => 0,
        "user_total" => 0,
        "user_wiggles" => 0,
        "user_spins" => 0,
    ];

    if ($username !== "") {
        $userStmt = $pdo->prepare(
            "SELECT best_time, total_time, wiggles, spins FROM users WHERE username = :username",
        );
        $userStmt->execute([":username" => $username]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $response["user_best"] = (float) $user["best_time"];
            $response["user_total"] = (float) $user["total_time"];
            $response["user_wiggles"] = (int) $user["wiggles"];
            $response["user_spins"] = (int) $user["spins"];

            $now = time();
            $seenStmt = $pdo->prepare(
                "UPDATE users SET last_seen = :now WHERE username = :username",
            );
            $seenStmt->execute([":now" => $now, ":username" => $username]);
        }
    }

    echo json_encode($response);
    exit();
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);

    $username = isset($input["username"])
        ? strtolower(trim($input["username"]))
        : "";
    $sessionBest = isset($input["best"]) ? (float) $input["best"] : 0;

    $addedTime = isset($input["addedTime"]) ? (float) $input["addedTime"] : 0;
    $addedWiggles = isset($input["addedWiggles"])
        ? (int) $input["addedWiggles"]
        : 0;
    $addedSpins = isset($input["addedSpins"]) ? (int) $input["addedSpins"] : 0;

    if ($addedTime < 0) {
        $addedTime = 0;
    }
    if ($addedWiggles < 0) {
        $addedWiggles = 0;
    }
    if ($addedSpins < 0) {
        $addedSpins = 0;
    }

    $updateGlobal = $pdo->prepare("
        UPDATE statistics 
        SET best_time = MAX(best_time, CAST(:best AS REAL)), 
            total_time = MAX(total_time + CAST(:addedTime AS REAL), best_time, CAST(:best AS REAL)),
            wiggles = wiggles + :addedWiggles,
            spins = spins + :addedSpins
        WHERE id = 1
    ");
    $updateGlobal->execute([
        ":best" => $sessionBest,
        ":addedTime" => $addedTime,
        ":addedWiggles" => $addedWiggles,
        ":addedSpins" => $addedSpins,
    ]);

    if ($username !== "") {
        $now = time();

        $updateUser = $pdo->prepare("
            INSERT INTO users (username, best_time, total_time, wiggles, spins, last_seen)
            VALUES (:username, CAST(:best AS REAL), MAX(CAST(:addedTime AS REAL), CAST(:best AS REAL)), :addedWiggles, :addedSpins, :now)
            ON CONFLICT(username) DO UPDATE SET
                best_time = MAX(best_time, CAST(:best AS REAL)),
                total_time = MAX(total_time + CAST(:addedTime AS REAL), best_time, CAST(:best AS REAL)),
                wiggles = wiggles + :addedWiggles,
                spins = spins + :addedSpins,
                last_seen = :now
        ");
        $updateUser->execute([
            ":username" => $username,
            ":best" => $sessionBest,
            ":addedTime" => $addedTime,
            ":addedWiggles" => $addedWiggles,
            ":addedSpins" => $addedSpins,
            ":now" => $now,
        ]);
    }

    echo json_encode(["success" => true]);
    exit();
}

http_response_code(405);
echo json_encode(["error" => "Method Not Allowed"]);
