<?php

function community_is_valid_original_sum($original_sum)
{
  return is_string($original_sum)
    && preg_match('/^[a-fA-F0-9]{32}$/', $original_sum) === 1;
}

function community_invalid_original_sum_error()
{
  return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid original_sum');
}

function community_capture_original_sum_from_request(&$community_state, $request)
{
  if (!isset($request['original_sum']) || !community_is_valid_original_sum($request['original_sum']))
  {
    unset($community_state['md5sum']);
    return false;
  }

  $community_state['md5sum'] = $request['original_sum'];

  return true;
}

function community_call_ws_images_add($params, $service)
{
  if (isset($GLOBALS['community_ws_images_add_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_add_delegate'], $params, $service);
  }

  if (!function_exists('ws_images_add'))
  {
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');
  }

  return ws_images_add($params, $service);
}

function community_call_ws_images_add_chunk($params, $service)
{
  if (isset($GLOBALS['community_ws_images_add_chunk_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_add_chunk_delegate'], $params, $service);
  }

  if (!function_exists('ws_images_add_chunk'))
  {
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');
  }

  return ws_images_add_chunk($params, $service);
}

function community_call_ws_images_add_simple($params, $service)
{
  if (isset($GLOBALS['community_ws_images_add_simple_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_add_simple_delegate'], $params, $service);
  }

  if (!function_exists('ws_images_addSimple'))
  {
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');
  }

  return ws_images_addSimple($params, $service);
}

function community_call_ws_images_upload($params, $service)
{
  if (isset($GLOBALS['community_ws_images_upload_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_delegate'], $params, $service);
  }

  if (!function_exists('ws_images_upload'))
  {
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');
  }

  return ws_images_upload($params, $service);
}

function community_call_ws_images_upload_async($params, $service)
{
  if (isset($GLOBALS['community_ws_images_upload_async_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_async_delegate'], $params, $service);
  }

  return community_finalize_ws_images_upload_async($params, $service, null);
}

