<?php
session_start();
include_once("keys.php");
include_once("session_helpers.php");

$context = uno_resolve_player_context();
$roomCode = $context['roomCode'];
$playerId = $context['playerId'];
$sessionPlayerId = $playerId;

if ($playerId === '' || $roomCode === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safeSessionPlayerId = mysqli_real_escape_string($link, $sessionPlayerId);

$authResult = mysqli_query($link, "select 1 from player where id='".$safeSessionPlayerId."' and roomCode='".$safeRoomCode."' limit 1");
if (!$authResult || mysqli_num_rows($authResult) === 0) {
    mysqli_close($link);
    http_response_code(403);
    exit('Forbidden');
}

$res = mysqli_query($link, "select isEnded, playerTurn from room where roomCode='".$safeRoomCode."'");
$row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if ($row && $row["isEnded"] == 1) {
    $resultPage = ($row["playerTurn"] == $playerId) ? "you_won.php" : "you_lost.php";
    uno_set_result_context($roomCode, $playerId);
    mysqli_close($link);
    header("Location: ".$resultPage);
    exit();
}

$cardCount = 0;
$unoPressed = 0;
$result = mysqli_query($link, "select count(*) as cout from card where id='".$safeSessionPlayerId."' and stack_id='".$safeRoomCode."'");
$list = $result ? mysqli_fetch_array($result, MYSQLI_ASSOC) : null;
$cardCount = $list ? (int) $list['cout'] : 0;

$result = mysqli_query($link, "select unoPressed from player where roomCode='".$safeRoomCode."' and id='".$safeSessionPlayerId."'");
$row = $result ? mysqli_fetch_array($result, MYSQLI_ASSOC) : null;
$unoPressed = $row ? (int) $row['unoPressed'] : 0;
mysqli_close($link);

include("entities/player.php");
include("entities/room.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Match</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/game-play-theme.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
    <script type="text/javascript" src="assets/js/game-play.js?v=8" defer></script>
    <script type="text/javascript" src="assets/js/ajax_gameplay.js?v=8" defer></script>
</head>
<body class="du-game-page">
    <main class="du-game-shell">
        <header class="du-game-topbar">
            <a class="du-brand" href="index.php" aria-label="Danex UNO home">
                <span class="du-brand-mark">D</span>
                <span>
                    <strong>Danex UNO</strong>
                    <small>Room <?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?></small>
                </span>
            </a>
            <div class="du-game-status" aria-live="polite">
                <p id="stat">YOUR TURN!</p>
                <span id="stat-2">Click a matching card to play</span>
            </div>
        </header>

        <section class="du-game-grid">
            <aside class="du-opponents-panel" aria-label="Opponents">
                <div class="du-board-header">
                    <div>
                        <p class="du-kicker">Table</p>
                        <h2>Players</h2>
                    </div>
                    <input id="turn" type="hidden" value="">
                </div>
                <table id="players">
                    <tr>
                        <td>
                            <h4>Room: <?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?></h4>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <table id="pt" border="3px"></table>
                        </td>
                    </tr>
                </table>
            </aside>

            <section class="du-table-zone" aria-label="Current card on table">
                <div class="du-table-glow"></div>
                <div class="du-table-meta">
                    <span>Current card</span>
                    <strong id="indicator"></strong>
                </div>
                <table id="floor">
                    <tr>
                        <td>
                            <p style="visibility: hidden;" id="carot"></p>
                            <p style="pointer-events: none;" id="cardOnTable"></p>
                        </td>
                    </tr>
                </table>
            </section>
        </section>

        <section class="du-hand-panel" aria-label="Your hand">
            <div class="du-hand-actions">
                <div>
                    <p class="du-kicker">Your hand</p>
                    <h2>You</h2>
                </div>
                <div class="du-action-buttons">
                    <form method="post" action="core/game/get_from_stack.php" onsubmit="return is_turn();">
                        <input id="rc" name="roomCode" type="hidden" value="<?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?>">
                        <input id="pl_id" name="player-id" type="hidden" value="<?php echo htmlspecialchars($sessionPlayerId, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="submit" value="Draw Stack" id="stackBut">
                    </form>
                    <?php
                    $stackUsed = 0;
                    $link = mysqli_connect($serverIp, $username, $pass, $dbName);
                    if ($link) {
                        $safeSessionPlayerId = mysqli_real_escape_string($link, $sessionPlayerId);
                        $res = mysqli_query($link, "select stackUsed from player where id='".$safeSessionPlayerId."' limit 1");
                        $row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
                        $stackUsed = $row ? (int) $row['stackUsed'] : 0;
                        mysqli_close($link);
                    }
                    if ($stackUsed === 1) {
                        echo '<form method="post" action="core/game/passPressed.php">'
                            .'<input name="roomCode" type="hidden" value="'.htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8').'">'
                            .'<input name="player-id" type="hidden" value="'.htmlspecialchars($sessionPlayerId, ENT_QUOTES, 'UTF-8').'">'
                            .'<input type="submit" value="Pass" id="stackBut">'
                            .'</form>';
                    }
                    if ($cardCount === 2) {
                        echo '<button id="unoBut" class="'.($unoPressed === 0 ? 'du-uno-ready' : 'du-uno-pressed').'" onclick="location.href=\'core/game/uno_pressed.php?'.uno_player_auth_query($roomCode, $sessionPlayerId).'\'">UNO!</button>';
                    }
                    ?>
                </div>
            </div>
            <input type="hidden" id="content_card" value="">
            <div class="du-cards-scroll">
                <table id="cards"></table>
            </div>
        </section>
    </main>

    <div id="myModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="pick-color-title">
        <div class="modal-content du-color-modal">
            <div class="modal-header">
                <span class="close" aria-label="Close">&times;</span>
                <h2 id="pick-color-title">Pick a color</h2>
            </div>
            <div class="modal-body">
                <form action="core/game/play-card.php" method="get" class="du-color-grid">
                    <input name="color" id="pickedColor" type="hidden" value="">
                    <input type="hidden" name="room-code" value="<?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="con" name="card-content" value="">
                    <input type="hidden" name="player-id" value="<?php echo htmlspecialchars($sessionPlayerId, ENT_QUOTES, 'UTF-8'); ?>">
                    <button class="du-pick-red" type="submit" onclick="document.getElementById('pickedColor').value = 'r';" aria-label="Pick red"></button>
                    <button class="du-pick-green" type="submit" onclick="document.getElementById('pickedColor').value = 'g';" aria-label="Pick green"></button>
                    <button class="du-pick-blue" type="submit" onclick="document.getElementById('pickedColor').value = 'b';" aria-label="Pick blue"></button>
                    <button class="du-pick-yellow" type="submit" onclick="document.getElementById('pickedColor').value = 'y';" aria-label="Pick yellow"></button>
                </form>
            </div>
        </div>
    </div>

    <footer class="du-footer du-game-footer">
        <span>Danex UNO Arcade</span>
        <span>Version: v0.3.0-alpha1</span>
    </footer>

    <script>
    var modal = document.getElementById('myModal');
    var span = document.getElementsByClassName('close')[0];
    if (span) {
        span.onclick = function() {
            modal.style.display = 'none';
        };
    }
    </script>
</body>
</html>
