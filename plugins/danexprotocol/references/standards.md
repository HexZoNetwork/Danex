# DanexProtocol Reference Baseline

Use current primary sources where precision matters. Do not invent protocol details, CVEs, headers, attacks, RFCs, or tool capabilities.

## Security References

- OWASP Top 10 2021: https://owasp.org/Top10/
- OWASP API Security Top 10 2023: https://owasp.org/API-Security/editions/2023/en/0x00-header/
- OWASP Core Rule Set: https://coreruleset.org/docs/
- CWE Top 25: https://cwe.mitre.org/top25/
- NIST SSDF SP 800-218: https://csrc.nist.gov/publications/detail/sp/800-218/final
- NIST Cybersecurity Framework 2.0: https://www.nist.gov/cyberframework
- MITRE ATT&CK: https://attack.mitre.org/

## Protocol References

- HTTP Semantics RFC 9110: https://www.rfc-editor.org/rfc/rfc9110
- HTTP/2 RFC 9113: https://www.rfc-editor.org/rfc/rfc9113
- HTTP/3 RFC 9114: https://www.rfc-editor.org/rfc/rfc9114
- TLS 1.3 RFC 8446: https://www.rfc-editor.org/rfc/rfc8446
- QUIC RFC 9000: https://www.rfc-editor.org/rfc/rfc9000

## Design And Accessibility References

- WCAG 2.1: https://www.w3.org/TR/WCAG21/
- Web.dev performance guidance: https://web.dev/learn/performance/
- Google Search structured data docs: https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data

## DanexProtocol Non-Negotiables

- Ask instead of guessing when requirements change behavior, security posture, performance targets, or cost.
- Use critic review before security code, architecture decisions, recommendations, and final designs.
- If runtime subagents are unavailable, run an inline CRITIC_AGENT section with the same prompt and make that limitation explicit.
- Production code must include input validation, timeouts, rate limiting or backpressure where relevant, circuit breakers for external dependencies, structured logging, and clear failure modes.
- Critical paths require a benchmark plan or measured benchmark output.
