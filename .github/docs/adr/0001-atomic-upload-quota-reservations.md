# ADR 0001: Atomic Community Upload Quota Reservations

Date: 2026-08-10
Status: Accepted

## Context

Community photo and storage quotas were checked after image persistence. Independent requests could each observe available capacity, exceed the collective limit, and trigger destructive rollback after core side effects. PHP sessions, process memory, and filesystem locks do not serialize the same user across all upload transports and processes.

Piwigo stores image and format `filesize` values in KiB after processing. Community can observe request temporary-file and decoded chunk sizes in bytes before its own buffer writes or core delegates, but it cannot control web-server request bodies or PHP-created temporary files.

## Decision

Use two plugin-owned InnoDB tables:

- `community_quota_locks`: one primary-keyed row per user, providing the stable serialization boundary.
- `community_quota_reservations`: server-generated reservation ID, authenticated owner, transport, logical upload identity, request identity, target photo count and bytes, and creation/refresh/expiry timestamps. A unique `(user_id, transport, logical_upload_id)` key makes exact retries idempotent; user/expiry indexes bound cleanup queries.

A reservation transaction upserts the user lock row, locks it with `SELECT ... FOR UPDATE`, expires stale reservations for that user, reloads uncached effective limits, reads fresh committed image and format usage, sums active reservations, performs overflow-safe arithmetic, and creates or adjusts the exact target reservation before any plugin-controlled buffer write or persistence delegate.

The universal lock order is:

1. Stable upload-state lock, when a transport has one.
2. InnoDB user quota row lock.

Direct batches reserve their complete validated target. Chunk transports reserve cumulative targets and revalidate immediately before final persistence. Definite failures release the exact reservation. Successful persistence settles only after the delegate returns and committed usage is visible. Crashes fail closed until expiry; committed database usage remains counted after settlement or expiry.

Observed payload bytes are reserved exactly. Committed Piwigo KiB values are converted to bytes without early MiB rounding. This may conservatively reserve more than final post-processing storage, but cannot silently allow overflow.

Community `pwg.images.upload` accepts one validated multipart file. Raw-body and core append-chunk modes are rejected for Community actors because they do not expose trusted actual bytes before core buffering. Genuine administrators and `faked_by_community=false` retain core behavior.

## Failure behavior

Quota denial or reservation infrastructure failure returns a normal localized quota error before delegates or plugin buffer writes. Denied attempts do not create image, pending, category, hook, activity, cache, notification, or upload-buffer effects and do not retain quota capacity. Exact completed receipts remain idempotent.

This decision does not claim a filesystem/database atomic transaction, crash-safe image recovery, transactional moderation, or durable notification delivery. Those remain REL-01/02 concerns. Web-server body-size, request-rate, concurrency, and global upload-buffer limits remain operational controls.

## Alternatives rejected

- Session or process-local counters: do not serialize independent workers.
- Per-upload filesystem locks as the quota authority: different uploads for one user do not contend.
- Check-then-write SQL without a locked user row: remains race-prone.
- Migrating every Community table to InnoDB in this slice: exceeds SEC-04 and does not solve filesystem recovery.
- Trusting declared/base64/content lengths: does not establish server-observed bytes.
- Preserving raw-body/core append-chunk Community uploads: incompatible with pre-write trusted-byte reservation at the current core callback boundary.

## Consequences

Quota checks add a short per-user database transaction and serialize concurrent uploads by that user. Two small durable tables require lifecycle migration and expiry cleanup. Community browser uploads use single multipart requests rather than Piwigo core append chunks. Quota rejection now occurs before persistence instead of deleting newly created images afterward.
