# PteroProtect Security Findings (CWE Mapping)

Date: 2026-11-12

Scope: `/home/hex/Secur` (PteroProtect): C++ daemons, Python sidecars, shell installers, systemd units, nginx/fail2ban overrides, Laravel panel overrides, frontend source, tests, and example configuration.

Method: 13 parallel static-review agents covering every component, followed by manual verification and a source-fix pass. Findings below include both confirmed defects and defense-in-depth gaps. Line numbers are omitted because several files changed during remediation; function and file names are stable.

Status legend:

- **Fixed**: source changed and covered by syntax/static tests where available.
- **Fixed (C++ unbuilt)**: source changed, but this environment lacks `nlohmann/json.hpp` and `mysql/mysql.h`, so compilation and binary tests were not possible.
- **Partial**: the primary issue is addressed, but a related gap remains.
- **Residual**: confirmed issue not remediated in this pass.
- **Note**: compatibility or false-positive trade-off, not necessarily a vulnerability.

Build and integration limitations:

- `make` fails before compiling PteroProtect code because `nlohmann/json.hpp` and `mysql/mysql.h` are absent.
- `scripts/validate_all.sh` passes shell, Python, and PHP stages; systemd verification fails only because `/pteroprotect/*` binaries are not installed in this container.
- `panel_overrides/node_modules` is absent, so `tsc` and the frontend production build were not run. The `UploadButton.tsx` fix is source-only until `yarn build:production` runs on a real panel host.
- No live Pterodactyl/Wings integration environment was available, so authz and challenge behavior were verified by code review and static tests, not end-to-end.

## Executive Summary

The highest-risk confirmed issues were:

1. Newline-containing filenames could inject records into disk-accounting parsing and cause deletion of unintended root-owned paths.
2. nginx challenge failure handling was fail-open, and direct `/index.php` access bypassed the challenge in some server blocks.
3. WAF middleware contained broad `/api/application` and static-asset bypasses.
4. The emergency root terminal panel defaulted to an unrestricted bind address and accepted weak tokens.
5. The upload proxy disabled TLS certificate verification.
6. The terminal ticket directory was group-writable by `www-data`, creating a local privilege-escalation path to a root PTY.
7. Provider-token gating and the WAF replay queue were not wired into the actual request lanes.
8. Several destructive panel actions (cleanup job, madeinweb delete-on-suspend) could remove servers without sufficient markers or confirmation.
9. Real-looking Telegram and database credentials were committed under `.codespaces`.

Most of these are fixed in source. Items 6, 7, and parts of 2/3 remain residual because they require architectural changes beyond a patch.

## A. Injection and Output Encoding

### PP-001 - Newline filename injection in disk accounting [CWE-78, CWE-88]
- Severity: Critical
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`get_top_disk_sources`, `emergency_delete_largest_files`, `reclaim_largest_entries`)
- Finding: `du`/`find` output was parsed line-by-line. A filename containing `\n` could create additional apparent records, including a forged size/path pair.
- Impact: An unprivileged server owner could influence which root-owned path the emergency cleanup selected, potentially deleting arbitrary files outside the server volume.
- Fix: Switched to NUL-delimited records (`find -print0`, `getdelim`), added `read_nul_record`, `parse_size_path_record`, `is_safe_fs_path_token`, and `path_within_root` realpath containment.

### PP-002 - Reflected XSS and open redirect through challenge `rd` parameter [CWE-79, CWE-601]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/challenge_guard.cpp` (challenge page generation and `/solve`)
- Finding: The `rd` redirect value was embedded into HTML/JavaScript without sufficient validation.
- Impact: An attacker could execute script in the challenge origin or redirect users to an attacker-controlled site after solving.
- Fix: Added `safe_redirect_path` (same-origin relative paths only) and `js_single_quote_literal`; applied both in page generation and `/solve`.

### PP-003 - Telegram HTML injection through filenames and owner fields [CWE-116]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`rakit_laporan_server`, `get_top_disk_sources`)
- Finding: File names, reasons, owner name, username, email, and biggest-path labels were inserted into Telegram HTML without escaping.
- Impact: A crafted filename or display name could break message formatting, spoof report content, or inject links/mentions into operator notifications.
- Fix: Added `html_escape` and applied it to all externally derived report fields.

### PP-004 - Log injection and terminal escape injection [CWE-117]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/logger.cpp`
- Finding: Log messages were written verbatim, including `\n`, `\r`, and control characters derived from filenames and process data.
- Impact: Attackers could forge log lines (for example fake suspension or success records) and inject terminal escapes when logs are viewed.
- Fix: Added `sanitize_log_message`, which replaces newlines/tabs with spaces and other control characters with `?`.

### PP-005 - Unsafe `source` of attacker-writable adaptive state in DDoS logger [CWE-94, CWE-78]
- Severity: High
- Status: Fixed
- Location: `scripts/ddos_host_logger.sh`
- Finding: The script sourced a state file as shell code.
- Impact: Anyone able to modify the state file could execute commands as the logger user, potentially root.
- Fix: Replaced `source` with a strict `key=value` parser and validated values before use.

### PP-006 - SQL escaping silently returns empty on reconnect failure [CWE-89, CWE-754]
- Severity: High
- Status: Residual
- Location: `src/db_guard.cpp` (`DatabaseGuard::escape`)
- Finding: `escape()` returns `""` when `ensure_connection()` fails. Queries then run with empty values instead of aborting. The code also manually concatenates SQL strings.
- Impact: Filter bypass or unintended broad queries during database outages/reconnects; future callers could turn this into SQL injection if they assume escaping succeeded.
- Recommended fix: Make escaping return a `bool`/`optional`, abort the query on failure, and migrate dynamic queries to prepared statements. Enforce `MYSQL_OPT_SSL_MODE` separately (PP-044).

### PP-007 - Admin API keys rendered/decrypted in HTML [CWE-312, CWE-79]
- Severity: High
- Status: Partial
- Location: `panel_overrides/resources/views/admin/api/index.blade.php`
- Finding: The admin API page decrypted and displayed full API keys.
- Impact: Stored XSS or a compromised admin session could mass-exfiltrate API credentials; shoulder-surfing and screenshots also exposed secrets.
- Fix: Removed decryption from the view; display only an identifier plus `****`. Confirm the storage layer still encrypts keys at rest and that no other view decrypts them.

## B. Path and File Handling

