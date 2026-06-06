<?php
function uno_session_started() {
    return session_status() === PHP_SESSION_ACTIVE;
}

function uno_ensure_session() {
    if (!uno_session_started()) {
        session_start();
    }
}

function uno_set_profile_session($userId, $username, $avatarUrl = '') {
    uno_ensure_session();
    $_SESSION['uno_profile'] = [
        'user_id' => (int) $userId,
        'username' => (string) $username,
        'avatar_url' => (string) $avatarUrl,
        'authenticated_at' => time(),
    ];
}

function uno_current_profile() {
    uno_ensure_session();
    $profile = $_SESSION['uno_profile'] ?? [];
    return is_array($profile) ? $profile : [];
}

function uno_register_player_session($roomCode, $playerId, $playerName = '', $isHost = false, $avatarUrl = '') {
    uno_ensure_session();
    if (!isset($_SESSION['uno_players']) || !is_array($_SESSION['uno_players'])) {
        $_SESSION['uno_players'] = [];
    }
    if (!isset($_SESSION['uno_players'][$roomCode]) || !is_array($_SESSION['uno_players'][$roomCode])) {
        $_SESSION['uno_players'][$roomCode] = [];
    }

    $_SESSION['uno_players'][$roomCode][$playerId] = [
        'name' => $playerName,
        'avatar_url' => $avatarUrl,
        'created_at' => time(),
    ];

    $_SESSION['player_id'] = $playerId;
    $_SESSION['name'] = $playerName;

    if ($isHost) {
        if (!isset($_SESSION['uno_hosts']) || !is_array($_SESSION['uno_hosts'])) {
            $_SESSION['uno_hosts'] = [];
        }
        $_SESSION['uno_hosts'][$roomCode] = $playerId;
        $_SESSION['host_room_code'] = $roomCode;
    }
}

function uno_register_spectator_session($roomCode, $userId, $username, $avatarUrl = '') {
    uno_ensure_session();
    if (!isset($_SESSION['uno_spectators']) || !is_array($_SESSION['uno_spectators'])) {
        $_SESSION['uno_spectators'] = [];
    }
    $_SESSION['uno_spectators'][$roomCode] = [
        'user_id' => (int) $userId,
        'username' => (string) $username,
        'avatar_url' => (string) $avatarUrl,
        'created_at' => time(),
    ];
}

function uno_set_active_player_context($roomCode, $playerId, $role = 'player') {
    uno_ensure_session();
    $_SESSION['uno_active_context'] = [
        'roomCode' => (string) $roomCode,
        'playerId' => (string) $playerId,
        'role' => (string) $role,
        'created_at' => time(),
    ];
}

function uno_get_active_player_context($requiredRole = '') {
    uno_ensure_session();
    $context = $_SESSION['uno_active_context'] ?? [];
    if (!is_array($context)) {
        return [];
    }
    if ($requiredRole !== '' && ($context['role'] ?? '') !== $requiredRole) {
        return [];
    }
    return $context;
}

function uno_resolve_player_context($requiredRole = '') {
    $roomCode = $_GET['room-code'] ?? '';
    $playerId = $_GET['player-id'] ?? '';
    if ($roomCode !== '' && $playerId !== '') {
        return ['roomCode' => $roomCode, 'playerId' => $playerId];
    }
    $context = uno_get_active_player_context($requiredRole);
    return [
        'roomCode' => (string) ($context['roomCode'] ?? ''),
        'playerId' => (string) ($context['playerId'] ?? ''),
    ];
}

function uno_set_active_spectator_context($roomCode) {
    uno_ensure_session();
    $_SESSION['uno_active_spectator'] = [
        'roomCode' => (string) $roomCode,
        'created_at' => time(),
    ];
}

function uno_resolve_spectator_room() {
    $roomCode = $_GET['room-code'] ?? '';
    if ($roomCode !== '') {
        return $roomCode;
    }
    $context = $_SESSION['uno_active_spectator'] ?? [];
    return is_array($context) ? (string) ($context['roomCode'] ?? '') : '';
}

function uno_player_session_allowed($roomCode, $playerId) {
    uno_ensure_session();
    return isset($_SESSION['uno_players'][$roomCode][$playerId]);
}

function uno_spectator_session_allowed($roomCode) {
    uno_ensure_session();
    return isset($_SESSION['uno_spectators'][$roomCode]);
}

function uno_any_room_session_allowed($roomCode, $playerId = '') {
    if ($playerId !== '' && uno_player_session_allowed($roomCode, $playerId)) {
        return true;
    }
    return uno_spectator_session_allowed($roomCode);
}

function uno_host_session_allowed($roomCode, $playerId) {
    uno_ensure_session();
    $hostId = $_SESSION['uno_hosts'][$roomCode] ?? '';
    return $hostId !== '' && $hostId === $playerId && uno_player_session_allowed($roomCode, $playerId);
}

function uno_player_auth_query($roomCode, $playerId) {
    return 'player-id='.rawurlencode($playerId).'&room-code='.rawurlencode($roomCode);
}

function uno_set_result_context($roomCode, $playerId) {
    uno_ensure_session();
    $_SESSION['uno_result_context'] = [
        'roomCode' => $roomCode,
        'playerId' => $playerId,
    ];
}

function uno_get_result_context() {
    uno_ensure_session();
    $context = $_SESSION['uno_result_context'] ?? [];
    return is_array($context) ? $context : [];
}

function uno_forbidden() {
    http_response_code(403);
    exit('Forbidden');
}
?>
