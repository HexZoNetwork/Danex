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

    public function meta(): JsonResponse
    {
        [$token, $botUsername] = $this->resolveTelegramBotIdentity();

        return new JsonResponse([
            'data' => [
                'telegram_ready' => $token !== null,
                'bot_username' => $botUsername,
                'bot_start_url' => $botUsername !== null ? ('https://t.me/' . ltrim($botUsername, '@')) : null,
                'required_channels' => $this->getRequiredJoinChannels(),
            ],
        ]);
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

        [$token, $botUsername] = $this->resolveTelegramBotIdentity();
        if ($token === null) {
            return new JsonResponse(['error' => 'Token Telegram belum tersedia. Pastikan setup sinkron ke .env (TELEGRAM_BOT_TOKEN) atau isi telegram.token di config.json.'], 500);
        }

        $telegramId = trim((string) $validated['telegram_id']);
        $requiredChannels = $this->getRequiredJoinChannels();
        $joinCheck = $this->validateRequiredChannelMembership($token, $telegramId, $requiredChannels);
        if (!(bool) ($joinCheck['ok'] ?? false)) {
            $missing = is_array($joinCheck['missing'] ?? null) ? $joinCheck['missing'] : [];

            return new JsonResponse([
                'error' => $this->buildJoinRetryErrorMessage($requiredChannels, $missing),
            ], 422);
        }

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
            $botHint = $botUsername !== null ? (' Bot: @' . ltrim($botUsername, '@') . '.') : '';

            return new JsonResponse([
                'error' => 'Gagal kirim OTP. Pastikan ID Telegram benar dan kamu sudah /start bot.' . $botHint . $hint,
            ], 422);
        }

        return new JsonResponse([
            'data' => [
                'request_token' => $requestToken,
                'expires_in' => 600,
                'bot_username' => $botUsername,
                'bot_start_url' => $botUsername !== null ? ('https://t.me/' . ltrim($botUsername, '@')) : null,
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
        return $this->resolveTelegramBotIdentity()[0];
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function resolveTelegramBotIdentity(): array
    {
        $envCandidates = [
            trim((string) env('TELEGRAM_BOT_TOKEN', '')),
            trim((string) env('PTEROPROTECT_TELEGRAM_TOKEN', '')),
            trim((string) env('TELEGRAM_TOKEN', '')),
        ];
        $candidates = $this->getConfigJsonCandidates();

        $tokens = [];
        foreach ($envCandidates as $token) {
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }
        foreach ($candidates as $path) {
            $decoded = $this->readJsonFile($path);
            if (!is_array($decoded)) {
                continue;
            }
            $token = trim((string) data_get($decoded, 'telegram.token', ''));
            if ($token !== '' && !in_array($token, $tokens, true)) {
                $tokens[] = $token;
            }
        }

        foreach ($tokens as $token) {
            $profile = $this->getTelegramBotProfile($token);
            if ((bool) ($profile['ok'] ?? false)) {
                $username = trim((string) ($profile['username'] ?? ''));

                return [$token, $username !== '' ? $username : null];
            }
        }

        // Keep token usable even if Telegram API cannot be reached right now.
        return [$tokens[0] ?? null, null];
    }

    private function isTelegramTokenValid(string $token): bool
    {
        return (bool) ($this->getTelegramBotProfile($token)['ok'] ?? false);
    }

    /**
     * @return array<int,string>
     */
    private function getRequiredJoinChannels(): array
    {
        $channels = [];

        $envRaw = [
            trim((string) env('TELEGRAM_REQUIRED_CHANNELS', '')),
            trim((string) env('PTEROPROTECT_TELEGRAM_REQUIRED_CHANNELS', '')),
            trim((string) env('TELEGRAM_CHANNEL', '')),
            trim((string) env('TELEGRAM_REPORT_CHANNEL', '')),
        ];

        foreach ($envRaw as $value) {
            if ($value === '') {
                continue;
            }

            $parts = preg_split('/[\s,]+/', $value) ?: [];
            foreach ($parts as $part) {
                $channel = trim((string) $part);
                if ($channel === '') {
                    continue;
                }
                if ($channel[0] !== '@') {
                    $channel = '@' . ltrim($channel, '@');
                }
                if (!in_array($channel, $channels, true)) {
                    $channels[] = $channel;
                }
            }
        }

        foreach ($this->getConfigJsonCandidates() as $path) {
            $decoded = $this->readJsonFile($path);
            if (!is_array($decoded)) {
                continue;
            }

            foreach (['telegram.channel', 'telegram.report_channel'] as $key) {
                $value = trim((string) data_get($decoded, $key, ''));
                if ($value === '') {
                    continue;
                }
                if ($value[0] !== '@') {
                    $value = '@' . ltrim($value, '@');
                }
                if (!in_array($value, $channels, true)) {
                    $channels[] = $value;
                }
            }
        }

        if (count($channels) <= 1) {
            return $channels;
        }

        // Show/require exactly one channel at a time, rotated every 10 minutes.
        $bucket = (int) floor(time() / 600);
        $seed = (string) config('app.key', 'pteroprotect') . '|' . (string) $bucket;
        $idx = (int) (abs(crc32($seed)) % count($channels));

        return [$channels[$idx]];
    }

    /**
     * @return array<int,string>
     */
    private function getConfigJsonCandidates(): array
    {
        return [
            base_path('config.json'),
            '/pteroprotect/config.json',
            '/root/Danex/config.json',
            '/root/porn/config.json',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<int,string> $channels
     * @return array{ok:bool,missing:array<int,string>}
     */
    private function validateRequiredChannelMembership(string $token, string $telegramId, array $channels): array
    {
        $missing = [];
        foreach ($channels as $channel) {
            $membership = $this->getTelegramChannelMembership($token, $channel, $telegramId);
            if (!(bool) ($membership['is_member'] ?? false)) {
                $missing[] = $channel;
            }
        }

        return [
            'ok' => count($missing) === 0,
            'missing' => $missing,
        ];
    }

    /**
     * @return array{ok:bool,is_member:bool,status:?string,description:?string}
     */
    private function getTelegramChannelMembership(string $token, string $channel, string $telegramId): array
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post("https://api.telegram.org/bot{$token}/getChatMember", [
                    'chat_id' => $channel,
                    'user_id' => $telegramId,
                ]);

            $json = $response->json();
            $ok = $response->ok() && (bool) data_get($json, 'ok', false);
            $status = trim((string) data_get($json, 'result.status', ''));
            $isMemberFlag = (bool) data_get($json, 'result.is_member', false);
            $description = trim((string) data_get($json, 'description', ''));

            $isMember = in_array($status, ['member', 'administrator', 'creator'], true)
                || ($status === 'restricted' && $isMemberFlag);

            return [
                'ok' => $ok,
                'is_member' => $ok ? $isMember : false,
                'status' => $status !== '' ? $status : null,
                'description' => $description !== '' ? $description : null,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'is_member' => false, 'status' => null, 'description' => 'network error'];
        }
    }

    /**
     * @param array<int,string> $requiredChannels
     * @param array<int,string> $missingChannels
     */
    private function buildJoinRetryErrorMessage(array $requiredChannels, array $missingChannels): string
    {
        $targets = count($missingChannels) > 0 ? $missingChannels : $requiredChannels;
        $channelText = implode(' dan ', $targets);

        return "Wajib join {$channelText} dulu, lalu retry pendaftaran.";
    }

    /**
     * @return array{ok:bool,username:?string}
     */
    private function getTelegramBotProfile(string $token): array
    {
        try {
            $response = Http::timeout(8)->get("https://api.telegram.org/bot{$token}/getMe");
            $json = $response->json();
            $ok = $response->ok() && (bool) data_get($json, 'ok', false);
            $username = trim((string) data_get($json, 'result.username', ''));

            return [
                'ok' => $ok,
                'username' => $username !== '' ? $username : null,
            ];
        } catch (\Throwable) {
            return ['ok' => false, 'username' => null];
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
