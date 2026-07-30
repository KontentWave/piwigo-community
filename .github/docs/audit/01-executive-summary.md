# Executive Summary

Audit date: 2026-07-30
Release posture: **Conditional, remediation required**

## Decision

Do not expose this version to broadly untrusted uploaders until SEC-01 through SEC-04 are resolved. The plugin has a coherent permission model and uses Piwigo validation helpers in many places, but its webservice bridge converts limited Community rights into ambient administrator status. That elevation still exposes existing-image replacement and format attachment without ownership checks, and the browser upload path still commits files before applying quotas and moderation state.

Update 2026-07-30: SEC-06 has plugin-owned guards and acceptance tests for `pwg.images.add`, `pwg.images.addChunk`, and filename uniqueness prechecks. The overall release posture does not change because the broader authorization and upload-flow findings remain open.

For a small, trusted contributor group behind normal Piwigo authentication, deployment can continue temporarily with ZIP uploads disabled, tightly scoped Community permissions, monitored storage, and administrator CSRF protections supplied at the reverse proxy or application layer. These are compensating controls, not fixes.

## Risk profile

| Area            | Rating   | Main reason                                                                                      |
| --------------- | -------- | ------------------------------------------------------------------------------------------------ |
| Security        | Critical | Webservice admin elevation is broader than the granted album permission.                         |
| Reliability     | High     | Upload, quota, moderation, notification, and deletion are not atomic.                            |
| Optimization    | Medium   | Permission evaluation scans albums and edit pages materialize full user image sets.              |
| Maintainability | High     | Global state, request mutation, SQL strings, and mixed controller/view concerns dominate.        |
| Expandability   | Medium   | Useful hooks exist, but there are no stable domain services or typed contracts.                  |
| Error handling  | High     | Fatal strings, silent return values, unchecked I/O, and `PwgError` are mixed ad hoc.             |
| Legacy burden   | Medium   | Compatibility branches, old uploader assumptions, MyISAM/utf8 schema, and copied core UI remain. |

## Priority findings

| ID       | Severity   | Finding                                                                                       |
| -------- | ---------- | --------------------------------------------------------------------------------------------- |
| SEC-01   | Critical   | Upload webservices gain administrator status without destination or object authorization.     |
| SEC-06   | Remediated | Legacy upload checksum and filename uniqueness paths are now guarded in Community wrappers.   |
| SEC-02   | High       | Admin state changes lack consistent CSRF validation; deletion is performed by GET.            |
| SEC-03   | High       | ZIP extraction has no canonical-path, expansion-size, entry-count, or depth limits.           |
| SEC-04   | High       | Quotas are checked after persistence and can be exceeded concurrently.                        |
| REL-01   | High       | Moderation and upload state span non-transactional MyISAM tables and filesystem changes.      |
| REL-02   | High       | Upload return values, archive operations, response decoding, and mail outcomes are unchecked. |
| MAINT-01 | High       | Authorization depends on mutating `$user['status']`, `$_POST`, and global configuration.      |
| PERF-01  | Medium     | Permission calculation repeatedly loads the full category universe and descendant trees.      |

## Positive controls already present

- Plugin entry points reject direct execution when `PHPWG_ROOT_PATH` is absent.
- Administrative pages call `check_status(ACCESS_ADMINISTRATOR)`.
- Member bulk editing checks `check_pwg_token()` and intersects selected photos with `added_by` ownership.
- Webservice upload completion validates `pwg_token`.
- IDs are commonly validated or cast before interpolation into SQL.
- Permission changes rotate a cache key, and deletion hooks remove plugin records.
- Templates generally rely on Smarty escaping and Piwigo-provided rendering helpers.

## Release gates

1. Replace ambient webservice admin elevation with method-specific authorization and verify every requested album/image.
2. Add POST-only CSRF checks to every administrator mutation and remove GET deletion.
3. Add bounded archive inspection/extraction and server-side preflight quotas.
4. Migrate plugin tables to InnoDB with keys and uniqueness constraints; introduce transaction-aware state transitions.
5. Add integration tests for denied destinations, ownership, CSRF, quota races, archive abuse, moderation rollback, and user deletion.
6. Establish structured logging and one application-to-Piwigo error translation boundary.

See [06-remediation-roadmap.md](06-remediation-roadmap.md) for implementation order and acceptance criteria.
