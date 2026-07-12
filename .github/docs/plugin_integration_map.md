# Plugin Integration Map

## Plugin dependency map

- Community depends on Piwigo core album creation via `create_virtual_category()` when auto-creating a user's root album.
- Community depends on Piwigo core cache invalidation via `invalidate_user_cache()` to refresh user/category visibility after album tree changes.

## Shared Piwigo hooks/events

- `invalidate_user_cache`: Community listens in `community_refresh_cache_update_time()` and rotates `community_cache_key`.
- `delete_categories`: Community removes matching rows from `community_permissions` and rotates `community_cache_key`.
- `create_virtual_category`: triggered by Piwigo core album creation; Community relies on the side effects of this core path for user-root album creation.

## Shared database tables

- `categories`: Community stores `community_user` ownership on the user root album and reads album tree state for upload/create permissions.
- `user_cache_categories`: Community webservice category listings join this table and therefore depend on Piwigo cache invalidation after album creation.

## Shared config keys

- `community_cache_key`: Community-specific global cache key used to invalidate per-session permission payloads.

## Shared services/includes

- `admin/include/functions.php`: provides `create_virtual_category()` and `invalidate_user_cache()` used by Community user-album creation.

## Risky couplings

- Community's `community_get_user_album()` creates albums through core `create_virtual_category()`; unlike the `pwg.categories.add` webservice path, direct use does not invalidate `user_cache_categories` unless Community does it explicitly.
- Community permission freshness depends on both its own session cache keys and Piwigo's user/category cache tables staying in sync.
- Community must treat Piwigo core `get_subcat_ids()` as "parent plus descendants", not descendants-only, or the user root container leaks back into non-admin upload targets.

## Last inspection notes

- 2026-07-10: confirmed same-page selector refresh bug was Community template code.
- 2026-07-10: confirmed first-request user-root auto-creation path needed explicit `invalidate_user_cache()` and `community_update_cache_key()` after direct core album creation.
- 2026-07-11: confirmed `get_subcat_ids()` includes the parent album, so Community now explicitly removes the user root album from descendant-only upload targets.
