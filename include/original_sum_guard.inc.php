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

function community_ws_images_add($params, $service)
{
  if (!isset($params['original_sum']) || !community_is_valid_original_sum($params['original_sum']))
  {
    return community_invalid_original_sum_error();
  }

  return community_call_ws_images_add($params, $service);
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