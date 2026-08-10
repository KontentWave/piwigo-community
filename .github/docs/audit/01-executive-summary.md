# Executive Summary

Audit date: 2026-07-30
Release posture: **Conditional, remediation required**

## Decision

SEC-01 through SEC-04 and SEC-06 are remediated locally, but the plugin remains conditional for broadly untrusted uploaders because REL-01/02 still leave filesystem, moderation, notification, and recovery workflows non-transactional. SEC-04 now rejects quota excess before Community persistence or plugin-controlled buffering; this does not replace web-server body, rate, concurrency, or global buffer controls.

Update 2026-07-30: SEC-06 has plugin-owned guards and acceptance tests for `pwg.images.add`, `pwg.images.addChunk`, and filename uniqueness prechecks. The overall release posture does not change because the broader authorization and upload-flow findings remain open.

Update 2026-07-31: SEC-01 is remediated. Community upload webservices and compatibility helpers now use method-specific authorization without administrator impersonation; the local `tests/OriginalSumGuardTest.php` suite passes with 196 tests and 1052 assertions. This is local PHPUnit evidence, not GitHub Actions or CI evidence. At that date, SEC-02, SEC-03, SEC-04, and REL-01/02 remained open.

Update 2026-08-10: SEC-02 is remediated. Administrator permission, configuration, album ownership, and pending-photo mutations now require POST, one strict action, and Piwigo's canonical token before mutation-specific work; permission deletion is no longer available through GET. The focused local suite passes with 22 tests and 532 assertions, and the complete configured local suite passes with 218 tests and 1584 assertions, with one existing warning and one PHPUnit deprecation. This is local-suite evidence, not GitHub Actions or CI evidence. The release posture remains conditional because SEC-04 and REL-01/02 remain open.

Update 2026-08-10: SEC-03 is remediated by intentionally removing Community ZIP upload compatibility. The server preflights complete direct-upload batches and rejects ZIP or malformed successful filenames before plugin-initiated filesystem, persistence, session, hook, moderation, or cleanup effects; the uploader advertises only configured picture extensions without mutating global configuration. The focused local suite passes with 15 tests and 188 assertions, and the complete configured suite passes with 233 tests and 1772 assertions, with the same existing warning and PHPUnit deprecation. This is local-suite evidence, not GitHub Actions or CI evidence. SEC-04 and REL-01/02 remain open.

Update 2026-08-10: SEC-04 is remediated with one InnoDB-backed per-user reservation boundary shared by direct, `addSimple`, single multipart `upload`, `uploadAsync`, and legacy `addChunk`/`add` transports. Exact observed bytes are reserved before plugin-controlled writes or delegates, active reservations join fresh committed image/format usage, and replacement/format operations charge only positive storage deltas. A real two-process MariaDB 11.4.8 test proves row-lock serialization for independent photo and byte races. The focused local suite passes with 22 tests and 142 assertions; the complete configured local suite passes with 255 tests and 1914 assertions, with the existing warning and PHPUnit deprecation. This is local process/database evidence, not GitHub Actions or CI evidence. REL-01/02 remain open, so the plugin is not declared production-ready.

For a small, trusted contributor group behind normal Piwigo authentication, deployment can continue temporarily with ZIP uploads disabled, tightly scoped Community permissions, and monitored storage. These are compensating controls for the remaining findings, not fixes.

## Risk profile

| Area            | Rating | Main reason                                                                                                                              |
| --------------- | ------ | ---------------------------------------------------------------------------------------------------------------------------------------- |
| Security        | Medium | High-severity upload authorization, CSRF, archive, and quota findings are remediated locally; operational upload limits remain required. |
| Reliability     | High   | Quota reservation is atomic, but upload persistence, moderation, notification, deletion, and crash recovery are not.                     |
| Optimization    | Medium | Permission evaluation scans albums and edit pages materialize full user image sets.                                                      |
| Maintainability | High   | Global state, request mutation, SQL strings, and mixed controller/view concerns dominate.                                                |
| Expandability   | Medium | Useful hooks exist, but there are no stable domain services or typed contracts.                                                          |
| Error handling  | High   | Fatal strings, silent return values, unchecked I/O, and `PwgError` are mixed ad hoc.                                                     |
| Legacy burden   | Medium | Compatibility branches, old uploader assumptions, MyISAM/utf8 schema, and copied core UI remain.                                         |

## Priority findings

| ID       | Severity   | Finding                                                                                       |
| -------- | ---------- | --------------------------------------------------------------------------------------------- |
| SEC-01   | Remediated | Upload webservices now use scoped authorization without administrator status elevation.       |
| SEC-06   | Remediated | Legacy upload checksum and filename uniqueness paths are now guarded in Community wrappers.   |
| SEC-02   | Remediated | Admin mutations now require strict POST actions and canonical CSRF validation.                |
| SEC-03   | Remediated | Community ZIP upload and extraction support are intentionally removed.                        |
| SEC-04   | Remediated | InnoDB per-user reservations enforce committed-plus-reserved count and byte quotas pre-write. |
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

1. Complete REL-01/02 recovery design for filesystem, image, moderation, and notification transitions.
2. Migrate the remaining plugin tables to InnoDB with keys and transaction-aware state transitions.
3. Add integration tests for moderation rollback, user deletion, and crash reconciliation.
4. Establish structured logging and one application-to-Piwigo error translation boundary.

See [06-remediation-roadmap.md](06-remediation-roadmap.md) for implementation order and acceptance criteria.
