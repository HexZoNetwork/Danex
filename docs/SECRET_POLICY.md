# Secret Policy

PteroProtect startup runs `scripts/security_startup_policy.py` before the main guard.

The standalone policy command is warn-only by default, but the installed
`pteroprotect.service` sets `PTEROPROTECT_ENFORCE_STARTUP_SECRET_POLICY=1` and
therefore fails closed. Run setup before restarting an older installation so it
can seed any missing control secrets safely.

On a first install with no `config.json`, `setup.sh` now creates independent
cryptographically secure values for every control secret. It never rotates an
operator-provided or existing runtime configuration. Keep `config.json` out of
version control and pass an alternate path with `setup.sh --config PATH` when
the configuration is stored outside the checkout.

Minimum live requirements:

- `network.waf_challenge_secret`: non-placeholder, at least 24 characters.
- `network.unblock_portal_token`: non-placeholder, at least 24 characters.
- `network.rce_control_key`: non-placeholder, at least 24 characters.
- `network.emergency_control_token`: non-placeholder, at least 24 characters.
- `network.node_auth_key`: non-placeholder, at least 32 characters.
- `resilience.replay.hmac_secret`: non-placeholder, at least 24 characters.
- `database.password`: must not be a known weak/default value such as `admin001`.
- `telegram.token`: if set, must match Telegram bot token format.
