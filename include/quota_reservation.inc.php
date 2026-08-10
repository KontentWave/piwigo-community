<?php

function community_quota_fits($committed_photos, $committed_bytes, $requested_photos, $requested_bytes, $photo_limit, $byte_limit)
{
  $values = array($committed_photos, $committed_bytes, $requested_photos, $requested_bytes);
  foreach ($values as $value)
  {
    if (!is_int($value) || $value < 0)
    {
      return false;
    }
  }

  if (isset($photo_limit))
  {
    if (!is_int($photo_limit) || $photo_limit < 0 || $requested_photos > $photo_limit || $committed_photos > $photo_limit - $requested_photos)
    {
      return false;
    }
  }

  if (isset($byte_limit))
  {
    if (!is_int($byte_limit) || $byte_limit < 0 || $requested_bytes > $byte_limit || $committed_bytes > $byte_limit - $requested_bytes)
    {
      return false;
    }
  }

  return true;
}

function community_quota_logical_key($user_id, $transport, $logical_upload_id, $request_identity)
{
  return hash(
    'sha256',
    implode("\0", array((string) $user_id, (string) $transport, (string) $logical_upload_id, (string) $request_identity))
  );
}

function community_quota_upload_delta($payload_bytes, $replacement_filesize_kib = null, $image_id = null)
{
  if (!is_int($payload_bytes) || $payload_bytes < 0)
  {
    return array('photos' => 0, 'bytes' => 0);
  }

  if (isset($image_id))
  {
    if (is_int($replacement_filesize_kib) && $replacement_filesize_kib > intdiv(PHP_INT_MAX, 1024))
    {
      return array('photos' => 0, 'bytes' => PHP_INT_MAX);
    }

    $committed_bytes = is_int($replacement_filesize_kib) && $replacement_filesize_kib > 0 ? $replacement_filesize_kib * 1024 : 0;

    return array(
      'photos' => 0,
      'bytes' => max(0, $payload_bytes - $committed_bytes),
    );
  }

  return array('photos' => 1, 'bytes' => $payload_bytes);
}

