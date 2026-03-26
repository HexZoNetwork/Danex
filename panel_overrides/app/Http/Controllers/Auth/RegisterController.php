<?php

namespace Pterodactyl\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Contracts\View\View;
use Pterodactyl\Models\User;

class RegisterController extends AbstractLoginController
{
    public function index(): View
    {
        return view('templates/auth.core');
    }

    public function start(Request $request): JsonResponse
    {
        if (!Schema::hasTable('registration_otp_requests') || !Schema::hasColumn('users', 'telegram_id')) {
            return new JsonResponse(['error' => 'Fitur daftar belum siap. Jalankan migration terlebih dahulu.'], 409);
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'username' => ['required', 'string', 'max:191', 'alpha_dash', 'unique:users,username'],
            'name_first' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'min:8', 'max:191'],
            'telegram_id' => ['required', 'string', 'regex:/^-?[0-9]{5,20}$/'],
        ]);

        $token = $this->getTelegramToken();
        if ($token === null) {
            return new JsonResponse(['error' => 'Token Telegram belum diatur pada config.json.'], 500);
        }

        $telegramId = trim((string) $validated['telegram_id']);

        $otp = (string) random_int(100000, 999999);
        $requestToken = Str::lower(Str::random(48));
        $now = now();

        DB::table('registration_otp_requests')
            ->where('email', trim((string) $validated['email']))
            ->orWhere('username', trim((string) $validated['username']))
            ->orWhere('telegram_id', $telegramId)
            ->delete();

        DB::table('registration_otp_requests')->insert([
            'request_token' => $requestToken,
            'email' => trim((string) $validated['email']),
            'username' => trim((string) $validated['username']),
            'name_first' => trim((string) $validated['name_first']),
            'name_last' => 'madeinweb',
            'telegram_id' => $telegramId,
            'password_encrypted' => Crypt::encryptString((string) $validated['password']),
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'otp_expires_at' => $now->copy()->addMinutes(10),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sendResult = $this->sendOtp($token, $telegramId, $otp);
        if (!$sendResult['ok']) {
            DB::table('registration_otp_requests')->where('request_token', $requestToken)->delete();

            $description = trim((string) ($sendResult['description'] ?? ''));
            $hint = $description !== '' ? (' (' . $description . ')') : '';

            return new JsonResponse([
                'error' => 'Gagal kirim OTP. Pastikan ID Telegram benar dan kamu sudah /start bot.' . $hint,
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'request_token' => $requestToken,
                'expires_in' => 600,
            ],
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        if (!Schema::hasTable('registration_otp_requests') || !Schema::hasColumn('users', 'telegram_id')) {
            return new JsonResponse(['error' => 'Fitur daftar belum siap. Jalankan migration terlebih dahulu.'], 409);
        }

        $validated = $request->validate([
            'request_token' => ['required', 'string', 'size:48'],
            'otp' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        $row = DB::table('registration_otp_requests')
            ->where('request_token', (string) $validated['request_token'])
            ->first();

        if (!$row) {
            return new JsonResponse(['error' => 'Sesi OTP tidak ditemukan atau sudah kadaluarsa.'], 422);
        }

        if ((int) $row->attempts >= 6 || now()->greaterThan($row->otp_expires_at)) {
            DB::table('registration_otp_requests')->where('id', $row->id)->delete();

            return new JsonResponse(['error' => 'OTP sudah kadaluarsa. Ulangi pendaftaran.'], 422);
        }

        if (!Hash::check((string) $validated['otp'], (string) $row->otp_hash)) {
            DB::table('registration_otp_requests')->where('id', $row->id)->increment('attempts');

            return new JsonResponse(['error' => 'Kode OTP salah.'], 422);
        }

        if (User::query()->where('email', (string) $row->email)->exists() || User::query()->where('username', (string) $row->username)->exists()) {
            DB::table('registration_otp_requests')->where('id', $row->id)->delete();

            return new JsonResponse(['error' => 'Email atau username sudah terpakai.'], 422);
        }

        $user = DB::transaction(function () use ($row) {
            $user = new User();
            $user->uuid = (string) Str::uuid();
            $user->email = (string) $row->email;
            $user->username = (string) $row->username;
            $user->name_first = (string) $row->name_first;
            $user->name_last = 'madeinweb';
            $user->password = Hash::make(Crypt::decryptString((string) $row->password_encrypted));
            $user->language = 'en';
            $user->root_admin = false;
            $user->use_totp = false;
            if (Schema::hasColumn('users', 'telegram_id')) {
                $user->telegram_id = (string) $row->telegram_id;
            }
            $user->saveOrFail();

            DB::table('registration_otp_requests')->where('id', $row->id)->delete();

            return $user->refresh();
        });

        return $this->sendLoginResponse($user, $request);
    }

    private function getTelegramToken(): ?string
    {
        $candidates = [
            base_path('config.json'),
            '/root/porn/config.json',
        ];

        $tokens = [];
        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }
            $raw = @file_get_contents($path);
            if (!is_string($raw) || trim($raw) === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }

            $token = trim((string) data_get($decoded, 'telegram.token', ''));
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        foreach ($tokens as $token) {
            if ($this->isTelegramTokenValid($token)) {
                return $token;
            }
        }

        return $tokens[0] ?? null;
    }

    private function isTelegramTokenValid(string $token): bool
    {
        try {
            $response = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getMe");

            return $response->ok() && (bool) data_get($response->json(), 'ok', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array{ok:bool,description?:string}
     */
    private function sendOtp(string $token, string $telegramId, string $otp): array
    {
        $text = sprintf(
            "Kode OTP Danex Panel: %s\nBerlaku 10 menit.\nJangan bagikan ke siapa pun.",
            $otp
        );

        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $telegramId,
                    'text' => $text,
                    'disable_web_page_preview' => 'true',
                ]);

            $json = $response->json();
            $ok = $response->ok() && (bool) data_get($json, 'ok', false);
            $description = trim((string) data_get($json, 'description', ''));

            return [
                'ok' => $ok,
                'description' => $description !== '' ? $description : null,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'description' => 'network error'];
        }
    }
}
