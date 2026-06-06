<?php
session_start();
include_once("../keys.php");
include_once("../session_helpers.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
$playerName = $_GET["player-name"] ?? '';

if ($roomCode === '' || $playerId === '' || !uno_host_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$sql = "select r.numberOfPlayersRemaining from room r inner join player p on p.roomCode = r.roomCode where r.roomCode='".$safeRoomCode."' and p.id='".$safePlayerId."' limit 1";
$res = mysqli_query($link, $sql);
$list = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;

if (!$list) {
    mysqli_close($link);
    uno_forbidden();
}

if ($list["numberOfPlayersRemaining"] < 3) {
    $sql = "update room set isStarted='1' where roomCode='".$safeRoomCode."'";
    mysqli_query($link, $sql);
    mysqli_close($link);
    header("Location: game/prepare-table.php?player-name=".rawurlencode($playerName)."&player-id=".rawurlencode($playerId)."&room-code=".rawurlencode($roomCode));
} else {
    mysqli_close($link);
    echo "Not enough players go back and wait for players to join.";
}
?>
