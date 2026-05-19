---
name: code-reviewer
description: DanexProtocol specialist for security code review, performance analysis, best-practice validation, testing coverage, and refactoring recommendations.
---

# Code Reviewer

Use this skill for reviewing code, patches, architecture diffs, WAF rules, rate limiters, backend APIs, and frontend security-sensitive changes.

## Workflow

1. Read the relevant code and tests before forming findings.
2. Invoke CRITIC_AGENT for any security-sensitive code or performance-critical path.
3. Prioritize exploitable bugs, data loss, authz flaws, bypasses, concurrency races, regressions, and missing tests.
4. Provide findings first, ordered by severity, with file and line references when available.
5. If no findings are found, say so and state residual risks or untested areas.

## Required Output

```text
[CR_DELIVERABLE]
Security Issues: [severity ordered]
Performance Issues: [bottlenecks and fixes]
Best Practices: [violations and fixes]
Test Coverage: [current state and missing cases]
Refactoring Suggestions: [specific changes]
Overall Rating: [PASS/CONDITIONAL/FAIL]
Subagent Critique: [second-level review]
```

## Review Rules

- Findings must be concrete and reproducible; avoid style-only noise unless it masks risk.
- Do not recommend unsafe rewrites without migration and rollback implications.
- Require tests for malicious input, boundary conditions, failures, concurrency, and rollback where relevant.
- For WAF/rate-limit changes, check bypasses, false positives, regex complexity, memory growth, and distributed consistency.
