# Security Review Record

This file records security review scope, findings, and follow-up status. It is not a replacement for tests or the review checklist.

## Scope
- Included: `src/`, `include/`, `scripts/`, `systemd/`, `host_overrides/`, `panel_overrides/`, `setup.sh`, `check.sh`, `docs/`, `tests/`.
- Excluded by default: `.git/`, `.codespaces/`, `backups/`, generated assets, binary build outputs, caches.

## Current Review
- Date: 2026-05-17
- Reviewer: OpenCode plus subagent critique
- Branch/commit: record before merge
- Commands run:
  - `bash setup.sh`
  - `nginx -t`
  - `fail2ban-client -t`
  - `bash check.sh`
  - `scripts/validate_all.sh` after this foundation exists

## Finding Format
- ID: `SEC-###`
- Severity: Critical, High, Medium, Low
- Status: Open, In Progress, Fixed, Accepted Risk, Not Reproducible
- Affected paths:
- Threat actor:
- Exploitability:
- Impact:
- False-positive risk:
- Required fix:
- Verification:
- Owner:

## Findings

### SEC-001 Break-Glass Terminal Blast Radius
- Severity: Critical
- Status: Open
- Affected paths: `scripts/pteroprotect_terminal_helper.py`, `systemd/pteroprotect-terminal.service`, `panel_overrides/app/Http/Controllers/Admin/ProtectController.php`
- Threat actor: stolen admin token, compromised browser, local attacker
- Exploitability: high if terminal ticket or RCE unlock key leaks
- Impact: root shell on host
- False-positive risk: not applicable; this is privilege/blast-radius risk
- Required fix: one-time tickets, IP/UA binding, short TTL, replay cache, tighter systemd sandbox, tests
- Verification: terminal replay/IP/UA/expiry tests and systemd security check

### SEC-002 Provider Gate Degraded When CIDR Cache Is Empty
- Severity: High
- Status: Open
- Affected paths: `src/challenge_guard.cpp`, `scripts/bootstrap_provider_cache.py`, `host_overrides/nginx/conf.d/pteroprotect_provider_gate.conf`, `config.example.json`
- Threat actor: cloud/VPS attacker
- Exploitability: medium when provider ranges are empty/stale
- Impact: provider-origin traffic may avoid intended token-only policy
- False-positive risk: overly broad CIDRs can challenge residential users
- Required fix: explicit degraded health state, freshness checks, provider-gate tests
- Verification: IPv4/IPv6 provider/residential/empty/stale cache tests

### SEC-003 Destructive Tenant Quarantine Needs Evidence Gate
- Severity: High
- Status: Open
- Affected paths: `scripts/ddos_host_logger.sh`, `src/disk_protect.cpp`, `src/resource_monitor.cpp`, panel quarantine UI
- Threat actor: noisy benign tenant, attacker inducing victim traffic, false-positive detector
- Exploitability: medium
- Impact: legitimate server suspend/kill/quarantine/delete
- False-positive risk: high without multi-signal evidence
- Required fix: dry-run/evidence score, rollback path, benign corpus
- Verification: benign/malicious corpus and rollback tests

### SEC-004 CDN/Real-IP Misconfiguration Risk
- Severity: High
- Status: Open
- Affected paths: `host_overrides/nginx/conf.d/pteroprotect_realip.conf`, `src/challenge_guard.cpp`, WAF middleware, rate limits
- Threat actor: spoofed XFF attacker, CDN/CGNAT users
- Exploitability: medium
- Impact: bypass or mass false-positive blocks
- False-positive risk: high under CDN/CGNAT
- Required fix: trust matrix tests and explicit configuration health warnings
- Verification: direct/CDN/spoof/private/missing-proxy tests

## Reviewed With No Finding
- Wings guard certificate selection fixed and validated for `nodes.el7.web.id` on 2026-05-17.
- DDoS logger systemd log-readability fixed and validated active after setup on 2026-05-17.

## Not Yet Reviewed
- Full frontend social/chat/DanexCoin feature set.
- Complete line-by-line review of every panel override controller and React component.
- Full destructive quarantine rollback behavior.
