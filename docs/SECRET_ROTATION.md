# Secret Rotation and History Hygiene

PteroProtect previously tracked development/mock credentials under `.codespaces/`.
Those files are removed from the working tree and ignored going forward, but Git
history still contains them. Treat every value in that history as compromised.

## Rotate first

1. **Telegram bot token**: revoke it with `@BotFather` (`/revoke`), create a new token,
   then update `telegram.token` through `pteroprotect-adminctl config-write`.
2. **Database password**: rotate the panel DB user password, update the panel `.env`
   and the matching `database.password` value, then restart the panel queue workers.
3. **Control secrets**: run
   `python3 scripts/security_startup_policy.py /pteroprotect/config.json --generate-missing`
   and restart the PteroProtect services.
4. **Replay HMAC secret**: the same helper fills `resilience.replay.hmac_secret` when
   it is missing or a placeholder.
5. Revoke any other token, webhook secret, or API key that ever appeared in the
   removed mock files (`runtime/real-panel-db-password.txt`, `runtime/codespaces.env`,
   any committed `config.json`).

## Verify

```bash
python3 scripts/security_secret_scan.py .
python3 scripts/security_startup_policy.py /pteroprotect/config.json --enforce
bash scripts/purge_committed_secrets.sh --report
```

`config.json` must be `root:www-data` mode `0640`, and only the `www-data` group may
read it. Use `pteroprotect-adminctl config-write` instead of editing it in place.

## Rewrite history only after rotation

History rewrite is disruptive and must be coordinated with every clone/fork. Never
rewrite a shared branch before the exposed credentials are revoked, because old
clones still contain them.

```bash
PTEROPROTECT_CONFIRM_PURGE=1 bash scripts/purge_committed_secrets.sh --purge-history
```

The helper builds a scrubbed mirror clone with `git-filter-repo`; it does not touch
the working repository. Review the mirror, notify collaborators, then force-push
deliberately. GitHub also requires a support request to purge cached views/forks of
already-exposed secrets. Rotating the credentials remains the only reliable fix.
