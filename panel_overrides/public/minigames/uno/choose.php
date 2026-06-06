<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Choose Table</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/theme.css">
    <link rel="stylesheet" type="text/css" href="assets/css/choose.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
</head>
<body class="du-lobby">
    <main class="du-panel du-choice-panel">
        <a class="du-back-link" href="index.php">← Danex UNO</a>
        <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-panel-logo">
        <p class="du-kicker">Choose your table</p>
        <h1>Start a match or join friends.</h1>
        <p class="du-lead">Create a private room code, or enter a code shared by another player.</p>
        <form action="core/redirection.php" method="get" class="du-choice-grid">
            <input type="hidden" name="action">
            <button onclick="document.getElementsByName('action')[0].value = 'create';" type="submit" name="create-button" class="du-mode-card du-mode-host">
                <span class="du-mode-icon">✦</span>
                <strong>Create Room</strong>
                <small>Host a fresh Danex table</small>
            </button>
            <button onclick="document.getElementsByName('action')[0].value = 'join';" type="submit" name="join-button" class="du-mode-card du-mode-join">
                <span class="du-mode-icon">⌁</span>
                <strong>Join Room</strong>
                <small>Use your friend’s room code</small>
            </button>
        </form>
    </main>
</body>
</html>
