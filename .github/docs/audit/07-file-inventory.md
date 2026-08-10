# File Inventory and Coverage

## Coverage statement

Plugin-owned executable PHP, JavaScript, and Smarty behavior was reviewed at source level. CSS and templates were reviewed for security-relevant behavior, dependency use, maintainability, and responsive/operational risk. Metadata, workspace configuration, localization, and generated Fontello assets were reviewed by structure, provenance, and role. Translated catalogs and generated font binaries were not semantically audited line by line.

The audit includes direct Piwigo code needed to establish webservice authorization, token, upload, hook, and schema assumptions. It is not a full audit of Piwigo core or the optional Two Factor plugin.

## PHP entry points and controllers

| File                                        | Reviewed concerns                                                                                                           |
| ------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| `main.inc.php`                              | Hooks, permission initialization, optional 2FA guard, webservice registration/elevation, moderation, notification, cleanup. |
| `add_photos.php`                            | Upload authorization, quotas, category selection, response composition.                                                     |
| `edit_photos.php`                           | Ownership, CSRF, bulk mutation/deletion, pagination and memory behavior.                                                    |
| `admin.php`                                 | Admin routing and tab integration.                                                                                          |
| `admin_album.php`                           | Album ownership mutation and POST/token enforcement.                                                                        |
| `admin_config.php`                          | Configuration mutation and POST/token enforcement.                                                                          |
| `admin_pendings.php`                        | Moderation transitions, deletion, pagination, POST/token enforcement, and atomicity.                                        |
| `admin_permissions.php`                     | Grant validation, CRUD, cache invalidation, and POST/token enforcement.                                                     |
| `maintain.class.php`                        | Install/upgrade/activate/uninstall schema behavior and defaults.                                                            |
| `include/functions_community.inc.php`       | Permission model, cache, user album creation, cleanup, quota aggregation, compatibility fallback.                           |
| `include/photos_add_direct_process.inc.php` | Multipart handling, atomic ZIP-batch rejection, image persistence, temporary files, and error checks.                       |

## Templates and JavaScript

| File                             | Reviewed concerns                                                                          |
| -------------------------------- | ------------------------------------------------------------------------------------------ |
| `template/add_photos.tpl`        | Webservice calls, CSRF token use, category creation, upload lifecycle, DOM/error handling. |
| `template/admin_album.tpl`       | Ownership form, canonical action, and token field.                                         |
| `template/admin_config.tpl`      | Configuration form, canonical action, and token field.                                     |
| `template/admin_pendings.tpl`    | Tokenized moderation POST/AJAX behavior and unpaginated card rendering.                    |
| `template/admin_permissions.tpl` | Tokenized permission form/POST deletion control and large inline assets.                   |
| `template/edit_photos.tpl`       | Bulk action form, token field, embedded set data, dependencies.                            |
| `template/navigation_bar.tpl`    | Pagination rendering and compatibility role.                                               |
| `edit_photos.js`                 | Webservice deletion, token use, batching, globals, copied legacy UI.                       |

## Styles and generated UI assets

| Group                                                               | Reviewed concerns                                                  |
| ------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `admin_permission.css`                                              | Admin permission layout ownership and duplication with inline CSS. |
| `edit_photos.css`, `edit_photos-clear.css`, `edit_photos-dark.css`  | Theme variants, maintainability, fixed-layout/legacy coupling.     |
| `fontello/config.json`, `fontello/css/*`, `fontello/font/*`         | Generated icon bundle, reproducibility, payload, provenance.       |
| `fontello/LICENSE.txt`, `fontello/README.txt`, `fontello/demo.html` | License/provenance and non-production demo role.                   |

## Metadata, localization, and workspace files

| Group                             | Reviewed concerns                                                                                   |
| --------------------------------- | --------------------------------------------------------------------------------------------------- |
| `pem_metadata.txt`                | Plugin identity/version/compatibility metadata.                                                     |
| `language/en_UK/*`                | Canonical message/error vocabulary and admin/plugin split.                                          |
| `language/*/*`                    | Translation structure and operational fallback risk; semantic translation quality was out of scope. |
| `community.code-workspace`        | Developer-local workspace artifact; untracked and not required at runtime.                          |
| `.github/copilot-instructions.md` | Repository audit/integration-map instructions.                                                      |

## Direct integration files inspected

| External file/surface                            | Reason                                                          |
| ------------------------------------------------ | --------------------------------------------------------------- |
| Piwigo `ws.php`                                  | Core method registration, `admin_only`/`post_only` contracts.   |
| Piwigo `include/ws_functions/pwg.images.php`     | Upload, image mutation/deletion, category association behavior. |
| Piwigo `include/ws_functions/pwg.categories.php` | Album creation behavior and token semantics.                    |
| Piwigo `include/ws_functions/pwg.tags.php`       | Tag creation admin contract.                                    |
| Piwigo webservice core/init                      | Handler registration and authorization timing.                  |
| Piwigo admin token helpers and forms             | Expected `get_pwg_token()`/`check_pwg_token()` pattern.         |
| Optional `two_factor` function contract          | Fail-open guard and direct function/constant coupling.          |

## Exclusions and required dynamic work

Not performed: penetration testing, malicious archive execution, browser matrix, load/concurrency testing, mail delivery, database failure injection, migration rehearsal, filesystem permission review, dependency CVE resolution, or production configuration review. These are explicit release-gate activities, not evidence that the corresponding behavior is safe.

## Audit limitations

- Line numbers describe the 2026-07-29 working tree and will drift.
- Piwigo core was inspected only around controlling integration points.
- Generated/minified third-party assets were not reverse engineered.
- Localization completeness and translation correctness require dedicated tooling and native-language review.
