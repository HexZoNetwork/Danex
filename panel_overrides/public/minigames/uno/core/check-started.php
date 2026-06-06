<?php
session_start();
include_once("../keys.php");
include_once("../session_helpers.php");

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
$sql = "select * from room where roomCode='".$safeRoomCode."'";
$res = mysqli_query($link, $sql);
$list = mysqli_fetch_array($res, MYSQLI_ASSOC);
mysqli_close($link);

$x = "2";
if ($list && $list["isStarted"] == 1) {
    $x = "1";
}
$return_arr = array();
$return_arr[] = array("start" => $x);
echo json_encode($return_arr);
?>
