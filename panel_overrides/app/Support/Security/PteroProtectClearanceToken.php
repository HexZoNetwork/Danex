<?php

namespace Pterodactyl\Support\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class PteroProtectClearanceToken
{
    public static function isValid(Request $request, ?string $token, ?string $cookieName = null): bool
    {
        return (bool) self::validate($request, $token, $cookieName)['ok'];
    }

    /**
     * @return array{ok:bool,reason:string,payload:array<string,mixed>|null}
     */
    public static function validate(Request $request, ?string $token, ?string $cookieName = null): array
    {
        $token = trim((string) $token);
        if ($token === '') {
            return self::result(false, 'missing_cookie');
        }

        [$payloadRaw, $sig] = self::splitToken($token);
        if ($payloadRaw === null || $sig === null) {
            return self::result(false, 'token_format');
        }

        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            return self::result(false, 'token_payload');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        $ipClaim = strtolower(trim((string) ($payload['ip'] ?? '')));
        $prefixClaim = strtolower(trim((string) (
            $payload['ip_prefix'] ?? ($payload['net_prefix_v4'] ?? ($payload['net_prefix_v6'] ?? ''))
        )));
        $uaClaim = trim((string) ($payload['ua_fp'] ?? ($payload['ua'] ?? '')));
        $sid = trim((string) ($payload['sid'] ?? ''));

        if ($exp <= time()) {
            return self::result(false, 'expired', $payload);
        }
        if ($sid === '' || ($ipClaim === '' && $prefixClaim === '') || $uaClaim === '') {
            return self::result(false, 'claims_missing', $payload);
        }

        $requestUaFp = self::userAgentFingerprint((string) $request->userAgent());
        if ($requestUaFp === '' || !hash_equals($uaClaim, $requestUaFp)) {
            return self::result(false, 'ua_mismatch', $payload);
        }

        $requestIp = self::clientIpForBinding($request);
        $secret = self::challengeSecret();

        if ($secret !== '') {
            $expectedSig = self::base64urlEncode(hash_hmac('sha256', $payloadRaw, $secret, true));
            if (!hash_equals($expectedSig, $sig)) {
                return self::result(false, 'invalid_signature', $payload);
            }

            $sidFpClaim = trim((string) ($payload['sid_fp'] ?? ''));
            if ($sidFpClaim !== '') {
                $requestSidFp = self::requestSessionFingerprint($request, $secret);
                if ($requestSidFp === '' || !hash_equals($sidFpClaim, $requestSidFp)) {
                    return self::result(false, 'session_mismatch', $payload);
                }
            }

            if ($prefixClaim !== '') {
                $requestPrefix = self::ipPrefixForBinding($requestIp);
                if ($requestPrefix === '' || !hash_equals($prefixClaim, $requestPrefix)) {
                    return self::result(false, 'ip_mismatch', $payload);
                }

                self::rememberSessionIp($sid, $uaClaim, $requestIp, $exp);
                return self::result(true, 'ok', $payload);
            }

            return self::sessionIpAllowed($sid, $uaClaim, $ipClaim, $requestIp, $exp)
                ? self::result(true, 'ok', $payload)
                : self::result(false, 'ip_mismatch', $payload);
        }

        // Fallback: ask challenge_guard directly when secret isn't locally available.
        $cookie = $cookieName ?: self::cookieName();
        return self::verifyViaChallengeGuard($request, $cookie, $token)
            ? self::result(true, 'ok', $payload)
            : self::result(false, 'guard_rejected', $payload);
    }

    /**
     * @return array{ok:bool,reason:string,payload:array<string,mixed>|null}
     */
    private static function result(bool $ok, string $reason, ?array $payload = null): array
    {
        return ['ok' => $ok, 'reason' => $reason, 'payload' => $payload];
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

    public static function userAgentFingerprint(string $uaRaw): string
    {
        return self::sha256Hex24(self::uaBindingMaterial($uaRaw));
    }

    public static function uaBindingMaterial(string $uaRaw): string
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

    public static function sha256Hex24(string $text): string
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
                $rawCookieHeader = trim((string) $request->headers->get('Cookie', ''));
                $cookieHeader = $rawCookieHeader !== '' ? $rawCookieHeader : ($cookieName . '=' . $token);
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

        return hash_equals($tokenIp, $requestIp);
    }

    private static function rememberSessionIp(string $sid, string $uaClaim, string $requestIp, int $exp): void
    {
        $requestIp = strtolower(trim($requestIp));
        if ($requestIp === '' || filter_var($requestIp, FILTER_VALIDATE_IP) === false) {
            return;
        }

        $ttl = max(1, min(86400, $exp - time()));
        $cacheKey = 'pteroprotect:clearance:ips:' . hash('sha256', $sid . '|' . $uaClaim);
        $ips = Cache::get($cacheKey, []);
        if (!is_array($ips)) {
            $ips = [];
        }

        $ips[] = $requestIp;
        $ips = array_slice(array_values(array_unique(array_filter(array_map(static function ($ip): string {
            $ip = strtolower(trim((string) $ip));
            return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '';
        }, $ips)))), -5);

        Cache::put($cacheKey, $ips, $ttl);
    }

    public static function clientIpForBinding(Request $request): string
    {
        // TrustProxies validates whether forwarded headers came from configured proxies.
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

    public static function ipPrefixForBinding(string $ip): string
    {
        $ip = strtolower(trim($ip));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $packed = @inet_pton($ip);
            if ($packed === false || strlen($packed) !== 4) {
                return '';
            }
            $parts = unpack('N', $packed);
            $value = (int) ($parts[1] ?? 0);
            $bits = self::networkPrefixBits('session_ip_prefix_v4', 24, 0, 32);
            $mask = $bits <= 0 ? 0 : ($bits >= 32 ? 0xFFFFFFFF : ((0xFFFFFFFF << (32 - $bits)) & 0xFFFFFFFF));
            return 'v4:' . $bits . ':' . (string) ($value & $mask);
        }

        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return '';
        }
        $bits = self::networkPrefixBits('session_ip_prefix_v6', 48, 0, 128);
        $bytes = intdiv($bits, 8);
        $rem = $bits % 8;
        $out = '';
        for ($i = 0; $i < $bytes; $i++) {
            $out .= sprintf('%02x', ord($packed[$i]));
        }
        if ($rem > 0 && $bytes < 16) {
            $out .= sprintf('%02x', ord($packed[$bytes]) & (0xFF << (8 - $rem)));
        }

        return 'v6:' . $bits . ':' . $out;
    }

    public static function requestSessionFingerprint(Request $request, ?string $secret = null): string
    {
        $secret = trim((string) ($secret ?? self::challengeSecret()));
        if ($secret === '') {
            return '';
        }

        $rawSessionCookie = self::rawCookieValue($request, 'pterodactyl_session');
        if ($rawSessionCookie === '') {
            return '';
        }

        return self::base64urlEncode(hash_hmac('sha256', 'sid:' . $rawSessionCookie, $secret, true));
    }

    public static function rawCookieValue(Request $request, string $name): string
    {
        $cookieHeader = (string) $request->headers->get('Cookie', '');
        foreach (explode(';', $cookieHeader) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            if ($eq === false) {
                continue;
            }
            if (trim(substr($part, 0, $eq)) === $name) {
                return trim(substr($part, $eq + 1));
            }
        }

        return trim((string) $request->cookie($name, ''));
    }

    private static function networkPrefixBits(string $key, int $default, int $min, int $max): int
    {
        $network = self::networkConfig();
        $bits = (int) ($network[$key] ?? $default);
        return max($min, min($max, $bits));
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
