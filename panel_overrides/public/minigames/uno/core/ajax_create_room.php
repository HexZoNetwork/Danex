<?php
session_start();
include_once("../keys.php");
include_once("../session_helpers.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';

if ($playerId === '' || $roomCode === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$stmt = $link->prepare("select 1 from player where id=? and roomCode=? limit 1");
$stmt->bind_param("ss", $playerId, $roomCode);
$stmt->execute();
$auth = $stmt->get_result();
if (!$auth || mysqli_num_rows($auth) === 0) {
    $stmt->close();
    mysqli_close($link);
    uno_forbidden();
}
$stmt->close();

$players = [];
$stmt = $link->prepare("select id, name from player where roomCode=? order by name asc, id asc");
$stmt->bind_param("s", $roomCode);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $players[] = [
        "id" => (string) $row["id"],
        "name" => (string) $row["name"],
        "current" => $row["id"] === $playerId,
    ];
}
$stmt->close();

$remaining = null;
$stmt = $link->prepare("select numberOfPlayersRemaining from room where roomCode=? limit 1");
$stmt->bind_param("s", $roomCode);
$stmt->execute();
$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
if ($row) {
    $remaining = (int) $row["numberOfPlayersRemaining"];
}
$stmt->close();
mysqli_close($link);

header('Content-Type: application/json');
echo json_encode(["players" => $players, "remaining" => $remaining]);
?>
