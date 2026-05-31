# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Common commands

### Native guard binaries
- Build both C++ binaries: `make`
- Build with limited parallelism: `make -j2`
- Remove C++ objects/binaries: `make clean`
- Install/deploy to the host: `make install` or `bash setup.sh`
  - Defaults from `Makefile`: `PREFIX=/pteroprotect`, `PANEL_DIR=/var/www/pterodactyl`, `SYSTEMD_DIR=/etc/systemd/system`, `NGINX_DIR=/etc/nginx`.
  - Override as needed, e.g. `make install PREFIX=/tmp/pteroprotect PANEL_DIR=/tmp/panel`.

### Repository validation and tests
- Static non-destructive validation: `bash scripts/validate_all.sh`
- Include host-local nginx/fail2ban config checks: `bash scripts/validate_all.sh --live`
- Include C++ build: `bash scripts/validate_all.sh --build`
- Include live config in secret scan: `bash scripts/validate_all.sh --include-live-config`
- Run all Python regression tests the same way the validator does: `for f in tests/test_*.py; do python3 "$f"; done`
- Run one Python regression test: `python3 tests/test_rate_protect.py`
- Shell syntax for one script: `bash -n scripts/install_host_protection.sh`
- Secret scanner self-test: `python3 scripts/security_secret_scan.py --self-test`
- Systemd unit syntax: `systemd-analyze verify systemd/*.service`

### Panel override frontend
Run from `panel_overrides/`:
- Install dependencies: `corepack yarn install --frozen-lockfile` or `yarn install --frozen-lockfile`
- Build development assets: `yarn build`
- Build production assets: `yarn build:production`
- Watch frontend assets: `yarn watch`
- Typecheck: `yarn tsc`
- Lint TS/TSX: `yarn lint`
- Run Jest tests: `yarn test`
- Run one Jest test: `yarn test path/to/test.test.tsx`
- Clean generated JS/maps: `yarn clean`

Node `>=22` is required by `panel_overrides/package.json` and setup attempts to upgrade/skip the panel build if Node is too old.

## Architecture overview

This repository is PteroProtect: a host + Pterodactyl panel protection stack. It is not only a C++ application; runtime behavior spans native daemons, Python sidecars, shell installers, nginx/fail2ban/firewall overrides, systemd units, and bundled Pterodactyl panel overrides.

### Native enforcement daemons
- `src/main.cpp` builds `./dann_guard`, the main guard daemon.
  - Loads runtime config from `/pteroprotect/config.json` by default, or `DANN_GUARD_HOME/config.json`.
  - Starts disk/file abuse protection and resource/network monitoring.
  - Uses MySQL to map Pterodactyl server UUIDs to panel users/servers, log violations, and suspend servers.
  - Sends Telegram startup/incident notifications.
- `src/challenge_guard.cpp` builds `./challenge_guard`, a standalone HTTP challenge/token daemon.
  - Reads many `network.*`, `database`, and `ptlc` settings directly from JSON.
  - Serves challenge/check endpoints used by nginx `auth_request` flows.
- Shared C++ modules live in `src/` with headers in `include/`: config loading, DB guard, disk protection, resource monitor, tracker DB, logging, Telegram, and rate protection.
- Config ownership is split: `src/config.cpp` maps a typed subset for `dann_guard`; `challenge_guard.cpp` parses additional raw JSON keys. Check both when adding config fields.

### Runtime config and install assumptions
- Example config: `config.example.json`; live config: `/pteroprotect/config.json`.
- Important live secret fields include `network.waf_challenge_secret`, `network.unblock_portal_token`, `network.rce_control_key`, `network.node_auth_key`, DB password, Telegram token, and PTLC key.
- Default Pterodactyl paths are `/var/www/pterodactyl` for the panel and `/var/lib/pterodactyl/volumes` for server volumes.
- Setup/deploy assumes a Pterodactyl host with nginx, systemd, Docker, MySQL/MariaDB, and optional fail2ban/ipset/iptables support.

### Host protection and services
- `setup.sh` is the main installer. It copies binaries/configs, applies host overrides, writes/enables systemd units, applies panel overrides, runs panel migrations, and may build/sync panel frontend assets.
- `systemd/` contains the service graph: main guard, challenge API, control plane, node agent, self-heal, runtime abuse guard, resilience services, unblock portal, terminal helper, panel sync, log watch, and DDoS logger.
- `host_overrides/` contains nginx, fail2ban, sysctl, and related host config overlays.
- `scripts/install_host_protection.sh` handles firewall/bootstrap logic and should preserve IPv4/IPv6 parity.
- `scripts/validate_all.sh` is the safest first validation command; by default it avoids service reloads, firewall mutation, package installs, and external contacts.

### Pterodactyl panel overrides
- `panel_overrides/` is the source override bundle copied into the live panel, not a standalone application.
- Backend overrides include Laravel/PHP files under `app/`, `config/`, `routes/`, and `database/migrations/`.
- Frontend source is under `panel_overrides/resources/scripts/`; Blade templates are under `panel_overrides/resources/views/`.
- Built assets and `manifest.json` live in `panel_overrides/public/assets/`.
- Do not hand-edit compiled JS assets. Edit source under `resources/`, rebuild, and keep `public/assets/manifest.json` consistent with the generated bundles.
- `panel_overrides/webpack.config.js` emits assets to `public/assets`, uses `/assets/` public path, generates SRI data in the manifest, and keeps a single initial entry bundle because Blade loads the main entrypoint from the manifest.

### Tests, fixtures, and docs
- Python regression tests are plain executable files under `tests/test_*.py`; they are run directly with `python3`, not through pytest.
- Security fixture corpus lives under `tests/security/`. Fixtures must be fake/local only; do not use production logs, real tokens, private keys, customer data, or live panel sessions.
- Security review guidance is in `docs/REVIEW_CHECKLIST.md`.
- Threat model and assumptions are in `docs/THREAT_MODEL.md`.
- Live secret requirements are in `docs/SECRET_POLICY.md`.
- Operator/runbook context is in `docs/incident-response.md` and `docs/resilience-operations.md`.

## Security-sensitive change guidance

- Preserve these boundaries: nginx is the public front door; challenge/control-plane endpoints should stay localhost-only unless a design explicitly changes that; trusted proxy/real-ip behavior requires explicit trusted CIDRs.
- Keep browser session flows, machine-token/API flows, Wings `/api/remote/` paths, and admin/control-plane flows distinct.
- Avoid broad ModSecurity exclusions, broad API/web challenge bypasses, or broad Wings bypasses. If a bypass is needed, inventory routes and document why it is narrow.
- Maintain IPv4 and IPv6 parity for firewall, fail2ban, rate-limit, and host protection changes.
- Destructive actions such as suspend, kill, quarantine, delete, reboot, or firewall block should not be triggered from a single noisy signal.
- For security-sensitive changes, record evidence: changed files, tests/validation commands, security impact, false-positive impact, rollback path, and residual risk.

## README/operator notes worth preserving

- Installation expects a VPS with Pterodactyl already installed and a configured `config.json`.
- Common operator mistakes noted by the README: wrong Telegram channel/chat ID/bot permissions, using a PLTA key where PTLC is required, putting the node URL where the panel URL is expected, secrets with spaces, missing node certificate, and closed required ports.
- README troubleshooting mentions allowing panel/node-related ports such as `80`, `8080`, `2022`, `18443`, and `18444`, and using certbot for the node domain when certificates are missing.
