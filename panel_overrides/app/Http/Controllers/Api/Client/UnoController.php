<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cookie;
use mysqli;
use Pterodactyl\Models\User;

class UnoController extends ClientApiController
{
    private const TOKEN_TTL_SECONDS = 90;

    public function rooms(Request $request): JsonResponse
    {
        $link = $this->db();
        $this->ensureSchema($link);

        $rooms = [];
        $sql = "select r.roomCode, r.numberOfPlayersRemaining, r.isStarted, r.isEnded, "
            ."coalesce(host.name, 'Host') as host_name, coalesce(host.avatar_url, '') as host_avatar, "
            ."count(p.id) as player_count "
            ."from room r "
            ."left join player p on p.roomCode = r.roomCode "
            ."left join player host on host.roomCode = r.roomCode and host.is_host = 1 "
            ."where r.isEnded = 0 "
            ."group by r.roomCode, r.numberOfPlayersRemaining, r.isStarted, r.isEnded, host.name, host.avatar_url "
            ."order by r.isStarted asc, r.roomCode desc limit 30";
        $result = $link->query($sql);
        while ($result && ($row = $result->fetch_assoc())) {
            $remaining = max(0, (int) $row['numberOfPlayersRemaining']);
            $started = (int) $row['isStarted'] === 1;
            $ended = (int) $row['isEnded'] === 1;
            $rooms[] = [
                'room_code' => (string) $row['roomCode'],
                'host_name' => (string) $row['host_name'],
                'host_avatar_url' => (string) ($row['host_avatar'] ?? ''),
                'player_count' => (int) $row['player_count'],
                'seats_remaining' => $remaining,
                'joinable' => !$started && !$ended && $remaining > 0,
                'spectatable' => !$ended,
                'started' => $started,
                'ended' => $ended,
            ];
        }
        $link->close();

        return new JsonResponse(['rooms' => $rooms, 'user' => $this->userPayload($request->user())]);
    }

    public function create(Request $request): JsonResponse
    {
        $link = $this->db();
        $this->ensureSchema($link);
        $user = $request->user();

        $roomCode = $this->uniqueCode($link, 'room', 'roomCode', 'r');
        $playerId = $this->uniqueCode($link, 'player', 'id', 'p');
        $profile = $this->userPayload($user);

        $link->begin_transaction();
        try {
            $stmt = $link->prepare("insert into room (roomCode, numberOfPlayersRemaining, isStarted, cardOnTable, isEnded) values (?, 3, 0, '-', 0)");
            $stmt->bind_param('s', $roomCode);
            $stmt->execute();
            $stmt->close();

            $stmt = $link->prepare("insert into player (id, name, numCards, roomCode, user_id, avatar_url, is_host) values (?, ?, 7, ?, ?, ?, 1)");
            $userId = (int) $user->id;
            $stmt->bind_param('sssis', $playerId, $profile['username'], $roomCode, $userId, $profile['avatar_url']);
            $stmt->execute();
            $stmt->close();

            $token = $this->createLaunchToken($link, $roomCode, $playerId, $user, 'host');
            $link->commit();
        } catch (\Throwable $exception) {
            $link->rollback();
            $link->close();
            throw $exception;
        }
        $link->close();

        return $this->withLaunchCookie($request, $token, new JsonResponse([
            'room_code' => $roomCode,
            'player_id' => $playerId,
            'launch_url' => $this->launchUrl(),
        ]));
    }

    public function join(Request $request, string $roomCode): JsonResponse
    {
        return $this->joinAs($request, $roomCode, false);
    }

    public function spectate(Request $request, string $roomCode): JsonResponse
    {
        return $this->joinAs($request, $roomCode, true);
    }

