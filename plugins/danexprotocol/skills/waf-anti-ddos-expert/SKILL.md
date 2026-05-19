---
name: waf-anti-ddos-expert
description: DanexProtocol specialist for WAF rules, DDoS mitigation, traffic analysis, rate limiting, fingerprinting, bot detection, and Layer 3-7 defense design.
---

# WAF & Anti-DDoS Expert

Use this skill for WAF rules, OWASP CRS tuning, DDoS mitigation, rate limiting algorithms, traffic fingerprints, bot mitigation, and Layer 3-7 protection.

## Workflow

1. Run the ambiguity gate for traffic volume, attack class, protected protocols, false-positive tolerance, deployment point, latency budget, and fail-open/fail-closed behavior.
2. Characterize the attack by layer, traffic shape, request semantics, source distribution, and bypass paths.
3. Choose mitigation controls across network, transport, TLS, HTTP, application, cache, and origin layers.
4. Invoke CRITIC_AGENT before creating WAF rules, mitigation strategy, rate limiter code, or final recommendations.
5. Include validation payloads, false-positive cases, rollback plan, metrics, and benchmark method.

## Required Output

```text
[WDE_DELIVERABLE]
Threat Analysis: [attack type, characteristics, confidence]
Mitigation Strategy: [Layer 3-7 controls]
WAF Rules: [ModSecurity/OWASP CRS/custom rules, if requested]
Rate Limiting: [algorithm, thresholds, keying, burst behavior]
Detection Signatures: [regex/YARA/custom signatures only when appropriate]
Performance Impact: [latency, throughput, false-positive target]
Operational Plan: [deploy, monitor, rollback]
Code: [production implementation when requested]
Subagent Critique: [security effectiveness review]
References: [OWASP CRS, RFC, vendor or threat intel links]
```

## Engineering Rules

- Prefer allow-listing and protocol-aware parsing over brittle regex when feasible.
- Bound regex complexity; reject catastrophic backtracking risks.
- Rate limiters must define identity keys, NAT handling, IPv6 aggregation, clock behavior, distributed consistency, memory limits, and eviction.
- Circuit breakers and timeouts are mandatory for external reputation, telemetry, or policy lookups.
- Never block solely on unauthenticated mutable headers unless the trusted proxy chain is defined.
