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

function community_find_update_mode_image_id($category_id, $name)
{
  $escaped_name = pwg_db_real_escape_string(stripslashes($name));
  $query = '
SELECT
    i.id
  FROM '.IMAGES_TABLE.' AS i
    INNER JOIN '.IMAGE_CATEGORY_TABLE.' AS ic ON ic.image_id = i.id
  WHERE i.file = \''.$escaped_name.'\'
    AND ic.category_id = '.(int) $category_id.'
;';
  $images = query2array($query);

  if (empty($images))
  {
    return null;
  }

  return (int) $images[0]['id'];
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

  if (!empty($params['format_of']) and !community_user_can_mutate_uploaded_image($params['format_of']))
  {
    return community_access_denied_error();
  }

  if (!empty($params['update_mode']))
  {
    $update_image_id = community_find_update_mode_image_id(
      $authorized_categories[0],
      isset($params['name']) ? $params['name'] : ''
    );

    if (isset($update_image_id) and !community_user_can_mutate_uploaded_image($update_image_id))
    {
      return community_access_denied_error();
    }
  }

  $result = community_call_ws_images_upload($params, $service);
  if (!($result instanceof PwgError))
  {
    $community['method'] = 'pwg.images.upload';
    $community['category'] = $authorized_categories[0];
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