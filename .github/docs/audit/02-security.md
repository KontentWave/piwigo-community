# Security Audit

## SEC-01: Unscoped webservice administrator elevation

**Severity: Critical**
**Evidence:** `main.inc.php:309-468`, function `community_switch_user_to_admin`; Piwigo `ws.php`, registrations for `pwg.images.*`, `pwg.tags.add`, and `pwg.categories.add`.

Any non-admin user with at least one upload or create permission can have `$user['status']` changed to `admin` for a broad allowlist. For `pwg.images.addSimple`, `pwg.images.upload`, `pwg.images.uploadAsync`, and `pwg.images.add`, the requested category is captured but never checked against `upload_categories` before elevation. Core therefore sees an administrator and accepts destinations outside the Community grant. Moderation is applied later in `community_sendResponse`, after the image has been created.

The same elevation grants `pwg.tags.add`, chunk/check methods, session status, and upload completion based only on the existence of any Community permission. `pwg.images.addChunk` is not bound to a destination at all. Request-global status also affects other handlers executing later in the request.

**Impact:** A limited contributor can upload into unauthorized albums and exercise core admin-only upload support methods. New or changed core handlers can silently expand the privilege surface.

**Remediation:**

- Stop mutating `$user['status']` in `ws_add_methods`.
- Register Community-owned wrapper methods with normal-user access and explicit policy checks, then invoke narrow upload services.
- Validate every requested category as a positive integer and require every category, not merely one, to be in `upload_categories`.
- Bind chunk sessions to user ID, intended categories, expected checksum, byte allowance, and expiry.
- For image mutation/deletion, authorize each image by owner and session policy immediately before the write.
- Add deny-by-default tests for mixed allowed/disallowed category arrays and every elevated method.

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

## SEC-05: Upload completion trusts caller-supplied image and category IDs

**Severity: Medium**
**Evidence:** `main.inc.php:772-881`, function `community_ws_images_uploadCompleted`.

The method validates the CSRF token but does not require queried images to have `added_by = current user`, nor verify that `category_id` is associated with every image. It can expose pending-state metadata for arbitrary IDs and mark arbitrary pending rows `notified_on` while attributing notification content to the caller and supplied category.

**Remediation:** Query through `IMAGES_TABLE` and `IMAGE_CATEGORY_TABLE` with current ownership/session constraints, reject any mismatch, deduplicate IDs, and derive category/user notification fields from database records.

## SEC-06: SQL safety depends on scattered caller discipline

**Severity: Medium**
**Evidence:** SQL construction throughout `main.inc.php`, `admin_*.php`, and `include/functions_community.inc.php`; notably `main.inc.php:927-935` interpolates the captured `original_sum`, and session ID is interpolated at `main.inc.php:430-438`.

Most identifiers are validated with `check_input_parameter()` or `intval()`, but raw SQL concatenation makes omissions difficult to detect and future extensions hazardous. Empty `IN ()` arrays can also produce errors in several paths.

**Remediation:** Centralize ID-list normalization and SQL escaping/prepared access using current Piwigo database APIs. Validate checksums against an exact hex pattern. Make empty collections return before query construction.

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
