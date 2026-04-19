# PteroProtect 15-Minute Incident Playbook

This playbook is designed for active attack windows (L3/L4/L7) with minimal downtime risk.

## Minute 0-2: Confirm Attack Pattern

Run a fast scorecard from a trusted host:

```bash
bash scripts/defense_scorecard.sh https://YOUR_PANEL_DOMAIN
```

Check current auto-mode + lockdown state:

```bash
bash scripts/pteroprotect-mode.sh status
```

Tail live mitigation log:

```bash
tail -n 120 /dev/shm/pteroprotect/ddos_host.latest
```

## Minute 2-5: Escalate Protection

Switch to emergency mode for 15 minutes:

```bash
bash scripts/pteroprotect-mode.sh emergency 900
```

Reload Nginx safely:

```bash
nginx -t && systemctl reload nginx
```

Ensure host guard + fail2ban are active:

```bash
systemctl restart pteroprotect-ddoslog || true
systemctl restart fail2ban || true
```

## Minute 5-10: Validate Effectiveness

Re-run bounded smoke checks:

```bash
bash scripts/smoke_http_protection.sh https://YOUR_PANEL_DOMAIN 120 12
bash scripts/smoke_l7_defense.sh https://YOUR_PANEL_DOMAIN 140 40
```

Expected emergency behavior:

- higher share of `429` or `403`
- no full service hang
- panel still reachable for legitimate browser/challenge flow

## Minute 10-15: Stabilize and Decide

If attack is still high:

- keep `emergency` mode
- extend TTL:

```bash
bash scripts/pteroprotect-mode.sh emergency 1800
```

If attack has dropped:

- downshift to aggressive:

```bash
bash scripts/pteroprotect-mode.sh aggressive
```

If stable for several windows:

```bash
bash scripts/pteroprotect-mode.sh normal
```

## Operational Notes

- Manual and auto modes now mirror runtime flags to both:
  - `/dev/shm/pteroprotect/*`
  - `/pteroprotect/runtime/*`
- This keeps Laravel WAF mode detection and host-level detector in sync.
- For provider abuse events, keep evidence:
  - `/dev/shm/pteroprotect/ddos_host.log`
  - `/var/log/nginx/pteroprotect.access.log`
  - `/var/log/nginx/pteroprotect.sqli.log`
  - `/var/log/fail2ban.log`
