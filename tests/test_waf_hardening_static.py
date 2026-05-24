#!/usr/bin/env python3
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def assert_contains(text: str, needle: str, message: str) -> None:
    if needle not in text:
        raise AssertionError(message)


def assert_not_contains(text: str, needle: str, message: str) -> None:
    if needle in text:
        raise AssertionError(message)


def main() -> int:
    waf = (ROOT / "panel_overrides/app/Http/Middleware/Security/PteroProtectWaf.php").read_text(encoding="utf-8")
    cfg = (ROOT / "panel_overrides/config/pteroprotect.php").read_text(encoding="utf-8")
    nginx = (ROOT / "host_overrides/nginx/snippets/pteroprotect_server.conf").read_text(encoding="utf-8")
    site = (ROOT / "host_overrides/nginx/sites-available/pterodactyl.conf").read_text(encoding="utf-8")
    restricted_admin = (ROOT / "panel_overrides/app/Http/Middleware/Security/PteroProtectRestrictedAdmin.php").read_text(encoding="utf-8")
    create_server = (ROOT / "panel_overrides/app/Http/Controllers/Admin/Servers/CreateServerController.php").read_text(encoding="utf-8")
    self_heal = (ROOT / "scripts/self_heal_monitor.py").read_text(encoding="utf-8")
    protect_controller = (ROOT / "panel_overrides/app/Http/Controllers/Admin/ProtectController.php").read_text(encoding="utf-8")
    protect_index = (ROOT / "panel_overrides/resources/views/admin/protect/index.blade.php").read_text(encoding="utf-8")
    example_config = (ROOT / "config.example.json").read_text(encoding="utf-8")
    realtime_hook = (ROOT / "panel_overrides/resources/scripts/plugins/useWafRealtime.ts").read_text(encoding="utf-8")
    public_chat = (ROOT / "panel_overrides/app/Http/Controllers/Api/Client/PublicChatController.php").read_text(encoding="utf-8")
    public_chat_api = (ROOT / "panel_overrides/resources/scripts/api/chat/publicChat.ts").read_text(encoding="utf-8")
    public_chat_panel = (ROOT / "panel_overrides/resources/scripts/components/dashboard/chat/PublicChatPanel.tsx").read_text(encoding="utf-8")

    assert_contains(waf, "isAllowedMethod($request, $config)", "WAF must reject disallowed HTTP methods")
    assert_contains(waf, "hasExcessiveHeaders($request, $config)", "WAF must bound header count and size")
    assert_contains(waf, "hasExcessiveCookies($request, $config)", "WAF must bound cookie count and size")
    assert_contains(waf, "isRateLimitedBySubject($request, $category", "WAF must include subject/session rate buckets")
    assert_contains(waf, "PteroProtectClearanceToken::isValid", "subject buckets must only trust valid clearance tokens")
    assert_contains(waf, "logStructuredDecision($config", "WAF must emit structured decision telemetry")
    assert_contains(waf, "logRateLimitEvent($resilienceConfig, 'subject'", "WAF must emit subject rate-limit telemetry")
    assert_contains(waf, "logRateLimitEvent($resilienceConfig, 'fingerprint_cluster'", "WAF must emit fingerprint rate-limit telemetry")
    assert_contains(waf, "isLightweightHydrationPath($path)", "WAF must avoid shedding lightweight panel hydration endpoints")
    assert_contains(waf, "isRecoverySafePanelPath($request, $path)", "WAF must avoid hard 503 on safe panel reads during dependency recovery")
    assert_contains(waf, "isRecoverySafeChatPath($request, $path)", "WAF must keep safe chat bootstrap endpoints available during recovery")
    assert_contains(waf, "api/client/ads", "ads hydration endpoint must remain available during WAF recovery")
    assert_contains(waf, "api/client/(?:rum|ads)", "RUM and ads hydration must remain fail-soft during WAF recovery")
    assert_contains(waf, "api/client/waf/(?:stats|timeline|threats)", "WAF dashboard reads must remain fail-soft during dependency recovery")
    assert_contains(waf, "api/client/servers/[^/]+/(?:resources|activity|websocket)", "server polling reads must remain fail-soft during Wings recovery")
    assert_contains(waf, "api/client/chat/notifications", "chat notification polling must remain available during WAF recovery")
    assert_contains(waf, "api/client/servers/[^/]+/websocket", "websocket metadata endpoint must be treated as core GET flow")
    assert_contains(nginx, "Browser panel websocket metadata uses Laravel session + CSRF", "nginx must not require bearer API tokens for UI websocket metadata")
    assert_contains(nginx, "Browser dashboard polling uses session auth", "nginx must not require bearer API tokens for UI resource polling")
    assert_contains(nginx, "location ^~ /api/client/chat/", "nginx must keep explicit chat UI lane")
    assert_contains(nginx, "location = /api/client/chat/conversations", "nginx must keep chat conversations bootstrap out of brownout hard 503")
    assert_contains(nginx, "location = /api/client/chat/presence", "nginx must keep chat presence heartbeat out of brownout hard 503")
    assert_contains(nginx, "location = /api/client/chat/messages", "nginx must keep chat message reads out of brownout hard 503")
    assert_contains(
        nginx,
        "location @pteroprotect_challenge_fail_open",
        "nginx auth_request fallback must not turn challenge upstream errors into browser 500s",
    )
    assert_contains(
        nginx,
        "error_page 500 502 503 504 = @pteroprotect_challenge_fail_open;",
        "challenge auth subrequests must fail open on local challenge outages instead of returning invalid auth_request 503",
    )
    assert_not_contains(
        nginx,
        "location ^~ /api/client/chat/ {\n    auth_request /__pteroprotect/challenge/check_provider_api;",
        "chat UI lane must use session challenge, not provider bearer API gate",
    )
    assert_not_contains(
        nginx,
        "auth_request /__pteroprotect/challenge/check_web;\n    error_page 401 = @pteroprotect_challenge_redirect;\n    error_page 403 = @pteroprotect_provider_web_block;",
        "web/session API lanes must not translate generic web 403s into provider bearer-token blocks",
    )
    assert_not_contains(
        site,
        "auth_request /__pteroprotect/challenge/check_web;\n        error_page 401 = @pteroprotect_challenge_redirect;\n        error_page 403 = @pteroprotect_provider_web_block;",
        "main browser route must not translate generic web 403s into provider bearer-token blocks",
    )
    assert_contains(
        nginx,
        "location = /admin/servers/new",
        "create-server UI route needs an exact nginx lane for CRS false positives on startup scripts",
    )
    assert_contains(
        nginx,
        "SecRuleRemoveById 930120 932100 932130 932140 932150",
        "create-server UI route must remove only the CRS rules that false-positive on Pterodactyl startup scripts",
    )
    assert_contains(
        nginx,
        "location = /admin/servers/new {\n    auth_request /__pteroprotect/challenge/check_web;",
        "create-server UI route must remain behind the browser challenge gate",
    )
    assert_not_contains(
        restricted_admin,
        "$path === 'admin/servers/new'",
        "browser admin create-server submissions must use Laravel form validation, not WAF forbidden guards",
    )
    assert_not_contains(
        restricted_admin,
        "if ($ownerId === 1)",
        "browser admin create-server must not reject primary-owner submissions before Laravel permissions run",
    )
    assert_not_contains(
        create_server,
        "$ownerId === 1",
        "browser admin create-server controller must not require API-style ownership restrictions",
    )
    assert_not_contains(
        restricted_admin + create_server,
        "Invalid server owner.",
        "browser admin create-server must not turn owner validation into a 403 Forbidden response",
    )
    assert_contains(
        self_heal,
        "external_check_min_interval_sec",
        "self-heal monitor must rate-limit external mywebcheck calls",
    )
    assert_contains(
        self_heal,
        "cached:{int(ts - last_checkhost_ts)}s",
        "self-heal monitor must reuse recent external check results between external probes",
    )
    assert_contains(
        self_heal,
        "def origin_probe(",
        "self-heal monitor must probe the origin directly instead of live-polling public edge every loop",
    )
    assert_contains(
        protect_controller,
        "selfHealSnapshot",
        "protect controller must expose the last health snapshot to Blade",
    )
    assert_contains(
        protect_index,
        "Web Check Snapshot",
        "protect Blade must show cached web-check status without browser live polling",
    )
    assert_contains(
        example_config,
        '"external_check_min_interval_sec": 60',
        "example config must document external web-check rate limiting",
    )
    assert_contains(
        realtime_hook,
        "__PTEROPROTECT_WAF_LIVE_WS__",
        "WAF realtime websocket must be feature-gated until a /waf/live backend is deployed",
    )
    assert_contains(
        realtime_hook,
        "setConnected(false);",
        "WAF realtime hook must fail closed to polling mode without websocket console spam",
    )
    assert_contains(public_chat, "private const DEFAULT_LIMIT = 8;", "chat API must default to an 8-message window")
    assert_contains(public_chat, "'before_id' => 'sometimes|integer|min:1'", "chat API must support older-message cursor pagination")
    assert_contains(public_chat_api, "getPublicMessagePage", "chat frontend API must expose paged message metadata")
    assert_contains(public_chat_panel, "const CHAT_PAGE_SIZE = 8;", "chat panel must request small message batches")
    assert_contains(public_chat_panel, "const CHAT_LIVE_BUFFER = 8;", "chat panel must keep live incoming render buffer small")

    for key in (
        "allowed_methods",
        "max_header_count",
        "max_cookie_bytes",
        "subject_limit_enabled",
        "websocket_subject_limit",
        "structured_log_file",
    ):
        assert_contains(cfg, key, f"config must expose {key}")

    assert_contains(nginx, "if ($request_method = TRACE) { return 444; }", "nginx must drop TRACE")
    assert_contains(nginx, "client_header_timeout 8s;", "nginx must bound slow header reads")

    print("waf hardening static tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
