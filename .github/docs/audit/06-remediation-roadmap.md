# Remediation Roadmap

## Phase 0: Immediate containment

Target: before the next production exposure change.

- Disable ZIP upload for Community users.
- Restrict Community grants to authenticated, trusted contributors and moderated albums.
- Apply web server body, rate, concurrency, and upload-buffer limits.
- Monitor unexpected destination albums, new tags, buffer growth, and failed cleanup.
- Back up plugin tables, category ownership data, and upload storage.

## Phase 1: Security release blocker

Target: first patch release.

1. Replace `community_switch_user_to_admin` with Community-owned, method-specific adapters.
2. Validate all category IDs against effective grants before any upload/chunk write.
3. Bind chunk state to actor, destination, checksum, size, and expiry.
4. Add ownership/category checks to upload completion and image mutation.
5. Convert all admin mutations to POST plus `pwg_token`; remove permission deletion by GET.
6. Add archive path and resource limits or remove archive support.

Acceptance tests:

- A user granted album A cannot upload, chunk, complete, edit, or delete in album B.
- A mixed `[A, B]` destination request fails entirely.
- A user with upload rights cannot gain unrelated admin webservice behavior.
- Missing, stale, and forged CSRF tokens cause zero writes.
- Traversal, absolute path, symlink-like, high-ratio, too-many-entry, and over-quota archives fail and leave no files.

## Phase 2: Integrity and dependability

Target: second patch/minor release.

1. Correct quota units and the `$idx` rollback bug.
2. Introduce atomic byte/photo reservations shared by all upload transports.
3. Migrate plugin tables to InnoDB with primary, unique, and query indexes.
4. Implement explicit moderation states and transaction-aware transitions.
5. Stage files outside public visibility until commit; add idempotent reconciliation.
6. Add notification outbox/retry and deterministic deduplication.
7. Make install/upgrade/uninstall migrations idempotent and observable.

Acceptance tests:

- Parallel uploads cannot exceed configured count or byte quotas.
- Failure injection after every write leaves a recoverable state and no visible unmoderated image.
- Validation/rejection is idempotent under retries and concurrent administrators.
- Migration preserves grants, ownership, pending state, and cache behavior on production-sized copies.

## Phase 3: Maintainable boundaries

Target: next minor release.

- Extract authorization, repositories, quota, archive, upload, moderation, and notification services.
- Add the exception hierarchy and one response translator per Piwigo boundary.
- Replace global/request mutation with typed inputs and explicit results.
- Add structured logs, correlation IDs, metrics, and operational alerts.
- Paginate edit/moderation data and remove unbounded ID serialization.
- Document hook payloads, dependency contracts, and supported versions.

## Phase 4: Legacy retirement and expansion

Target: planned major release.

- Set and enforce minimum Piwigo/PHP/database versions.
- Remove dead compatibility paths and copied obsolete core UI code.
- Move inline assets into tested, page-scoped files and adopt current Piwigo components.
- Add storage adapter contracts and asynchronous processing where large uploads require it.
- Establish deprecation policy and migration guides for webservice/hook consumers.

## Test and CI gate

Every release should run:

- PHP syntax checks across plugin PHP files on every supported PHP version.
- Static analysis and code-style checks with no new baseline debt.
- Unit tests for permission precedence, destination checks, quota arithmetic, archive policy, and state transitions.
- Piwigo integration tests for webservice/admin/gallery flows.
- Migration tests from every supported installed schema.
- Browser tests for upload, cancellation, retry, edit, moderation, and CSRF failures.
- Concurrency tests for quota reservations, completion, notification, validation, and rejection.
- Dependency/license and secret scanning.

## Definition of production-ready

No open Critical/High findings; all mutation paths have explicit authentication, authorization, CSRF where applicable, validation, and failure translation; all upload transports share the same quota and moderation policy; recovery tests pass; schema migrations are rehearsed; and dashboards alert on stuck or inconsistent upload state.
