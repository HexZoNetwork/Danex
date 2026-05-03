<?php

namespace Pterodactyl\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class PteroProtectClearanceToken
{
    public static function isValid(Request $request, ?string $token, ?string $cookieName = null): bool
    {
        $token = trim((string) $token);
        if ($token === '') {
            return false;
        }

        [$payloadRaw, $sig] = self::splitToken($token);
        if ($payloadRaw === null || $sig === null) {
            return false;
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            return false;
        }

        $exp = (int) ($payload['exp'] ?? 0);
        $ipClaim = strtolower(trim((string) ($payload['ip'] ?? '')));
        $uaClaim = trim((string) ($payload['ua'] ?? ''));
        $sid = trim((string) ($payload['sid'] ?? ''));

        if ($exp <= time() || $sid === '' || $ipClaim === '' || $uaClaim === '') {
            return false;
        }

        $requestUaFp = self::sha256Hex24(self::uaBindingMaterial((string) $request->userAgent()));
        if ($requestUaFp === '' || !hash_equals($uaClaim, $requestUaFp)) {
            return false;
        }

        $requestIp = self::clientIpForBinding($request);
        $secret = self::challengeSecret();

        if ($secret !== '') {
            $expectedSig = self::base64urlEncode(hash_hmac('sha256', $payloadRaw, $secret, true));
            if (!hash_equals($expectedSig, $sig)) {
                return false;
            }

            return self::sessionIpAllowed($sid, $uaClaim, $ipClaim, $requestIp, $exp);
        }

        // Fallback: ask challenge_guard directly when secret isn't locally available.
        $cookie = $cookieName ?: self::cookieName();
        return self::verifyViaChallengeGuard($request, $cookie, $token);
    }

    public static function cookieName(): string
    {
        $cookie = trim((string) config('pteroprotect.waf.challenge_cookie_name', 'pp_clearance'));
        return $cookie !== '' ? $cookie : 'pp_clearance';
    }

    private static function splitToken(string $token): array
    {
        $dot = strpos($token, '.');
        if ($dot === false || $dot <= 0 || $dot >= strlen($token) - 1) {
            return [null, null];
        }

        $encoded = substr($token, 0, $dot);
        $sig = substr($token, $dot + 1);
        if ($encoded === '' || $sig === '') {
            return [null, null];
        }

        if (!preg_match('/^[A-Za-z0-9\-_]+$/', $encoded) || !preg_match('/^[A-Za-z0-9\-_]+$/', $sig)) {
            return [null, null];
        }

        $decoded = self::base64urlDecode($encoded);
        if ($decoded === null || $decoded === '') {
            return [null, null];
        }

        return [$decoded, $sig];
    }

    private static function uaBindingMaterial(string $uaRaw): string
    {
        $ua = strtolower(trim($uaRaw));
        if ($ua === '') {
            return '';
        }
        if (strlen($ua) > 512) {
            $ua = substr($ua, 0, 512);
        }

        $mobile = self::uaMobileLike($ua);
        $inApp = self::uaInAppLike($ua);
        if (!$mobile && !$inApp) {
            return $ua;
        }

        $platform = 'mobile';
        if (str_contains($ua, 'android')) {
            $platform = 'android';
        } elseif (str_contains($ua, 'iphone') || str_contains($ua, 'ipad') || str_contains($ua, 'ipod')) {
            $platform = 'ios';
        }

        $browser = 'other';
        $rules = [
            ['edg/', 'edge'],
            ['opr/', 'opera'],
            ['firefox/', 'firefox'],
            ['fxios/', 'firefox'],
            ['crios/', 'chrome'],
            ['chrome/', 'chrome'],
            ['version/', 'safari'],
            ['telegram', 'telegram'],
            ['fbav', 'facebook'],
            ['instagram', 'instagram'],
        ];

        foreach ($rules as [$needle, $name]) {
            if (!str_contains($ua, $needle)) {
                continue;
            }

            $browser = $name;
            break;
        }

        return "mobile|{$platform}|{$browser}";
    }

    private static function uaMobileLike(string $ua): bool
    {
        return str_contains($ua, 'mobile')
            || str_contains($ua, 'android')
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipad')
            || str_contains($ua, 'ipod');
    }

    private static function uaInAppLike(string $ua): bool
    {
        $hints = [
            ' wv)', '; wv', 'telegram', 'fb_iab', 'fban', 'fbav', 'instagram',
            'line/', 'micromessenger', 'gsa/', 'okhttp', 'vivo', 'miuibrowser',
        ];

        foreach ($hints as $hint) {
            if (str_contains($ua, $hint)) {
                return true;
            }
        }

        return false;
    }

    private static function sha256Hex24(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return substr(hash('sha256', $text), 0, 24);
    }

