<?php
session_start();
include_once("../keys.php");
include_once("../session_helpers.php");

$token = $_COOKIE["danex_uno_launch"] ?? '';
if ($token === '' || !preg_match('/^[A-Za-z0-9_-]{32,}$/', $token)) {
    uno_forbidden();
}

setcookie('danex_uno_launch', '', [
    'expires' => time() - 3600,
    'path' => '/minigames/uno/core',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}
mysqli_set_charset($link, 'utf8mb4');

$hash = hash('sha256', $token);
$stmt = $link->prepare("select token_hash, roomCode, player_id, user_id, username, avatar_url, role, expires_at, used_at from uno_launch_tokens where token_hash=? limit 1");
$stmt->bind_param('s', $hash);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row || (int) $row['expires_at'] < time() || $row['used_at'] !== null) {
    mysqli_close($link);
    uno_forbidden();
}

$usedAt = time();
$stmt = $link->prepare("update uno_launch_tokens set used_at=? where token_hash=? and used_at is null");
$stmt->bind_param('is', $usedAt, $hash);
$stmt->execute();
$claimed = $stmt->affected_rows === 1;
$stmt->close();
if (!$claimed) {
    mysqli_close($link);
    uno_forbidden();
}

$roomCode = (string) $row['roomCode'];
$playerId = (string) ($row['player_id'] ?? '');
$username = (string) $row['username'];
$avatarUrl = (string) ($row['avatar_url'] ?? '');
$role = (string) $row['role'];

uno_set_profile_session((int) $row['user_id'], $username, $avatarUrl);
if ($role === 'spectator') {
    uno_register_spectator_session($roomCode, (int) $row['user_id'], $username, $avatarUrl);
    uno_set_active_spectator_context($roomCode);
    mysqli_close($link);
    header('Location: ../spectate.php');
    exit();
}

if ($playerId === '') {
    mysqli_close($link);
    uno_forbidden();
}

uno_register_player_session($roomCode, $playerId, $username, $role === 'host', $avatarUrl);
uno_set_active_player_context($roomCode, $playerId, $role === 'host' ? 'host' : 'player');
mysqli_close($link);

$target = $role === 'host' ? '../create-room.php' : '../queue-page.php';
header('Location: '.$target);
exit();
?>
