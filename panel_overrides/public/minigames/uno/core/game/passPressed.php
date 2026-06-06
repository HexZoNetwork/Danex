<?php
session_start();
include("../../keys.php");
include_once("../../session_helpers.php");

$roomCode = $_POST["roomCode"] ?? '';
$playerId = $_POST["player-id"] ?? '';
if ($roomCode === '' || $playerId === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$res = mysqli_query($link, "select * from room where roomCode='".$safeRoomCode."'");
$row1 = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
$res = mysqli_query($link, "select * from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
$row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$row1 || !$row) {
    mysqli_close($link);
    uno_forbidden();
}
mysqli_query($link, "update player set unoPressed=0 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");
$nextTurn = $row1["direction"] == 1 ? $row["nextPlayer"] : $row["previousPlayer"];
$safeNextTurn = mysqli_real_escape_string($link, $nextTurn);
mysqli_query($link, "update room set playerTurn='".$safeNextTurn."' where roomCode='".$safeRoomCode."'");
mysqli_query($link, "update player set stackUsed=0 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");
mysqli_close($link);

header("Location: ../../game-play.php?".uno_player_auth_query($roomCode, $playerId));
?>
