<?php
session_start();
include_once("keys.php");
include_once("session_helpers.php");

$roomCode = uno_resolve_spectator_room();
$profile = uno_current_profile();
$spectator = $_SESSION['uno_spectators'][$roomCode] ?? [];
if ($roomCode === '' || !uno_spectator_session_allowed($roomCode)) {
    uno_forbidden();
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}
$safeRoomCode = mysqli_real_escape_string($link, $roomCode);
$res = mysqli_query($link, "select roomCode, isStarted, isEnded from room where roomCode='".$safeRoomCode."' limit 1");
$room = $res ? mysqli_fetch_array($res, MYSQLI_ASSOC) : null;
mysqli_close($link);
if (!$room) {
    uno_forbidden();
}
$spectatorName = $spectator['username'] ?? ($profile['username'] ?? 'Spectator');
$spectatorAvatar = $spectator['avatar_url'] ?? ($profile['avatar_url'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO - Spectator</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/game-play-theme.css">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
    <script type="text/javascript" src="assets/js/ajax_spectate.js?v=1" defer></script>
</head>
<body class="du-game-page du-spectator-page">
    <main class="du-game-shell">
        <header class="du-game-topbar">
            <a class="du-brand" href="/mini-games" aria-label="Back to Mini Games">
                <span class="du-brand-mark">D</span>
                <span>
                    <strong>Danex UNO</strong>
                    <small>Spectating room <span id="roomCode"><?php echo htmlspecialchars($roomCode, ENT_QUOTES, 'UTF-8'); ?></span></small>
                </span>
            </a>
            <div class="du-game-status" aria-live="polite">
                <p id="spectatorState">Watching</p>
                <span id="stat-2">Read-only spectator mode</span>
            </div>
        </header>

        <section class="du-game-grid">
            <aside class="du-opponents-panel" aria-label="Table players">
                <div class="du-board-header">
                    <div>
                        <p class="du-kicker">Spectator</p>
                        <h2><?php echo htmlspecialchars($spectatorName, ENT_QUOTES, 'UTF-8'); ?></h2>
                    </div>
                    <?php if ($spectatorAvatar !== ''): ?>
                        <span class="du-avatar-dot du-avatar-photo" style="background-image:url('<?php echo htmlspecialchars($spectatorAvatar, ENT_QUOTES, 'UTF-8'); ?>')"></span>
                    <?php endif; ?>
                </div>
                <table id="spectatorPlayers" class="du-players-table" aria-label="Players at table"></table>
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
                            <p style="pointer-events: none;" id="cardOnTable"></p>
                        </td>
                    </tr>
                </table>
            </section>
        </section>

        <section class="du-hand-panel" aria-label="Spectator mode notice">
            <div class="du-hand-actions">
                <div>
                    <p class="du-kicker">Read-only</p>
                    <h2>Spectator mode</h2>
                    <p class="du-lead">You can watch the match live, but you cannot draw, pass, press UNO, or play cards.</p>
                </div>
                <div class="du-action-buttons">
                    <button type="button" id="stackBut" onclick="location.href='/mini-games';">Back to Lobby</button>
                </div>
            </div>
        </section>
    </main>

    <footer class="du-footer du-game-footer">
        <span>Danex UNO Arcade</span>
        <span>Spectator mode • profile-authenticated</span>
    </footer>
</body>
</html>