### PP-008 - Double-decoded path traversal in file manager [CWE-22, CWE-177]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/Servers/FileController.php` (`normalizePath`)
- Finding: Path normalization did not consistently raw-URL-decode or reject encoded control characters, allowing inconsistent interpretation between layers.
- Impact: A client could reach paths outside the intended server directory or bypass quarantine checks.
- Fix: Normalize with `rawurldecode`, reject control characters, and apply the check before path use.

### PP-009 - Directory traversal and quarantine bypass in upload proxy [CWE-22]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/Servers/FileUploadController.php`
- Finding: The upload directory was appended to the node URL without decoding/validation; `.dann_quarantine` and `../` were not fully blocked.
- Impact: Uploads could be written outside the intended directory, including into protected quarantine paths.
- Fix: Raw-URL-decode the directory, reject control characters, `../`, and `.dann_quarantine`, and validate before forwarding.

### PP-010 - Percent-encoding bypass in node file-shield middleware [CWE-22, CWE-177]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Middleware/Security/PteroProtectNodeFileShield.php` (`resolveAction`)
- Finding: The middleware inspected the raw request path, while downstream code decoded it, so `%2e%2e` could bypass quarantine and path checks.
- Impact: Quarantine enforcement could be bypassed for encoded paths.
- Fix: Decode the path with `rawurldecode` before resolving the action.

### PP-011 - Quarantine path traversal in admin controller [CWE-22]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Admin/ProtectController.php` (`resolveQuarantinePath`)
- Finding: User-supplied `path` values were resolved without proving the final realpath stayed under the configured quarantine root.
- Impact: An admin or CSRF-assisted request could read, edit, rename, or delete files outside the quarantine tree.
- Fix: Resolve `realpath` and require containment under the quarantine base plus the quarantine marker.

### PP-012 - Disk cleanup path containment [CWE-22]
- Severity: Critical
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`path_within_root` and emergency cleanup)
- Finding: Cleanup relied on string-derived paths and did not consistently prove that a candidate remained under the server volume root.
- Impact: Symlinks, odd paths, or parser confusion could lead to deletion outside the intended volume.
- Fix: Added realpath-based containment checks and rejected unsafe filesystem tokens before deletion.

### PP-013 - Symlink and special-file following in file inspection [CWE-59]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`get_file_hash`, `is_binary_file`, `is_probably_elf_file`, `is_suspicious_file`)
- Finding: File checks used `stat` and ordinary opens, following symlinks and blocking on FIFOs/devices.
- Impact: A server owner could point a symlink at a root-only file, cause the guard to read/hash it, or hang a monitoring thread with a FIFO.
- Fix: Use `lstat`, `open(O_RDONLY|O_CLOEXEC|O_NOFOLLOW|O_NONBLOCK)`, and `fstat`; cap hashing at 50 MB.

### PP-014 - TOCTOU between suspicious-file stat and open [CWE-367]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`is_suspicious_file` and file inspection)
- Finding: A path could be swapped between the metadata check and the read.
- Impact: An attacker could race a safe file with a sensitive target.
- Fix: Operate on file descriptors and re-check metadata after open; use `O_NOFOLLOW`.

### PP-015 - FIFO/device denial of service during hashing [CWE-400, CWE-754]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/disk_protect.cpp` (`get_file_hash`, `is_binary_file`, `is_probably_elf_file`)
- Finding: Opening a FIFO or device could block indefinitely or consume resources.
- Impact: A malicious server workload could stall the guard.
- Fix: `O_NONBLOCK`, regular-file checks, bounded read size, and `fstat` validation.

### PP-016 - Predictable temporary files in setup/install scripts [CWE-377]
- Severity: Medium
- Status: Residual
- Location: `setup.sh`, installer helpers
- Finding: Some temporary paths are predictable rather than created with `mktemp` and restrictive permissions.
- Impact: A local attacker could pre-create or symlink a temp file to influence root-run installation steps.
- Recommended fix: Use `mktemp -d` with `umask 077`, verify ownership, and clean up with `trap`.

## C. Authentication, Authorization, and Session

### PP-017 - Email change without current-password verification [CWE-620]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Requests/Api/Client/Account/UpdateProfileRequest.php`
- Finding: A user could change the account email without re-authenticating.
- Impact: A stolen session could move the account to an attacker-controlled address and take over password recovery.
- Fix: Added a `current_password` rule and `withValidator` requirement when the email changes.

### PP-018 - `current_password` passed into profile update service [CWE-522]
- Severity: Medium
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/AccountController.php`
- Finding: The validation-only secret was included in the data handed to `UserUpdateService`.
- Impact: Unnecessary exposure of a credential to downstream code/logging.
- Fix: Unset `current_password` before calling the update service.

### PP-019 - Database deletion IDOR in admin server controller [CWE-639]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Admin/ServersController.php` (`deleteDatabase`)
- Finding: The database record was deleted by key without confirming it belonged to the target server.
- Impact: An admin with limited scope, or a crafted request, could delete another server's database.
- Fix: Scope the lookup through `$server->databases()->whereKey(...)->firstOrFail()`.

### PP-020 - Missing authorization on DanexC telemetry endpoints [CWE-862]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/DanexCController.php`
- Finding: Overview, timeline, feed, threats, and config endpoints were reachable without the intended admin check.
- Impact: Sensitive telemetry and security state could be read by ordinary users.
- Fix: Added `requireAdmin` (`root_admin`) to all five endpoints.

### PP-021 - Restricted-admin app API can obtain node tokens [CWE-862, CWE-269]
- Severity: High
- Status: Residual
- Location: `PteroProtectRestrictedAdmin` application API paths
- Finding: Delegated/restricted admin flows can still reach node token material or equivalent privileged operations through the application API surface.
- Impact: A restricted admin could escalate to node control.
- Recommended fix: Enforce an explicit capability for node-token operations, separate machine-token lanes from browser-admin lanes, and add negative tests for delegated admins.

### PP-022 - Broad `/api/application` WAF bypass [CWE-288, CWE-693]
- Severity: Critical
- Status: Fixed
- Location: `panel_overrides/app/Http/Middleware/Security/PteroProtectWaf.php`
- Finding: The middleware returned early for application API paths, skipping WAF checks entirely.
- Impact: Attacks against `/api/application` bypassed the WAF, including challenge and rate controls.
- Fix: Removed the application-API early returns; WAF logic now runs for those paths.

### PP-023 - Static-asset suffix bypass in WAF [CWE-288, CWE-693]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Middleware/Security/PteroProtectWaf.php` (`isStaticAssetPath`)
- Finding: Any path ending in an asset extension could be treated as static, including PHP or API paths with crafted suffixes.
- Impact: Attackers could bypass WAF inspection by appending an asset extension.
- Fix: Restrict bypass to known prefixes (`assets`, `favicons`, `themes`, `vendor`) and validated extensions.

