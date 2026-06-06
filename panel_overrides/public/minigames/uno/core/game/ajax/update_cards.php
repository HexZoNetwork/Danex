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
$res = mysqli_query($link, "select content from card where stack_id='".$safeRoomCode."' and id='".$safePlayerId."'");
$list = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
mysqli_close($link);

$cards = [];
foreach ($list as $row) {
    $cards[] = ["content" => (string) $row["content"]];
}

header('Content-Type: application/json');
echo json_encode(["cards" => $cards]);
?>
