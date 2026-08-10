# Community Plugin Production Audit

Audit date: 2026-07-29
Plugin version: 16.f
Scope: `/home/marcel/projects/piwigo/plugins/community`

## Executive verdict

The plugin should not be treated as production-hardened without compensating controls and remediation. Its core authorization model is understandable, and scoped webservice adapters, legacy checksum guards, and POST-only CSRF-protected administrator mutations now address SEC-01, SEC-06, and SEC-02. Upload quotas are still enforced after files are committed, ZIP processing has no explicit expansion or path-safety limits, and the MyISAM schema prevents transactional moderation workflows.

The recommended release posture is **conditional / remediation required**. Resolve all Critical and High findings before broad untrusted-user deployment. Resolve Medium reliability findings before promising upload or moderation durability.

## Documents

- [01-executive-summary.md](01-executive-summary.md): production decision, risk profile, and priorities.
- [02-security.md](02-security.md): authorization, CSRF, upload, output, and data-integrity findings.
- [03-performance-and-optimization.md](03-performance-and-optimization.md): database, caching, upload, and frontend costs.
- [04-reliability-and-error-handling.md](04-reliability-and-error-handling.md): failure modes, transactions, observability, and custom exception strategy.
- [05-maintainability-expandability-legacy.md](05-maintainability-expandability-legacy.md): architecture, extension points, technical debt, and modernization.
- [06-remediation-roadmap.md](06-remediation-roadmap.md): ordered implementation plan and release gates.
- [07-file-inventory.md](07-file-inventory.md): reviewed file groups and coverage notes.

## Method

This was a static production-readiness audit of plugin-owned PHP, Smarty templates, JavaScript, CSS, metadata, bundled Fontello assets, localization structure, and repository documentation. Direct Piwigo integration points were inspected where necessary to understand hooks, webservice privilege changes, database tables, cache invalidation, upload behavior, and plugin lifecycle.

Generated and third-party artifacts were reviewed by role and provenance rather than line by line: Fontello binaries and generated CSS, translated copies of the canonical language catalog, and license/readme assets. The plugin-owned executable paths received line-level review.

No dynamic penetration test, concurrent upload load test, database migration rehearsal, browser compatibility matrix, or mail-delivery test was performed. Findings marked as architectural risks should be confirmed in a staging environment matching production PHP, database, web server, storage, and Piwigo versions.

## Severity model

| Severity | Meaning                                                                              |
| -------- | ------------------------------------------------------------------------------------ |
| Critical | Plausible privilege boundary failure or major compromise requiring immediate action. |
| High     | Material security, integrity, or availability risk likely to affect production.      |
| Medium   | Important reliability, performance, or maintainability weakness with bounded impact. |
| Low      | Hardening, consistency, or modernization improvement.                                |

## Evidence convention

Each finding identifies the owning file and source line or function, explains the production impact, and gives a concrete remediation. Line numbers refer to the audited working tree on 2026-07-29 and may move as fixes are applied.
