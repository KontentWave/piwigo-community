# Security Audit

## SEC-01: Unscoped webservice administrator elevation

**Severity: Critical**
**Evidence:** `main.inc.php:309-468`, function `community_switch_user_to_admin`; Piwigo `ws.php`, registrations for `pwg.images.*`, `pwg.tags.add`, and `pwg.categories.add`.

Any non-admin user with at least one upload or create permission can have `$user['status']` changed to `admin` for a broad allowlist. For `pwg.images.addSimple`, `pwg.images.upload`, `pwg.images.uploadAsync`, and `pwg.images.add`, the requested category is captured but never checked against `upload_categories` before elevation. Core therefore sees an administrator and accepts destinations outside the Community grant. Moderation is applied later in `community_sendResponse`, after the image has been created.

The elevated methods also accept object-level mutation parameters that Community does not authorize. Piwigo documents and implements `image_id` on `pwg.images.add`, `pwg.images.addSimple`, and `pwg.images.uploadAsync` as replacement of an existing image; `pwg.images.upload` accepts `format_of` to attach a format to an existing image and supports `update_mode`. Core checks that an object exists, but these upload paths do not require it to be owned by the Community caller. This bypasses the ownership filtering Community applies only to `pwg.images.setInfo` and `pwg.images.delete`.

The same elevation grants `pwg.tags.add`, chunk/check methods, session status, and upload completion based only on the existence of any Community permission. `pwg.images.addChunk` is not bound to a destination at all. Request-global status also affects other handlers executing later in the request.

**Impact:** A limited contributor can upload into unauthorized albums, replace or modify existing images they do not own, attach formats to arbitrary images, and exercise core admin-only upload support methods. New or changed core handlers can silently expand the privilege surface.

**Remediation:**

- Stop mutating `$user['status']` in `ws_add_methods`.
- Register Community-owned wrapper methods with normal-user access and explicit policy checks, then invoke narrow upload services.
- Validate every requested category as a positive integer and require every category, not merely one, to be in `upload_categories`.
- Bind chunk sessions to user ID, intended categories, expected checksum, byte allowance, and expiry.
- For image mutation/deletion, authorize each image by owner and session policy immediately before the write.
- Add deny-by-default tests for mixed allowed/disallowed category arrays and every elevated method.

-**Update 2026-07-31:** Slice 1 is partially completed for `pwg.images.addSimple`, `pwg.images.upload`, and `pwg.images.uploadAsync`. Community now replaces all three methods with same-name wrappers for Community-enabled non-admin users, validates normalized destination categories against effective `upload_categories`, removes all three methods from the ambient administrator-elevation allowlist, enforces owner and guest/generic session checks for `image_id` replacement on `pwg.images.addSimple` and `pwg.images.uploadAsync`, authorizes `format_of` mutations on `pwg.images.upload`, and rejects Community `update_mode` outright because the core delegate re-resolves replacement targets internally and cannot be pinned safely through the current callback boundary. The `pwg.images.uploadAsync` wrapper now also owns a lock-protected manifest and chunk store under the upload buffer, binding each accepted upload to user ID, guest/generic session identity, normalized categories, checksum, chunk count, replacement target, filename, final-write metadata, received-byte accounting, and expiry before any later chunk can write or delegate.

**Acceptance evidence 2026-07-31:** PHPUnit lifecycle coverage now proves that the real Community `ws_add_methods` registration for non-admin requests preserves out-of-order `pwg.images.uploadAsync` completion without status elevation, rejects actor/session/category/`image_id`/chunk-count/filename/metadata/level/tag drift before delegate calls or second-chunk writes, enforces checksum, index, per-chunk, cumulative-byte, retry, expiry, and revoked-ownership checks, keeps genuine administrator callbacks untouched, and preserves the `faked_by_community=false` bypass.

