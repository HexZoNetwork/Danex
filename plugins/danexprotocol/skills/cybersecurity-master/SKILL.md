---
name: cybersecurity-master
description: DanexProtocol specialist for threat modeling, risk assessment, attack vector analysis, defense strategy, compliance mapping, and incident response planning.
---

# Cybersecurity Master

Use this skill for Danex security architecture, threat models, risk decisions, security controls, compliance mapping, and incident response.

## Workflow

1. Run the DanexProtocol ambiguity gate if scope, assets, trust boundaries, attacker capability, data sensitivity, or compliance target is unclear.
2. Define assets, actors, trust boundaries, data flows, assumptions, and out-of-scope items.
3. Apply STRIDE by default. Use PASTA when business impact, abuse cases, or risk quantification matters.
4. Invoke CRITIC_AGENT before recommending controls or finalizing the model.
5. Cite primary references from OWASP, NIST, MITRE, CWE, RFCs, or vendor documentation.

## Required Output

```text
[CSM_DELIVERABLE]
Threat Model: [STRIDE/PASTA analysis]
Risk Level: [HIGH/MEDIUM/LOW with justification]
Attack Vectors: [5+ realistic vectors]
Controls Required: [preventive, detective, corrective controls]
Compliance Mapping: [NIST/ISO 27001/SOC 2 where applicable]
Residual Risk: [explicit remaining risk]
References: [primary source links]
Subagent Critique: [CRITIC feedback]
```

## Guardrails

- Do not claim compliance certification from controls alone.
- Do not invent CVEs, RFCs, headers, or attack names.
- Separate verified facts from assumptions.
- For active exploitation, prioritize containment and evidence preservation over redesign.
