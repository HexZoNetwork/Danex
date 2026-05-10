# PteroProtect Incident Response Runbook

## Active L7/L4 attack
1. Verify attack class from `/var/log/nginx/pteroprotect.access.log`, `/var/log/nginx/error.log`, and `/var/log/nginx/modsec_audit.log`.
2. Enable emergency mode by creating `/dev/shm/pteroprotect/brownout.flag` and confirm challenge path is reachable.
3. Tighten temporary rates in `config.json` (`rate_limits.*`) and reload Nginx.
4. Confirm `fail2ban-client status` and `ipset list pteroprotect_block_v4` are growing only with hostile sources.
5. Preserve evidence: copy logs before rotation and save active firewall state (`iptables-save`, `ipset save`).

## Control-plane / challenge outage
1. Validate `pteroprotect-challenge`, `pteroprotect-control-plane`, `nginx`, `wings` status.
2. Sensitive routes fail closed by policy; verify static/health paths remain reachable.
3. Recover challenge service first, then clear emergency flags.

## Service down
1. Check `systemctl status nginx pteroprotect pteroprotect-selfheal pteroprotect-abuse-guard pteroprotect-log-watch wings`.
2. Inspect recent journal logs for each failed unit.
3. Restore from known-good config backups if `nginx -t` fails.
4. Validate panel login, API, websocket, and Wings SFTP before closing incident.