### PP-024 - Direct `/index.php` front-controller challenge bypass [CWE-288]
- Severity: Critical
- Status: Fixed
- Location: `host_overrides/nginx/sites-available/pterodactyl.conf`
- Finding: The PHP location blocks did not consistently apply `auth_request`, so direct `/index.php` access could skip the challenge path used by normal front-controller requests.
- Impact: The web challenge could be bypassed by requesting `/index.php` directly.
- Fix: Added `auth_request /__pteroprotect/challenge/check_web` and `error_page 401 = @pteroprotect_challenge_redirect` to both PHP location blocks.

### PP-025 - Fail-open challenge fallback [CWE-636]
- Severity: Critical
- Status: Fixed
- Location: `host_overrides/nginx/snippets/pteroprotect_server.conf` (`@pteroprotect_challenge_fail_open`)
- Finding: The fallback location returned 204, allowing requests through when the challenge service was unavailable.
- Impact: An attacker could intentionally break the challenge service to bypass it.
- Fix: Fallback now returns 503 with `Retry-After` and `X-PteroProtect-Challenge-Fallback: fail-closed`.

### PP-026 - Emergency root panel exposed on all interfaces with weak auth [CWE-306, CWE-1327]
- Severity: Critical
- Status: Fixed
- Location: `scripts/pteroprotect_emergency_panel.py`
- Finding: The panel defaulted to an unrestricted bind and accepted short/placeholder tokens; query-based bootstrap was enabled by default.
- Impact: Remote access to a root-capable PTY with guessable or bootstrap credentials.
- Fix: Bind defaults to `127.0.0.1`; query bootstrap defaults false; `main` refuses disabled config, weak/placeholder/short/non-ASCII tokens; added constant-time comparison.

### PP-027 - Control-plane heartbeat fail-open [CWE-636]
- Severity: High
- Status: Fixed
- Location: `scripts/pteroprotect_control_plane.py` (`POST /heartbeat`)
- Finding: The endpoint allowed heartbeat processing when `node_auth_key` was missing.
- Impact: An unauthenticated local/remote caller could impersonate a node when configuration was incomplete.
- Fix: Fails closed with 503 when the key is absent; uses constant-time comparison.

### PP-028 - ProtectController GET actions only bound to session token [CWE-352, CWE-862]
- Severity: Medium
- Status: Residual
- Location: `panel_overrides/app/Http/Controllers/Admin/ProtectController.php` (`quarantineDownload`, `quarantineEdit`)
- Finding: GET quarantine actions require the verified session but do not bind a per-action token; verification state persists for the TTL.
- Impact: CSRF-assisted downloads/views and token reuse within the session window; impact is limited by same-origin response handling but remains a defense gap.
- Recommended fix: Require a per-action signed token on GETs, use POST for state-changing operations, and shorten verification TTL for quarantine actions.

### PP-029 - Terminal ticket files trusted without root mediation [CWE-306, CWE-732]
- Severity: High
- Status: Residual
- Location: `scripts/pteroprotect_terminal_helper.py` (`TICKET_DIR`, `verify_ticket`)
- Finding: The root helper reads one-time tickets created by the panel in a `2770` directory owned by `root:www-data`. Any `www-data` process can create a ticket for a privileged session.
- Impact: A compromised panel process or another `www-data`-owned service can escalate to a root PTY.
- Recommended fix: Move ticket creation behind the control plane or a small root-owned broker, sign tickets with a key the panel cannot read directly, and bind tickets to server/user/command with short TTL and single use.

### PP-030 - Client-controlled `CF-Connecting-IP` spoofing in terminal binding [CWE-290, CWE-348]
- Severity: Medium
- Status: Fixed
- Location: `scripts/pteroprotect_terminal_helper.py` (`client_ip_from_headers`), nginx terminal locations
- Finding: nginx forwarded the client-supplied `CF-Connecting-IP` header, and the helper preferred it over `X-Real-IP`.
- Impact: A client could spoof the IP used for ticket binding.
- Fix: Removed `CF-Connecting-IP` forwarding from terminal locations and made the helper prefer `X-Real-IP`.

### PP-031 - Terminal ticket in URL and nginx access logs [CWE-598, CWE-532]
- Severity: High
- Status: Fixed
- Location: `host_overrides/nginx/sites-available/pterodactyl.conf`, `host_overrides/nginx/snippets/pteroprotect_server.conf`
- Finding: Terminal WebSocket requests carried `?ticket=` and were logged by default.
- Impact: Anyone with log access could replay a live ticket.
- Fix: Added `access_log off;` to the terminal session and WebSocket locations. Trade-off: terminal connection metadata is no longer in access logs; reject logs are still emitted by the helper.

### PP-032 - Challenge secret fallback to unblock portal token and environment [CWE-798, CWE-1188]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/challenge_guard.cpp`
- Finding: A missing `waf_challenge_secret` silently fell back to `unblock_portal_token`, and an environment variable fallback existed.
- Impact: Secret reuse across security domains and unexpected acceptance of tokens from the environment.
- Fix: Fallback is now gated by an explicit `waf_challenge_legacy_secret_fallback` setting; environment token fallback removed.

### PP-033 - Weak `waf_challenge_secret` not rejected by C++ daemon [CWE-521]
- Severity: Medium
- Status: Residual
- Location: `src/challenge_guard.cpp` config loading; `setup.sh` only warns
- Finding: The daemon loads the challenge secret without enforcing minimum length or rejecting placeholders. Setup warns but does not fail.
- Impact: A placeholder secret can survive deployment and make challenge tokens forgeable.
- Recommended fix: Reject empty/short/placeholder secrets at startup (fail closed) and align with `security_startup_policy.py`.

### PP-034 - Client-side challenge remains automatable [CWE-602, CWE-804]
- Severity: High
- Status: Residual
- Location: `src/challenge_guard.cpp` (`/verify-math`, `/solve`, challenge page)
- Finding: After the math step, `pattern_points` and `pattern_hint` are returned to the client; the pattern is then verified from client-supplied click data. The challenge is fundamentally client-side.
- Impact: A bot can parse the JSON, synthesize clicks, and satisfy the flow without human interaction.
- Recommended fix: Move the pattern/answer check fully server-side with a per-session secret, avoid returning the exact sequence, and add server-observed timing/entropy checks. Treat this as a speed bump unless a third-party CAPTCHA is used.

