<?php
include("entities/player.php");
include("entities/room.php");
session_start();
include_once("keys.php");
include_once("session_helpers.php");

$context = uno_resolve_player_context('host');
$roomCode = $context['roomCode'];
$playerId = $context['playerId'];
$playerName = '';
$playersRemaining = '?';

if ($roomCode === '' || $playerId === '' || !uno_host_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$sql = "select r.numberOfPlayersRemaining, p.name from room r inner join player p on p.roomCode = r.roomCode where r.roomCode='".$safeRoomCode."' and p.id='".$safePlayerId."' limit 1";
$result = mysqli_query($link, $sql);
$row = $result ? mysqli_fetch_array($result, MYSQLI_ASSOC) : null;
if (!$row) {
    mysqli_close($link);
    uno_forbidden();
}
$playersRemaining = $row["numberOfPlayersRemaining"];
$playerName = $row["name"] ?? $playerName;
if ($result) {
    mysqli_free_result($result);
}
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Host Room</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/create-room.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
    <script src="assets/js/check-fields.js" type="text/javascript" defer></script>
    <script type="text/javascript" src="assets/js/ajax_createroom_page.js?v=2" defer></script>
</head>
<body class="du-lobby du-room-page">
    <input type="hidden" value="<?php echo htmlspecialchars($playerId, ENT_QUOTES, 'UTF-8'); ?>" id="playerI">
    <input type="hidden" value="<?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?>" id="roomC">

    <main class="du-room-shell du-waiting-room">
        <section class="du-room-hero">
            <a class="du-back-link" href="choose.php">← Choose Table</a>
            <div id="animated_div"><img src="assets/res/uno_logo.png" class="animated_div" alt="Danex UNO"></div>
            <p class="du-kicker">Host private table</p>
            <h1>Room code: <span class="du-room-code"><?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?></span></h1>
            <p class="du-lead">Share this code with friends, wait until everyone joins, then start the match.</p>

            <div class="du-room-stats" aria-label="Room status">
                <div>
                    <span>Players remaining</span>
                    <strong class="Players_remaining"><?php echo htmlspecialchars((string) $playersRemaining, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                    <span>Max table</span>
                    <strong>4</strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong>Waiting</strong>
                </div>
            </div>
        </section>

        <section class="du-room-board" aria-label="Waiting room board">
            <div class="du-board-header">
                <div>
                    <p class="du-kicker">Lobby players</p>
                    <h2>Connected players</h2>
                </div>
                <span class="du-live-badge"><i class="du-live-dot"></i> Live</span>
            </div>

            <table id="players" class="du-players-table"></table>

            <div class="du-loading-card" aria-label="Waiting animation">
                <div class="loading" align="center">
                    <div class="finger finger-1"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-2"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-3"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-4"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="last-finger"><div class="last-finger-item"><span></span><i></i></div></div>
                </div>
                <p class="msg">Waiting for other players to join. Start when the table is ready.</p>
            </div>

            <form action="core/check-players.php" method="get" onsubmit="return checkCreate();" class="du-start-form">
                <input type="hidden" name="room-code" value="<?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" id="player-name" name="player-name" value="<?php echo htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" id="player-id" name="player-id" value="<?php echo htmlspecialchars($playerId, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="submit" value="Start Match" id="welbut">
            </form>
        </section>
    </main>

    <footer class="du-footer du-room-footer">
        <span>Danex UNO Arcade</span>
        <span>Private room • browser match • classic rules</span>
    </footer>
</body>
</html>
