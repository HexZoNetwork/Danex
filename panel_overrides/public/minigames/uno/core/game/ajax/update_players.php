<?php
session_start();
include_once("../../../keys.php");
include_once("../../../session_helpers.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
if ($roomCode === '' || $playerId === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

function sort_for_dir($list, $playerId) {
    $ap = array();
    for ($i = 0; $i < count($list); $i++) {
        if ($playerId == $list[$i]["id"]) {
            array_push($ap, $list[$i]);
        }
    }
    for ($i = 0; $i < count($list); $i++) {
        for ($j = 0; $j < count($list); $j++) {
            if (isset($ap[$i]) && $ap[$i]["nextPlayer"] == $list[$j]["id"]) {
                if (isset($ap[0]) && $ap[0]["id"] != $list[$j]["id"]) {
                    array_push($ap, $list[$j]);
                }
            }
        }
    }
    return $ap;
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$member = mysqli_query($link, "select 1 from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
if (!$member || mysqli_num_rows($member) === 0) {
    mysqli_close($link);
    uno_forbidden();
}
$sql = "select id, name, numCards, nextPlayer from player where roomCode='".$safeRoomCode."'";
$res = mysqli_query($link, $sql);
$list = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
$r = mysqli_query($link, "select playerTurn from room where roomCode='".$safeRoomCode."'");
$room = $r ? mysqli_fetch_array($r, MYSQLI_ASSOC) : null;
mysqli_close($link);

$turnPlayer = $room["playerTurn"] ?? '';
$ap = sort_for_dir($list, $playerId);
$players = [];
foreach ($ap as $row) {
    if ($row["id"] != $playerId) {
        $players[] = [
            "id" => (string) $row["id"],
            "name" => (string) $row["name"],
            "cards" => (int) $row["numCards"],
            "active" => $row["id"] == $turnPlayer,
        ];
    }
}

$turn = ($room && $room["playerTurn"] == $playerId) ? "1" : "0";
header('Content-Type: application/json');
echo json_encode([["players" => $players, "turn" => $turn]]);
?>
