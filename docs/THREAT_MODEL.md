# PteroProtect Threat Model

Motto: selamatkan tanpa perak, hidupkan tanpa emas. Protection must keep the panel alive without punishing legitimate users or requiring expensive infrastructure by default.

## Scope
This model covers the PteroProtect repo, native daemons, host scripts, nginx/fail2ban/firewall integration, systemd units, and Pterodactyl panel overrides. It excludes upstream Pterodactyl vulnerabilities unless this repo changes their exposure.

## Assets
- Live secrets: `/pteroprotect/config.json`, panel `.env`, Telegram tokens, PTLC keys, daemon tokens, challenge secrets, unblock tokens, RCE/terminal keys.
- User/session material: Laravel sessions, `pp_clearance` cookies, API keys, websocket tokens.
- Server data: Pterodactyl volumes, backups, file manager data, console streams, resource telemetry.
- Host controls: systemd units, nginx config, ModSecurity rules, fail2ban jails, iptables/ipset state.
- Runtime state: `/dev/shm/pteroprotect/*`, `/pteroprotect/runtime/*`, brownout/lockdown/mode/resilience files.
- Audit data: nginx logs, WAF logs, DDoS logs, quarantine records, admin action logs.

## Trust Boundaries
- Internet to nginx: untrusted clients, bots, proxies, CDN, direct-origin traffic.
- nginx to challenge guard: localhost auth_request boundary at `127.0.0.1:18444`.
- nginx to Laravel panel: PHP-FPM and panel middleware boundary.
- Panel to Wings: daemon auth over node FQDN and Wings guard port `8080`.
- Panel to database/cache: MySQL/Redis credentials and local service boundary.
- Panel to host controls: sudo/adminctl/systemd and break-glass terminal boundary.
- Host scripts to kernel firewall: root capability boundary for iptables/ipset/sysctl.
- Node auto-config to remote hosts: SSH key, script, and remote root boundary.

## Actors
- Unauthenticated internet attacker: scans, floods, probes, SQLi, token spray, challenge bypass.
- Authenticated user: abuses file manager, console, websocket, chat, RUM, create-panel, server resources.
- Compromised user account: tries lateral movement to other users/servers/admin routes.
- Malicious delegated admin: abuses ownership gaps or restricted admin bypasses.
- Compromised node/Wings token: attacks panel remote API and daemon trust assumptions.
- Local low-privilege process/container: tries localhost services, firewall bypass, log/state poisoning.
- Misconfigured proxy/CDN: creates false positives or lets spoofed identity through.

## Primary Threats
- Token theft or weak defaults allow control-plane, challenge, unblock, RCE, or panel API bypass.
- Shared IP/CDN/CGNAT causes false positive blocks if rate keys collapse incorrectly.
- Spoofed `X-Forwarded-For` causes bypass if trusted proxy config is wrong.
- `/api/remote/` and Wings paths become an attacker focus because they need narrower challenge bypass.
- Broad ModSecurity exclusions allow payloads in startup/env/file fields.
- Noisy heuristics suspend, kill, or quarantine legitimate tenants.
- IPv6 traffic avoids IPv4-first protections.
- Root helper services become full host compromise if tickets or local access controls fail.
- Installer drift breaks Wings certificate, systemd sandbox, nginx zones, or fail2ban validity.

## False-Positive Policy
- Destructive actions require multiple independent signals or an explicit high-confidence reason.
- Recent successful auth and IP trust reduce noisy overload actions only; they must not bypass high-confidence attack signals.
- Local, host, and private IP protections must be explicit and logged.
- Benign high-volume panel behavior must be in corpus: resource polling, websocket, Wings remote API, chat, RUM, file manager, admin operations.
- Every manual unblock is a data point. Track reason, source, and whether a rule needs tuning.

## Non-Goals
- Absorbing unlimited volumetric DDoS without upstream capacity.
- Replacing all upstream Pterodactyl authorization checks.
- Perfect bot detection without false positives.
- Formal verification of every shell/nginx/firewall interaction.

## Required Assumptions
- nginx is the public front door for panel and Wings guard traffic.
- localhost-only services are not externally exposed.
- systemd units run with the checked-in users, groups, capabilities, and writable paths.
- live secrets are high entropy and not committed to source.
- CDN/proxy CIDRs are configured before trusting forwarded headers.
- installer can be rerun idempotently without weakening existing protections.

## Residual Risks To Track
- Break-glass terminal remains high impact even with ticket controls.
- Provider CIDR quality and freshness determine cloud/VPS challenge policy accuracy.
- ModSecurity exclusions trade fewer false positives for narrower coverage.
- Quarantine and owner-lock mechanisms need rollback and evidence scoring before broad rollout.