### PP-035 - Client-controlled PoW hints can lower difficulty [CWE-807]
- Severity: Medium
- Status: Residual
- Location: `src/challenge_guard.cpp` (adaptive PoW based on hint headers)
- Finding: Client hint headers can reduce `pow_bits` down to a minimum.
- Impact: Automated clients can claim low-power/mobile and reduce proof-of-work cost.
- Recommended fix: Base difficulty on server-side reputation and observed behavior; cap client-influenced reductions; never go below a floor for suspicious sources.

### PP-036 - Clearance cookie parsed after encryption middleware nulls it [CWE-693]
- Severity: High
- Status: Fixed
- Location: `PteroProtectWaf.php`, `PteroProtectSessionBinding.php`, `PteroProtectClearanceToken.php`
- Finding: Middleware read the clearance cookie through the encrypted cookie bag, which could yield `null` after Laravel cookie handling, causing clearance checks to fail or behave inconsistently.
- Impact: Clearance bypass or denial of legitimate users depending on middleware order.
- Fix: Read the raw cookie value via `PteroProtectClearanceToken::rawCookieValue` in all three call sites.

### PP-037 - IPv6 clearance prefix too broad [CWE-183]
- Severity: Medium
- Status: Fixed
- Location: `panel_overrides/app/Support/Security/PteroProtectClearanceToken.php`
- Finding: The default IPv6 binding prefix was `/48`, shared by large provider allocations.
- Impact: Clearance tokens could be replayed across unrelated customers on the same provider network.
- Fix: Default changed to `/56`.

### PP-038 - Provider-token gate not applied to API lanes [CWE-693, CWE-288]
- Severity: Critical
- Status: Residual
- Location: nginx provider gate snippet, `PteroProtectWaf`, `challenge_guard`
- Finding: The provider-token gate is rendered only on some lanes. `/api/remote`, `/api/client`, and `/api/application` are not consistently covered, and `/check-web` ignores the provider-block header.
- Impact: Traffic from provider ranges that should be blocked/challenged can reach the panel or Wings API.
- Recommended fix: Inventory all request lanes, apply the gate at the outermost nginx layer, pass the provider-block decision to every auth/challenge endpoint, and add integration tests per lane.

### PP-039 - WAF replay queue is written but never consumed [CWE-636, CWE-693]
- Severity: High
- Status: Residual
- Location: `PteroProtectWaf.php` (`queueReplayTicket`), `scripts/resilience_orchestrator.py` (`prune_replay_queue`)
- Finding: During recovery, the WAF returns 202 and writes replay tickets, but the orchestrator only prunes the queue; there is no consumer that replays deferred requests.
- Impact: Requests are silently dropped while the UI reports "deferred"; this is a fake resilience control.
- Recommended fix: Implement a consumer with strict method/path allowlisting, idempotency keys, TTL enforcement, and audit logging, or remove the 202 path and return an explicit failure.

## D. Secrets, TLS, and Credentials

### PP-040 - Upload proxy disables TLS verification [CWE-295]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/Servers/FileUploadController.php`
- Finding: The Guzzle client used `verify => false` when proxying uploads to Wings.
- Impact: A network attacker could MITM the panel-to-node upload and steal or alter files/credentials.
- Fix: Defaults to `verify => true`, configurable through `PTEROPROTECT_FILES_VERIFY_NODE_TLS`; also set a 300-second timeout. Operators with self-signed node certificates must either install a trusted CA or explicitly opt out (not recommended).

### PP-041 - MySQL TLS not enforced [CWE-319]
- Severity: High
- Status: Residual
- Location: `src/db_guard.cpp` (`connect`)
- Finding: `mysql_real_connect` uses flags `0`; no SSL mode or CA verification is configured.
- Impact: Database credentials, server metadata, and violation logs can traverse the network in cleartext.
- Recommended fix: Add config for SSL mode/CA and require `MYSQL_OPT_SSL_MODE=VERIFY_IDENTITY` (or at least `REQUIRED`) for non-local databases. Fail closed when TLS is configured but unavailable.

### PP-042 - Committed Telegram and database credentials [CWE-798, CWE-312]
- Severity: Critical
- Status: Partial
- Location: `.codespaces/mock/pteroprotect/config.example.json`, `.codespaces/mock/runtime/real-panel-db-password.txt`, `.codespaces/mock/pteroprotect/.codespaces/mock/runtime/real-panel-db-password.txt`
- Finding: A real-looking Telegram bot token and database password were committed in mock/codespaces files.
- Impact: Anyone with repository history can use the bot token or database password.
- Fix: Current files redacted to `replace_me`. Git history still contains the values, so rotate the Telegram token and database password immediately and purge history if the repository is shared.

### PP-043 - Auto-configure secrets persisted in payload [CWE-312, CWE-522]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Services/Nodes/AutoConfigure/NodeAutoConfigureService.php`, `panel_overrides/app/Jobs/Nodes/ExecuteNodeAutoConfigureJob.php`
- Finding: `node_yaml_b64` and ephemeral credentials could remain in the persisted job payload; the ephemeral key was not revoked on every path.
- Impact: Long-lived storage of node bootstrap secrets and orphaned keys.
- Fix: Removed `node_yaml_b64` from the requested payload; generate node YAML at runtime; revoke the ephemeral key in `finally`; clear encrypted password/private key fields; set `$tries=1`, `$timeout=300`.

### PP-044 - Logger file permissions [CWE-732]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/logger.cpp` (`init`)
- Finding: The log file was created with default umask permissions, often `0644`.
- Impact: Local users could read security events, victim names, UUIDs, and paths.
- Fix: Apply `chmod(path, 0640)` after opening. Ownership hardening is still a deployment responsibility.

### PP-045 - `config.json` readable/writable by panel group [CWE-732, CWE-522]
- Severity: High
- Status: Residual
- Location: `setup.sh` install permissions, live `/pteroprotect/config.json`
- Finding: The live config is `root:www-data` with group read/write (and possibly ACL write), while the panel runs as `www-data`.
- Impact: A compromised panel can read all secrets and modify guard settings, including disabling protections.
- Recommended fix: Make config root-only (`0640` root:root or `0600`), expose only the minimum settings the panel needs through a separate signed API, and remove write ACLs for `www-data`.

## E. Fail-Open Behavior, Defaults, and Rate Controls

