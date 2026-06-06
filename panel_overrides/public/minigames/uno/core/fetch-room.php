<?php
session_start();
include_once("../entities/room.php");
include_once("../entities/player.php");
include_once("../session_helpers.php");

function checkIntegrity($x) {
    include("../keys.php");
    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
    $safeId = mysqli_real_escape_string($link, $x);
    $sql = "select * from player where id='".$safeId."'";
    $res = mysqli_query($link, $sql);
    $num = mysqli_num_rows($res);
    mysqli_close($link);
    return $num == 0 ? 1 : 0;
}

function roomRow($roomCode) {
    include("../keys.php");
    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
    $safeRoom = mysqli_real_escape_string($link, $roomCode);
    $sql = "select * from room where roomCode='".$safeRoom."'";
    $res = mysqli_query($link, $sql);
    $row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
    mysqli_close($link);
    return $row;
}

$roomCode = $_GET["roomnum"] ?? '';
$playerName = $_GET["player-name"] ?? '';
$room = roomRow($roomCode);

if (!$room) {
    echo "room not found 404";
    exit();
}
if ($room["isStarted"] == '1') {
    echo "Room is started!";
    exit();
}

$x = ((int) $room["numberOfPlayersRemaining"]) - 1;
if ($x < 0) {
    echo "Room is full!";
    exit();
}

include("../keys.php");
$link = mysqli_connect($serverIp, $username, $pass, $dbName);
$safeRoom = mysqli_real_escape_string($link, $roomCode);
$sql = "update room set numberOfPlayersRemaining='".$x."' where roomCode='".$safeRoom."'";
mysqli_query($link, $sql);
mysqli_close($link);

do {
    $playerId = rand(1000, 9999);
    $playerId .= "p";
} while (checkIntegrity($playerId) == 0);

$player = new Player($playerName, $playerId, $roomCode);
$player->addPlayerToDB();
$token = uno_register_player_session($roomCode, $player->getId(), $player->getName(), false);
header("Location: ../queue-page.php?".uno_player_auth_query($roomCode, $playerId));
?>
