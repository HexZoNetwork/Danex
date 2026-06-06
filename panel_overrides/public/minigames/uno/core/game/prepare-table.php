<?php
session_start();
include_once("../../session_helpers.php");
include_once("../../entities/game/shuffler.php");
include_once("../../entities/game/stack.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
if ($roomCode === '' || $playerId === '' || !uno_host_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$shuf = new Shuffler();
$stack = new Stack($roomCode, 108, $roomCode, 1);
$shuf->createStack($stack);
$shuf->shuffleCards($stack);
$shuf->organizeCards($roomCode);
$shuf->setCardOnTable($roomCode);
$shuf->setPlayDirection($roomCode);
$shuf->pickTheFirstTurn($roomCode);
header("Location: ../../game-play.php?".uno_player_auth_query($roomCode, $playerId));
?>