**Update 2026-07-31 (legacy `pwg.images.add` + `pwg.images.addChunk`):** Community now replaces both legacy methods with same-name wrappers for Community-enabled non-admin users, removes both methods from the ambient administrator-elevation allowlist, keeps genuine administrators and `faked_by_community=false` on the untouched core callbacks, validates `original_sum` before any filesystem or SQL use, rejects missing/malformed/mixed/unauthorized destination category strings atomically at `pwg.images.add`, enforces owner and guest/generic session checks for legacy `image_id` replacement immediately before finalization, and binds buffered chunk state to Community-owned upload state keyed by authenticated user ID, guest/generic session identity, checksum, chunk type, chunk position, byte size, and expiry before any later `pwg.images.add` request can finalize it.

**Acceptance evidence 2026-07-31 (legacy `pwg.images.add` + `pwg.images.addChunk`):** PHPUnit lifecycle coverage now proves that the real Community `ws_add_methods` registration for non-admin requests lets the same actor chunk and finalize only into authorized destination categories without status elevation, rejects unauthorized and mixed destination requests before persistence, rejects cross-user reuse of buffered chunks, preserves existing checksum hardening before filesystem writes and SQL checks, enforces ownership and guest/generic session provenance for legacy `image_id` replacement, and keeps administrator passthrough plus `faked_by_community=false` behavior intact. Forked process-level regressions for both legacy and `uploadAsync` state prove that cleanup retains one lock inode while a second request acquires it, a third request remains excluded, unrelated state survives, and only the exact manifest, chunks, merged file, and optional receipt are removed. The complete upload-guard file and complete PHPUnit suite pass with 88 tests and 535 assertions, including retry, expiry, exact cleanup, and receipt-write fallback coverage.

**Update 2026-07-31 (upload completion):** Community now replaces `pwg.images.uploadCompleted` for Community-enabled non-admin users and routes it together with `community.images.uploadCompleted` through one plugin-owned scoped completion service. Both methods require POST and a valid token, authorize a strict canonical image batch and existing destination category from normalized parameters, enforce owner and guest/generic session provenance, and require an existing category association or the exact lounge row for every image. The service never elevates status or calls core `empty_lounge()`; it associates and deletes only authorized lounge tuples, emits hooks with canonical records, and derives notification identity from the authenticated actor and validated category. Sorted stable per-image locks and a canonical completion receipt make intersecting and repeated calls idempotent while preserving each public response shape. Genuine administrators and `faked_by_community=false` retain the untouched core callback.

**Acceptance evidence 2026-07-31 (upload completion):** Real webservice lifecycle tests cover strict malformed/empty/duplicate/non-positive/missing/foreign/mixed/wrong-category/unauthorized rejection, token failures with zero side effects, owner and guest/generic provenance, exact lounge isolation, absence of global `empty_lounge()`, canonical hooks, sequential retries, process-overlap serialization, administrator passthrough, `faked_by_community=false`, and one authoritative browser completion request with failure handling. The complete upload-guard file and full PHPUnit suite pass with 108 tests and 650 assertions.

**Residual risk after the completed slices:** SEC-01 remains open. Ambient elevation still applies to `pwg.tags.add`, `pwg.images.exist`, `pwg.images.checkUpload`, `pwg.images.checkFiles`, and `pwg.session.getStatus`, and other image edit/delete behavior has not been redesigned by this slice. File-backed upload and completion state is not transactional database state. Completion receipts suppress duplicate effects under retry and overlap, but they are not a notification outbox: a process failure around mail delivery can still lose or repeat a notification. Quota reservation, archive hardening, and SEC-02 through SEC-04 remain open.

## SEC-02: Missing CSRF protection on administrator mutations

**Severity: High**
**Evidence:** `admin_permissions.php:44-204`; `admin_config.php:44-59`; `admin_album.php:51-73`; `admin_pendings.php:42-101`; corresponding templates contain no `pwg_token`. `edit_photos.php:32-36` demonstrates the expected token check.

Permission creation/update, configuration changes, album ownership assignment, and moderation validation/rejection accept authenticated administrator requests without `check_pwg_token()`. Permission deletion is a state-changing GET at `admin_permissions.php:187-204` and links are generated at `admin_permissions.php:517`.

**Impact:** A malicious page can induce a logged-in administrator to alter upload rights, disable moderation, transfer album ownership, approve content, reject/delete photos, or change plugin configuration.

