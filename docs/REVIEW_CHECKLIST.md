# PteroProtect Review Checklist

This checklist is a required quality gate for security-sensitive changes. Reviewers should ask for evidence, not intent. Evidence can be a file path, test path, command output, or a written reason when a check is not applicable.

## Review Standard
- Design: the change belongs in this layer and does not duplicate a better existing control.
- Functionality: the change works for real panel, Wings, nginx, systemd, and host firewall paths.
- Complexity: the code is the smallest understandable solution for the current requirement.
- Tests: every behavior change has a unit, integration, corpus, smoke, or explicit manual verification.
- Naming: names describe purpose, scope, and risk without hiding side effects.
- Comments: comments explain why, not what; complex regex and security tradeoffs are documented.
- Style: follow local shell, Python, C++, PHP, TypeScript, nginx, and systemd conventions.
- Consistency: new behavior matches surrounding code unless the old pattern is unsafe.
- Documentation: user-visible or operator-visible changes update docs or runbooks.
- Every line: human-written security code must be reviewed in full context, not only the diff hunk.

## Required Evidence
- Changed files:
- Tests or validation commands:
- Security impact:
- False-positive impact:
- Rollback path:
- Residual risk:

## Security Questions
- Authentication: does this depend on a cookie, bearer token, daemon token, HMAC, or session value? Is it validated fail-closed?
- Authorization: can delegated admins, normal users, nodes, or local services reach more than intended?
- Session binding: does IP/UA/session binding tolerate mobile/CDN reality without granting shared-NAT bypass?
- CSRF and replay: can a browser, stale token, or replayed ticket trigger state changes?
- Input validation: are paths, IPs, CIDRs, ports, domains, and JSON fields parsed with strict bounds?
- File paths: can symlinks, `..`, encoded separators, double slashes, or absolute paths escape the intended root?
- Command execution: are command names allowlisted and arguments quoted or passed without shell expansion?
- SSRF and egress: can users force requests to metadata, localhost, private ranges, or Wings internals?
- Secrets: are tokens high entropy, redacted in logs, excluded from docs, and rotated when exposed?
- Logging: are logs useful for audit without storing passwords, tokens, cookies, private keys, or full bearer values?
- Rate limits: are limits keyed on the right identity under CDN, proxy, CGNAT, IPv6, and direct-origin traffic?
- Fail-closed behavior: if challenge, DB, Redis, nginx, fail2ban, or ipset fails, what is allowed?
- Destructive actions: can suspend, kill, delete, move, reboot, or firewall actions be triggered by noisy signals?
- Rollback: can an operator undo block, quarantine, owner lock, feature shedding, lockdown, or brownout?

## Pterodactyl-Specific Surfaces
- Panel overrides: route/middleware order is explicit and verified against real panel paths.
- Wings API: `/api/remote/` and node daemon paths have only the minimum required bypasses.
- Node auto-config: remote scripts are deterministic, logged, and do not leak bootstrap keys.
- Daemon file operations: file manager and archive operations are rate-limited and path-safe.
- Admin routes: primary-admin-only operations require fresh verification and audit.
- Client server routes: normal polling, websocket, resources, files, console, chat, ads, and RUM do not trigger false positives.
- Public endpoints: login, register, challenge, RUM, ads, chat, and create-panel have independent abuse controls.

## Host Protection Questions
- nginx: rate zones exist, maps compile, ModSecurity exclusions are narrow, and challenge auth_request paths are reachable.
- fail2ban: filters match malicious samples and ignore benign samples; bantime/maxretry are explicit.
- iptables/ipset: rule order is idempotent and local/container ACCEPT rules are intentional.
- IPv6: every IPv4 protection has an IPv6 decision: implemented, intentionally skipped, or documented.
- systemd: service user, capabilities, writable paths, and sandbox exceptions are justified.

## Test Requirements
- Benign corpus must include normal panel polling, websocket, Wings API, chat, RUM, file manager, admin, and install flows.
- Malicious corpus must include double-slash paths, encoded paths, token spray, probe scans, SQLi, proxy swarm, SSRF, and private-key leaks.
- Regression tests must cover previously fixed bugs: Wings certificate selection, DDoS logger log readability, path normalization, high-confidence whitelist override, setup idempotency.
- Tests must fail when the protected behavior is broken and must not require production secrets.

## Merge Gate
- No new high-risk behavior without tests or a documented emergency exception.
- No live secret in source, docs, tests, fixtures, or generated review output.
- No destructive enforcement from a single noisy signal.
- No broad bypass without route inventory and explicit reason.
- No setup change without idempotency and post-install smoke validation.
