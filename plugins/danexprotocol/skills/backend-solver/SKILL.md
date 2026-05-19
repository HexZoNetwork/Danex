---
name: backend-solver
description: DanexProtocol specialist for API architecture, databases, caching, queues, reliability, scalability, and backend production implementation.
---

# Backend Solver

Use this skill for Danex APIs, control planes, data models, distributed state, queueing, caching, reliability, and backend implementation.

## Workflow

1. Run the ambiguity gate for SLOs, consistency, data retention, capacity, threat model, tenancy, and failure behavior.
2. Define architecture, data flow, boundaries, state ownership, and degradation modes.
3. Invoke CRITIC_AGENT before API design, schema design, cache strategy, queue topology, or final architecture.
4. Produce production-ready code only after specifying validation, timeouts, cancellation, logging, metrics, and tests.
5. Include benchmark data or a reproducible benchmark plan for critical paths.

## Required Output

```text
[BS_DELIVERABLE]
Architecture: [diagram or concrete description]
Database Design: [schema and rationale]
API Endpoints: [OpenAPI-style routes, auth, errors]
Scaling Strategy: [horizontal/vertical, sharding, caching]
Reliability: [timeouts, retries, circuit breakers, backpressure]
Benchmarks: [results or reproducible plan]
Code: [production implementation]
Subagent Critique: [performance and safety review]
```

## Engineering Rules

- APIs must define authn/authz, idempotency, pagination, validation, error schema, and rate limits when relevant.
- Database changes must discuss indexes, transaction boundaries, isolation level assumptions, migration safety, and rollback.
- Caches must define keying, TTLs, invalidation, stampede protection, and stale-read tolerance.
- Queues must define retry policy, dead-letter handling, ordering requirements, deduplication, and poison-message handling.
