<?php

namespace Pterodactyl\Http\Controllers\Api\Client;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use DateTimeInterface;

class DanexCoinController extends ClientApiController
{
    /**
     * @var list<string>
     */
    private const SYMBOLS = ['7', 'BAR', 'CHERRY', 'DIAMOND', 'BELL', 'STAR'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $rows = collect();
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
        }

        return new JsonResponse([
            'balance' => $this->asDecimal($user->danex_coin ?? 0),
            'currency' => 'DanexCoin',
            'rules' => [
                'jackpot' => '777 = x2 payout',
                'triple' => '3 simbol sama selain 7 = x1.5 payout',
                'miss' => '2 simbol sama atau tidak ada yang sama = zonk',
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
        if (!Schema::hasColumn('users', 'danex_coin') || !Schema::hasTable('danexcoin_spin_logs')) {
            return new JsonResponse([
                'error' => 'DanexCoin belum siap. Jalankan migration terlebih dahulu.',
            ], 409);
        }

        $validated = $request->validate([
            'bet' => ['required', 'numeric', 'min:1', 'max:100000000'],
        ]);

        $bet = round((float) $validated['bet'], 2);
        if ($bet <= 0) {
            return new JsonResponse(['error' => 'Bet harus lebih dari 0.'], 422);
        }

        $userId = (int) $request->user()->id;
        $result = DB::transaction(function () use ($userId, $bet) {
            $locked = DB::table('users')
                ->where('id', $userId)
                ->lockForUpdate()
                ->first(['id', 'danex_coin']);

            $balanceBefore = round((float) ($locked->danex_coin ?? 0), 2);
            if ($balanceBefore < $bet) {
                return null;
            }

            $activeUsers = (int) DB::table('danexcoin_spin_logs')
                ->where('created_at', '>=', now()->subMinutes(5))
                ->distinct('user_id')
                ->count('user_id');
            $winRate = $this->resolveWinRate($bet, $activeUsers);
            $reels = $this->rollReels($winRate);

            $isJackpot = $reels[0] === '7' && $reels[1] === '7' && $reels[2] === '7';
            $isTriple = $reels[0] === $reels[1] && $reels[1] === $reels[2];
            $isDouble = !$isTriple && (
                $reels[0] === $reels[1] ||
                $reels[0] === $reels[2] ||
                $reels[1] === $reels[2]
            );
            $multiplier = $isJackpot ? 2.0 : ($isTriple ? 1.5 : ($isDouble ? 0.25 : 0.0));
            $payout = round($bet * $multiplier, 2);
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
                'reels' => $reels,
                'multiplier' => $this->asDecimal($multiplier),
                'payout' => $this->asDecimal($payout),
                'balance_before' => $this->asDecimal($balanceBefore),
                'balance_after' => $this->asDecimal($balanceAfter),
                'is_jackpot' => $isJackpot,
            ];
        });

        if ($result === null) {
            return new JsonResponse([
                'error' => 'DanexCoin tidak cukup untuk bet tersebut.',
            ], 422);
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
    private function rollReels(float $winRate): array
    {
        $roll = random_int(1, 10000) / 10000;
        $win = $roll <= $winRate;

        if ($win) {
            $jackpotRoll = random_int(1, 10000) / 10000;
            if ($jackpotRoll <= 0.08) {
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
        } while ($isTriple);

        return $reels;
    }

    private function resolveWinRate(float $bet, int $activeUsers): float
    {
        $base = 0.65;
        if ($bet >= 100_000_000) {
            $base = 0.30;
        } elseif ($bet >= 10_000_000) {
            $base = 0.40;
        } elseif ($bet >= 1_000_000) {
            $base = 0.50;
        }

        $activityAmplitude = min(0.10, max(0.01, $activeUsers * 0.004));
        $phase = ((int) floor(now()->timestamp / 45)) + $activeUsers;
        $swing = sin($phase) * $activityAmplitude;

        return max(0.05, min(0.90, $base + $swing));
    }

    private function asDecimal(float|int|string $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
