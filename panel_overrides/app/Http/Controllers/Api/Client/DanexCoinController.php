<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DateTimeInterface;
use Pterodactyl\Services\PteroProtect\DanexCoinSettingsService;

class DanexCoinController extends ClientApiController
{
    /**
     * @var list<string>
     */
    private const SYMBOLS = ['7', 'BAR', 'CHERRY', 'DIAMOND', 'BELL', 'STAR'];

    public function __construct(private DanexCoinSettingsService $settings)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $this->settings->get();
        $rows = collect();
        $summary = [
            'total_spins' => 0,
            'wins' => 0,
            'jackpots' => 0,
            'biggest_payout' => '0.00',
            'hotness' => 0,
            'streak' => 0,
        ];
        if (Schema::hasTable('danexcoin_spin_logs')) {
            $rows = DB::table('danexcoin_spin_logs')
                ->where('user_id', (int) $user->id)
                ->orderByDesc('id')
                ->limit(15)
                ->get([
                    'id',
                    'bet',
                    'reel_1',
                    'reel_2',
                    'reel_3',
                    'multiplier',
                    'payout',
                    'balance_before',
                    'balance_after',
                    'is_jackpot',
                    'created_at',
                ]);

            $window = now()->subMinutes((int) $settings['hot_window_minutes']);
            $recentRows = DB::table('danexcoin_spin_logs')
                ->where('user_id', (int) $user->id)
                ->where('created_at', '>=', $window)
                ->orderByDesc('id')
                ->limit(50)
                ->get(['multiplier', 'payout', 'is_jackpot']);
            $wins = $recentRows->filter(fn ($row) => (float) $row->multiplier > 0)->count();
            $summary = [
                'total_spins' => $recentRows->count(),
                'wins' => $wins,
                'jackpots' => $recentRows->filter(fn ($row) => (bool) $row->is_jackpot)->count(),
                'biggest_payout' => $this->asDecimal((float) ($recentRows->max('payout') ?? 0)),
                'hotness' => $recentRows->count() > 0 ? (int) round(($wins / max(1, $recentRows->count())) * 100) : 0,
                'streak' => $this->currentStreak($rows->all()),
            ];
        }

