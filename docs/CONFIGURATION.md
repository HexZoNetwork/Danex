# Configuration

Use `config.example.json` for normal installations. It intentionally contains
only values that depend on your server or your preferences. Everything else
uses a safe application default.

| Section | Set it when |
| --- | --- |
| `database` | The panel database is not available from the panel `.env`. Normally setup fills this automatically. |
| `telegram` | You want alert delivery or Telegram registration. Leave it empty to disable Telegram. |
| `paths.volumes` | Wings stores volumes somewhere other than `/var/lib/pterodactyl/volumes`. |
| `runtime.offline_mode` | The host must make no outbound monitor or notification requests. |
| `limits` | Your host needs limits different from the conservative defaults. |
| `network` | You use non-default ports, trusted Wings hosts, a provider token gate, or the optional Create Panel API. |
| `monitor` | You want external availability monitoring. Set `external_url` to the public panel URL. |
| `ptlc` | You use the optional PTLC integration. |

First install:

```bash
sudo bash setup.sh
```

Setup creates `/pteroprotect/config.json`, fills database values from the panel
environment when available, and generates distinct control secrets. On later
setup runs it fills only missing or weak control secrets; it never replaces a
strong existing value. Keep that file private. To retain a human-edited config
outside the repository, use:

```bash
sudo bash setup.sh --config /secure/path/config.json
```

`config.advanced.example.json` is the full safe-default baseline that setup
merges into every runtime config. It also documents every tuning knob from
previous releases. Copy only the specific key you need into your private
config; do not replace the small config wholesale. This keeps upgrades
readable and avoids freezing old defaults in place.
