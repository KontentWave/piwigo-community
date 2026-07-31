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

  if (!is_file($paths['manifest_file']) && !is_dir($paths['chunks_dir']) && (!is_file($paths['receipt_file']) || $remove_receipt))
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

function community_finalize_ws_images_upload_async($params, $service, $merged_filepath)
{
  global $user;

  if (isset($GLOBALS['community_ws_images_upload_async_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_async_delegate'], $params, $service);
  }

  include_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

  $image_id = add_uploaded_file(
    $merged_filepath,
    $params['filename'],
    $params['category'],
    $params['level'],
    $params['image_id'],
    $params['original_sum']
  );

  if (isset($params['tag_ids']) and !empty($params['tag_ids']))
  {
    set_tags(
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

  invalidate_user_cache();

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
      $manifest = null;
      $result = community_upload_async_expired_error();
    }

    if (!isset($result))
    {
      if (!is_array($manifest))
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
              community_write_json_file(
                $paths['receipt_file'],
                array(
                  'request' => $manifest_request,
                  'result' => $result,
                  'expires_at' => time() + $limits['receipt_ttl_seconds'],
                )
              );
              community_cleanup_upload_async_artifacts($manifest, $paths, false);
              $community['method'] = 'pwg.images.uploadAsync';
              $community['category'] = $authorized_categories[0];
            }
          }
        }
      }
    }
  }

  flock($lock_handle, LOCK_UN);
  fclose($lock_handle);

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

function community_ws_images_add($params, $service)
{
  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  $filename_uniqueness_error = community_maybe_precheck_original_filename_uniqueness($params);
  if (isset($filename_uniqueness_error))
  {
    return $filename_uniqueness_error;
  }

  return community_call_ws_images_add($params, $service);
}

function community_ws_images_add_chunk($params, $service)
{
  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  return community_call_ws_images_add_chunk($params, $service);
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