**Remediation:** Make every mutation POST-only, assign `get_pwg_token()` to its template, submit `pwg_token`, and call `check_pwg_token()` before validation or writes. Use separate action names and reject unknown actions. Add request tests proving missing/invalid tokens cause no state change.

## SEC-03: Unbounded and insufficiently constrained ZIP extraction

**Severity: High**
**Evidence:** `include/photos_add_direct_process.inc.php:61-114`.

ZIP files are moved into the upload buffer, listed, filtered only by filename extension, and extracted with PclZip. The code does not reject absolute paths or `..` segments, verify the canonical extraction target, limit entry count, nesting, compression ratio, total expanded bytes, per-entry bytes, or processing time. Return values from move and extract operations are not checked, and temporary archives/directories are not explicitly removed.

Whether the bundled PclZip version fully blocks traversal must not be treated as the plugin's security boundary. Extension checks also do not establish that decompressed content is a valid image.

**Impact:** Disk or CPU exhaustion, buffer pollution, retained temporary data, and potentially path traversal depending on library behavior/version.

**Remediation:** Prefer disabling archive upload for untrusted users. Otherwise inspect metadata before extraction; reject unsafe names; enforce configured entry/expanded-byte/ratio/depth limits; extract each approved entry to an application-generated flat filename; verify `realpath` containment and image content; and clean up in `finally`.

## SEC-04: Quotas are post-write and race-prone

**Severity: High**
**Evidence:** `add_photos.php:75-154`, `include/functions_community.inc.php:432-443`, and webservice upload flow in `template/add_photos.tpl`.

The browser path calculates usage after `add_uploaded_file()` has persisted each image, then deletes newest images until usage falls under the limit. Concurrent requests can each observe available quota and collectively exceed it. Webservice uploads are processed through core and this post-write browser cleanup is not a reliable enforcement boundary. Chunk and archive expansion consume temporary storage before quota enforcement.

**Impact:** Quota bypass, temporary disk exhaustion, inconsistent user feedback, and destructive rollback after other hooks may already have observed the image.

**Remediation:** Reserve photo count and bytes atomically before accepting content, enforce hard request/body/archive limits at the web server, and release reservations on failure. Count committed plus reserved usage. Enforce the same policy in all upload transports.

**Update 2026-07-31:** File-backed legacy and `uploadAsync` upload state now retains its small state directory and lock-file placeholder instead of unlinking the lock pathname. This preserves one inode across overlapping requests and removes the unlocked check-then-unlink race while exact upload artifacts are still removed on expiry and completion. This is lock-lifecycle hardening only: retained placeholders require bounded operational cleanup if their count becomes material, and atomic quota reservation, archive limits, and temporary-storage quota enforcement remain open.

## SEC-05: Upload completion trusts caller-supplied image and category IDs

**Severity: Medium**
**Evidence:** `main.inc.php:772-881`, function `community_ws_images_uploadCompleted`.

The method validates the CSRF token but does not require queried images to have `added_by = current user`, nor verify that `category_id` is associated with every image. It can expose pending-state metadata for arbitrary IDs and mark arbitrary pending rows `notified_on` while attributing notification content to the caller and supplied category.

**Remediation:** Query through `IMAGES_TABLE` and `IMAGE_CATEGORY_TABLE` with current ownership/session constraints, reject any mismatch, deduplicate IDs, and derive category/user notification fields from database records.

**Status 2026-07-31: Remediated.** Both `pwg.images.uploadCompleted` and `community.images.uploadCompleted` now use the same Community-owned scoped service described in the SEC-01 upload-completion update. It validates token, actor, session provenance, category grant/existence, ownership, and exact association-or-lounge eligibility before side effects; rejects the complete batch generically on any mismatch; finalizes only exact authorized lounge tuples; and uses lock-protected canonical receipts to suppress duplicate association, hooks, pending notification mutation, and mail under retries or overlapping calls. Public response compatibility is preserved through separate adapters, and the browser now makes one authoritative completion request with explicit success/failure handling.

This remediation does not claim crash-safe notification delivery: an outbox remains Phase 2 work.

## SEC-06: Caller-controlled checksum reaches unescaped SQL