### PP-046 - fail2ban installer exits successfully on invalid config [CWE-636]
- Severity: High
- Status: Fixed
- Location: `scripts/install_fail2ban.sh`
- Finding: Configuration validation failure ended with `exit 0`.
- Impact: Automation could believe fail2ban was installed while jails were broken.
- Fix: Return `exit 1` on validation failure.

### PP-047 - `offline_mode` default suppresses alerts [CWE-1188]
- Severity: Medium
- Status: Fixed
- Location: `include/config.h`, `src/config.cpp`
- Finding: Missing `runtime.offline_mode` defaulted to `true`, which suppresses external notifications and changes mitigation behavior.
- Impact: Silent protection gaps on partially configured deployments.
- Fix: Default is now `false`, matching `config.example.json`.

### PP-048 - Config parse failure silently falls back to defaults [CWE-636]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/config.cpp` (`Config::load`), `src/main.cpp`
- Finding: JSON parse/validation errors were caught, logged (before the file logger was initialized), and replaced with default config.
- Impact: The guard could start with unsafe defaults and no visible error.
- Fix: Added `Config::valid`, initialize the logger before loading, and refuse to start when parsing or validation fails.

### PP-049 - Unblock-portal and emergency-panel default unrestricted bind [CWE-1327]
- Severity: High
- Status: Fixed
- Location: `scripts/unblock_portal.py`, `scripts/pteroprotect_emergency_panel.py`, `setup.sh`, `config.example.json`
- Finding: Defaults bound to `0.0.0.0`.
- Impact: Administrative/root-adjacent services exposed on all interfaces.
- Fix: Defaults changed to `127.0.0.1` in code, installer, and example config.

### PP-050 - Unblock allowlist accepts `/0` and overly broad ranges [CWE-183]
- Severity: High
- Status: Fixed
- Location: `scripts/unblock_portal.py` (`is_safe_allowlist_spec`)
- Finding: The portal accepted extremely broad CIDRs, including the entire IPv4/IPv6 space.
- Impact: An operator or compromised portal could permanently allowlist all addresses, disabling blocking.
- Fix: Require IPv4 prefix length `>= 24` and IPv6 `>= 64`; reject invalid/mixed families.

### PP-051 - Query bootstrap enabled by default in emergency panel [CWE-1188]
- Severity: High
- Status: Fixed
- Location: `scripts/pteroprotect_emergency_panel.py`, `setup.sh`, `config.example.json`
- Finding: Token bootstrap via query string was enabled by default.
- Impact: Token leakage through URLs/logs and easier unauthorized bootstrap.
- Fix: Default is now `false`.

### PP-052 - DanexCoin cooldown reset through `Cache::forget` [CWE-799]
- Severity: Medium
- Status: Fixed
- Location: `panel_overrides/app/Http/Controllers/Api/Client/DanexCoinController.php`
- Finding: A `finally` block removed the spin lock, allowing rapid repeated spins.
- Impact: Rate-limit bypass and coin/points abuse.
- Fix: Removed the `Cache::forget` reset so the cooldown persists for its intended TTL.

### PP-053 - Unbounded log-watcher counters [CWE-770]
- Severity: Medium
- Status: Fixed
- Location: `scripts/security_log_watch.py`
- Finding: Per-key counters grew without pruning.
- Impact: Long-running hosts could exhaust memory.
- Fix: Prune counters above 20,000 entries.

### PP-054 - Log watcher does not reopen after rotation [CWE-778]
- Severity: Medium
- Status: Fixed
- Location: `scripts/security_log_watch.py`
- Finding: The watcher kept the old file descriptor after logrotate.
- Impact: Security events after rotation were not monitored.
- Fix: Track inode, reopen on change, and close the descriptor in `finally`.

### PP-055 - Startup secret policy is warn-only [CWE-636]
- Severity: High
- Status: Residual
- Location: `scripts/security_startup_policy.py`, systemd units
- Finding: Weak/missing secrets are reported but do not block startup.
- Impact: A deployment can run with placeholder secrets.
- Recommended fix: Make the policy enforcing in production units (`ExecStartPre` with failure), with an explicit development override.

### PP-056 - Weak secret/password validation in startup policy [CWE-521]
- Severity: High
- Status: Fixed
- Location: `scripts/security_startup_policy.py`
- Finding: The policy did not require `network.emergency_control_token`, accepted repeated-character secrets, and did not reject empty/short/repetitive database passwords.
- Impact: Trivially guessable administrative secrets.
- Fix: Require a 24-character emergency token, reject `change_me`/repeated-character values, and validate database password length/uniqueness.

### PP-057 - Null node auto-configure exit code treated as success [CWE-754]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Jobs/Nodes/ExecuteNodeAutoConfigureJob.php`
- Finding: A missing exit code could be interpreted as success.
- Impact: Nodes reported configured when the command did not run.
- Fix: Treat `null` exit code as failure and clear secrets/revoke the ephemeral key in `finally`.

## F. Privilege, Permissions, systemd, and Installation

### PP-058 - `/pteroprotect` writable by `www-data` [CWE-732, CWE-269]
- Severity: Critical
- Status: Fixed
- Location: `setup.sh` (ACL setup)
- Finding: Installer granted `www-data` recursive `rwx` and a default ACL on the entire install directory.
- Impact: A compromised panel could replace daemons, scripts, or config, escalating to root.
- Fix: Removed `setfacl rwx` and default ACLs; install directory is now `r-x` for the panel group.

### PP-059 - hostguard systemd unit prevents kernel module loading [CWE-1188]
- Severity: High
- Status: Fixed
- Location: `systemd/pteroprotect-hostguard.service`
- Finding: `ProtectKernelModules=true` blocked iptables/ip6tables module autoload.
- Impact: Host firewall protection could fail silently.
- Fix: Set `ProtectKernelModules=false` for this unit.

### PP-060 - DDoS log service cannot write nginx logs [CWE-732]
- Severity: Medium
- Status: Fixed
- Location: `systemd/pteroprotect-ddoslog.service`
- Finding: The unit lacked write access to `/etc/nginx`.
- Impact: Log rotation/management failed under sandboxing.
- Fix: Added `/etc/nginx` to `ReadWritePaths`.

### PP-061 - Emergency panel has no restart policy [CWE-1188]
- Severity: Low
- Status: Fixed
- Location: `systemd/pteroprotect-emergency-panel.service`
- Finding: The service did not restart after failure.
- Impact: Emergency access unavailable when most needed.
- Fix: `Restart=on-failure`.

