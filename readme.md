# PteroProtect (Danex)

PteroProtect adalah full-stack Web Application Firewall (WAF), anti-DDoS, dan abuse mitigation stack yang dirancang khusus untuk Pterodactyl game panel. Melindungi dari layer jaringan (L3/L4) hingga aplikasi (L7) dengan defense-in-depth.

Dikembangkan oleh **HexZo & Dann** sebagai lapisan keamanan untuk ekosistem Pterodactyl (https://pterodactyl.io/).

---

## Fitur

**WAF & Anti-DDoS**
- Multi-layer request inspection: nginx rate limits -> ModSecurity + OWASP CRS -> C++ challenge guard -> Laravel WAF
- JavaScript/browser challenge (PoW, math, click, behavior analysis)
- HMAC-SHA256 clearance token signed per IP/session/UA
- Provider token gate: blokir traffic datacenter, allow hanya API dengan bearer token valid
- Fingerprint-based attack clustering & mitigation
- Adaptive protection modes: normal, elevated, constrained, emergency

**Host Protection**
- iptables/ipset dynamic blocking (IPv4 + IPv6 parity)
- fail2ban jails with custom filters
- Connection tracking & SYN flood protection
- Bandwidth abuse detection & quarantine
- OOM score protection (nginx, PHP-FPM, Wings, pteroq)
- Docker resource containment (CPU, memory)
- Dangerous process detection (dd, stress-ng, dll)

**Abuse Detection**
- Disk/file abuse monitoring
- Resource abuse monitoring (CPU, RAM)
- Self-DDoS detection
- Runtime abuse guard (Docker process monitoring)
- Strike-based escalation dengan threshold configurable

**Panel Overrides**
- Admin protection dashboard, WAF stats, threat timeline
- Terminal access & unblock portal
- WAF configuration UI
- UNO mini-game

**Resilience System**
- Adaptive mode orchestration (attack score + health score)
- Feature shedding (ads -> chat -> websocket -> polling)
- Request replay queue untuk non-critical API
- Redis-based consensus multi-node
- Resource governor per traffic category

**Notifications**
- Telegram startup/incident/attack notifications
- Security startup policy validation
- Security log watcher & dashboard (`check.sh`)

---

## Architecture

### C++ Daemons

| Binary | Source | Fungsi |
|--------|--------|--------|
| `dann_guard` | `src/main.cpp` + modules | Main guard: disk/file abuse, resource/network monitoring, MySQL mapping, suspension, Telegram |
| `challenge_guard` | `src/challenge_guard.cpp` | HTTP challenge/token daemon: challenge pages, clearance cookies, panel API token validation, provider gate |

### Python Sidecars

Di `scripts/`: control plane, node agent, emergency panel, terminal helper, firewall manager, runtime abuse guard, resilience orchestrator, unblock portal, security log watch, self-heal monitor, dan lainnya.

### Systemd Services (15 unit)

| Service | Fungsi |
|---------|--------|
| `pteroprotect.service` | Main guard daemon |
| `pteroprotect-challenge.service` | Challenge API daemon |
| `pteroprotect-control-plane.service` | Control plane API |
| `pteroprotect-node-agent.service` | Node agent |
| `pteroprotect-selfheal.service` | Self-heal monitor |
| `pteroprotect-abuse-guard.service` | Runtime abuse guard |
| `pteroprotect-resilience.service` | Resilience orchestrator |
| `pteroprotect-unblock-portal.service` | Unblock portal |
| `pteroprotect-terminal.service` | Break-glass terminal |
| `pteroprotect-panel-sync.service` | Panel override sync |
| `pteroprotect-log-watch.service` | Security log watch |
| `pteroprotect-ddoslog.service` | DDoS logger |
| `pteroprotect-hostguard.service` | Host firewall guard |
| `pteroprotect-emergency-panel.service` | Emergency control panel |

---

## Requirements

**OS:** Debian/Ubuntu

**Pre-installed:**
- Pterodactyl panel di `/var/www/pterodactyl`
- Wings di `/etc/pterodactyl/config.yml`
- MariaDB/MySQL (local)
- nginx serving panel
- systemd init

**Dependencies (auto-installed oleh setup.sh):**
- `build-essential`, `g++`, `make` - C++ build
- `python3` - Python sidecars
- `libmysqlclient-dev`, `libcurl4-openssl-dev`, `libssl-dev` - libraries
- `nlohmann-json3-dev` - JSON parsing
- `fail2ban`, `iproute2`, `iptables`, `ipset`, `nftables` - firewall
- `conntrack`, `curl`, `ca-certificates`
- `inotify-tools`, `perl`, `xz-utils`, `acl`, `pkg-config`, `procps`

**Optional:** `modsecurity-crs`, `libmodsecurity3`, `libnginx-mod-http-modsecurity`, `redis`, `docker`, `node >= 22`

---

## Cara Install

Pastikan VPS sudah install Pterodactyl. Siapkan config.json lalu jalankan:

```bash
sudo bash setup.sh
```

Atau:

```bash
make install
```

Override path jika perlu:

```bash
sudo bash setup.sh --install-dir /opt/pteroprotect --panel-dir /var/www/pterodactyl
```

**Yang dilakukan setup.sh:**
1. Validasi environment (root, apt, config.json)
2. Install APT dependencies
3. Build C++ binaries (`make clean && make`)
4. Copy runtime bundle ke `PREFIX` (default `/pteroprotect`)
5. Merge config.json dengan defaults, sync DB credentials dari panel `.env`
6. Apply host overrides (nginx, sysctl)
7. Install & enable systemd units
8. Apply panel overrides (Laravel files, migrations, frontend build)
9. Configurasi ModSecurity + OWASP CRS
10. Hardening host (MariaDB bind, OOM protection, Docker clamping)
11. Setup firewall (ipset, iptables, fail2ban)
12. Post-install smoke tests

---

## Konfigurasi

File: `/pteroprotect/config.json` (contoh: `config.example.json`)

### Section penting:

| Section | Fungsi |
|---------|--------|
| `database` | MySQL connection |
| `telegram` | Bot token, channel, chat ID, admin |
| `ptlc` | Panel API key (PTLC, bukan PLTA) & URL |
| `network` | WAF, challenge, rate limit, provider gate, trusted hosts, dll (~100+ keys) |
| `limits` | Check interval, max disk/CPU/RAM thresholds |
| `abuse` | Runtime abuse, strikes, escalation |
| `resilience` | Stage thresholds, feature shedding, Redis consensus |
| `monitor` | Health checks, challenge paths, lockdown config |

### Paling penting di network:

```json
{
  "rce_control_key": "xxxx",
  "unblock_portal_token": "xxxx",
  "waf_challenge_secret": "xxxxx"
}
```

### Telegram:

```json
{
  "telegram": {
    "channel": "@usernamechannel",
    "chat_id": "-100xxx",
    "creator": "@username",
    "report_channel": "@reportchannel",
    "token": "token_bot"
  }
}
```

### PTLC (bukan PLTA):

```json
{
  "ptlc": {
    "api_key": "ptlc_xxx",
    "url": "https://panel.domain.com"
  }
}
```

---

## Ports

| Port | Fungsi |
|------|--------|
| 80/443 | Panel web traffic |
| 8080 | Wings API |
| 2022 | Wings SFTP |
| 18443 | Unblock portal |
| 18444 | Challenge API (WAF) |
| 18445 | Terminal helper |
| 18446 | Control plane |
| 18447 | Emergency control |

---

## Management

**Dashboard:** `bash check.sh` — live WAF stats, connections, bandwidth, blocks

**Manual mode switch:** `bash scripts/pteroprotect-mode.sh <normal|elevated|constrained|emergency>`

**Debug/Lab:** `python3 scripts/pteroprotect-lab.py`

**Firewall:** `bash scripts/pteroprotect_firewall_manager.sh`

---

## Troubleshooting

### Yang sering lupa:
- Admin bot di channel report
- Make PLTA sebagai PTLC (salah)
- ID channel salah
- Naro node URL di web URL
- Key/token pakai spasi (recomended: pake `_`)

### Port closed
```bash
ufw allow 80 443 8080 2022 18443 18444
```

### Certificate node
```bash
certbot certonly --nginx -d node.domain
```

### Websocket & Wings mati
Buka `https://ip:18443/?token=token_di_config`, cari IP VPS, klik allow list lalu unblock.

### Service status
```bash
systemctl status pteroprotect.service
systemctl status pteroprotect-challenge.service
journalctl -u pteroprotect.service -f
```

---

## Testing & Validation

```bash
# Static validation (non-destructive)
bash scripts/validate_all.sh

# Python regression tests
python3 tests/test_rate_protect.py

# Secret scanner
python3 scripts/security_secret_scan.py --self-test

# Systemd syntax
systemd-analyze verify systemd/*.service

# Shell syntax
bash -n scripts/*.sh
```

### Frontend (panel_overrides/)

```bash
yarn install --frozen-lockfile
yarn build:production    # Build production assets
yarn tsc                 # Typecheck
yarn lint                # Lint
yarn test                # Jest tests
```

---

## File Structure

```
/root/Danex/
|-- Makefile                    # C++ build
|-- config.example.json         # Example config
|-- setup.sh                    # Main installer
|-- check.sh                    # Dashboard
|-- readme.md                   # This file
|-- waf.md                      # WAF architecture docs
|-- src/                        # C++ source (11 files)
|-- include/                    # C++ headers (9 files)
|-- systemd/                    # Systemd service units (15 files)
|-- scripts/                    # Python/Bash sidecars (43+ files)
|-- host_overrides/             # nginx, fail2ban, sysctl configs
|-- panel_overrides/            # Laravel + React panel overrides
|   |-- app/                    #   Laravel backend
|   |-- database/               #   Migrations
|   |-- resources/scripts/      #   React frontend
|   |-- resources/views/        #   Blade templates
|   |-- public/assets/          #   Compiled JS assets
|   |-- webpack.config.js       #   Webpack config
|-- tests/                      # Python test suite (17 files)
|-- docs/                       # Documentation
|-- plugins/danexprotocol/      # Protocol plugin
```

---

## Security

- **Defense in depth:** 7-layer dari L3 iptables hingga Laravel middleware
- **Fail closed:** Jika challenge_guard down, nginx return 503
- **Multi-signal:** Destructive actions perlu multiple signals, bukan 1 noisy signal
- **IPv4 + IPv6 parity** di firewall, fail2ban, rate limits
- **Systemd sandboxing:** NoNewPrivileges, ProtectSystem, PrivateTmp
- **Secrets validated** di startup oleh security_startup_policy.py

---

## CopyRight

- **Dane Everitt** — Creator of Pterodactyl
- **https://pterodactyl.io/** — MIT License
- **HexZo & Dann** — Developer & Contributor PteroProtect
