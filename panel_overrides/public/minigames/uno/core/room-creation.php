<?php
session_start();
include("../entities/room.php");
include_once("../entities/player.php");
include_once("../session_helpers.php");

function checkPlayerIdIntegrity($x) {
    include("../keys.php");
    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
    $safeId = mysqli_real_escape_string($link, $x);
    $sql = "select * from player where id='".$safeId."'";
    $res = mysqli_query($link, $sql);
    $num = mysqli_num_rows($res);
    mysqli_close($link);
    return $num === 0 ? 1 : 0;
}

function checkRoomIdIntegrity($x) {
    include("../keys.php");
    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
    $safeRoom = mysqli_real_escape_string($link, $x);
    $sql = "select * from room where roomCode='".$safeRoom."'";
    $res = mysqli_query($link, $sql);
    $num = mysqli_num_rows($res);
    mysqli_close($link);
    return $num === 0 ? 1 : 0;
}

function uno_short_code($suffix) {
    $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
    $code = '';
    for ($i = 0; $i < 4; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code.$suffix;
}

do {
    $playerId = uno_short_code('p');
} while (checkPlayerIdIntegrity($playerId) == 0);

do {
    $roomCode = uno_short_code('r');
} while (checkRoomIdIntegrity($roomCode) == 0);

$initialPlayer = new Player($_GET["player-name"], $playerId, $roomCode);
$room = new Room($roomCode, $initialPlayer, "-");
$room->addRoomToDB();
$initialPlayer->addPlayerToDB();
uno_register_player_session($room->getRoomCode(), $initialPlayer->getId(), $initialPlayer->getName(), true);
header("Location: ../create-room.php?".uno_player_auth_query($room->getRoomCode(), $initialPlayer->getId())."&player-name=".rawurlencode($initialPlayer->getName()));
?>
