# Secret Policy

PteroProtect startup runs `scripts/security_startup_policy.py` before the main guard.

Default mode is warn-only to avoid taking production offline during rotation. Set `PTEROPROTECT_ENFORCE_STARTUP_SECRET_POLICY=1` in the service environment after rotating weak values to make startup fail closed.

Minimum live requirements:

- `network.waf_challenge_secret`: non-placeholder, at least 24 characters.
- `network.unblock_portal_token`: non-placeholder, at least 24 characters.
- `network.rce_control_key`: non-placeholder, at least 24 characters.
- `network.node_auth_key`: non-placeholder, at least 32 characters.
- `database.password`: must not be a known weak/default value such as `admin001`.
- `telegram.token`: if set, must match Telegram bot token format.