function community_quota_persistence_delta($payload_bytes, $image_id = null, $format_of = null, $format_name = null)
{
  if (isset($GLOBALS['community_quota_persistence_delta_callback']))
  {
    return call_user_func($GLOBALS['community_quota_persistence_delta_callback'], $payload_bytes, $image_id, $format_of, $format_name);
  }

  if (isset($format_of))
  {
    $format_ext = is_string($format_name) ? strtolower(pathinfo($format_name, PATHINFO_EXTENSION)) : '';
    $format_filesize_kib = 0;
    if ('' !== $format_ext)
    {
      $row = pwg_db_fetch_assoc(pwg_query('
SELECT filesize
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.(int) $format_of.'
    AND ext = \''.pwg_db_real_escape_string($format_ext).'\'
;'));
      $format_filesize_kib = isset($row['filesize']) ? (int) $row['filesize'] : 0;
    }

    return community_quota_upload_delta($payload_bytes, $format_filesize_kib, (int) $format_of);
  }

  $replacement_filesize_kib = null;
  if (isset($image_id))
  {
    $row = pwg_db_fetch_assoc(pwg_query('SELECT filesize FROM '.IMAGES_TABLE.' WHERE id = '.(int) $image_id.';'));
    $replacement_filesize_kib = isset($row['filesize']) ? (int) $row['filesize'] : 0;
  }

  return community_quota_upload_delta($payload_bytes, $replacement_filesize_kib, $image_id);
}

function community_quota_error()
{
  return new PwgError(403, l10n('Upload quota exceeded'));
}

function community_quota_error_message($error)
{
  if (is_callable(array($error, 'message')))
  {
    return $error->message();
  }

  return isset($error->message) ? $error->message : l10n('Upload quota exceeded');
}

function community_quota_transport_reserve($transport, $logical_upload_id, $request_identity, $photos, $bytes)
{
  if (isset($GLOBALS['community_quota_transport_reserve_callback']))
  {
    return call_user_func(
      $GLOBALS['community_quota_transport_reserve_callback'],
      $transport,
      $logical_upload_id,
      $request_identity,
      $photos,
      $bytes
    );
  }

  return community_quota_reserve($transport, $logical_upload_id, $request_identity, $photos, $bytes);
}

function community_quota_transport_release($transport, $logical_upload_id, $request_identity)
{
  if (isset($GLOBALS['community_quota_transport_release_callback']))
  {
    return call_user_func(
      $GLOBALS['community_quota_transport_release_callback'],
      $transport,
      $logical_upload_id,
      $request_identity
    );
  }

  return community_quota_release($transport, $logical_upload_id, $request_identity);
}

function community_quota_transport_settle($transport, $logical_upload_id, $request_identity)
{
  if (isset($GLOBALS['community_quota_transport_settle_callback']))
  {
    return call_user_func(
      $GLOBALS['community_quota_transport_settle_callback'],
      $transport,
      $logical_upload_id,
      $request_identity
    );
  }

  return community_quota_settle($transport, $logical_upload_id, $request_identity);
}

function community_quota_uploaded_file_request($transport, $uploaded_file, $params)
{
  global $user;

  if (isset($GLOBALS['community_quota_uploaded_file_request_callback']))
  {
    return call_user_func(
      $GLOBALS['community_quota_uploaded_file_request_callback'],
      $transport,
      $uploaded_file,
      $params
    );
  }

  if (
    !is_array($uploaded_file)
    || !isset($uploaded_file['tmp_name'])
    || !is_string($uploaded_file['tmp_name'])
    || !is_file($uploaded_file['tmp_name'])
    || (isset($uploaded_file['error']) && UPLOAD_ERR_OK !== (int) $uploaded_file['error'])
  )
  {
    return null;
  }

  $payload_bytes = filesize($uploaded_file['tmp_name']);
  $payload_sum = hash_file('sha256', $uploaded_file['tmp_name']);
  if (false === $payload_bytes || false === $payload_sum || $payload_bytes > PHP_INT_MAX)
  {
    return null;
  }

  $format_of = !empty($params['format_of']) ? (int) $params['format_of'] : null;
  $image_id = !empty($params['image_id']) ? (int) $params['image_id'] : null;
  $delta = community_quota_persistence_delta(
    (int) $payload_bytes,
    $image_id,
    $format_of,
    isset($params['name']) ? (string) $params['name'] : null
  );
  $identity_parts = array(
    (int) $user['id'],
    $transport,
    $payload_sum,
    isset($uploaded_file['name']) ? (string) $uploaded_file['name'] : '',
    isset($params['category']) ? implode(',', (array) $params['category']) : '',
    isset($image_id) ? $image_id : 0,
    isset($format_of) ? $format_of : 0,
  );
  $request_identity = hash('sha256', implode("\0", $identity_parts));

  return array(
    'logical_upload_id' => hash('sha256', implode("\0", array_slice($identity_parts, 0, 5))),
    'request_identity' => $request_identity,
    'photos' => $delta['photos'],
    'bytes' => $delta['bytes'],
  );
}

function community_quota_snapshot($user_id, $transport, $logical_upload_id)
{
  if (isset($GLOBALS['community_quota_test_snapshot_callback']))
  {
    return call_user_func($GLOBALS['community_quota_test_snapshot_callback'], $user_id, $transport, $logical_upload_id);
  }

  $permissions = community_get_user_permissions($user_id, false);
  $photo_limit = -1 == $permissions['nb_photos'] ? null : (int) $permissions['nb_photos'];
  $byte_limit = null;
  if (-1 != $permissions['storage'])
  {
    $storage_limit_mib = filter_var($permissions['storage'], FILTER_VALIDATE_INT);
    if (false === $storage_limit_mib || $storage_limit_mib < 0 || $storage_limit_mib > intdiv(PHP_INT_MAX, 1024 * 1024))
    {
      $byte_limit = -1;
    }
    else
    {
      $byte_limit = $storage_limit_mib * 1024 * 1024;
    }
  }

  $usage = pwg_db_fetch_assoc(pwg_query('
SELECT
    COUNT(id) AS committed_photos,
    IFNULL(SUM(filesize), 0) + IFNULL((
      SELECT SUM(image_format.filesize)
        FROM '.IMAGE_FORMAT_TABLE.' AS image_format
          INNER JOIN '.IMAGES_TABLE.' AS format_image ON format_image.id = image_format.image_id
        WHERE format_image.added_by = '.(int) $user_id.'
    ), 0) AS committed_kib
  FROM '.IMAGES_TABLE.'
  WHERE added_by = '.(int) $user_id.'
;'));

  $reserved = pwg_db_fetch_assoc(pwg_query('
SELECT
    IFNULL(SUM(reserved_photos), 0) AS reserved_photos,
    IFNULL(SUM(reserved_bytes), 0) AS reserved_bytes
  FROM '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.'
  WHERE user_id = '.(int) $user_id.'
    AND expires_at > NOW()
;'));

  $existing = pwg_db_fetch_assoc(pwg_query('
SELECT
    reservation_id,
    request_identity,
    reserved_photos,
    reserved_bytes
  FROM '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.'
  WHERE user_id = '.(int) $user_id.'
    AND transport = \''.pwg_db_real_escape_string($transport).'\'
    AND logical_upload_id = \''.pwg_db_real_escape_string($logical_upload_id).'\'
;'));

  return array(
    'photo_limit' => $photo_limit,
    'byte_limit' => $byte_limit,
    'committed_photos' => (int) $usage['committed_photos'],
    'committed_bytes' => (int) $usage['committed_kib'] > intdiv(PHP_INT_MAX, 1024) ? PHP_INT_MAX : (int) $usage['committed_kib'] * 1024,
    'reserved_photos' => (int) $reserved['reserved_photos'],
    'reserved_bytes' => (int) $reserved['reserved_bytes'],
    'existing' => $existing ?: null,
  );
}

function community_quota_advisory_usage($user_id)
{
  $snapshot = community_quota_snapshot((int) $user_id, 'display', '');

  $photos = (int) $snapshot['committed_photos'] + (int) $snapshot['reserved_photos'];
  $bytes = (int) $snapshot['committed_bytes'] + (int) $snapshot['reserved_bytes'];
  if ($photos < 0 || $bytes < 0)
  {
    return array('nb_photos' => PHP_INT_MAX, 'bytes' => PHP_INT_MAX);
  }

  return array('nb_photos' => $photos, 'bytes' => $bytes);
}

function community_quota_reserve($transport, $logical_upload_id, $request_identity, $target_photos, $target_bytes, $ttl_seconds = 3600)
{
  global $user;

  $user_id = (int) $user['id'];
  if ($user_id <= 0 || !is_int($target_photos) || !is_int($target_bytes) || $target_photos < 0 || $target_bytes < 0 || !is_int($ttl_seconds) || $ttl_seconds <= 0)
  {
    return community_quota_error();
  }

  pwg_query('START TRANSACTION');

  try
  {
    pwg_query('
INSERT INTO '.COMMUNITY_QUOTA_LOCKS_TABLE.'
  (user_id, updated_at)
VALUES
  ('.$user_id.', NOW())
ON DUPLICATE KEY UPDATE updated_at = updated_at
;');
    pwg_query('SELECT user_id FROM '.COMMUNITY_QUOTA_LOCKS_TABLE.' WHERE user_id = '.$user_id.' FOR UPDATE;');

    if (isset($GLOBALS['community_quota_after_user_lock_callback']))
    {
      call_user_func($GLOBALS['community_quota_after_user_lock_callback']);
    }

    pwg_query('DELETE FROM '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.' WHERE user_id = '.$user_id.' AND expires_at <= NOW();');
    $snapshot = community_quota_snapshot($user_id, $transport, $logical_upload_id);
    $existing = $snapshot['existing'];

    if (isset($existing) && !hash_equals((string) $existing['request_identity'], (string) $request_identity))
    {
      pwg_query('ROLLBACK');
      return community_quota_error();
    }

    $existing_photos = isset($existing) ? (int) $existing['reserved_photos'] : 0;
    $existing_bytes = isset($existing) ? (int) $existing['reserved_bytes'] : 0;
    $base_photos = (int) $snapshot['committed_photos'] + (int) $snapshot['reserved_photos'] - $existing_photos;
    $base_bytes = (int) $snapshot['committed_bytes'] + (int) $snapshot['reserved_bytes'] - $existing_bytes;

    if ($base_photos < 0 || $base_bytes < 0 || !community_quota_fits($base_photos, $base_bytes, $target_photos, $target_bytes, $snapshot['photo_limit'], $snapshot['byte_limit']))
    {
      pwg_query('ROLLBACK');
      return community_quota_error();
    }

    $reservation_id = isset($existing) ? $existing['reservation_id'] : bin2hex(random_bytes(16));
    if (isset($existing))
    {
      pwg_query('
UPDATE '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.'
  SET reserved_photos = '.$target_photos.', reserved_bytes = '.$target_bytes.', refreshed_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL '.$ttl_seconds.' SECOND)
  WHERE reservation_id = \''.pwg_db_real_escape_string($reservation_id).'\'
;');
    }
    else
    {
      pwg_query('
INSERT INTO '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.'
  (reservation_id, user_id, transport, logical_upload_id, request_identity, reserved_photos, reserved_bytes, created_at, refreshed_at, expires_at)
VALUES
  (\''.pwg_db_real_escape_string($reservation_id).'\', '.$user_id.', \''.pwg_db_real_escape_string($transport).'\', \''.pwg_db_real_escape_string($logical_upload_id).'\', \''.pwg_db_real_escape_string($request_identity).'\', '.$target_photos.', '.$target_bytes.', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL '.$ttl_seconds.' SECOND))
;');
    }

    pwg_query('COMMIT');

    return array(
      'reservation_id' => $reservation_id,
      'reserved_photos' => $target_photos,
      'reserved_bytes' => $target_bytes,
    );
  }
  catch (Throwable $error)
  {
    pwg_query('ROLLBACK');
    return community_quota_error();
  }
}

function community_quota_delete_reservation($transport, $logical_upload_id, $request_identity)
{
  global $user;

  $user_id = (int) $user['id'];
  if ($user_id <= 0)
  {
    return false;
  }

  pwg_query('START TRANSACTION');

  try
  {
    pwg_query('
INSERT INTO '.COMMUNITY_QUOTA_LOCKS_TABLE.'
  (user_id, updated_at)
VALUES
  ('.$user_id.', NOW())
ON DUPLICATE KEY UPDATE updated_at = updated_at
;');
    pwg_query('SELECT user_id FROM '.COMMUNITY_QUOTA_LOCKS_TABLE.' WHERE user_id = '.$user_id.' FOR UPDATE;');
    pwg_query('
DELETE FROM '.COMMUNITY_QUOTA_RESERVATIONS_TABLE.'
  WHERE user_id = '.$user_id.'
    AND transport = \''.pwg_db_real_escape_string($transport).'\'
    AND logical_upload_id = \''.pwg_db_real_escape_string($logical_upload_id).'\'
    AND request_identity = \''.pwg_db_real_escape_string($request_identity).'\'
;');
    pwg_query('COMMIT');

    return true;
  }
  catch (Throwable $error)
  {
    pwg_query('ROLLBACK');
    return false;
  }
}

function community_quota_release($transport, $logical_upload_id, $request_identity)
{
  return community_quota_delete_reservation($transport, $logical_upload_id, $request_identity);
}

function community_quota_settle($transport, $logical_upload_id, $request_identity)
{
  return community_quota_delete_reservation($transport, $logical_upload_id, $request_identity);
}

?>