### PP-062 - Uninstall overwrites config without backup [CWE-404]
- Severity: Medium
- Status: Fixed
- Location: `Makefile` (`uninstall`)
- Finding: Removal could overwrite/delete the live config without preserving a copy.
- Impact: Loss of operator configuration and secrets.
- Fix: Back up `config.json` before removal.

### PP-063 - Missing compiler/linker hardening [CWE-693]
- Severity: Medium
- Status: Fixed
- Location: `Makefile`, `setup.sh`
- Finding: Build flags lacked fortify, stack protector, stack-clash protection, PIE, and format-security checks.
- Impact: Exploit mitigation reduced for memory-safety bugs in the C++ daemons.
- Fix: Added `-O2 -D_FORTIFY_SOURCE=2 -fstack-protector-strong -fstack-clash-protection -D_GLIBCXX_ASSERTIONS -fPIE -Wformat -Wformat-security`. Linking should also use `-pie -Wl,-z,relro,-z,now`; verify the Makefile link line on a build host.

## G. Destructive Logic and Availability

### PP-064 - Cleanup job could delete unrelated servers [CWE-862, CWE-639]
- Severity: Critical
- Status: Partial
- Location: `panel_overrides/app/Jobs/ExecuteProtectCleanupJob.php`
- Finding: The job could force-delete servers based on broad criteria. It now requires the `Created via madeinweb Create Panel` description marker, offline state, and suspension, and excludes subusers/owner cleanup without the marker.
- Impact: Destructive action on legitimate customer servers if markers are wrong or spoofed.
- Remaining gap: No dry-run mode, explicit allowlist, or two-person confirmation.
- Recommended fix: Add a dry-run/preview, require an admin-supplied server allowlist or job token, log every candidate and decision, and make deletion reversible where possible.

### PP-065 - madeinweb delete-on-suspend conversion [CWE-862, CWE-1188]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/app/Services/Servers/SuspensionService.php`
- Finding: Suspending a madeinweb-created server deleted it instead of suspending.
- Impact: Routine suspension destroyed customer data.
- Fix: Removed the delete-on-suspend conversion; regular suspension always applies.

### PP-066 - Allowlist persistence loses ownership/permissions [CWE-708]
- Severity: Medium
- Status: Fixed
- Location: `scripts/unblock_portal.py` (`write_json`)
- Finding: Rewriting the allowlist file could change ownership/mode.
- Impact: Privilege or availability issues if the file becomes inaccessible or too broad.
- Fix: Preserve mode/uid/gid when replacing the file.

### PP-067 - CreatePanel race and name gate [CWE-362]
- Severity: Medium
- Status: Residual
- Location: CreatePanel flow
- Finding: Concurrent requests can race server-name/creation checks.
- Impact: Duplicate or unintended server creation; possible quota bypass.
- Recommended fix: Add a database unique constraint and a transactional lock around name reservation.

### PP-068 - Ads rotation ignores configured weights [CWE-1188]
- Severity: Low
- Status: Residual
- Location: Ads rotation logic
- Finding: Weighted ads are selected uniformly.
- Impact: Commercial/feature behavior does not match configuration; no direct security impact.
- Recommended fix: Implement weighted selection and add a deterministic test.

### PP-069 - RUM/chat retention pruning missing [CWE-459]
- Severity: Medium
- Status: Residual
- Location: RUM/chat retention jobs
- Finding: Retention settings exist but pruning is incomplete.
- Impact: Unbounded storage growth and longer-than-intended PII retention.
- Recommended fix: Implement scheduled pruning with per-table caps and audit logs.

## H. Monitoring, Detection, and Logging

### PP-070 - Secret scanner skipped paths by absolute components [CWE-693]
- Severity: Medium
- Status: Fixed
- Location: `scripts/security_secret_scan.py`
- Finding: Skip logic evaluated absolute path parts, so a workspace under a skipped component could cause entire subtrees to be skipped.
- Impact: Committed secrets could evade CI scanning.
- Fix: Evaluate `Path(relative).parts` instead.

### PP-071 - Runtime abuse guard counted the wrong socket endpoint [CWE-1188]
- Severity: High
- Status: Fixed
- Location: `scripts/runtime_abuse_guard.py`
- Finding: Gateway-port connection counting used remote endpoints or the wrong `ss` fields.
- Impact: Gateway abuse detection missed or misclassified connections.
- Fix: Added `local_endpoints_for_pid_from_ss` and count local endpoints for gateway ports.

### PP-072 - Container TCP count used host namespace [CWE-668]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`get_container_tcp_connections` and namespace helpers)
- Finding: Some connection counts were derived from the host namespace rather than the container.
- Impact: Per-server connection limits were inaccurate.
- Fix: Use validated PID plus `nsenter` into the container network namespace.

### PP-073 - IPv4-mapped IPv6 addresses bypass private/local checks [CWE-20]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`is_private_or_local_ip`, `block_iptables_ip`)
- Finding: `::ffff:127.0.0.1` and similar forms were not normalized before classification.
- Impact: Local/private address checks and firewall rules could be bypassed.
- Fix: Normalize IPv4-mapped IPv6 to IPv4 before checks and firewall rule generation.

### PP-074 - IPv6 addresses sent to `iptables` [CWE-20]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`block_iptables_ip`)
- Finding: IPv6 blocked addresses were passed to the IPv4 `iptables` binary.
- Impact: IPv6 abuse was not blocked.
- Fix: Use `ip6tables` for IPv6 and normalize mapped addresses.

### PP-075 - Missing IPv6 firewall parity [CWE-284]
- Severity: Critical
- Status: Fixed
- Location: `scripts/install_host_protection.sh`
- Finding: DOCKER-USER isolation and host-port protections were IPv4-only.
- Impact: IPv6 traffic bypassed DDoS and lateral-movement protections.
- Fix: Added equivalent IPv6 rules for docker subnets, link-local/ULA/loopback drops, host port drops, and egress limits.

### PP-076 - Overly broad install-command detection [CWE-184]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`looks_like_legit_install_command`)
- Finding: Substring matching treated many attacker commands as legitimate installers.
- Impact: Malicious workloads could receive install grace and avoid mitigation.
- Fix: Match command segments against known installer prefixes and require inactive state before granting grace.

### PP-077 - API-service detection spoofable through egg name [CWE-807]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`looks_like_api_service`)
- Finding: A user-controlled egg name alone could classify a workload as an API service.
- Impact: Attackers could claim API-service exemptions.
- Fix: Use only `egg_name` plus `nest_name` from trusted metadata and apply stricter checks.

