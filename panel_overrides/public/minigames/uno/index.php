<!DOCTYPE html>
<html lang="en">
<head>
    <title>Danex UNO</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="assets/css/danex-uno.v7.css">
</head>
<body class="du-home">
<script>
function manageSound() {
    var s = document.getElementById('song');
    var d = document.getElementById('sound-toggle');
    if (!s) return;
    if (s.paused === false) {
        s.pause();
        if (d) d.setAttribute('aria-pressed', 'false');
    } else {
        s.play().catch(function () {});
        if (d) d.setAttribute('aria-pressed', 'true');
    }
}
</script>
<audio id="song" preload="none" loop>
    <source src="assets/res/jazz.mp3" type="audio/mpeg">
</audio>

<div class="du-shell">
    <nav class="du-topbar" aria-label="Danex UNO navigation">
        <a class="du-brand" href="/mini-games" aria-label="Back to Mini Games">
            <span class="du-brand-mark">D</span>
            <span>
                <strong>Danex UNO</strong>
                <small>Mini Games Arcade</small>
            </span>
        </a>
        <div class="du-top-actions">
            <button id="sound-toggle" class="du-icon-button" type="button" onclick="manageSound();" aria-pressed="false" aria-label="Toggle music">♪</button>
            <button class="du-ghost-button" type="button" onclick="location.href='/mini-games';">Mini Games</button>
        </div>
    </nav>

    <main class="du-hero">
        <section class="du-hero-copy" aria-labelledby="du-title">
            <p class="du-kicker">Private browser table</p>
            <h1 id="du-title">UNO, rebuilt for the Danex arcade.</h1>
            <p class="du-lead">Create a room, share the code, and play a fast card match with friends in a polished graphite table.</p>
            <div class="du-hero-actions">
                <button class="du-primary-button" type="button" onclick="location.href='choose.php';">Play Now</button>
                <button class="du-secondary-button" type="button" onclick="location.href='join-room.php';">Join Room</button>
            </div>
            <div class="du-feature-row" aria-label="Game features">
                <span>Room Codes</span>
                <span>2–4 Players</span>
                <span>Browser Match</span>
            </div>
        </section>

        <section class="du-showcase" aria-label="Danex UNO card preview">
            <div class="du-table-card">
                <div class="du-card-stack" aria-hidden="true">
                    <div class="du-mini-card du-card-red">UNO</div>
                    <div class="du-mini-card du-card-gold">+2</div>
                    <div class="du-mini-card du-card-purple">⟳</div>
                </div>
                <img src="assets/res/uno_logo.png" alt="Danex UNO" class="du-hero-logo">
                <div class="du-table-status">
                    <span class="du-live-dot"></span>
                    Ready to host
                </div>
            </div>
        </section>
    </main>

    <footer class="du-footer">
        <span>Danex UNO Arcade</span>
        <span>Graphite table • gold actions • classic card rules</span>
    </footer>
</div>
</body>
</html>
