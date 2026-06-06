<?php
session_start();
include("../../../keys.php");
include_once("../../../session_helpers.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
if ($roomCode === '' || $playerId === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

function colorLabel($color) {
    switch ($color) {
        case 'r': return "Red";
        case 'g': return "Green";
        case 'y': return "Yellow";
        case 'b': return "Blue";
        default: return "";
    }
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$member = mysqli_query($link, "select 1 from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
if (!$member || mysqli_num_rows($member) === 0) {
    mysqli_close($link);
    uno_forbidden();
}
$res = mysqli_query($link, "select cardOnTable, color from room where roomCode='".$safeRoomCode."'");
$list = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
mysqli_close($link);

$cardOnTable = $list['cardOnTable'] ?? '-';
header('Content-Type: application/json');
echo json_encode([["cardOnTable" => $cardOnTable, "colorInd" => colorLabel($list['color'] ?? '')]]);
?>