function community_call_add_uploaded_file($source_filepath, $original_filename = null, $categories = null, $level = null, $image_id = null, $original_md5sum = null)
{
  if (isset($GLOBALS['community_test']['add_uploaded_file_calls']))
  {
    $GLOBALS['community_test']['add_uploaded_file_calls'][] = array(
      'tmp_name' => $source_filepath,
      'original_name' => $original_filename,
      'categories' => $categories,
      'level' => $level,
      'image_id' => $image_id,
      'original_md5sum' => $original_md5sum,
    );

    if (isset($GLOBALS['community_test']['add_uploaded_file_callback']))
    {
      return call_user_func(
        $GLOBALS['community_test']['add_uploaded_file_callback'],
        $source_filepath,
        $original_filename,
        $categories,
        $level,
        $image_id,
        $original_md5sum
      );
    }

    return null === $image_id ? 1 : $image_id;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

  return add_uploaded_file(
    $source_filepath,
    $original_filename,
    $categories,
    $level,
    $image_id,
    $original_md5sum
  );
}

function community_call_update_category($category_ids)
{
  if (isset($GLOBALS['community_test']['update_category_calls']))
  {
    $GLOBALS['community_test']['update_category_calls'][] = $category_ids;
    return null;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  return update_category($category_ids);
}

function community_call_invalidate_user_cache($full = true)
{
  if (isset($GLOBALS['community_test']['invalidate_user_cache_calls']))
  {
    $GLOBALS['community_test']['invalidate_user_cache_calls']++;
    return null;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  return invalidate_user_cache($full);
}

function community_call_set_tags($tag_ids, $image_id)
{
  if (isset($GLOBALS['community_test']['set_tag_calls']))
  {
    $GLOBALS['community_test']['set_tag_calls'][] = array(
      'tag_ids' => $tag_ids,
      'image_id' => $image_id,
    );

    return null;
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

  return set_tags($tag_ids, $image_id);
}

function community_upload_async_invalid_param_error($message)
{
  return new PwgError(WS_ERR_INVALID_PARAM, $message);
}

function community_upload_async_conflict_error($message)
{
  return new PwgError(409, $message);
}

function community_upload_async_expired_error()
{
  return new PwgError(410, 'Upload expired');
}

function community_upload_async_size_error($message)
{
  return new PwgError(413, $message);
}

function community_get_upload_async_limits()
{
  global $conf;

  $chunk_size_kb = isset($conf['upload_form_chunk_size']) ? (int) $conf['upload_form_chunk_size'] : 500;
  if ($chunk_size_kb <= 0)
  {
    $chunk_size_kb = 500;
  }

  $max_chunks = 200;
  if (isset($conf['community']['upload_async_max_chunks']) and (int) $conf['community']['upload_async_max_chunks'] > 0)
  {
    $max_chunks = (int) $conf['community']['upload_async_max_chunks'];
  }

  $expiry_seconds = 24 * 60 * 60;
  if (isset($conf['community']['upload_async_expiry_seconds']) and (int) $conf['community']['upload_async_expiry_seconds'] > 0)
  {
    $expiry_seconds = (int) $conf['community']['upload_async_expiry_seconds'];
  }

  $max_chunk_bytes = $chunk_size_kb * 1024;
  $max_total_bytes = $max_chunk_bytes * $max_chunks;
  if (isset($conf['community']['upload_async_max_bytes']) and (int) $conf['community']['upload_async_max_bytes'] > 0)
  {
    $max_total_bytes = (int) $conf['community']['upload_async_max_bytes'];
  }

  return array(
    'max_chunks' => $max_chunks,
    'max_chunk_bytes' => $max_chunk_bytes,
    'max_total_bytes' => $max_total_bytes,
    'expiry_seconds' => $expiry_seconds,
    'receipt_ttl_seconds' => 5 * 60,
  );
}

function community_get_upload_async_session_identity()
{
  global $user;

  if (in_array($user['status'], array('guest', 'generic')))
  {
    return session_id();
  }

  return null;
}

function community_get_upload_async_state_paths($original_sum, $user_id = null, $session_identity = null)
{
  global $conf, $user;

  if (!isset($user_id))
  {
    $user_id = (int) $user['id'];
  }

  if (!isset($session_identity))
  {
    $session_identity = community_get_upload_async_session_identity();
  }

  $base_dir = rtrim($conf['upload_dir'], '/').'/buffer/community-upload-async';
  $state_key = sha1('community-upload-async|'.$user_id.'|'.(string) $session_identity.'|'.strtolower($original_sum));
  $state_dir = $base_dir.'/'.$state_key;

  return array(
    'base_dir' => $base_dir,
    'state_dir' => $state_dir,
    'lock_file' => $state_dir.'/state.lock',
    'manifest_file' => $state_dir.'/manifest.json',
    'receipt_file' => $state_dir.'/receipt.json',
    'chunks_dir' => $state_dir.'/chunks',
    'merged_file' => $state_dir.'/merged.bin',
  );
}

function community_ensure_directory($directory)
{
  if (is_dir($directory))
  {
    return true;
  }

  return @mkdir($directory, 0777, true) || is_dir($directory);
}

function community_validate_uploaded_tmp_file($tmp_name)
{
  if (!is_string($tmp_name) || '' === $tmp_name || !is_file($tmp_name) || !is_readable($tmp_name))
  {
    return false;
  }

  if ('cli' === PHP_SAPI)
  {
    return true;
  }

  return is_uploaded_file($tmp_name);
}

function community_upload_async_normalize_chunk_index($chunk, $chunks)
{
  if (!is_int($chunk) || !is_int($chunks) || $chunks <= 0)
  {
    return null;
  }

  if (1 === $chunks && 1 === $chunk)
  {
    return 0;
  }

  if ($chunk < 0 || $chunk >= $chunks)
  {
    return null;
  }

  return $chunk;
}

function community_upload_async_normalize_tag_ids($tag_ids)
{
  if (!isset($tag_ids) || '' === $tag_ids)
  {
    return null;
  }

  if (is_array($tag_ids))
  {
    return implode(',', $tag_ids);
  }

  return (string) $tag_ids;
}

function community_build_upload_async_manifest_request($params, $authorized_categories)
{
  global $user;

  return array(
    'user_id' => (int) $user['id'],
    'session_id' => community_get_upload_async_session_identity(),
    'category' => $authorized_categories,
    'original_sum' => $params['original_sum'],
    'chunks' => (int) $params['chunks'],
    'image_id' => !empty($params['image_id']) ? (int) $params['image_id'] : null,
    'filename' => (string) $params['filename'],
    'name' => isset($params['name']) ? (string) $params['name'] : null,
    'author' => isset($params['author']) ? (string) $params['author'] : null,
    'comment' => isset($params['comment']) ? (string) $params['comment'] : null,
    'date_creation' => isset($params['date_creation']) ? (string) $params['date_creation'] : null,
    'level' => isset($params['level']) ? (int) $params['level'] : 0,
    'tag_ids' => community_upload_async_normalize_tag_ids(isset($params['tag_ids']) ? $params['tag_ids'] : null),
  );
}

function community_upload_async_manifest_matches($manifest, $manifest_request)
{
  $keys = array(
    'user_id',
    'session_id',
    'category',
    'original_sum',
    'chunks',
    'image_id',
    'filename',
    'name',
    'author',
    'comment',
    'date_creation',
    'level',
    'tag_ids',
  );

  foreach ($keys as $key)
  {
    if (!array_key_exists($key, $manifest) || $manifest[$key] !== $manifest_request[$key])
    {
      return false;
    }
  }

  return true;
}

function community_read_json_file($filepath)
{
  if (!is_file($filepath))
  {
    return null;
  }

  $content = file_get_contents($filepath);
  if (false === $content)
  {
    return null;
  }

  $decoded = json_decode($content, true);
  if (!is_array($decoded))
  {
    return null;
  }

  return $decoded;
}

function community_write_json_file($filepath, $data)
{
  if (isset($GLOBALS['community_test_write_json_file_callback']))
  {
    $callback_result = call_user_func($GLOBALS['community_test_write_json_file_callback'], $filepath, $data);
    if (isset($callback_result))
    {
      return (bool) $callback_result;
    }
  }

  $directory = dirname($filepath);
  if (!community_ensure_directory($directory))
  {
    return false;
  }

  $encoded = json_encode($data);
  if (false === $encoded)
  {
    return false;
  }

  $temporary_file = $filepath.'.tmp';
  if (false === file_put_contents($temporary_file, $encoded, LOCK_EX))
  {
    return false;
  }

  return @rename($temporary_file, $filepath);
}

function community_delete_path($path)
{
  if (is_dir($path) && !is_link($path))
  {
    $entries = scandir($path);
    if (false !== $entries)
    {
      foreach ($entries as $entry)
      {
        if ('.' === $entry || '..' === $entry)
        {
          continue;
        }

        community_delete_path($path.'/'.$entry);
      }
    }

    return @rmdir($path);
  }

  if (file_exists($path) || is_link($path))
  {
    return @unlink($path);
  }

  return true;
}

function community_cleanup_upload_async_artifacts($manifest, $paths, $remove_receipt = false)
{
  community_delete_path($paths['merged_file']);
  community_delete_path($paths['manifest_file']);
  community_delete_path($paths['chunks_dir']);

  if ($remove_receipt)
  {
    community_delete_path($paths['receipt_file']);
  }

  if (isset($manifest['original_sum'], $manifest['user_id'], $manifest['chunks']))
  {
    global $conf;

    $core_prefix = rtrim($conf['upload_dir'], '/').'/buffer/'.$manifest['original_sum'].'-u'.(int) $manifest['user_id'];
    community_delete_path($core_prefix.'.merged');

    for ($chunk_id = 1; $chunk_id <= (int) $manifest['chunks']; $chunk_id++)
    {
      community_delete_path(sprintf('%s-%03uof%03u.chunk', $core_prefix, $chunk_id, (int) $manifest['chunks']));
    }
  }
}

function community_cleanup_upload_async_state_directory($paths)
{
  if (is_file($paths['manifest_file']) || is_dir($paths['chunks_dir']) || is_file($paths['receipt_file']) || is_file($paths['merged_file']))
  {
    return;
  }

  community_delete_path($paths['lock_file']);

  if (is_dir($paths['state_dir']))
  {
    community_delete_path($paths['state_dir']);
  }
}

function community_upload_async_uploaded_chunk_numbers($manifest)
{
  $chunk_numbers = array();

  if (!isset($manifest['chunk_map']) || !is_array($manifest['chunk_map']))
  {
    return $chunk_numbers;
  }

  $indexes = array_map('intval', array_keys($manifest['chunk_map']));
  sort($indexes, SORT_NUMERIC);

  foreach ($indexes as $index)
  {
    $chunk_numbers[] = $index + 1;
  }

  return $chunk_numbers;
}

function community_upload_async_status_message($manifest)
{
  return array('message' => 'chunks uploaded = '.implode(',', community_upload_async_uploaded_chunk_numbers($manifest)));
}

function community_upload_async_all_chunks_present($manifest)
{
  if (!isset($manifest['chunk_map']) || !is_array($manifest['chunk_map']))
  {
    return false;
  }

  for ($chunk_index = 0; $chunk_index < (int) $manifest['chunks']; $chunk_index++)
  {
    if (!isset($manifest['chunk_map'][(string) $chunk_index]))
    {
      return false;
    }
  }

  return true;
}

function community_revalidate_upload_async_final_manifest($manifest)
{
  $authorized_categories = community_authorize_upload_categories($manifest['category']);
  if (empty($authorized_categories) || $authorized_categories !== $manifest['category'])
  {
    return false;
  }

  if (!empty($manifest['image_id']) && !community_user_can_mutate_uploaded_image($manifest['image_id']))
  {
    return false;
  }

  return true;
}

function community_upload_async_completed_manifest_result($manifest, $manifest_request)
{
  if (!is_array($manifest)
    || !isset($manifest['completed_request'])
    || !isset($manifest['completed_result'])
    || !community_upload_async_manifest_matches($manifest['completed_request'], $manifest_request)
  )
  {
    return null;
  }

  return $manifest['completed_result'];
}

function community_store_upload_async_completed_manifest($manifest, $manifest_request, $result, $paths, $limits)
{
  $completed_manifest = array(
    'created_at' => isset($manifest['created_at']) ? $manifest['created_at'] : time(),
    'expires_at' => time() + $limits['receipt_ttl_seconds'],
    'completed_at' => time(),
    'completed_request' => $manifest_request,
    'completed_result' => $result,
  );

  return community_write_json_file($paths['manifest_file'], $completed_manifest);
}

function community_finalize_ws_images_upload_async($params, $service, $merged_filepath)
{
  global $user;

  if (isset($GLOBALS['community_ws_images_upload_async_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_async_delegate'], $params, $service);
  }

  $image_id = community_call_add_uploaded_file(
    $merged_filepath,
    $params['filename'],
    $params['category'],
    $params['level'],
    $params['image_id'],
    $params['original_sum']
  );

  if (isset($params['tag_ids']) and !empty($params['tag_ids']))
  {
    community_call_set_tags(
      explode(',', $params['tag_ids']),
      $image_id
    );
  }

  $info_columns = array(
    'name',
    'author',
    'comment',
    'date_creation',
  );

  $update = array();
  foreach ($info_columns as $key)
  {
    if (isset($params[$key]))
    {
      $update[$key] = $params[$key];
    }
  }

  if (count(array_keys($update)) > 0)
  {
    single_update(
      IMAGES_TABLE,
      $update,
      array('id' => $image_id)
    );
  }

  community_call_invalidate_user_cache();

  if (!empty($params['level']) and $params['level'] > $user['level'])
  {
    $user['level'] = $params['level'];
  }

  return $service->invoke('pwg.images.getInfo', array('image_id' => $image_id));

  if (!function_exists('ws_images_uploadAsync'))
  {
    include_once(PHPWG_ROOT_PATH.'include/ws_functions/pwg.images.php');
  }

  return ws_images_uploadAsync($params, $service);
}

function community_access_denied_error()
{
  return new PwgError(401, 'Access denied');
}

function community_normalize_upload_category_ids($categories)
{
  if (is_array($categories))
  {
    $raw_categories = $categories;
  }
  elseif (isset($categories))
  {
    $raw_categories = array($categories);
  }
  else
  {
    return null;
  }

  if (count($raw_categories) == 0)
  {
    return null;
  }

  $normalized_categories = array();
  foreach ($raw_categories as $raw_category)
  {
    if (is_int($raw_category))
    {
      $category_id = $raw_category;
    }
    elseif (is_string($raw_category) and preg_match('/^[1-9][0-9]*$/', $raw_category))
    {
      $category_id = (int) $raw_category;
    }
    else
    {
      return null;
    }

    if ($category_id <= 0)
    {
      return null;
    }

    $normalized_categories[] = $category_id;
  }

  return $normalized_categories;
}

function community_authorize_upload_categories($categories)
{
  global $user;

  $normalized_categories = community_normalize_upload_category_ids($categories);
  if (empty($normalized_categories))
  {
    return null;
  }

  $user_permissions = community_get_user_permissions($user['id']);
  if (empty($user_permissions['upload_categories']))
  {
    return null;
  }

  $allowed_categories = array_map('intval', $user_permissions['upload_categories']);
  foreach ($normalized_categories as $category_id)
  {
    if (!in_array($category_id, $allowed_categories))
    {
      return null;
    }
  }

  return $normalized_categories;
}

function community_user_can_mutate_uploaded_image($image_id)
{
  global $user;

  $query = '
SELECT COUNT(*)
  FROM '.IMAGES_TABLE.'
  WHERE id = '.(int) $image_id.'
    AND `added_by` = '.$user['id'].'
;';
  list($count) = pwg_db_fetch_row(pwg_query($query));
  if (empty($count))
  {
    return false;
  }

  if (in_array($user['status'], array('guest', 'generic')))
  {
    $query = '
SELECT COUNT(*)
  FROM '.ACTIVITY_TABLE.'
  WHERE `object` = \'photo\'
    AND `action` = \'add\'
    AND `object_id` = '.(int) $image_id.'
    AND `session_idx` = \''.pwg_db_real_escape_string(session_id()).'\'
;';
    list($count) = pwg_db_fetch_row(pwg_query($query));
    if (empty($count))
    {
      return false;
    }
  }

  return true;
}

function community_ws_images_add_simple($params, $service)
{
  global $community;

  $authorized_categories = community_authorize_upload_categories(
    isset($params['category']) ? $params['category'] : null
  );
  if (empty($authorized_categories))
  {
    return community_access_denied_error();
  }

  $params['category'] = $authorized_categories;

  if (!empty($params['image_id']) and !community_user_can_mutate_uploaded_image($params['image_id']))
  {
    return community_access_denied_error();
  }

  $result = community_call_ws_images_add_simple($params, $service);
  if (!($result instanceof PwgError))
  {
    $community['method'] = 'pwg.images.addSimple';
    $community['category'] = $authorized_categories[0];

    if (isset($GLOBALS['community_test']['metadata_sync_calls']) && isset($result['image_id']))
    {
      $GLOBALS['community_test']['metadata_sync_calls'][] = array((int) $result['image_id']);
    }
  }

  return $result;
}

function community_ws_images_upload($params, $service)
{
  global $community;

  $authorized_categories = community_authorize_upload_categories(
    isset($params['category']) ? $params['category'] : null
  );
  if (empty($authorized_categories))
  {
    return community_access_denied_error();
  }

  $params['category'] = $authorized_categories;

  // Core update_mode re-resolves the replacement target internally using
  // name/category, so Community cannot safely pin the object it just
  // authorized through this delegate boundary.
  if (!empty($params['update_mode']))
  {
    return community_access_denied_error();
  }

  if (!empty($params['format_of']) and !community_user_can_mutate_uploaded_image($params['format_of']))
  {
    return community_access_denied_error();
  }

  $result = community_call_ws_images_upload($params, $service);
  if (!($result instanceof PwgError))
  {
    $community['method'] = 'pwg.images.upload';
    $community['category'] = $authorized_categories[0];
  }

  return $result;
}

function community_ws_images_upload_async($params, $service)
{
  global $community;

  $cleanup_state_dir_after_unlock = false;

  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  if (!isset($params['chunk_sum']) || !community_is_valid_original_sum($params['chunk_sum']))
  {
    return community_upload_async_invalid_param_error('Invalid chunk_sum');
  }

  $authorized_categories = community_authorize_upload_categories(
    isset($params['category']) ? $params['category'] : null
  );
  if (empty($authorized_categories))
  {
    return community_access_denied_error();
  }

  $params['category'] = $authorized_categories;

  $chunks = isset($params['chunks']) ? (int) $params['chunks'] : 0;
  $chunk_index = community_upload_async_normalize_chunk_index(
    isset($params['chunk']) ? (int) $params['chunk'] : null,
    $chunks
  );
  if (!isset($chunk_index))
  {
    return community_upload_async_invalid_param_error('Invalid chunk index');
  }

  $limits = community_get_upload_async_limits();
  if ($chunks > $limits['max_chunks'])
  {
    return community_upload_async_invalid_param_error('Too many chunks');
  }

  if (!isset($_FILES['file']) || !is_array($_FILES['file']))
  {
    return community_upload_async_invalid_param_error('The file chunk is missing');
  }

  if (!empty($_FILES['file']['error']))
  {
    return new PwgError(500, 'Upload failed');
  }

  $tmp_name = isset($_FILES['file']['tmp_name']) ? $_FILES['file']['tmp_name'] : null;
  if (!community_validate_uploaded_tmp_file($tmp_name))
  {
    return community_upload_async_invalid_param_error('Invalid uploaded file');
  }

  $chunk_size = filesize($tmp_name);
  if (false === $chunk_size)
  {
    return community_upload_async_invalid_param_error('Unable to read uploaded chunk size');
  }

  if ($chunk_size > $limits['max_chunk_bytes'])
  {
    return community_upload_async_size_error('Uploaded chunk exceeds the configured chunk size limit');
  }

  $actual_chunk_sum = md5_file($tmp_name);
  if (false === $actual_chunk_sum || strtolower($actual_chunk_sum) !== strtolower($params['chunk_sum']))
  {
    return community_upload_async_invalid_param_error('Chunk checksum mismatched');
  }

  if (!empty($params['image_id']) and !community_user_can_mutate_uploaded_image($params['image_id']))
  {
    return community_access_denied_error();
  }

  $manifest_request = community_build_upload_async_manifest_request($params, $authorized_categories);
  $paths = community_get_upload_async_state_paths(
    $manifest_request['original_sum'],
    $manifest_request['user_id'],
    $manifest_request['session_id']
  );

  if (!community_ensure_directory($paths['state_dir']))
  {
    return new PwgError(500, 'Unable to prepare upload state directory');
  }

  $lock_handle = fopen($paths['lock_file'], 'c+');
  if (false === $lock_handle)
  {
    return new PwgError(500, 'Unable to prepare upload state lock');
  }

  $result = null;
  if (!flock($lock_handle, LOCK_EX))
  {
    fclose($lock_handle);
    return new PwgError(500, 'Unable to lock upload state');
  }

  $receipt = community_read_json_file($paths['receipt_file']);
  if (is_array($receipt) && isset($receipt['expires_at']) && $receipt['expires_at'] < time())
  {
    community_cleanup_upload_async_artifacts($manifest_request, $paths, true);
    $receipt = null;
  }

  if (is_array($receipt)
    and isset($receipt['request'])
    and community_upload_async_manifest_matches($receipt['request'], $manifest_request)
    and isset($receipt['result'])
  )
  {
    $result = $receipt['result'];
  }
  else
  {
    $manifest = community_read_json_file($paths['manifest_file']);
    if (is_array($manifest) && isset($manifest['expires_at']) && $manifest['expires_at'] < time())
    {
      community_cleanup_upload_async_artifacts($manifest, $paths, true);
      $cleanup_state_dir_after_unlock = true;
      $manifest = null;
      $result = community_upload_async_expired_error();
    }

    if (!isset($result))
    {
      $completed_result = community_upload_async_completed_manifest_result($manifest, $manifest_request);
      if (isset($completed_result))
      {
        $result = $completed_result;
      }
      elseif (!is_array($manifest))
      {
        $manifest = $manifest_request;
        $manifest['created_at'] = time();
        $manifest['expires_at'] = $manifest['created_at'] + $limits['expiry_seconds'];
        $manifest['received_bytes'] = 0;
        $manifest['chunk_map'] = array();
      }
      elseif (!community_upload_async_manifest_matches($manifest, $manifest_request))
      {
        $result = community_access_denied_error();
      }
    }

    if (!isset($result))
    {
      $chunk_key = (string) $chunk_index;
      $chunk_path = $paths['chunks_dir'].'/'.sprintf('%06u.chunk', $chunk_index);

      if (isset($manifest['chunk_map'][$chunk_key]))
      {
        $stored_chunk = $manifest['chunk_map'][$chunk_key];
        $same_chunk = isset($stored_chunk['chunk_sum'], $stored_chunk['size'])
          && strtolower($stored_chunk['chunk_sum']) === strtolower($params['chunk_sum'])
          && (int) $stored_chunk['size'] === (int) $chunk_size
          && is_file($chunk_path)
          && strtolower((string) md5_file($chunk_path)) === strtolower($params['chunk_sum']);

        if (!$same_chunk)
        {
          $result = community_upload_async_conflict_error('Chunk retry conflicts with the existing upload state');
        }
      }
      else
      {
        if ($manifest['received_bytes'] + $chunk_size > $limits['max_total_bytes'])
        {
          $result = community_upload_async_size_error('Upload exceeds the configured cumulative size limit');
        }
        elseif (!community_ensure_directory($paths['chunks_dir']))
        {
          $result = new PwgError(500, 'Unable to prepare upload chunk directory');
        }
        elseif (!@copy($tmp_name, $chunk_path))
        {
          $result = new PwgError(500, 'Unable to persist the uploaded chunk');
        }
        else
        {
          $manifest['chunk_map'][$chunk_key] = array(
            'chunk_sum' => $params['chunk_sum'],
            'size' => (int) $chunk_size,
          );
          $manifest['received_bytes'] += (int) $chunk_size;
          $manifest['expires_at'] = time() + $limits['expiry_seconds'];

          if (!community_write_json_file($paths['manifest_file'], $manifest))
          {
            community_delete_path($chunk_path);
            unset($manifest['chunk_map'][$chunk_key]);
            $manifest['received_bytes'] -= (int) $chunk_size;
            $result = new PwgError(500, 'Unable to persist upload state');
          }
        }
      }
    }

    if (!isset($result))
    {
      if (!community_upload_async_all_chunks_present($manifest))
      {
        $result = community_upload_async_status_message($manifest);
      }
      elseif (!community_revalidate_upload_async_final_manifest($manifest))
      {
        $result = community_access_denied_error();
      }
      else
      {
        community_delete_path($paths['merged_file']);

        $merged_handle = fopen($paths['merged_file'], 'wb');
        if (false === $merged_handle)
        {
          $result = new PwgError(500, 'Unable to prepare merged upload file');
        }
        else
        {
          for ($merged_chunk_index = 0; $merged_chunk_index < $manifest['chunks']; $merged_chunk_index++)
          {
            $stored_chunk_path = $paths['chunks_dir'].'/'.sprintf('%06u.chunk', $merged_chunk_index);
            $chunk_contents = file_get_contents($stored_chunk_path);
            if (false === $chunk_contents || false === fwrite($merged_handle, $chunk_contents))
            {
              $result = new PwgError(500, 'Unable to merge uploaded chunks');
              break;
            }
          }

          fclose($merged_handle);
        }

        if (!isset($result))
        {
          $merged_sum = md5_file($paths['merged_file']);
          if (false === $merged_sum || strtolower($merged_sum) !== strtolower($manifest['original_sum']))
          {
            $result = community_upload_async_invalid_param_error('Merged upload checksum mismatched');
            community_cleanup_upload_async_artifacts($manifest, $paths, true);
            $cleanup_state_dir_after_unlock = true;
          }
          else
          {
            $final_params = $manifest;
            unset(
              $final_params['created_at'],
              $final_params['expires_at'],
              $final_params['received_bytes'],
              $final_params['chunk_map'],
              $final_params['user_id'],
              $final_params['session_id']
            );

            $result = community_finalize_ws_images_upload_async($final_params, $service, $paths['merged_file']);
            if ($result instanceof PwgError)
            {
              community_delete_path($paths['merged_file']);
            }
            else
            {
              $receipt_written = community_write_json_file(
                $paths['receipt_file'],
                array(
                  'request' => $manifest_request,
                  'result' => $result,
                  'expires_at' => time() + $limits['receipt_ttl_seconds'],
                )
              );

              if (!$receipt_written)
              {
                $receipt_fallback_written = community_store_upload_async_completed_manifest(
                  $manifest,
                  $manifest_request,
                  $result,
                  $paths,
                  $limits
                );

                if (!$receipt_fallback_written)
                {
                  $result = new PwgError(500, 'Upload completed but completion state could not be recorded');
                }
                else
                {
                  community_delete_path($paths['merged_file']);
                  community_delete_path($paths['chunks_dir']);
                  $community['method'] = 'pwg.images.uploadAsync';
                  $community['category'] = $authorized_categories[0];
                }
              }
              else
              {
                community_cleanup_upload_async_artifacts($manifest, $paths, false);
                $community['method'] = 'pwg.images.uploadAsync';
                $community['category'] = $authorized_categories[0];
              }
            }
          }
        }
      }
    }
  }

  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);

  if ($cleanup_state_dir_after_unlock)
  {
    community_cleanup_upload_async_state_directory($paths);
  }

  return $result;
}

function community_maybe_precheck_original_filename_uniqueness(&$params)
{
  global $conf;

  if (empty($params['check_uniqueness']) || 'filename' != $conf['uniqueness_mode'])
  {
    return null;
  }

  $original_filename = isset($params['original_filename']) ? (string) $params['original_filename'] : '';
  $query = '
SELECT COUNT(*)
  FROM '.IMAGES_TABLE.'
  WHERE file = \''.pwg_db_real_escape_string($original_filename).'\'
;';

  list($counter) = pwg_db_fetch_row(pwg_query($query));
  if ($counter != 0)
  {
    return new PwgError(500, 'file already exists');
  }

  $params['check_uniqueness'] = false;

  return null;
}

function community_get_legacy_add_limits()
{
  return array(
    'expiry_seconds' => 24 * 60 * 60,
  );
}

function community_get_legacy_add_state_paths($original_sum, $user_id = null, $session_identity = null)
{
  global $conf, $user;

  if (!isset($user_id))
  {
    $user_id = (int) $user['id'];
  }

  if (!isset($session_identity))
  {
    $session_identity = community_get_upload_async_session_identity();
  }

  $base_dir = rtrim($conf['upload_dir'], '/').'/buffer/community-legacy-add';
  $state_key = sha1('community-legacy-add|'.$user_id.'|'.(string) $session_identity.'|'.strtolower($original_sum));
  $state_dir = $base_dir.'/'.$state_key;

  return array(
    'base_dir' => $base_dir,
    'state_dir' => $state_dir,
    'lock_file' => $state_dir.'/state.lock',
    'manifest_file' => $state_dir.'/manifest.json',
    'chunks_dir' => $state_dir.'/chunks',
    'merged_file' => $state_dir.'/merged.bin',
  );
}

function community_cleanup_legacy_add_state_directory($paths)
{
  if (is_file($paths['manifest_file']) || is_dir($paths['chunks_dir']) || is_file($paths['merged_file']))
  {
    return;
  }

  community_delete_path($paths['lock_file']);

  if (is_dir($paths['state_dir']))
  {
    community_delete_path($paths['state_dir']);
  }
}

function community_cleanup_legacy_add_artifacts($paths)
{
  community_delete_path($paths['merged_file']);
  community_delete_path($paths['manifest_file']);
  community_delete_path($paths['chunks_dir']);
  community_cleanup_legacy_add_state_directory($paths);
}

function community_normalize_legacy_add_chunk_type($type)
{
  if (!isset($type) || '' === $type)
  {
    return 'file';
  }

  if (!is_string($type) || !in_array($type, array('file', 'high', 'thumb')))
  {
    return null;
  }

  return $type;
}

function community_normalize_legacy_add_chunk_position($position)
{
  if (is_int($position))
  {
    return $position >= 0 ? $position : null;
  }

  if (is_string($position) && preg_match('/^\d+$/', $position))
  {
    return (int) $position;
  }

  return null;
}

function community_decode_legacy_add_chunk_data($data)
{
  if (!is_string($data))
  {
    return null;
  }

  $decoded = base64_decode($data, true);
  if (false === $decoded)
  {
    return null;
  }

  return $decoded;
}

function community_parse_legacy_add_category_links($categories_string)
{
  if (!is_string($categories_string) || '' === trim($categories_string))
  {
    return null;
  }

  $tokens = explode(';', $categories_string);
  $links = array();

  foreach ($tokens as $token)
  {
    if (!preg_match('/^([1-9][0-9]*)(?:,([^;]+))?$/', trim($token), $matches))
    {
      return null;
    }

    $rank = isset($matches[2]) ? trim($matches[2]) : 'auto';
    if ('auto' !== $rank && !preg_match('/^[1-9][0-9]*$/', $rank))
    {
      return null;
    }

    $links[] = array(
      'category_id' => (int) $matches[1],
      'rank' => 'auto' === $rank ? 'auto' : (int) $rank,
    );
  }

  return $links;
}

function community_authorize_legacy_add_categories($categories_string)
{
  $category_links = community_parse_legacy_add_category_links($categories_string);
  if (empty($category_links))
  {
    return null;
  }

  $category_ids = array();
  foreach ($category_links as $category_link)
  {
    $category_ids[] = $category_link['category_id'];
  }

  $authorized_categories = community_authorize_upload_categories($category_ids);
  if (empty($authorized_categories) || $authorized_categories !== $category_ids)
  {
    return null;
  }

  return array(
    'ids' => $authorized_categories,
    'links' => $category_links,
  );
}

function community_legacy_add_manifest_matches_actor($manifest, $original_sum)
{
  global $user;

  if (!is_array($manifest))
  {
    return false;
  }

  return array_key_exists('user_id', $manifest)
    && array_key_exists('session_id', $manifest)
    && array_key_exists('original_sum', $manifest)
    && (int) $manifest['user_id'] === (int) $user['id']
    && $manifest['session_id'] === community_get_upload_async_session_identity()
    && strtolower($manifest['original_sum']) === strtolower($original_sum);
}

function community_legacy_add_manifest_is_expired($manifest)
{
  return isset($manifest['expires_at']) && (int) $manifest['expires_at'] < time();
}

function community_legacy_add_manifest_chunk_path($paths, $type, $position)
{
  return $paths['chunks_dir'].'/'.$type.'-'.sprintf('%05u', $position).'.chunk';
}

function community_legacy_add_select_original_type($manifest)
{
  if (isset($manifest['chunks']['high']) && count($manifest['chunks']['high']) > 0)
  {
    return 'high';
  }

  if (isset($manifest['chunks']['file']) && count($manifest['chunks']['file']) > 0)
  {
    return 'file';
  }

  return null;
}

function community_merge_legacy_add_chunks($manifest, $paths, $type)
{
  if (!isset($manifest['chunks'][$type]) || !is_array($manifest['chunks'][$type]) || count($manifest['chunks'][$type]) === 0)
  {
    return community_access_denied_error();
  }

  if (is_file($paths['merged_file']) && !@unlink($paths['merged_file']))
  {
    return new PwgError(500, 'Unable to reset merged upload buffer');
  }

  $positions = array_map('intval', array_keys($manifest['chunks'][$type]));
  sort($positions, SORT_NUMERIC);

  foreach ($positions as $expected_position => $position)
  {
    if ($position !== $expected_position)
    {
      return new PwgError(409, 'Upload chunks are incomplete');
    }

    $chunk_meta = $manifest['chunks'][$type][(string) $position];
    $chunk_path = community_legacy_add_manifest_chunk_path($paths, $type, $position);
    if (!is_file($chunk_path) || !is_readable($chunk_path))
    {
      return community_access_denied_error();
    }

    $chunk_sum = md5_file($chunk_path);
    if (false === $chunk_sum || !isset($chunk_meta['chunk_sum']) || strtolower($chunk_meta['chunk_sum']) !== strtolower($chunk_sum))
    {
      return new PwgError(409, 'Upload chunk checksum mismatched');
    }

    $chunk_contents = file_get_contents($chunk_path);
    if (false === $chunk_contents || false === file_put_contents($paths['merged_file'], $chunk_contents, FILE_APPEND))
    {
      return new PwgError(500, 'Unable to merge buffered upload chunks');
    }
  }

  return $paths['merged_file'];
}

function community_apply_legacy_add_category_relations($image_id, $category_links)
{
  $current_rank_of = array();
  $inserts = array();

  foreach ($category_links as $category_link)
  {
    $rank = $category_link['rank'];
    if ('auto' === $rank)
    {
      $category_id = $category_link['category_id'];
      if (!isset($current_rank_of[$category_id]))
      {
        $query = '
SELECT MAX(`rank`) AS max_rank
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.(int) $category_id.'
;';
        $row = pwg_db_fetch_row(pwg_query($query));
        $current_rank_of[$category_id] = empty($row[0]) ? 0 : (int) $row[0];
      }

      $current_rank_of[$category_id]++;
      $rank = $current_rank_of[$category_id];
    }

    $inserts[] = array(
      'image_id' => (int) $image_id,
      'category_id' => (int) $category_link['category_id'],
      'rank' => $rank,
    );
  }

  if (count($inserts) > 0)
  {
    mass_inserts(
      IMAGE_CATEGORY_TABLE,
      array_keys($inserts[0]),
      $inserts
    );

    $category_ids = array();
    foreach ($category_links as $category_link)
    {
      $category_ids[] = (int) $category_link['category_id'];
    }

    community_call_update_category(array_values(array_unique($category_ids)));
  }

  return true;
}

function community_finalize_legacy_ws_images_add($params, $merged_filepath, $authorized_categories)
{
  $image_id = community_call_add_uploaded_file(
    $merged_filepath,
    $params['original_filename'],
    null,
    isset($params['level']) ? $params['level'] : null,
    !empty($params['image_id']) ? (int) $params['image_id'] : null,
    $params['original_sum']
  );

  $relation_result = community_apply_legacy_add_category_relations($image_id, $authorized_categories['links']);
  if ($relation_result instanceof PwgError)
  {
    return $relation_result;
  }

  $info_columns = array(
    'name',
    'author',
    'comment',
    'date_creation',
  );

  $update = array();
  foreach ($info_columns as $key)
  {
    if (isset($params[$key]))
    {
      $update[$key] = $params[$key];
    }
  }

  if (count($update) > 0)
  {
    single_update(
      IMAGES_TABLE,
      $update,
      array('id' => $image_id)
    );
  }

  if (isset($params['tag_ids']) && !empty($params['tag_ids']))
  {
    community_call_set_tags(
      explode(',', $params['tag_ids']),
      $image_id
    );
  }

  $url_params = array('image_id' => $image_id);
  if (!empty($authorized_categories['ids']))
  {
    $query = '
SELECT id, name, permalink
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.(int) $authorized_categories['ids'][0].'
;';
    $category = pwg_db_fetch_assoc(pwg_query($query));
    $url_params['section'] = 'categories';
    $url_params['category'] = $category;
  }

  community_call_invalidate_user_cache();

  return array(
    'image_id' => $image_id,
    'url' => make_picture_url($url_params),
  );
}

function community_ws_images_add($params, $service)
{
  global $community;

  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  $authorized_categories = community_authorize_legacy_add_categories(
    isset($params['categories']) ? $params['categories'] : null
  );
  if (empty($authorized_categories))
  {
    return community_access_denied_error();
  }

  if (!empty($params['image_id']) and !community_user_can_mutate_uploaded_image($params['image_id']))
  {
    return community_access_denied_error();
  }

  $filename_uniqueness_error = community_maybe_precheck_original_filename_uniqueness($params);
  if (isset($filename_uniqueness_error))
  {
    return $filename_uniqueness_error;
  }

  $paths = community_get_legacy_add_state_paths($params['original_sum']);
  if (!is_dir($paths['state_dir']) || !is_file($paths['manifest_file']))
  {
    return community_access_denied_error();
  }

  $lock_handle = fopen($paths['lock_file'], 'c+');
  if (false === $lock_handle || !flock($lock_handle, LOCK_EX))
  {
    if (false !== $lock_handle)
    {
      fclose($lock_handle);
    }

    return new PwgError(500, 'Unable to lock upload state');
  }

  $manifest = community_read_json_file($paths['manifest_file']);
  if (community_legacy_add_manifest_is_expired($manifest))
  {
    community_cleanup_legacy_add_artifacts($paths);
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return community_upload_async_expired_error();
  }

  if (!community_legacy_add_manifest_matches_actor($manifest, $params['original_sum']))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return community_access_denied_error();
  }

  $original_type = community_legacy_add_select_original_type($manifest);
  if (!isset($original_type))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return community_access_denied_error();
  }

  $merged_filepath = community_merge_legacy_add_chunks($manifest, $paths, $original_type);
  if ($merged_filepath instanceof PwgError)
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return $merged_filepath;
  }

  $result = community_finalize_legacy_ws_images_add($params, $merged_filepath, $authorized_categories);
  if (!($result instanceof PwgError))
  {
    $community['method'] = 'pwg.images.add';
    $community['category'] = $authorized_categories['ids'][0];
  }

  community_cleanup_legacy_add_artifacts($paths);
  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);

  return $result;
}