### PP-078 - PID injection before `nsenter` [CWE-78]
- Severity: High
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp` (`get_container_pid`, namespace helpers)
- Finding: PID strings derived from Docker output were concatenated into shell commands without validation.
- Impact: Crafted process metadata could inject shell arguments.
- Fix: Validate with `is_safe_numeric_token` before building `nsenter` commands.

### PP-079 - Unconditional direct suspension branch [CWE-1188]
- Severity: Critical
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp`
- Finding: A fallback branch called `db.suspend_server(..., "path=direct_suspend")` without the normal multi-signal gate.
- Impact: A single noisy metric could suspend a server.
- Fix: Branch is now observe-only; suspension requires the intended evidence path.

### PP-080 - Install grace granted while workload active [CWE-1188]
- Severity: Medium
- Status: Fixed (C++ unbuilt)
- Location: `src/resource_monitor.cpp`
- Finding: Install grace could apply even when the workload was already active.
- Impact: Abuse could hide behind an install window repeatedly.
- Fix: Grace is granted only when the workload is inactive and matches installer patterns.

### PP-081 - Fake node IP detection [CWE-1188]
- Severity: Low
- Status: Fixed
- Location: `scripts/pteroprotect_node_agent.py` (`detect_ip`)
- Finding: The agent returned a placeholder/loopback address instead of detecting the node IP.
- Impact: Node registration and allowlists used the wrong address.
- Fix: Detect via a UDP connect probe with hostname fallback.

### PP-082 - Logger duplicates every message to stdout and file [CWE-778]
- Severity: Low
- Status: Residual
- Location: `src/logger.cpp`
- Finding: Every event is written twice (stdout and file), with no structured level/queue.
- Impact: Log volume and potential duplicate alert processing.
- Recommended fix: Choose one sink per environment or add a rate-limited structured handler.

## I. Fake, No-Op, or Incomplete Controls

### PP-083 - `ApiKey::can()` always returns false [CWE-693]
- Severity: Medium
- Status: Residual
- Location: `panel_overrides/app/Models/ApiKey.php`
- Finding: The ability check is a stub returning `false`; `cant()` negates it.
- Impact: Any code relying on it denies all abilities, which can cause fail-closed behavior but is not a real authorization model. Future use could create unexpected bypasses if callers invert assumptions.
- Recommended fix: Implement ability matching against stored scopes or remove the method and force explicit checks.

### PP-084 - Tracking subsystem is in-memory/fake [CWE-693]
- Severity: Medium
- Status: Residual
- Location: `src/tracking.cpp` / tracker DB integration
- Finding: Tracking results are not persisted as the surrounding code implies.
- Impact: Operators may believe abuse history exists when it is lost on restart.
- Recommended fix: Persist to the tracker DB or remove the misleading interface and document the limitation.

### PP-085 - `runRootRaw` can silently fall back to a no-op [CWE-693]
- Severity: Low
- Status: Residual
- Location: `panel_overrides/app/Http/Controllers/Admin/ProtectController.php` (`runRootRaw`)
- Finding: The sudo fallback can fail without a clear hard error in some paths.
- Impact: Admin actions may appear successful while doing nothing.
- Recommended fix: Return an explicit failure when privilege escalation is unavailable and surface it in the UI.

### PP-086 - Frontend upload fallback bypassed the hardened proxy [CWE-295]
- Severity: High
- Status: Fixed
- Location: `panel_overrides/resources/scripts/components/server/files/UploadButton.tsx`
- Finding: The UI could fall back to `uploadViaPanel`, avoiding the intended node upload path.
- Impact: Inconsistent TLS/path validation depending on branch.
- Fix: Removed the fallback and the unused `http` import. Rebuild panel assets before deployment.

## J. False-Positive and Robustness Research

These are areas where the audit specifically checked for overblocking, false negatives, and compatibility risk.

### PP-FP-01 - Install-command matching
- The original broad substring check produced false negatives (attackers misclassified as installers). The fix tightens matching to known installer prefixes and requires inactive state. Monitor for legitimate installers that no longer match.
- Related: PP-076, PP-080.

### PP-FP-02 - API-service classification
- Using only egg/nest metadata reduces spoofing but may misclassify custom eggs. Verify on real Pterodactyl egg data.
- Related: PP-077.

### PP-FP-03 - IPv4-mapped IPv6 normalization
- Normalization prevents false negatives in private-range and firewall checks. Ensure `ip6tables` rules still cover native IPv6 after normalization.
- Related: PP-073, PP-074.

### PP-FP-04 - WAF static-asset bypass
- The old suffix rule was both a bypass and a false-positive source for API paths ending in `.js`/`.css`. The new prefix allowlist is narrower; verify that legitimate asset URLs under custom themes are still reachable.
- Related: PP-023.

### PP-FP-05 - Secret-scanner skip logic
- Relative-path evaluation avoids accidental whole-tree skips. Self-test and full scan now report 377 files and 0 findings. Keep fixture exclusions narrow.
- Related: PP-070.

### PP-FP-06 - Challenge fail-closed behavior
- Returning 503 when the challenge service is down prevents bypass but increases availability impact. Operators should monitor challenge health and keep a documented break-glass path.
- Related: PP-025.

### PP-FP-07 - Terminal access-log suppression
- Disabling access logs for terminal endpoints protects tickets but removes connection metadata. Reject events are still emitted by the helper; consider a sanitized log format if metadata is required.
- Related: PP-031.

### PP-FP-08 - Node TLS verification default
- `verify => true` is correct but will break self-signed Wings certificates. Prefer installing a trusted CA. The explicit `PTEROPROTECT_FILES_VERIFY_NODE_TLS=false` escape hatch must be treated as a security downgrade.
- Related: PP-040.

### PP-FP-09 - Emergency-token length requirement
- Requiring 24 characters may reject existing short tokens. The policy is currently warn-only; plan a migration before enforcing.
- Related: PP-055, PP-056.

### PP-FP-10 - `auth_request` on all PHP requests
- Adding challenge enforcement to PHP locations may affect non-browser API clients that use `/index.php`. Validate the challenge's skip logic for machine-token lanes before enforcing broadly.
- Related: PP-024, PP-038.

## Residual Risk Register

