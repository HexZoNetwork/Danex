<?php
session_start();
include_once("../../keys.php");
include_once("../../session_helpers.php");

$roomCode = uno_resolve_spectator_room();
if ($roomCode === '' || !uno_spectator_session_allowed($roomCode)) {
    uno_forbidden();
}

function uno_spectate_color_label($color) {
    switch ($color) {
        case 'r': return 'Red';
        case 'g': return 'Green';
        case 'y': return 'Yellow';
        case 'b': return 'Blue';
        default: return '';
    }
}

$link = mysqli_connect($serverIp, $username, $pass, $dbName);
if (!$link) {
    http_response_code(500);
    exit('Database connection failed.');
}
mysqli_set_charset($link, 'utf8mb4');

$stmt = $link->prepare("select roomCode, cardOnTable, color, playerTurn, isStarted, isEnded from room where roomCode=? limit 1");
$stmt->bind_param('s', $roomCode);
$stmt->execute();
$room = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$room) {
    mysqli_close($link);
    uno_forbidden();
}

$players = [];
$stmt = $link->prepare("select id, name, numCards, avatar_url from player where roomCode=? order by is_host desc, name asc, id asc");
$stmt->bind_param('s', $roomCode);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $players[] = [
        'id' => (string) $row['id'],
        'name' => (string) $row['name'],
        'avatar_url' => (string) ($row['avatar_url'] ?? ''),
        'cards' => (int) $row['numCards'],
        'active' => $row['id'] === $room['playerTurn'],
    ];
}
$stmt->close();
mysqli_close($link);

header('Content-Type: application/json');
echo json_encode([
    'room_code' => (string) $room['roomCode'],
    'card_on_table' => (string) ($room['cardOnTable'] ?? '-'),
    'color' => uno_spectate_color_label($room['color'] ?? ''),
    'turn_player_id' => (string) ($room['playerTurn'] ?? ''),
    'started' => (int) $room['isStarted'] === 1,
    'ended' => (int) $room['isEnded'] === 1,
    'players' => $players,
]);
?>
