<?php
session_start();
include("../../../keys.php");
include_once("../../../session_helpers.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
if ($roomCode === '' || $playerId === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$member = mysqli_query($link, "select 1 from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
if (!$member || mysqli_num_rows($member) === 0) {
    mysqli_close($link);
    uno_forbidden();
}

$res = mysqli_query($link, "select playerTurn, isEnded from room where roomCode='".$safeRoomCode."' limit 1");
$list = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
mysqli_close($link);

$winnerId = $list["playerTurn"] ?? '';
$isEnded = $list && (int) $list["isEnded"] === 1;
$isTurn = $list && $winnerId === $playerId;
$resultPath = $isTurn ? "you_won.php" : "you_lost.php";

if ($isEnded) {
    uno_set_result_context($roomCode, $playerId);
}

header('Content-Type: application/json');
echo json_encode([
    "ended" => $isEnded,
    "turn" => $isTurn,
    "result_url" => $resultPath,
]);
?>
