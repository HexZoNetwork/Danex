<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Create Room</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.css">
    <link rel="stylesheet" type="text/css" href="assets/css/join-room.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
</head>
<body class="du-lobby">
    <main class="du-panel du-form-panel">
        <a class="du-back-link" href="choose.php">← Choose Table</a>
        <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-panel-logo">
        <p class="du-kicker">Create table</p>
        <h1>Pick your player name.</h1>
        <p class="du-lead">This name is shown at the table while your friends join the room.</p>
        <form action="core/room-creation.php" method="get" class="du-form-card">
            <label for="roomnum">Player name</label>
            <input type="text" name="player-name" id="roomnum" placeholder="Your display name" autocomplete="nickname">
            <button type="submit" class="du-primary-button">Create Room</button>
        </form>
    </main>
</body>
</html>