function community_ws_images_add_chunk($params, $service)
{
  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  $type = community_normalize_legacy_add_chunk_type(isset($params['type']) ? $params['type'] : null);
  if (!isset($type))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid type');
  }

  $position = community_normalize_legacy_add_chunk_position(isset($params['position']) ? $params['position'] : null);
  if (!isset($position))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid position');
  }

  $chunk_contents = community_decode_legacy_add_chunk_data(isset($params['data']) ? $params['data'] : null);
  if (!isset($chunk_contents))
  {
    return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid data');
  }

  $paths = community_get_legacy_add_state_paths($params['original_sum']);
  if (!community_ensure_directory($paths['chunks_dir']))
  {
    return new PwgError(500, 'Unable to prepare upload state directory');
  }

  $lock_handle = fopen($paths['lock_file'], 'c+');
  if (false === $lock_handle || !flock($lock_handle, LOCK_EX))
  {
    if (false !== $lock_handle)
    {
      fclose($lock_handle);
    }

    return new PwgError(500, 'Unable to lock upload state');
  }

  $manifest = community_read_json_file($paths['manifest_file']);
  if (community_legacy_add_manifest_is_expired($manifest))
  {
    community_cleanup_legacy_add_artifacts($paths);
    $manifest = null;
  }

  if (!is_array($manifest))
  {
    global $user;

    $limits = community_get_legacy_add_limits();
    $manifest = array(
      'created_at' => time(),
      'expires_at' => time() + $limits['expiry_seconds'],
      'user_id' => (int) $user['id'],
      'session_id' => community_get_upload_async_session_identity(),
      'original_sum' => $params['original_sum'],
      'chunks' => array(),
    );
  }
  elseif (!community_legacy_add_manifest_matches_actor($manifest, $params['original_sum']))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return community_access_denied_error();
  }

  if (!isset($manifest['chunks'][$type]) || !is_array($manifest['chunks'][$type]))
  {
    $manifest['chunks'][$type] = array();
  }

  $chunk_path = community_legacy_add_manifest_chunk_path($paths, $type, $position);
  $chunk_sum = md5($chunk_contents);
  $chunk_size = strlen($chunk_contents);
  $existing_chunk = isset($manifest['chunks'][$type][(string) $position]) ? $manifest['chunks'][$type][(string) $position] : null;

  if (isset($existing_chunk))
  {
    if (isset($existing_chunk['chunk_sum'], $existing_chunk['size'])
      && strtolower($existing_chunk['chunk_sum']) === strtolower($chunk_sum)
      && (int) $existing_chunk['size'] === $chunk_size
      && is_file($chunk_path)
      && strtolower((string) md5_file($chunk_path)) === strtolower($chunk_sum)
    )
    {
      flock($lock_handle, LOCK_UN);
      fclose($lock_handle);

      return true;
    }

    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return community_upload_async_conflict_error('Conflicting chunk retry');
  }

  if (false === file_put_contents($chunk_path, $chunk_contents, LOCK_EX))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return new PwgError(500, 'Unable to store buffered upload chunk');
  }

  $manifest['chunks'][$type][(string) $position] = array(
    'chunk_sum' => $chunk_sum,
    'size' => $chunk_size,
  );

  if (!community_write_json_file($paths['manifest_file'], $manifest))
  {
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);

    return new PwgError(500, 'Unable to persist upload state');
  }

  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);

  return true;
}

function community_find_image_id_by_original_sum($original_sum)
{
  $query = '
SELECT
    id
  FROM '.IMAGES_TABLE.'
  WHERE md5sum = \''.pwg_db_real_escape_string($original_sum).'\'
  ORDER BY id DESC
  LIMIT 1
;';

  $row = pwg_db_fetch_row(pwg_query($query));
  if (empty($row))
  {
    return null;
  }

  return (int) $row[0];
}