        return new JsonResponse([
            'enabled' => (bool) $settings['enabled'],
            'balance' => $this->asDecimal($user->danex_coin ?? 0),
            'currency' => 'DanexCoin',
            'settings' => [
                'min_bet' => $this->asDecimal($settings['min_bet']),
                'max_bet' => $this->asDecimal($settings['max_bet']),
                'default_bet' => $this->asDecimal($settings['default_bet']),
                'spin_cooldown_seconds' => (int) $settings['spin_cooldown_seconds'],
                'base_win_rate' => $settings['base_win_rate'],
                'jackpot_rate' => $settings['jackpot_rate'],
                'house_edge_label' => (string) $settings['house_edge_label'],
            ],
            'summary' => $summary,
            'rules' => [
                'jackpot' => '777 = bet kembali + jackpot x' . $this->asDecimal($settings['jackpot_multiplier']),
                'triple' => '3 simbol sama selain 7 = bet kembali + bonus x' . $this->asDecimal($settings['triple_multiplier']),
                'double' => '2 simbol sama = bet kembali + bonus x' . $this->asDecimal($settings['double_multiplier']),
                'miss' => 'Tidak ada simbol sama = zonk',
                'bet_limit' => 'Bet harus <= saldo aktif.',
            ],
            'history' => $rows->map(function ($row) {
                $createdAt = null;
                if ($row->created_at instanceof DateTimeInterface) {
                    $createdAt = $row->created_at->format(DATE_ATOM);
                } elseif (is_string($row->created_at) && $row->created_at !== '') {
                    $createdAt = $row->created_at;
                }

                return [
                    'id' => (int) $row->id,
                    'bet' => $this->asDecimal($row->bet),
                    'reels' => [(string) $row->reel_1, (string) $row->reel_2, (string) $row->reel_3],
                    'multiplier' => $this->asDecimal($row->multiplier),
                    'payout' => $this->asDecimal($row->payout),
                    'balance_before' => $this->asDecimal($row->balance_before),
                    'balance_after' => $this->asDecimal($row->balance_after),
                    'is_jackpot' => (bool) $row->is_jackpot,
                    'created_at' => $createdAt,
                ];
            })->values(),
        ]);
    }

    public function spin(Request $request): JsonResponse
    {
        $settings = $this->settings->get();
        if (!(bool) $settings['enabled']) {
            return new JsonResponse(['error' => 'DanexCoin sedang dimatikan admin.'], 423);
        }
        if (!Schema::hasColumn('users', 'danex_coin') || !Schema::hasTable('danexcoin_spin_logs')) {
            return new JsonResponse([
                'error' => 'DanexCoin belum siap. Jalankan migration terlebih dahulu.',
            ], 409);
        }

        $validated = $request->validate([
            'bet' => ['required', 'numeric', 'min:' . $settings['min_bet'], 'max:' . $settings['max_bet']],
        ]);

        $requestedBet = round((float) $validated['bet'], 2);
        if ($requestedBet <= 0) {
            return new JsonResponse(['error' => 'Bet harus lebih dari 0.'], 422);
        }

        $userId = (int) $request->user()->id;
        $spinLockKey = "danexcoin:spin:lock:{$userId}";
        if (!Cache::add($spinLockKey, 1, now()->addSeconds((int) $settings['spin_cooldown_seconds']))) {
            return new JsonResponse([
                'error' => 'Terlalu cepat spin. Tunggu sebentar lalu coba lagi.',
            ], 429);
        }

        try {
            $result = DB::transaction(function () use ($userId, $requestedBet, $settings) {
                $locked = DB::table('users')
                    ->where('id', $userId)
                    ->lockForUpdate()
                    ->first(['id', 'danex_coin']);

                $balanceBefore = round((float) ($locked->danex_coin ?? 0), 2);
                if ($balanceBefore <= 0) {
                    return null;
                }
                $bet = round($requestedBet, 2);
                if ($bet > $balanceBefore) {
                    return ['error' => 'Bet melebihi saldo aktif.'];
                }
                if ($bet <= 0) {
                    return null;
                }

                $activeUsers = (int) DB::table('danexcoin_spin_logs')
                    ->where('created_at', '>=', now()->subMinutes(5))
                    ->distinct('user_id')
                    ->count('user_id');
                $winRate = $this->resolveWinRate($bet, $activeUsers, $settings);
                $reels = $this->rollReels($winRate, (float) $settings['jackpot_rate']);

                $isJackpot = $reels[0] === '7' && $reels[1] === '7' && $reels[2] === '7';
                $isTriple = $reels[0] === $reels[1] && $reels[1] === $reels[2];
                $isDouble = !$isTriple && (
                    $reels[0] === $reels[1] ||
                    $reels[0] === $reels[2] ||
                    $reels[1] === $reels[2]
                );
                $multiplier = $isJackpot ? (float) $settings['jackpot_multiplier'] : ($isTriple ? (float) $settings['triple_multiplier'] : ($isDouble ? (float) $settings['double_multiplier'] : 0.0));
                $wagerReturn = $multiplier > 0 ? $bet : 0.0;
                $bonus = round($bet * $multiplier, 2);
                $payout = round($wagerReturn + $bonus, 2);
                $balanceAfter = round($balanceBefore - $bet + $payout, 2);

                DB::table('users')
                    ->where('id', $userId)
                    ->update(['danex_coin' => $balanceAfter]);

                $logId = DB::table('danexcoin_spin_logs')->insertGetId([
                    'user_id' => $userId,
                    'bet' => $bet,
                    'reel_1' => $reels[0],
                    'reel_2' => $reels[1],
                    'reel_3' => $reels[2],
                    'multiplier' => $multiplier,
                    'payout' => $payout,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'is_jackpot' => $isJackpot,
                    'created_at' => now(),
                ]);

                return [
                    'id' => (int) $logId,
                    'bet' => $this->asDecimal($bet),
                    'requested_bet' => $this->asDecimal($requestedBet),
                    'reels' => $reels,
                    'multiplier' => $this->asDecimal($multiplier),
                    'payout' => $this->asDecimal($payout),
                    'balance_before' => $this->asDecimal($balanceBefore),
                    'balance_after' => $this->asDecimal($balanceAfter),
                    'is_jackpot' => $isJackpot,
                    'win_rate' => $winRate,
                ];
            });
        } finally {
            Cache::forget($spinLockKey);
        }

        if ($result === null) {
            return new JsonResponse([
                'error' => 'DanexCoin kamu kosong.',
            ], 422);
        }
        if (isset($result['error'])) {
            return new JsonResponse(['error' => (string) $result['error']], 422);
        }

        return new JsonResponse($result);
    }

    private function randomSymbol(): string
    {
        return self::SYMBOLS[random_int(0, count(self::SYMBOLS) - 1)];
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function rollReels(float $winRate, float $jackpotRate): array
    {
        $roll = random_int(1, 10000) / 10000;
        $win = $roll <= $winRate;

        if ($win) {
            $jackpotRoll = random_int(1, 10000) / 10000;
            if ($jackpotRoll <= $jackpotRate) {
                return ['7', '7', '7'];
            }

            $nonSeven = array_values(array_filter(self::SYMBOLS, static fn (string $item) => $item !== '7'));
            $symbol = $nonSeven[random_int(0, count($nonSeven) - 1)];

            return [$symbol, $symbol, $symbol];
        }

        do {
            $reels = [
                $this->randomSymbol(),
                $this->randomSymbol(),
                $this->randomSymbol(),
            ];
            $isTriple = $reels[0] === $reels[1] && $reels[1] === $reels[2];
            $isDouble = (
                $reels[0] === $reels[1] ||
                $reels[0] === $reels[2] ||
                $reels[1] === $reels[2]
            );
        } while ($isTriple || $isDouble);

        return $reels;
    }

    private function resolveWinRate(float $bet, int $activeUsers, array $settings): float
    {
        $base = (float) ($settings['base_win_rate'] ?? 0.16);
        if ($bet >= 100_000_000) {
            $base = min($base, 0.02);
        } elseif ($bet >= 10_000_000) {
            $base = min($base, 0.04);
        } elseif ($bet >= 1_000_000) {
            $base = min($base, 0.07);
        }

        $activityAmplitude = min(0.03, max(0.005, $activeUsers * 0.0015));
        $phase = ((int) floor(now()->timestamp / 45)) + $activeUsers;
        $swing = sin($phase) * $activityAmplitude;

        return max(0.01, min(0.30, $base + $swing));
    }

    private function currentStreak(array $rows): int
    {
        $streak = 0;
        foreach ($rows as $row) {
            if ((float) ($row->multiplier ?? 0) <= 0) {
                break;
            }
            $streak++;
        }

        return $streak;
    }

    private function asDecimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
