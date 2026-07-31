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
   Status 2026-07-31: partially completed. `pwg.images.addSimple`, `pwg.images.upload`, `pwg.images.uploadAsync`, `pwg.images.add`, `pwg.images.addChunk`, `pwg.images.uploadCompleted`, `pwg.images.setInfo`, `pwg.images.delete`, `pwg.categories.add`, and `pwg.tags.add` now use Community-owned wrappers with normalized authorization and method-specific checks. Both upload-completion public names share one scoped service without status elevation or global lounge draining. `pwg.images.uploadAsync` and the legacy `pwg.images.add` + `pwg.images.addChunk` lifecycle persist plugin-owned, lock-protected upload state across independent requests. `pwg.images.upload` rejects Community `update_mode` because the core delegate cannot safely pin the replacement target. Category/tag creation is POST-only with `pwg_token`; category creation enforces root/parent grants and refreshes the Community permission-cache revision after success, while tag creation requires an effective upload grant. Genuine administrators and `faked_by_community=false` retain core behavior. Ambient elevation remains only for `pwg.images.exist`, `pwg.images.checkUpload`, `pwg.images.checkFiles`, and `pwg.session.getStatus`.
2. Reject legacy upload checksums that are not exactly 32 hexadecimal characters before filesystem or SQL use, and remove raw checksum interpolation.
   Status 2026-07-30: completed in plugin-owned wrappers for `pwg.images.add` and `pwg.images.addChunk`, with escaped Community checksum lookup and escaped filename uniqueness precheck for the legacy `original_filename` SQL path.
3. Validate all category IDs against effective grants before any upload/chunk write.
   Status 2026-07-31: partially completed for SEC-01 Slice 1 only. `pwg.images.addSimple`, `pwg.images.upload`, `pwg.images.uploadAsync`, and the legacy `pwg.images.add` finalizer now reject missing, malformed, non-positive, mixed, and unauthorized destination categories atomically before delegation or persistence, and `pwg.images.uploadAsync` repeats destination authorization immediately before final persistence.
4. Bind chunk state to actor, destination, checksum, size, and expiry.
   Status 2026-07-31: partially completed for SEC-01 Slice 1 only. `pwg.images.uploadAsync` now persists plugin-owned upload state under the upload buffer and binds first-accepted chunks to authenticated user ID, guest/generic session identity, normalized destination categories, `original_sum`, total chunk count, `image_id`, filename, final-write metadata, actual received bytes, creation time, and expiry; later chunks must match that immutable manifest, identical retries are idempotent, conflicting retries are rejected, and exact artifact cleanup occurs on expiry and successful completion. The legacy `pwg.images.add` + `pwg.images.addChunk` lifecycle now also persists a plugin-owned manifest and chunk store under the upload buffer, binding accepted chunks to authenticated user ID, guest/generic session identity, checksum, chunk type, chunk position, byte size, creation time, and expiry before any later `pwg.images.add` request can finalize them. Both file-backed lifecycles retain their state directory and lock-file placeholder so overlapping requests always contend on one stable inode; forked process tests verify held and reacquired locks are not deleted, a third contender remains excluded, unrelated state survives cleanup, and exact upload artifacts plus the optional receipt retain their existing cleanup behavior.
5. Add ownership/category checks to upload completion, image replacement, format attachment, update mode, and other image mutation.
   Status 2026-07-31: partially completed. `pwg.images.addSimple`, `pwg.images.uploadAsync`, and the legacy `pwg.images.add` finalizer replacement path require ownership and, for guest/generic users, current-session provenance immediately before delegation or persistence. `pwg.images.upload` authorizes `format_of` and rejects Community `update_mode` until replacement targets can be pinned. Upload completion now atomically validates every canonical image against owner/session, effective category grant, category existence, and exact association-or-lounge eligibility; it finalizes only those lounge tuples and uses stable per-image locks plus a canonical receipt for retry and overlap idempotence. `pwg.images.setInfo` and `pwg.images.delete` now share strict object authorization requiring an upload grant, ownership, guest/generic session provenance, and exclusively authorized current category associations immediately before one core delegation. Metadata, category, tag, privacy, token, and batch inputs are validated locally and denied atomically without status elevation. Category/tag creation now has its own normalized POST/token boundary; the remaining elevated helper methods remain open.
6. Convert all admin mutations to POST plus `pwg_token`; remove permission deletion by GET.
7. Add archive path and resource limits or remove archive support.

Acceptance tests:

- A user granted album A cannot upload, chunk, complete, edit, or delete in album B.
- A user cannot replace another owner's image, attach a format to it, or target it through upload update mode.
- Invalid or adversarial `original_sum` values are rejected before creating buffer files or issuing queries.
- `pwg.images.add` and `pwg.images.addChunk` activate Community wrappers in the real `ws_add_methods` lifecycle for non-admin requests, while genuine administrators retain the untouched core callbacks.
- Buffered legacy chunks cannot be finalized by a different actor than the one who wrote them.
- Legacy and `uploadAsync` cleanup cannot replace or remove a held/reacquired lock inode, admit an overlapping contender under a different lock, or remove another request's unrelated state.
- A legacy `image_id` replacement succeeds only for the owner and, for guest/generic users, only for the current session provenance.
- Quote and metacharacter `original_filename` values cannot alter filename uniqueness SQL structure, and valid duplicate/non-duplicate filename behavior remains unchanged.
- A mixed `[A, B]` destination request fails entirely.
- A user with upload rights cannot gain unrelated admin webservice behavior.
- Missing, stale, and forged CSRF tokens cause zero writes.
- Either upload-completion public method authorizes and finalizes only the caller's exact image/category batch, never drains unrelated lounge rows, and remains idempotent under sequential retries and overlapping requests.
- The upload browser sends one authoritative completion request and exposes completion failures instead of independently firing both public methods.
- Category creation accepts only an authorized existing parent or an authorized root request, refreshes recursive Community permissions after success, and never uses ambient administrator status.
- Tag creation requires an effective upload grant; category-creation rights alone are insufficient.
- Community category/tag creation requires POST plus a valid token, delegates exactly once, and leaves denied requests without creation, activity, hook, or cache side effects.
- Traversal, absolute path, symlink-like, high-ratio, too-many-entry, and over-quota archives fail and leave no files.

## Phase 2: Integrity and dependability

Target: second patch/minor release.

1. Correct the `$idx` rollback bug and remove repeated full-history quota aggregation during rollback.
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
