# Maintainability, Expandability, and Legacy Audit

## Current architecture

The plugin is primarily procedural controllers plus event callbacks:

- `main.inc.php` registers hooks, webservices, authorization adaptation, moderation, notifications, and lifecycle cleanup.
- Page scripts combine request parsing, policy, SQL, writes, and template composition.
- `include/functions_community.inc.php` combines permission policy, caching, user-album provisioning, cleanup, quota aggregation, and compatibility code.
- Templates contain substantial CSS and JavaScript behavior.
- Piwigo globals, constants, request superglobals, and mutable session state are implicit dependencies.

This structure works for a small feature set but raises change risk because policy is duplicated across browser uploads, webservice uploads, edit pages, and admin pages.

## MAINT-01: Global privilege and request mutation

**Severity: High**
**Evidence:** `main.inc.php:309-468`.

Changing `$user['status']`, unsetting/rewriting `$_POST`, and changing `$conf['allow_html_descriptions']` makes authorization dependent on handler order and affects unrelated code in the same request. There is no guaranteed restoration path.

**Recommendation:** Introduce immutable request DTOs and an explicit `CommunityAuthorizationPolicy`. Core integration adapters should pass a scoped capability object to an upload service; global user/config state must remain unchanged.

## MAINT-02: Controllers mix too many responsibilities

**Severity: High**
**Evidence:** all plugin PHP entry points, especially `add_photos.php`, `admin_permissions.php`, and `main.inc.php`.

Business rules cannot be tested without bootstrapping Piwigo globals and a database. SQL, rendering, validation, filesystem work, and messaging are interleaved.

**Recommendation:** Incrementally extract, without a framework rewrite:

- `PermissionRepository` and `PendingUploadRepository`.
- `CommunityAuthorizationPolicy`.
- `QuotaService` with reservation semantics.
- `ArchiveInspector` and `UploadService`.
- `ModerationService` with explicit transitions.
- `NotificationOutbox`.
- Thin Piwigo page/webservice/hook adapters.

Start with SEC-01 so the first abstraction removes real security complexity.

## MAINT-03: No automated test suite or static-analysis configuration

**Severity: High**
**Evidence:** no plugin-owned test, PHPStan/Psalm, lint, or CI configuration was found in scope.

Authorization and migration behavior is too subtle for manual-only verification.

**Recommendation:** Add a supported Piwigo integration-test harness, unit tests around pure policy objects, PHP syntax checks for supported runtimes, targeted static analysis with a baseline, JavaScript linting, migration tests, and security regression tests. Keep the compatibility matrix explicit in metadata and CI.

## MAINT-04: Data model lacks enforceable invariants

**Severity: Medium**
**Evidence:** `maintain.class.php:14-40`.

String enums (`'true'/'false'`), nullable polymorphic subject columns, unconstrained state strings, no pending primary key, and no foreign keys push invariants into PHP. Duplicate grants and duplicate pending rows remain possible.

**Recommendation:** Use compact booleans/current Piwigo conventions, constrained state values, primary/unique keys, timestamps for transitions, and explicit subject/scope representation. Add a migration that detects and reports ambiguous duplicates rather than silently discarding them.

## MAINT-05: Extension points exist but contracts are informal

**Severity: Medium**
**Evidence:** event registrations across `main.inc.php`; `community_element_set_global_action` and `community_loc_end_element_set_global` in `edit_photos.php`.

Piwigo events provide useful integration points, but payload shapes, mutation expectations, ordering, and failure behavior are undocumented. The optional two-factor integration probes functions and constants directly.

**Recommendation:** Document each hook's payload and timing, expose stable service interfaces for policy decisions, and add capability checks/version contracts for optional plugins. Prefer a dedicated event before/after a successful state transition over exposing mutable globals.

## Legacy inventory

| Legacy element                         | Evidence                                                                 | Action                                                             |
| -------------------------------------- | ------------------------------------------------------------------------ | ------------------------------------------------------------------ | ------------------------- | ---------------------------------------------- |
| Ambient admin compatibility bridge     | `community_switch_user_to_admin`                                         | Replace immediately.                                               |
| MyISAM and `utf8` schema               | `maintain.class.php`                                                     | Migrate to InnoDB and current Piwigo charset/collation.            |
| Old-version branches                   | Piwigo `<2.10` branch in `main.inc.php`; `safe_version_compare` fallback | Define minimum supported Piwigo/PHP, then remove dead branches.    |
| Copied core UI logic                   | header in `edit_photos.js`; old progressbar/manageAjax patterns          | Rebase on current Piwigo APIs or provide a small owned component.  |
| PclZip archive processing              | upload include                                                           | Prefer maintained core/archive API with explicit limits.           |
| Inline template assets                 | admin/upload templates                                                   | Extract and lint.                                                  |
| Historical commented debug/TODO blocks | PHP and templates                                                        | Convert actionable items to issues/tests; remove stale narration.  |
| Closing `?>` in PHP-only files         | multiple PHP files                                                       | Remove during touched-file modernization.                          |
| Inconsistent style/naming              | `and/or` vs `&&/                                                         |                                                                    | `, snake/camel, raw `die` | Adopt project formatter and focused standards. |
| Bundled Fontello generated variants    | `fontello/`                                                              | Confirm usage; regenerate reproducibly and remove unused variants. |

## Expandability target

A maintainable request flow should be:

```text
Piwigo adapter
  -> parse and validate request DTO
  -> authorization policy
  -> quota reservation
  -> upload/archive service
  -> transactional moderation state
  -> outbox event
  -> Piwigo response translation
```

New upload transports should reuse the same policy, quota, and state services. New moderation rules should implement a documented policy interface and return a decision, not mutate image privacy directly. Storage backends should expose staging, commit, and cleanup operations so local disk and object storage have equivalent failure semantics.

## Documentation needs

- Supported Piwigo, PHP, database, and browser versions.
- Permission precedence and quota-unit definitions.
- Upload/moderation state machine and recovery procedures.
- Hook/webservice contracts and optional plugin dependencies.
- Migration, rollback, backup, and uninstall behavior.
- Operational limits, log fields, alerts, and incident runbooks.