    private function joinAs(Request $request, string $roomCode, bool $spectator): JsonResponse
    {
        $roomCode = strtolower(trim($roomCode));
        if (!preg_match('/^[a-z0-9]{5}$/', $roomCode)) {
            return new JsonResponse(['error' => 'Room code tidak valid.'], 422);
        }

        $link = $this->db();
        $this->ensureSchema($link);
        $user = $request->user();
        $profile = $this->userPayload($user);
        $role = $spectator ? 'spectator' : 'player';

        $link->begin_transaction();
        try {
            $stmt = $link->prepare('select roomCode, numberOfPlayersRemaining, isStarted, isEnded from room where roomCode=? for update');
            $stmt->bind_param('s', $roomCode);
            $stmt->execute();
            $room = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$room) {
                $link->rollback();
                $link->close();
                return new JsonResponse(['error' => 'Room tidak ditemukan.'], 404);
            }
            if ((int) $room['isEnded'] === 1) {
                $link->rollback();
                $link->close();
                return new JsonResponse(['error' => 'Room sudah selesai.'], 409);
            }

            $userId = (int) $user->id;
            if ($spectator) {
                $this->upsertSpectator($link, $roomCode, $userId, $profile['username'], $profile['avatar_url']);
                $playerId = '';
            } else {
                if ((int) $room['isStarted'] === 1) {
                    $link->rollback();
                    $link->close();
                    return new JsonResponse(['error' => 'Match sudah dimulai. Gunakan Spectate.'], 409);
                }

                $existing = $this->existingPlayerForUser($link, $roomCode, $userId);
                if ($existing !== '') {
                    $playerId = $existing;
                } else {
                    $remaining = (int) $room['numberOfPlayersRemaining'];
                    if ($remaining <= 0) {
                        $link->rollback();
                        $link->close();
                        return new JsonResponse(['error' => 'Room sudah penuh.'], 409);
                    }
                    $playerId = $this->uniqueCode($link, 'player', 'id', 'p');
                    $stmt = $link->prepare("insert into player (id, name, numCards, roomCode, user_id, avatar_url, is_host) values (?, ?, 7, ?, ?, ?, 0)");
                    $stmt->bind_param('sssis', $playerId, $profile['username'], $roomCode, $userId, $profile['avatar_url']);
                    $stmt->execute();
                    $stmt->close();

                    $nextRemaining = max(0, $remaining - 1);
                    $stmt = $link->prepare('update room set numberOfPlayersRemaining=? where roomCode=?');
                    $stmt->bind_param('is', $nextRemaining, $roomCode);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $token = $this->createLaunchToken($link, $roomCode, $playerId, $user, $role);
            $link->commit();
        } catch (\Throwable $exception) {
            $link->rollback();
            $link->close();
            throw $exception;
        }
        $link->close();

        return $this->withLaunchCookie($request, $token, new JsonResponse([
            'room_code' => $roomCode,
            'player_id' => $playerId,
            'role' => $role,
            'launch_url' => $this->launchUrl(),
        ]));
    }

    private function db(): mysqli
    {
        $mysql = Config::get('database.connections.mysql', []);

        $link = new mysqli(
            (string) ($mysql['host'] ?? '127.0.0.1'),
            (string) ($mysql['username'] ?? 'pterodactyl'),
            (string) ($mysql['password'] ?? ''),
            (string) ($mysql['database'] ?? 'pterodactyl'),
            (int) ($mysql['port'] ?? 3306)
        );
        if ($link->connect_error) {
            abort(503, 'UNO database unavailable.');
        }
        $link->set_charset('utf8mb4');

        return $link;
    }

    private function ensureSchema(mysqli $link): void
    {
        $link->query("create table if not exists room (roomCode varchar(5) not null, numberOfPlayersRemaining int not null default 3, isStarted tinyint(1) not null default 0, cardOnTable varchar(32) not null default '-', isEnded tinyint(1) not null default 0, playerTurn varchar(5) null, direction int not null default 1, color varchar(16) null, created_at timestamp null default current_timestamp, updated_at timestamp null default current_timestamp on update current_timestamp, primary key (roomCode), key room_started_ended (isStarted, isEnded)) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci");
        $link->query("create table if not exists player (id varchar(5) not null, name varchar(191) not null, numCards int not null default 7, roomCode varchar(5) not null, nextPlayer varchar(5) null, previousPlayer varchar(5) null, unoPressed tinyint(1) not null default 0, user_id bigint unsigned null, avatar_url varchar(2048) null, is_host tinyint(1) not null default 0, created_at timestamp null default current_timestamp, updated_at timestamp null default current_timestamp on update current_timestamp, primary key (id), key player_room (roomCode), key player_user (user_id)) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci");
        $link->query("create table if not exists stack (stack_id varchar(5) not null, numberOfCardsRemaining int not null default 0, roomCode varchar(5) not null, nextCardNumber int not null default 0, primary key (stack_id), key stack_room (roomCode)) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci");
        $link->query("create table if not exists card (stack_id varchar(5) not null, number int not null, order_in_stack int not null, content varchar(32) not null, id varchar(5) null, key card_stack_order (stack_id, order_in_stack), key card_player (id), key card_number_stack_player (number, stack_id, id)) engine=InnoDB default charset=utf8mb4 collate=utf8mb4_unicode_ci");
        $this->addColumnIfMissing($link, 'player', 'user_id', 'bigint unsigned null after roomCode');
        $this->addColumnIfMissing($link, 'player', 'avatar_url', 'varchar(2048) null after user_id');
        $this->addColumnIfMissing($link, 'player', 'is_host', 'tinyint(1) not null default 0 after avatar_url');
        $link->query('alter table player modify name varchar(191) not null');
        $link->query('create table if not exists uno_spectators (id bigint unsigned not null auto_increment, roomCode varchar(5) not null, user_id bigint unsigned not null, username varchar(191) not null, avatar_url varchar(2048) null, created_at timestamp null default current_timestamp, updated_at timestamp null default current_timestamp on update current_timestamp, primary key (id), unique key uno_spectators_room_user (roomCode, user_id), key uno_spectators_room (roomCode)) engine=InnoDB default charset=utf8mb4');
        $link->query('create table if not exists uno_launch_tokens (token_hash char(64) not null, roomCode varchar(5) not null, player_id varchar(5) null, user_id bigint unsigned not null, username varchar(191) not null, avatar_url varchar(2048) null, role varchar(16) not null, expires_at int unsigned not null, used_at int unsigned null, primary key (token_hash), key uno_launch_tokens_room (roomCode), key uno_launch_tokens_expiry (expires_at)) engine=InnoDB default charset=utf8mb4');
    }

    private function addColumnIfMissing(mysqli $link, string $table, string $column, string $definition): void
    {
        $safeTable = $link->real_escape_string($table);
        $safeColumn = $link->real_escape_string($column);
        $result = $link->query("show columns from `{$safeTable}` like '{$safeColumn}'");
        if ($result && $result->num_rows > 0) {
            return;
        }
        $link->query("alter table `{$safeTable}` add column `{$safeColumn}` {$definition}");
    }

    private function uniqueCode(mysqli $link, string $table, string $column, string $suffix): string
    {
        $alphabet = '23456789abcdefghjkmnpqrstuvwxyz';
        do {
            $code = '';
            for ($i = 0; $i < 4; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code .= $suffix;
            $safeTable = $link->real_escape_string($table);
            $safeColumn = $link->real_escape_string($column);
            $safeCode = $link->real_escape_string($code);
            $result = $link->query("select 1 from `{$safeTable}` where `{$safeColumn}`='{$safeCode}' limit 1");
        } while ($result && $result->num_rows > 0);

        return $code;
    }

    private function existingPlayerForUser(mysqli $link, string $roomCode, int $userId): string
    {
        $stmt = $link->prepare('select id from player where roomCode=? and user_id=? limit 1');
        $stmt->bind_param('si', $roomCode, $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (string) $row['id'] : '';
    }

    private function upsertSpectator(mysqli $link, string $roomCode, int $userId, string $username, string $avatarUrl): void
    {
        $stmt = $link->prepare('insert into uno_spectators (roomCode, user_id, username, avatar_url) values (?, ?, ?, ?) on duplicate key update username=values(username), avatar_url=values(avatar_url), updated_at=current_timestamp');
        $stmt->bind_param('siss', $roomCode, $userId, $username, $avatarUrl);
        $stmt->execute();
        $stmt->close();
    }

    private function createLaunchToken(mysqli $link, string $roomCode, string $playerId, User $user, string $role): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $token);
        $profile = $this->userPayload($user);
        $expiresAt = time() + self::TOKEN_TTL_SECONDS;
        $userId = (int) $user->id;
        $stmt = $link->prepare('insert into uno_launch_tokens (token_hash, roomCode, player_id, user_id, username, avatar_url, role, expires_at) values (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssisssi', $hash, $roomCode, $playerId, $userId, $profile['username'], $profile['avatar_url'], $role, $expiresAt);
        $stmt->execute();
        $stmt->close();

        return $token;
    }

    private function launchUrl(): string
    {
        return '/minigames/uno/core/auth-launch.php';
    }

    private function withLaunchCookie(Request $request, string $token, JsonResponse $response): JsonResponse
    {
        return $response->withCookie(Cookie::make(
            'danex_uno_launch',
            $token,
            2,
            '/minigames/uno/core',
            null,
            $request->isSecure(),
            true,
            false,
            'Lax'
        ));
    }

    /**
     * @return array{username:string,avatar_url:string}
     */
    private function userPayload(?User $user): array
    {
        $username = trim((string) ($user?->username ?? ''));
        if ($username === '') {
            $username = trim((string) ($user?->name ?? 'Player'));
        }

        return [
            'username' => mb_substr($username, 0, 191),
            'avatar_url' => (string) ($user?->avatar_url ?? ''),
        ];
    }
}
