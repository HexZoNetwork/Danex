<?php
session_start();
include_once("keys.php");
include_once("session_helpers.php");

$context = uno_get_result_context();
$roomCode = $_GET["room-code"] ?? ($context['roomCode'] ?? '');
$playerId = $_GET["player-id"] ?? ($context['playerId'] ?? '');
$winnerName = 'You';

if ($playerId === '' || $roomCode === '' || !uno_player_session_allowed($roomCode, $playerId)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}

$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$safePlayerId = mysqli_real_escape_string($link, $playerId);
$res = mysqli_query($link, "select name from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
$row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
if (!$row) {
    mysqli_close($link);
    uno_forbidden();
}
$winnerName = $row["name"];
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Winner</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
</head>
<body class="du-lobby">
    <main class="du-panel">
        <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-panel-logo">
        <p class="du-kicker">Victory</p>
        <h1><?php echo htmlspecialchars($winnerName, ENT_QUOTES, 'UTF-8'); ?> won!</h1>
        <p class="du-lead">Clean finish. Start another Danex UNO table when your squad is ready.</p>
        <button type="button" class="du-primary-button" onclick="location.href='index.php';">Back Home</button>
    </main>
</body>
</html>
