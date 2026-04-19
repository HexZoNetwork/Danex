<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DanexCoinController extends Controller
{
    public function __construct(private AlertsMessageBag $alert)
    {
    }

    public function index(Request $request): View
    {
        $this->assertAdmin($request);

        $recent = collect();
        if (Schema::hasTable('danexcoin_spin_logs')) {
            $recent = DB::table('danexcoin_spin_logs as l')
                ->join('users as u', 'u.id', '=', 'l.user_id')
                ->orderByDesc('l.id')
                ->limit(80)
                ->get([
                    'l.id',
                    'u.id as user_id',
                    'u.username',
                    'l.bet',
                    'l.reel_1',
                    'l.reel_2',
                    'l.reel_3',
                    'l.multiplier',
                    'l.payout',
                    'l.balance_before',
                    'l.balance_after',
                    'l.is_jackpot',
                    'l.created_at',
                ]);
        }

        return view('admin.danexcoin.index', [
            'recent' => $recent,
        ]);
    }

    public function adjust(Request $request): RedirectResponse
    {
        $this->assertAdmin($request);
        if (!Schema::hasColumn('users', 'danex_coin')) {
            $this->alert->danger('Kolom DanexCoin belum ada. Jalankan migration terlebih dahulu.')->flash();
            return redirect()->route('admin.management.danexcoin.index');
        }

        $validated = $request->validate([
            'identifier' => ['required', 'string', 'min:1', 'max:191'],
            'mode' => ['required', 'in:add,remove'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:100000000'],
        ]);

        $identifier = trim((string) $validated['identifier']);
        $mode = (string) $validated['mode'];
        $amount = round((float) $validated['amount'], 2);

        $target = $this->resolveUser($identifier);
        if (!$target) {
            $this->alert->danger('User tidak ditemukan. Pakai user id / username / email yang valid.')->flash();
            return redirect()->route('admin.management.danexcoin.index');
        }

        $updated = DB::transaction(function () use ($target, $mode, $amount) {
            $locked = DB::table('users')
                ->where('id', (int) $target->id)
                ->lockForUpdate()
                ->first(['id', 'username', 'danex_coin']);

            $before = round((float) ($locked->danex_coin ?? 0), 2);
            if ($mode === 'add') {
                $after = round($before + $amount, 2);
                $effective = $amount;
            } else {
                $after = round(max(0, $before - $amount), 2);
                $effective = round($before - $after, 2);
            }

            DB::table('users')
                ->where('id', (int) $locked->id)
                ->update(['danex_coin' => $after]);

            return [
                'id' => (int) $locked->id,
                'username' => (string) $locked->username,
                'before' => $before,
                'after' => $after,
                'effective' => $effective,
            ];
        });

        $verb = $mode === 'add' ? 'ditambah' : 'dikurangi';
        $this->alert->success(sprintf(
            'DanexCoin user %s (#%d) berhasil %s %.2f. Saldo: %.2f -> %.2f',
            $updated['username'],
            $updated['id'],
            $verb,
            $updated['effective'],
            $updated['before'],
            $updated['after']
        ))->flash();

        return redirect()->route('admin.management.danexcoin.index');
    }

    private function resolveUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            $user = User::query()->find((int) $identifier);
            if ($user) {
                return $user;
            }
        }

        return User::query()
            ->where('username', $identifier)
            ->orWhere('email', $identifier)
            ->first();
    }

    private function assertAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException('Hanya admin yang bisa mengakses DanexCoin management.');
        }
    }
}