    private static function base64urlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }

        $b64 = strtr($encoded, '-_', '+/');
        $pad = strlen($b64) % 4;
        if ($pad > 0) {
            $b64 .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($b64, true);
        return is_string($decoded) ? $decoded : null;
    }

    private static function verifyViaChallengeGuard(Request $request, string $cookieName, string $token): bool
    {
        $ip = self::clientIpForBinding($request);
        $ua = trim((string) $request->userAgent());
        $cacheKey = 'pteroprotect:clearance:check:' . hash('sha256', $cookieName . '|' . $token . '|' . $ip . '|' . $ua);

        return (bool) Cache::remember($cacheKey, 30, function () use ($request, $cookieName, $token): bool {
            [$bind, $port] = self::challengeEndpoint();
            if ($bind === '' || $port <= 0) {
                return false;
            }

            $host = ($bind === '0.0.0.0') ? '127.0.0.1' : $bind;
            $host = trim($host);
            if ($host === '') {
                return false;
            }

            $fp = @fsockopen($host, $port, $errno, $errstr, 0.2);
            if (!is_resource($fp)) {
                return false;
            }

            try {
                stream_set_timeout($fp, 0, 250000);
                $ip = trim((string) $request->ip());
                $ua = trim((string) $request->userAgent());
                $cookieHeader = $cookieName . '=' . $token;
                $raw = "GET /check HTTP/1.1\r\n"
                    . "Host: {$host}\r\n"
                    . "Connection: close\r\n"
                    . "User-Agent: " . ($ua !== '' ? $ua : 'PteroProtectClearanceToken/1.0') . "\r\n"
                    . "Cookie: {$cookieHeader}\r\n"
                    . ($ip !== '' ? "X-Forwarded-For: {$ip}\r\n" : '')
                    . "\r\n";

                fwrite($fp, $raw);
                $line = fgets($fp, 256);
                if (!is_string($line) || $line === '') {
                    return false;
                }

                return preg_match('#^HTTP/\d\.\d\s+204\b#', $line) === 1;
            } finally {
                fclose($fp);
            }
        });
    }

    private static function sessionIpAllowed(string $sid, string $uaClaim, string $tokenIp, string $requestIp, int $exp): bool
    {
        $tokenIp = strtolower(trim($tokenIp));
        $requestIp = strtolower(trim($requestIp));
        if ($requestIp === '' || filter_var($requestIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($tokenIp === '' || filter_var($tokenIp, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ttl = max(1, min(86400, $exp - time()));
        $cacheKey = 'pteroprotect:clearance:ips:' . hash('sha256', $sid . '|' . $uaClaim);
        $ips = Cache::get($cacheKey, []);
        if (!is_array($ips)) {
            $ips = [];
        }

        $ips[] = $tokenIp;
        $ips = array_values(array_unique(array_filter(array_map(static function ($ip): string {
            $ip = strtolower(trim((string) $ip));
            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
        }, $ips))));

        if (in_array($requestIp, $ips, true)) {
            Cache::put($cacheKey, $ips, $ttl);
            return true;
        }

        if (count($ips) >= 5) {
            Cache::put($cacheKey, $ips, $ttl);
            return false;
        }

        $ips[] = $requestIp;
        Cache::put($cacheKey, $ips, $ttl);

        return true;
    }

    private static function clientIpForBinding(Request $request): string
    {
        // Prefer explicit edge headers first for real client IP.
        $cfConnectingIp = strtolower(trim((string) $request->headers->get('CF-Connecting-IP', '')));
        if ($cfConnectingIp !== '' && filter_var($cfConnectingIp, FILTER_VALIDATE_IP) !== false) {
            return $cfConnectingIp;
        }

        $xRealIp = strtolower(trim((string) $request->headers->get('X-Real-IP', '')));
        if ($xRealIp !== '' && filter_var($xRealIp, FILTER_VALIDATE_IP) !== false) {
            return $xRealIp;
        }

        $xForwardedFor = trim((string) $request->headers->get('X-Forwarded-For', ''));
        if ($xForwardedFor !== '') {
            foreach (explode(',', $xForwardedFor) as $part) {
                $candidate = strtolower(trim($part));
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        // Fallback to framework-resolved client IP.
        $requestIp = strtolower(trim((string) $request->ip()));
        if ($requestIp !== '' && filter_var($requestIp, FILTER_VALIDATE_IP) !== false) {
            return $requestIp;
        }

        // Last resort: socket peer.
        $remoteAddr = strtolower(trim((string) $request->server('REMOTE_ADDR', '')));
        if ($remoteAddr !== '' && filter_var($remoteAddr, FILTER_VALIDATE_IP) !== false) {
            return $remoteAddr;
        }

        return '';
    }

    private static function challengeSecret(): string
    {
        $env = trim((string) env('PTEROPROTECT_WAF_CHALLENGE_SECRET', ''));
        if ($env !== '') {
            return $env;
        }

        $network = self::networkConfig();
        return trim((string) ($network['waf_challenge_secret'] ?? ''));
    }

    private static function challengeEndpoint(): array
    {
        $network = self::networkConfig();
        $bind = trim((string) ($network['waf_challenge_bind'] ?? '127.0.0.1'));
        $port = (int) ($network['waf_challenge_port'] ?? 18444);
        if ($port <= 0 || $port > 65535) {
            $port = 18444;
        }

        return [$bind, $port];
    }

    private static function networkConfig(): array
    {
        static $cached = null;
        if (is_array($cached)) {
            return $cached;
        }

        $paths = array_values(array_unique(array_filter([
            (string) env('PTEROPROTECT_CONFIG_PATH', '/pteroprotect/config.json'),
            '/pteroprotect/config.json',
            '/root/porn/config.json',
            base_path('config.json'),
        ])));

        foreach ($paths as $path) {
            try {
                if (!File::exists($path) || !File::isReadable($path)) {
                    continue;
                }
                $raw = File::get($path);
                $json = json_decode($raw, true);
                if (is_array($json) && is_array($json['network'] ?? null)) {
                    $cached = $json['network'];
                    return $cached;
                }
            } catch (\Throwable) {
                // Try next path.
            }
        }

        $cached = [];
        return $cached;
    }
}
