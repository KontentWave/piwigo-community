# Reliability and Error Handling Audit

## REL-01: Upload and moderation state is non-atomic

**Severity: High**
**Evidence:** `main.inc.php:884-1015`, `admin_pendings.php:55-101`, `maintain.class.php:14-40`.

An upload is first committed by Piwigo, then the `sendResponse` hook inserts a pending row and changes image privacy. A crash between these operations can expose an unmoderated image or omit moderation metadata. Validation updates pending state and image visibility in separate statements. Rejection deletes the pending row before filesystem/database deletion. MyISAM prevents transactions and row locking.

**Remediation:** Migrate plugin tables to InnoDB. Model upload lifecycle states explicitly (`reserved`, `processing`, `pending`, `published`, `rejected`, `failed`) and make database transitions transactional. Keep files in a non-public staging area until the commit that makes them visible. Make cleanup/reconciliation jobs idempotent.

## REL-02: Important return values and parse results are unchecked

**Severity: High**
**Evidence:** `include/photos_add_direct_process.inc.php`; `main.inc.php:894-935`; `main.inc.php:837-879`.

`add_uploaded_file()`, JSON decoding/result shape, notification delivery, cleanup, and many database writes are assumed to succeed. A TODO explicitly notes that non-integer upload IDs are not handled. `mass_inserts(array_keys($inserts[0]))` can dereference an empty array if the uploaded image cannot be found. The former Community ZIP move/extraction operations were removed with SEC-03 on 2026-08-10; the remaining REL-02 operations are still open.

**Remediation:** Check every external operation, attach context, and stop the state transition on failure. Decode JSON with explicit error handling. Treat mail as an outbox side effect: commit notification intent, send asynchronously/retry, then record delivery outcome.

## REL-03: Quota rollback has a concrete indexing bug

**Severity: Medium**
**Evidence:** `add_photos.php:111-118` and `137-144`.

Both loops iterate `$tn_idx => $thumbnail` but call `unset($page['thumbnails'][$idx])`. `$idx` is undefined in this scope, so the rejected thumbnail can remain in output and PHP emits a notice depending on error settings.

**Remediation:** Use `$tn_idx`, reindex the array after deletion, and add a regression test asserting rejected images are absent from both storage and response presentation.

## REL-04: Temporary upload cleanup is incomplete

**Severity: Medium**
**Status 2026-08-10: ZIP-specific portion remediated; chunk/request temporary-storage lifecycle remains open**
**Evidence:** File-backed chunk state in `main.inc.php` and PHP/core request temporary files.

Community no longer moves ZIP archives or creates extraction directories, so archive and partial-extraction cleanup is no longer a plugin runtime concern. Chunk methods still require lifecycle cleanup coordinated with core, and PHP may create request temporary files before plugin code executes.

**Remediation:** Track created paths, clean them in `finally`, and add a scheduled sweeper constrained to application-owned names and maximum age. Monitor buffer bytes and oldest-file age.

## REL-05: Schema lifecycle is destructive and weakly guarded

**Severity: Medium**
**Evidence:** `maintain.class.php:43-125`, `172-194`.

Install/upgrade executes schema changes without checking or accumulating meaningful errors. Uninstall unconditionally drops tables and a core `categories` column, with no existence guards or retention option. Activation creates a default album and broad registered-user permission when no grants exist, which is surprising production behavior.

**Remediation:** Version migrations, make each idempotent, verify postconditions, preserve/offer export of permission and moderation data on uninstall, and require explicit administrator confirmation before creating a default grant. Rehearse install, every supported upgrade path, deactivate/reactivate, and uninstall on a copy of production data.

## REL-06: Cache and notification consistency are best-effort

**Severity: Medium**
**Evidence:** `include/functions_community.inc.php:24-310`; `main.inc.php:772-881`.

Permissions are cached in session using a global random key. Some category ownership changes and lifecycle operations rely on broad Piwigo invalidation, while notification deduplication is a read-then-send-then-update sequence. Concurrent completion requests can send duplicate mail; a mail failure can still lead to ambiguous state depending on mail behavior.

**Remediation:** Use monotonic revisions, transactional outbox records with a uniqueness key, and explicit cache invalidation at the owning service boundary.

## Standardized custom exception strategy

Exceptions should be used inside new plugin domain/application services, not allowed to escape Piwigo event handlers or webservice callbacks. The outer adapter must translate them into the response type expected by Piwigo.

Recommended hierarchy:

```php
abstract class CommunityException extends RuntimeException {}

final class AuthorizationException extends CommunityException {}
final class ValidationException extends CommunityException {}
final class CsrfException extends CommunityException {}
final class QuotaExceededException extends CommunityException {}
final class ArchiveRejectedException extends CommunityException {}
final class UploadPersistenceException extends CommunityException {}
final class ModerationTransitionException extends CommunityException {}
final class IntegrationException extends CommunityException {}
```

Each exception should carry a stable machine code, safe localized message key, HTTP/Pwg status, and structured non-secret context. Do not put raw SQL, filesystem paths, tokens, mail addresses, or stack traces in user messages.

Translation policy:

| Boundary       | Translation                                                                  |
| -------------- | ---------------------------------------------------------------------------- |
| Webservice     | `PwgError` with stable status/code and safe message.                         |
| Gallery page   | `$page['errors']` plus appropriate 4xx response/redirect.                    |
| Admin page     | `$page['errors']`; preserve submitted safe fields.                           |
| Event hook     | Catch, structured-log, and fail closed; never leave elevated global state.   |
| Background job | Mark retryable/permanent failure, increment attempts, retain correlation ID. |

Expected user mistakes should usually be returned as validation result objects before writes. Exceptions are for failed invariants and external operations. Catch only where recovery or translation is possible.

## Observability baseline

Log structured events for authorization denial, quota reservation/release, upload state transition, archive rejection reason, moderation decision, cleanup result, and notification attempt. Include request/correlation ID, actor ID, image/category IDs, method, outcome, duration, and byte counts. Redact credentials, session IDs, CSRF tokens, raw filenames when sensitive, and image metadata. Define alerts for stuck processing records, old pending notifications, buffer growth, rollback failures, and denied-destination attempts.
