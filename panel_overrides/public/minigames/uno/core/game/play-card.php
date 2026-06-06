<?php
session_start();
include_once("../../keys.php");
include_once("../../session_helpers.php");
include_once("../../entities/game/card-handler.php");
include_once("../../entities/game/action-card.php");

$roomCode = $_GET["room-code"] ?? '';
$playerId = $_GET["player-id"] ?? '';
$cardContent = $_GET["card-content"] ?? '';
$color = $_GET["color"] ?? '';

if ($roomCode === '' || $playerId === '' || $cardContent === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

if (($color === '') && ($cardContent === "+4" || $cardContent === "wc")) {
    http_response_code(400);
    exit("Bad Request");
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$safeCardContent = mysqli_real_escape_string($link, $cardContent);

$res = mysqli_query($link, "select * from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
$playerRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$playerRow) {
    mysqli_close($link);
    http_response_code(400);
    exit("Bad Request");
}

$res = mysqli_query($link, "select * from card where id='".$safePlayerId."' and stack_id='".$safeRoomCode."' and content='".$safeCardContent."' limit 1");
$cardRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$cardRow) {
    mysqli_close($link);
    http_response_code(400);
    exit("Bad Request");
}

$res = mysqli_query($link, "select 1 from room where roomCode='".$safeRoomCode."' and playerTurn='".$safePlayerId."' and isEnded=0 limit 1");
$turnRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$turnRow) {
    mysqli_close($link);
    uno_forbidden();
}
mysqli_close($link);

if ($color !== '') {
    $ch = new CardHandler($roomCode, $playerId, $cardContent, $cardRow["number"]);
    $ch->setColor($color);
} else {
    $ch = new CardHandler($roomCode, $playerId, $cardContent, $cardRow["number"]);
}

if ($ch->isCompatible()) {
    $ch->updateCardOnTable();
    $ch->managePlayerCards();
    if ($ch->isActionCard() == false) {
        $ch->passTurn();
    } else {
        if ($color !== '') {
            $ac = new ActionCard($roomCode, $playerId, $cardContent, $cardRow["number"]);
            $ac->setColor($color);
        } else {
            $ac = new ActionCard($roomCode, $playerId, $cardContent, $cardRow["number"]);
        }
        $ac->applyActionCard();
    }

    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
    if (!$link) {
        http_response_code(500);
        exit('Database connection failed.');
    }
    $safeRoomCode = mysqli_real_escape_string($link, $roomCode);
    $safePlayerId = mysqli_real_escape_string($link, $playerId);

    mysqli_query($link, "update player set stackUsed=0 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");

    $res = mysqli_query($link, "select * from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
    $playerRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
    if (!$playerRow) {
        mysqli_close($link);
        uno_forbidden();
    }

    $newCardCount = max(0, ((int) $playerRow["numCards"]) - 1);
    mysqli_query($link, "update player set numCards='".$newCardCount."' where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");

    $result = mysqli_query($link, "select count(*) as cout from card where id='".$safePlayerId."' and stack_id='".$safeRoomCode."'");
    $list = $result ? mysqli_fetch_array($result, MYSQLI_ASSOC) : null;
    $remainingCards = $list ? (int) $list['cout'] : 0;

    if ($remainingCards >= 2) {
        mysqli_query($link, "update player set unoPressed=0 where id='".$safePlayerId."' and roomCode='".$safeRoomCode."'");
    }

    if ($remainingCards == 1) {
        $res = mysqli_query($link, "select unoPressed from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
        $unoRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
        if ($unoRow && (int) $unoRow["unoPressed"] === 0) {
            $res = mysqli_query($link, "select * from stack where stack_id='".$safeRoomCode."' limit 1");
            $stackRow = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
            if ($stackRow) {
                $d = (int) $stackRow["numberOfCardsRemaining"];
                $g = (int) $stackRow["nextCardNumber"];
                for ($i = 0; $i < 2; $i++) {
                    mysqli_query($link, "update card set id='".$safePlayerId."' where order_in_stack=".$g." and stack_id='".$safeRoomCode."'");
                    $d--;
                    $g++;
                    mysqli_query($link, "update stack set numberOfCardsRemaining=".$d.", nextCardNumber=".$g." where stack_id='".$safeRoomCode."'");
                }
            }
        }
    }

    if ($remainingCards == 0) {
        mysqli_query($link, "update room set isEnded=1, playerTurn='".$safePlayerId."' where roomCode='".$safeRoomCode."'");
        mysqli_close($link);
        header("Location: ../../you_won.php?".uno_player_auth_query($roomCode, $playerId));
        exit();
    }

    mysqli_close($link);
    header("Location: ../../game-play.php?".uno_player_auth_query($roomCode, $playerId));
    exit();
}

http_response_code(400);
echo "Wrong card played. Please go back to the match and choose a compatible card.";
?>
