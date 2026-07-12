# ADR-0001: Preserve Community Upload Target and Queue Invariants

## Status

Accepted on 2026-07-12.

## Context

A non-admin Community user has a root album that is a container for child image-holder albums. The root container must be available as a parent when creating child albums, but it must never be an upload target.

The upload flow crosses several boundaries:

- Community computes and session-caches `upload_categories` and `create_categories`.
- Piwigo core `get_subcat_ids()` returns the parent category together with its descendants.
- Community creates a user root album directly through Piwigo core.
- The upload page refreshes its selector through a webservice and uses Plupload for its queue.
- An active theme template override may replace the plugin upload template at runtime.

The prior behavior could expose the root container as an upload target, prevent creation of the first child album, enable file selection before a valid target existed, and reject a populated Plupload queue as empty.

## Integration Context

`core_privacy_toggle` is outside the controlling path for Community upload targets and upload-control enablement. Community owns album-target computation, root-album creation, server-rendered selector contents, selector refresh, and client-side upload validation.

`two_factor` participates only at the authenticated-session boundary. Community caches its computed permission payload in `$_SESSION['community_user_permissions']`, `$_SESSION['community_cache_key']`, and `$_SESSION['community_user_id']`. The local Two Factor integration clears those keys when authentication enters pending validation and again before the successful post-validation redirect. Community must therefore recompute fresh permissions after 2FA; no additional Two Factor-owned Community state is required.

## Decision

Community upload behavior must enforce these invariants:

1. A non-admin user root album is a creation parent, never an upload target.
2. A user with `create_categories` may create the first child album even when `upload_categories` is empty.
3. Direct root-album creation must refresh Piwigo user/category visibility and Community's in-request cache key.
4. Same-page selector refresh must use Community's filtered category webservice.
5. File selection and upload start are enabled only when the current page has a valid selected upload target and Plupload has queued files.
6. Active theme overrides of `add_photos.tpl` must preserve the same upload guard semantics as the plugin template.

## Implementation

- `include/functions_community.inc.php`
  - Remove the user root album id from `get_subcat_ids()` before deriving non-admin upload targets.
  - Call `invalidate_user_cache()` and `community_update_cache_key()` after direct user-root creation.
  - Update `$conf['community_cache_key']` when rotating the persisted Community cache key.

- `main.inc.php`
  - Do not return from Community webservice authorization while `create_categories` is non-empty. This permits `pwg.categories.add` for the first child album.

- `template/add_photos.tpl`
  - Refresh the selector with `community.categories.getList`.
  - Bind the guard to `#startUpload` before calling `up.start()`.
  - Use the Plupload instance's `files.length` instead of obsolete Uploadify queue markup.
  - Synchronize `#addFiles` and `#startUpload` disabled state with the available album options and Plupload queue.

- Active theme override
  - Apply equivalent queue validation and control-state changes to the active theme's `add_photos.tpl`; a theme override takes precedence over the plugin template at runtime.

## Consequences

- Before the first child album exists, the upload selector is empty and both file selection and upload start are disabled.
- Creating the first child album succeeds in the same session.
- After creation, the selector contains child upload targets only; it does not reintroduce the root container.
- A queued Plupload file set can be uploaded and follows Community moderation rules.
- Template overrides are an operational coupling: a theme-provided `add_photos.tpl` must receive equivalent fixes or it can restore obsolete client-side behavior.

## Validation

Manual browser validation completed on 2026-07-12 with a clean non-admin user after SMS 2FA:

1. The initial upload page had no selectable upload album and disabled `Add Photos` and `Start Upload` controls.
2. The user created a first child album in the same page without an access-denied response.
3. The refreshed selector contained only the child album; the root container was absent.
4. Adding five JPEG files enabled `Start Upload` without a false "Select at least one photo" error.
5. The upload completed and the photos entered the expected moderation-pending state.

## Related Documents

- `plugin_integration_map.md` records the Piwigo and plugin coupling points.

## Supersedes

This ADR supersedes `COMMUNITY_UPLOAD_SESSION_CACHE_NOTE.md`. The ADR retains the durable architectural decisions, integration boundary, implementation rationale, and validation evidence; the superseded note may be removed once this document is retained.
