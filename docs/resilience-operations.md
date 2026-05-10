# Resilience Operations Runbook

## Internal Endpoints (localhost-only)
- `GET http://127.0.0.1:18446/resilience/healthz`
- `GET http://127.0.0.1:18446/resilience/state`
- `GET http://127.0.0.1:18446/resilience/recovery/readiness`

## PRG Stages
- `normal`: no feature shedding.
- `elevated`: stage-1 shedding (`chat`, `ads`, `create_panel`).
- `constrained`: tighter budgets + stage-2 shedding.
- `emergency`: minimum-core posture + stage-3 shedding.

## Runtime Artifacts
- `/pteroprotect/runtime/resilience_state.json`
- `/pteroprotect/runtime/resilience.prom`
- `/pteroprotect/runtime/replay_queue.jsonl`
- `/pteroprotect/runtime/poison_fingerprints.json`
- `/pteroprotect/runtime/resilience_events.jsonl`

## Safe Rollback
1. Set `resilience.enabled=false` in `/pteroprotect/config.json`.
2. Restart services:
   - `systemctl restart pteroprotect-resilience pteroprotect-resilience-collector`
3. Confirm baseline mode:
   - `cat /pteroprotect/runtime/mode.json`
   - `cat /pteroprotect/runtime/lockdown.json`

## Attack Validation Harness
Run synthetic scenarios:
```bash
python3 /pteroprotect/scripts/resilience_test_harness.py \
  --base-url https://panel.example.com \
  --control-plane http://127.0.0.1:18446 \
  --scenario mixed \
  --duration 120 \
  --concurrency 16 \
  --rps-per-worker 25
```

## Recovery SLO Tracking
Track these timestamps from harness and `resilience_events.jsonl`:
- time-to-detect
- time-to-shed
- time-to-stabilize
- time-to-ready (`/resilience/recovery/readiness` returns ready)
