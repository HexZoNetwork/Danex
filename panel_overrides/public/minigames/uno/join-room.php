<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Join Room</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/join-room.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
    <script src="assets/js/check-fields.js" type="text/javascript"></script>
</head>
<body class="du-lobby">
    <main class="du-panel du-form-panel">
        <a class="du-back-link" href="choose.php">← Choose Table</a>
        <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-panel-logo">
        <p class="du-kicker">Join table</p>
        <h1>Enter the room code.</h1>
        <p class="du-lead">Ask your friend for the room code, then pick a name for the match.</p>
        <form action="core/fetch-room.php" method="get" onsubmit="return checkJoin();" class="du-form-card">
            <label for="roomnum">Room code</label>
            <input type="text" id="roomnum" name="roomnum" placeholder="Example: 4026r" autocomplete="off">
            <label for="player-name">Player name</label>
            <input type="text" id="player-name" name="player-name" placeholder="Your display name" autocomplete="nickname">
            <button type="submit" class="du-primary-button">Join Match</button>
        </form>
    </main>
</body>
</html>
