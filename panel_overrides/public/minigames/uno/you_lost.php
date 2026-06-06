<?php
session_start();
include_once("keys.php");
include_once("session_helpers.php");

$context = uno_get_result_context();
$roomCode = $_GET["room-code"] ?? ($context['roomCode'] ?? '');
$playerId = $_GET["player-id"] ?? ($context['playerId'] ?? '');
$winnerText = 'The table has ended.';

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
$membership = mysqli_query($link, "select 1 from player where id='".$safePlayerId."' and roomCode='".$safeRoomCode."' limit 1");
if (!$membership || mysqli_num_rows($membership) === 0) {
    mysqli_close($link);
    uno_forbidden();
}

$sql = "select playerTurn from room where roomCode='".$safeRoomCode."'";
$res = mysqli_query($link, $sql);
$row = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;

if ($row) {
    $safeWinnerId = mysqli_real_escape_string($link, $row["playerTurn"]);
    $sql = "select name from player where id='".$safeWinnerId."' and roomCode='".$safeRoomCode."' limit 1";
    $res = mysqli_query($link, $sql);
    $row1 = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
    if ($row1) {
        $winnerText = "The winner is ".$row1["name"]." (id: ".$row["playerTurn"].")";
    }
}
mysqli_close($link);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Match Ended</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
</head>
<body class="du-lobby">
    <main class="du-panel">
        <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-panel-logo">
        <p class="du-kicker">Match ended</p>
        <h1><?php echo htmlspecialchars($winnerText, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p class="du-lead">Good game. Return to the arcade and host another table.</p>
        <button type="button" class="du-primary-button" onclick="location.href='index.php';">Back Home</button>
    </main>
</body>
</html>