| ID | Area | Severity | Why it remains | Recommended owner action |
|---|---|---|---|---|
| PP-006 | DB escaping/SQL | High | Requires API change and prepared statements | Refactor DB layer; fail closed |
| PP-021 | Restricted admin node tokens | High | Capability model not redesigned | Add explicit node-token capability and tests |
| PP-029 | Terminal ticket trust | High | Needs root broker/control-plane redesign | Move ticket issuance behind root mediation |
| PP-033 | Weak challenge secret | Medium | C++ validation not added | Reject weak secrets at startup |
| PP-034 | Challenge automation | High | Client-side design | Server-side pattern validation or third-party CAPTCHA |
| PP-035 | PoW hint trust | Medium | Adaptive policy needs reputation data | Server-side difficulty floor |
| PP-038 | Provider gate lanes | Critical | Partial wiring | Enforce at outermost nginx for every lane |
| PP-039 | WAF replay | High | No consumer implemented | Implement bounded replay or return explicit failure |
| PP-041 | MySQL TLS | High | Config/schema not added | Require verified TLS for remote DB |
| PP-042 | Committed secrets | Critical | Values remain in git history | Rotate tokens/passwords; purge history |
| PP-045 | config.json permissions | High | Deployment permission model | Root-only config plus narrow API |
| PP-055 | Startup policy warn-only | High | Enforcement not enabled | Add failing `ExecStartPre` |
| PP-064 | Cleanup job destructive | Critical | No dry-run/allowlist | Add preview, allowlist, audit |
| PP-067 | CreatePanel race | Medium | Needs DB constraint/transaction | Add unique constraint and lock |
| PP-069 | RUM/chat retention | Medium | Pruning incomplete | Implement scheduled retention |
| PP-083 | ApiKey::can | Medium | Stub not replaced | Implement scopes or remove |
| PP-084 | Tracking fake | Medium | Persistence not implemented | Persist or document |
| PP-085 | runRootRaw no-op | Low | Error propagation incomplete | Fail loudly |

## Validation Performed

- `for f in tests/test_*.py; do python3 "$f"; done` - all pass, including challenge API, DDoS policy, fail2ban, IPv6 parity, firewall manager, ModSecurity, provider gate, rate protect, runtime abuse, security headers, startup policy, terminal helper, WAF hardening, and Wings guard tests.
- `php -l` on all modified PHP files - clean.
- `bash -n` on modified shell files and `python3 -m py_compile` on modified Python files - clean.
- `git diff --check` - clean.
- `python3 scripts/security_secret_scan.py .` - `files_scanned=377 findings=0`; self-test passes.
- `g++ ... -c src/logger.cpp` - compiles successfully.
- `make -j2` - blocked by missing `nlohmann/json.hpp` and `mysql/mysql.h`; C++ binary behavior is unverified.
- `bash scripts/validate_all.sh` - passes shell, Python, and PHP stages; systemd stage fails because `/pteroprotect/*` binaries are not installed in this container.

## Recommended Remediation Order

1. Rotate the `.codespaces` Telegram token and database password; purge git history if the repository is shared.
2. Make `config.json` root-only and remove `www-data` write access to `/pteroprotect`.
3. Replace terminal ticket files with a root-mediated/signed issuance flow.
4. Wire the provider-token gate and challenge decision into every nginx lane, including `/api/remote`, `/api/client`, `/api/application`, and `/check-web`.
5. Implement or remove the WAF replay queue.
6. Enforce the startup secret policy and reject weak challenge secrets in C++.
7. Require verified MySQL TLS and refactor DB escaping/prepared statements.
8. Add dry-run/allowlist controls to the cleanup job and a test that proves unrelated servers are untouched.
9. Implement `ApiKey::can()` scopes or remove the stub; persist tracking data or document the limitation.
10. Build the C++ binaries and panel assets on a real host, then run live integration tests for challenge, upload, terminal, and cleanup flows.

## Changed Files Referenced

- C++: `src/disk_protect.cpp`, `src/challenge_guard.cpp`, `src/resource_monitor.cpp`, `src/logger.cpp`, `src/config.cpp`, `src/main.cpp`, `include/config.h`
- Python: `scripts/pteroprotect_emergency_panel.py`, `scripts/pteroprotect_control_plane.py`, `scripts/pteroprotect_node_agent.py`, `scripts/pteroprotect_terminal_helper.py`, `scripts/unblock_portal.py`, `scripts/runtime_abuse_guard.py`, `scripts/security_log_watch.py`, `scripts/security_secret_scan.py`, `scripts/security_startup_policy.py`
- Shell/systemd: `setup.sh`, `Makefile`, `scripts/install_fail2ban.sh`, `scripts/install_host_protection.sh`, `scripts/ddos_host_logger.sh`, `systemd/pteroprotect-ddoslog.service`, `systemd/pteroprotect-hostguard.service`, `systemd/pteroprotect-emergency-panel.service`
- nginx/config: `host_overrides/nginx/sites-available/pterodactyl.conf`, `host_overrides/nginx/snippets/pteroprotect_server.conf`, `config.example.json`
- Panel PHP: `panel_overrides/app/Http/Controllers/Admin/ProtectController.php`, `panel_overrides/app/Http/Controllers/Admin/ServersController.php`, `panel_overrides/app/Http/Controllers/Api/Client/AccountController.php`, `panel_overrides/app/Http/Controllers/Api/Client/DanexCController.php`, `panel_overrides/app/Http/Controllers/Api/Client/DanexCoinController.php`, `panel_overrides/app/Http/Controllers/Api/Client/Servers/FileController.php`, `panel_overrides/app/Http/Controllers/Api/Client/Servers/FileUploadController.php`, `panel_overrides/app/Http/Middleware/Security/PteroProtectNodeFileShield.php`, `panel_overrides/app/Http/Middleware/Security/PteroProtectSessionBinding.php`, `panel_overrides/app/Http/Middleware/Security/PteroProtectWaf.php`, `panel_overrides/app/Http/Requests/Api/Client/Account/UpdateProfileRequest.php`, `panel_overrides/app/Jobs/ExecuteProtectCleanupJob.php`, `panel_overrides/app/Jobs/Nodes/ExecuteNodeAutoConfigureJob.php`, `panel_overrides/app/Services/Nodes/AutoConfigure/NodeAutoConfigureService.php`, `panel_overrides/app/Services/Servers/SuspensionService.php`, `panel_overrides/app/Support/Security/PteroProtectClearanceToken.php`, `panel_overrides/config/pteroprotect.php`
- Frontend/view: `panel_overrides/resources/scripts/components/server/files/UploadButton.tsx`, `panel_overrides/resources/views/admin/api/index.blade.php`
- Tests: `tests/test_runtime_abuse_guard.py`, `tests/test_security_startup_policy.py`, `tests/test_waf_hardening_static.py`

No commits were made. This report is a working security record, not a claim that every item has been runtime-verified.
