<?php
session_start();
include_once("entities/player.php");
include_once("keys.php");
include_once("session_helpers.php");

$context = uno_resolve_player_context('player');
$playerId = $context['playerId'];
$roomCode = $context['roomCode'];
$playerName = 'Player';
$playerRoomCode = $roomCode;

if ($playerId === '' || $roomCode === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safePlayerId = mysqli_real_escape_string($link, $playerId);
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$sql = "select name, roomCode from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1";
$res = mysqli_query($link, $sql);
$list = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$list) {
    mysqli_close($link);
    uno_forbidden();
}
$playerName = $list["name"] ?? $playerName;
$playerRoomCode = $list["roomCode"] ?? $playerRoomCode;
if ($res) {
    mysqli_free_result($res);
}
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Waiting Room</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/queue-page.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
    <script src="assets/js/check-fields.js" type="text/javascript" defer></script>
    <script type="text/javascript" src="assets/js/ajax_createroom_page.js?v=2" defer></script>
    <script type="text/javascript" src="assets/js/ajax_queue_page.js" defer></script>
</head>
<body class="du-lobby du-room-page">
    <input type="hidden" value="<?php echo htmlspecialchars($playerId, ENT_QUOTES, 'UTF-8'); ?>" id="playerI">
    <input type="hidden" value="<?php echo htmlspecialchars($playerRoomCode, ENT_QUOTES, 'UTF-8'); ?>" id="roomC">

    <main class="du-room-shell du-queue-room">
        <section class="du-room-hero">
            <a class="du-back-link" href="choose.php">← Choose Table</a>
            <div id="animated_div"><img src="assets/res/uno_logo.png" class="animated_div" alt="Danex UNO"></div>
            <p class="du-kicker">Waiting for host</p>
            <h1>Welcome, <?php echo htmlspecialchars($playerName, ENT_QUOTES, 'UTF-8'); ?>.</h1>
            <p class="du-lead">You are inside the Danex table. The match opens automatically after the host starts.</p>

            <div class="du-room-stats" aria-label="Player status">
                <div>
                    <span>Room code</span>
                    <strong class="du-room-code"><?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                    <span>Player ID</span>
                    <strong><?php echo htmlspecialchars($playerId, ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
                <div>
                    <span>Status</span>
                    <strong>Queued</strong>
                </div>
            </div>
        </section>

        <section class="du-room-board" aria-label="Waiting room status">
            <div class="du-board-header">
                <div>
                    <p class="du-kicker">Auto start</p>
                    <h2>Hold tight</h2>
                </div>
                <span class="du-live-badge"><i class="du-live-dot"></i> Polling</span>
            </div>

            <table id="players" class="du-players-table" aria-label="Connected players"></table>

            <div class="du-loading-card du-loading-card-large">
                <div class="loading" align="center">
                    <div class="finger finger-1"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-2"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-3"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="finger finger-4"><div class="finger-item"><span></span><i></i></div></div>
                    <div class="last-finger"><div class="last-finger-item"><span></span><i></i></div></div>
                </div>
                <p class="msg">Please wait until the room creator starts the match.</p>
                <small>Keep this tab open. Danex UNO will redirect you when the table is ready.</small>
            </div>
        </section>
    </main>

    <footer class="du-footer du-room-footer">
        <span>Danex UNO Arcade</span>
        <span>Secure private table • mobile ready</span>
    </footer>
</body>
</html>