**Severity: Critical**
**Evidence:** `main.inc.php:335-339` captures `$_REQUEST['original_sum']`; `main.inc.php:922-930` interpolates it into a query. Piwigo `ws.php`, registration for `pwg.images.add`, declares `original_sum` without a type or pattern constraint; `include/ws_functions/pwg.images.php`, function `ws_images_add`, interpolates it into the uniqueness query; `admin/include/functions_upload.inc.php`, function `add_uploaded_file`, interpolates the supplied checksum into duplicate detection. Piwigo's newer `ws_images_uploadAsync` validates the same field with `/^[a-fA-F0-9]{32}$/`, demonstrating the missing legacy-path boundary.

Any Community-enabled caller elevated for `pwg.images.add` can supply an arbitrary `original_sum`. The value is used in chunk filenames and regular expressions, then reaches raw SQL in both Piwigo and Community without escaping or checksum validation. The database insert helper does not make the earlier duplicate-detection and response-hook queries safe.

**Impact:** A contributor can alter SQL query structure under the database account used by Piwigo. Depending on the driver configuration and payload, this can disclose or corrupt gallery data, bypass duplicate selection, misassociate moderation state, or cause availability failures. The same input also creates unsafe filesystem and regular-expression behavior before persistence.

**Remediation:** Reject `original_sum` unless it matches exactly 32 hexadecimal characters at the first Community boundary and independently in every core method that consumes it. Escape values or use structured database helpers for every query; do not rely on format validation as the SQL defense. Quote checksum values used in regular expressions with `preg_quote()` and derive buffer names from server-generated identifiers. Add adversarial tests covering quotes, regex metacharacters, separators, traversal characters, invalid lengths, and mixed case.

**Update 2026-07-30:** Community now replaces both `pwg.images.add` and `pwg.images.addChunk` with same-name wrappers that preserve the core parameter schema but reject non-hex 32-character `original_sum` values before delegating. The plugin also performs an escaped filename uniqueness precheck when `check_uniqueness=true` and `$conf['uniqueness_mode'] === 'filename'`, then disables the downstream core filename uniqueness branch for that request so raw `original_filename` does not reach Piwigo's legacy SQL. The post-upload Community checksum lookup now escapes `md5sum` before interpolation.

**Acceptance evidence 2026-07-30:** PHPUnit lifecycle coverage proves the real Community `ws_add_methods` sequence activates the plugin wrappers for non-admin requests, preserves untouched core callbacks for genuine administrators, rejects adversarial checksums before delegate invocation or plugin SQL, delegates mixed-case valid checksums exactly once for both `pwg.images.add` and `pwg.images.addChunk`, and preserves duplicate/non-duplicate filename uniqueness behavior while preventing quote/metacharacter filenames from altering SQL structure.

**Residual risk:** This remediation depends on Community's wrappers remaining the final registrations for `pwg.images.add` and `pwg.images.addChunk` during `ws_add_methods`. The broader authorization problem described in SEC-01 remains open, so checksum and filename interception are fixed without changing the underlying ambient-admin design.

## SEC-07: Output and client error handling need hardening

**Severity: Low**
**Evidence:** `template/add_photos.tpl` constructs image HTML from API fields; several templates contain large inline scripts/styles; `add_photos.php:52` echoes a raw authorization error.

Piwigo-generated image fields are expected to be safe, but DOM construction with concatenated HTML and inline code complicates a strict Content Security Policy. Raw fatal messages reveal internal behavior and are inconsistent with Piwigo errors.

**Remediation:** Build DOM nodes with `.attr()`/`.text()`, move scripts and styles to assets, adopt nonces or a strict external-script CSP, and translate authorization failures into standard 403 responses without diagnostic detail.

## Compensating controls

- Disable ZIP uploads and cap request size, request rate, concurrent uploads, and buffer filesystem usage.
- Grant only moderated permissions to trusted authenticated accounts; avoid `any_visitor` until SEC-01 is fixed.
- Alert on uploads to albums outside expected contributor scopes and on sudden tag creation.
- Use a dedicated upload volume with no script execution and restrictive filesystem permissions.
- Keep Piwigo, PHP, database, and archive libraries patched.
