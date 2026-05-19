---
name: danexprotocol-core
description: Route DanexProtocol requests to the right specialist skill and enforce critic gates, ambiguity handling, quality gates, and anti-hallucination discipline for WAF, anti-DDoS, backend, UI, cybersecurity, and code review work.
---

# DanexProtocol Core

Use this skill whenever the user asks for DanexProtocol, DP, Danex WAF, anti-DDoS platform, security architecture, WAF rules, traffic mitigation, or security-grade implementation guidance.

## Routing

- Use `cybersecurity-master` for threat modeling, risk assessment, compliance mapping, incident response, or security controls.
- Use `waf-anti-ddos-expert` for WAF rules, DDoS mitigation, rate limiting, traffic analysis, fingerprinting, bot detection, and Layer 3-7 protection.
- Use `backend-solver` for APIs, databases, caching, queues, distributed systems, reliability, and scaling.
- Use `web-designer` for dashboards, control panels, customer-facing UI, SEO, accessibility, and performance budgets.
- Use `code-reviewer` for security review, performance review, testing gaps, and refactoring.

## Critic Gate

Invoke a critic before security code, architecture decisions, recommendations, and final designs. If the runtime supports subagents and the user has requested DP, treat that as authorization to use a critic subagent. If subagents are unavailable, include an inline critic section.

Use this prompt shape:

```text
[CRITIC_AGENT]: Analyze this design/code for:
- Security vulnerabilities
- Performance bottlenecks
- Scalability issues
- Edge cases not handled
- Best practices violations
Provide 3-5 critical feedback points. Be harsh.
```

Critic output shape:

```text
[CRITICAL ISSUE #1]: [Description] -> [Fix]
[CRITICAL ISSUE #2]: [Description] -> [Fix]
[WARNING #1]: [Description] -> [Recommendation]
[APPROVED]: [Strengths of design/code]
```

## Ambiguity Gate

If a requirement is unclear and affects security, behavior, scale, budget, or UX, do not guess. Respond with:

```text
[AMBIGUITY DETECTED]
You mentioned "[requirement]".
- Interpretation 1: [specific meaning]
- Interpretation 2: [specific meaning]
- Interpretation 3: [specific meaning]
Questions:
1. [specific question]
2. [specific question]
3. [specific question]
```

Proceed only when assumptions are low-risk or explicitly stated by the user.

## Quality Gates

For code:

- Edge cases are explicitly handled.
- Security review is included.
- Error handling and structured logging are present.
- Timeouts, rate limits, backpressure, or circuit breakers are included where relevant.
- Critical paths include measured benchmarks or a concrete benchmark plan.
- Tests cover success, failure, malicious input, boundary conditions, and concurrency where relevant.
- No fake headers, fake CVEs, fake RFCs, fake attack names, or invented protocol behavior.

For design:

- WCAG 2.1 AA minimum.
- Dark mode native for security operations UI.
- Mobile and tablet breakpoints are considered.
- Core UI performance budget targets less than 1.5s load where feasible.
- SEO applies only to customer-facing pages.

## Reference Discipline

Prefer primary sources. Baseline references live in `../../references/standards.md`. Verify current details before citing if the fact may have changed.
