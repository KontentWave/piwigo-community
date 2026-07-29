# Performance and Optimization Audit

## PERF-01: Permission calculation scales with the full gallery

**Severity: Medium**
**Evidence:** `include/functions_community.inc.php:24-278`.

On a cache miss, `community_get_user_permissions()` queries groups and grants, calculates Piwigo permissions, may inspect image/category relations, loads every category ID, expands descendant trees, and performs several PHP array set operations. The session cache reduces repeat work, but a global random cache key invalidates every user's permission cache after any grant change.

**Recommendation:** Cache normalized effective grants by `(user_id, permission_revision)` in a bounded shared cache, calculate only affected category subtrees, and version permission rows or scopes so unrelated users are not invalidated. Benchmark with realistic category depth and user/group counts before changing semantics.

## PERF-02: Edit Photos materializes the complete owned image set

**Severity: Medium**
**Evidence:** `edit_photos.php:45-61`, `223-307`, and `406-408`.

The page fetches every owned image ID into PHP, intersects category IDs in memory, embeds all IDs into SQL `IN (...)`, passes all IDs to JavaScript, and computes common tags across the complete set. Selecting `display=all` removes the normal page limit. Large contributor accounts will increase SQL text, PHP memory, response size, and browser memory.

**Recommendation:** Keep selection server-side by an opaque filter token, page with indexed SQL joins, cap page size, and process bulk actions in bounded batches. Do not serialize the entire result set into HTML/JavaScript.

## PERF-03: Moderation page is unpaginated and eagerly derives media

**Severity: Medium**
**Evidence:** `admin_pendings.php:133-244`.

All pending rows are loaded, all category relations are loaded, and derivative URLs are constructed for each item. The template renders a full card grid. A backlog can make moderation slow or unavailable.

**Recommendation:** Add cursor or ID-based pagination, bounded batch size, indexed state ordering, and a count query. Load medium previews only on demand.

## PERF-04: Missing table indexes and constraints amplify common queries

**Severity: High at scale**
**Evidence:** `maintain.class.php:14-40`; queries filter by permission type/user/group/category and pending state/image/notified time.

Only `community_permissions.id` is indexed. `community_pendings` has no primary key. Frequent lookups, joins, orphan cleanup, moderation counts, and notification updates therefore scan tables and duplicate pending rows are possible.

**Recommendation:** After duplicate cleanup, add:

- `PRIMARY KEY (image_id)` on pendings.
- Indexes for `(state, image_id)`, `(notified_on)`, permission `(type, user_id)`, `(type, group_id)`, and `(category_id)`.
- A uniqueness rule representing the normalized who/where grant identity.
- Foreign keys where Piwigo's supported schema policy permits them.

Validate with `EXPLAIN` on production-like data.

## PERF-05: Repeated usage aggregation is expensive

**Severity: Medium**
**Evidence:** `community_get_user_limits()` at `include/functions_community.inc.php:432-443`; repeated calls in `add_photos.php:96-149`.

Each quota check aggregates all images for a user. During rollback it repeats the full aggregate after every deletion. Piwigo stores `images.filesize` in KiB (`floor(filesize($path)/1024)`), so Community's `FLOOR(SUM(filesize)/1024)` correctly reports whole MiB for the permission field labeled MB. The arithmetic loses sub-MiB precision, but it does not create the previously suspected 1024-fold unit mismatch.

**Recommendation:** Avoid recalculating full historical usage after every uploaded image and deletion. Maintain reservation/usage counters transactionally, preferably in integer KiB to match Piwigo or explicitly named bytes, and recalculate asynchronously as a reconciliation check. Add boundary tests around KiB and MiB rounding plus exact quota limits.

## PERF-06: Frontend assets are duplicated and legacy-heavy

**Severity: Low**
**Evidence:** large inline CSS/JavaScript in `template/admin_permissions.tpl`, `template/admin_pendings.tpl`, and `template/add_photos.tpl`; copied `edit_photos.js`; bundled Fontello variants.

Inline assets reduce cache reuse and make templates difficult to parse. The upload and edit interfaces depend on older jQuery plugins and copied core behavior, increasing payload and upgrade cost.

**Recommendation:** Extract plugin assets, remove dead/commented blocks, use Piwigo's current bundled components, and load page-specific assets only. Measure before/after transfer size, parse time, and moderation/upload interaction latency.

## Optimization validation

Test at minimum 100k images, 10k albums, 10k users, 100 groups per user, 10k pending images, and concurrent chunked uploads. Capture query count, slow-query plans, PHP peak memory, p95 request latency, storage high-water mark, and cache hit ratio.
