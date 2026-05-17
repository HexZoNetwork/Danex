# Security Test Corpus

This directory contains deterministic fixtures for security scanners and future WAF/monitor tests. Fixtures must be fake, local, and safe. Do not copy production logs, live `config.json`, real tokens, private keys, customer data, or real panel sessions here.

## Layout
- `benign/`: examples that must not trigger enforcement.
- `malicious/`: examples that must trigger the expected detector when the detector test runs.
- `edge/`: ambiguous cases used to tune false positives.
- `regression/`: previously fixed bugs that must stay fixed.
- `expected/`: machine-readable expectations for corpus tests.

## Rules
- Malicious fixtures use fake values only.
- Expected detections must name a rule ID, not depend on matching a full secret value.
- Scanner output must redact sensitive values.
- Benign fixtures should represent real Pterodactyl behavior: panel polling, resource endpoints, Wings remote API, chat, RUM, file manager, and admin actions.

## Current Coverage
- Secret scanner benign placeholders.
- Secret scanner malicious fake tokens/private keys.
- Regression placeholders for Wings certificate selection and DDoS logger log readability.

Future corpus additions should be reviewed with `docs/REVIEW_CHECKLIST.md`.
