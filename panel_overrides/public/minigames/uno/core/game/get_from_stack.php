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
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);

$res = mysqli_query($link, "select stackUsed from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
$playerState = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$playerState) {
    mysqli_close($link);
    uno_forbidden();
}

$res = mysqli_query($link, "select * from room where roomCode='".$safeRoomCode."' and playerTurn='".$safePlayerId."' and isEnded=0 limit 1");
$turnRoomRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$turnRoomRow) {
    mysqli_close($link);
    uno_forbidden();
}

if ((int) $playerState["stackUsed"] === 0) {
    mysqli_query($link, "update player set stackUsed=1 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");

    $res = mysqli_query($link, "select * from stack where stack_id='".$safeRoomCode."' limit 1");
    $stackRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
    if ($stackRow) {
        $d = (int) $stackRow["numberOfCardsRemaining"];
        $g = (int) $stackRow["nextCardNumber"];

        mysqli_query($link, "update card set id='".$safePlayerId."' where order_in_stack=".$g." and stack_id='".$safeRoomCode."'");
        $d--;
        $g++;
        mysqli_query($link, "update stack set numberOfCardsRemaining=".$d.", nextCardNumber=".$g." where stack_id='".$safeRoomCode."'");
    }

    $res = mysqli_query($link, "select * from room where roomCode='".$safeRoomCode."' limit 1");
    $roomRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;

    $res = mysqli_query($link, "select * from card where stack_id='".$safeRoomCode."' and id='".$safePlayerId."'");
    $cards = $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];

    $found = false;
    if ($roomRow) {
        foreach ($cards as $cardRow) {
            $content = (string) $cardRow["content"];
            if ($content === "+4" || $content === "wc") {
                $found = true;
            } elseif ($roomRow["cardOnTable"] === "+4" || $roomRow["cardOnTable"] === "wc") {
                if ($roomRow["color"] === $content[strlen($content) - 1] || $roomRow["color"] === null) {
                    $found = true;
                }
            } elseif ($roomRow["color"] === $content[strlen($content) - 1]) {
                $found = true;
            } else {
                $buff = "";
                for ($i = 0; $i < strlen($content) && $content[$i] !== "-"; $i++) {
                    $buff .= $content[$i];
                }
                $tableCard = (string) $roomRow["cardOnTable"];
                $buff2 = "";
                for ($i = 0; $i < strlen($tableCard) && $tableCard[$i] !== "-"; $i++) {
                    $buff2 .= $tableCard[$i];
                }
                if ($buff === $buff2) {
                    $found = true;
                }
            }
        }
    }

    if ($found === false && $roomRow) {
        $res = mysqli_query($link, "select * from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
        $playerRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
        if ($playerRow) {
            $nextTurn = ((int) $roomRow["direction"] === 1) ? $playerRow["nextPlayer"] : $playerRow["previousPlayer"];
            $safeNextTurn = mysqli_real_escape_string($link, $nextTurn);
            mysqli_query($link, "update room set playerTurn='".$safeNextTurn."' where roomCode='".$safeRoomCode."'");
            mysqli_query($link, "update player set stackUsed=0 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");
        }
    }
}

mysqli_close($link);
header("Location: ../../game-play.php?".uno_player_auth_query($roomCode, $playerId));
?>
