<?php

define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');
define('IN_WS', true);
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('IMAGES_TABLE', 'piwigo_images');
define('ACTIVITY_TABLE', 'piwigo_activity');

$prefixeTable = 'piwigo_';

require_once PHPWG_ROOT_PATH . 'include/functions_plugins.inc.php';
require_once PHPWG_ROOT_PATH . 'include/ws_core.inc.php';

function set_status_header($code, $message = '')
{
  $GLOBALS['community_test']['status_headers'][] = array($code, $message);
}

function is_admin()
{
  global $user;

  return isset($user['status']) && in_array($user['status'], array('admin', 'webmaster'));
}

function is_a_guest()
{
  return false;
}

function load_language()
{
}

function safe_unserialize($value)
{
  return $value;
}

function l10n($message)
{
  return $message;
}

function get_root_url()
{
  return '/';
}

function conf_update_param($name, $value)
{
  $GLOBALS['conf'][$name] = $value;
}

function generate_key($length)
{
  return str_repeat('k', $length);
}

function calculate_permissions()
{
  return '';
}

function get_subcat_ids($category_ids)
{
  return $category_ids;
}

function array_from_query($query)
{
  $GLOBALS['community_test']['queries'][] = $query;
  return array();
}

function hash_from_query($query)
{
  $GLOBALS['community_test']['queries'][] = $query;
  return array();
}

function query2array($query)
{
  $GLOBALS['community_test']['queries'][] = $query;

  if (!empty($GLOBALS['community_test']['query2array_returns']))
  {
    return array_shift($GLOBALS['community_test']['query2array_returns']);
  }

  if (array_key_exists('query2array_return', $GLOBALS['community_test']))
  {
    return $GLOBALS['community_test']['query2array_return'];
  }

  return array();
}

function pwg_db_real_escape_string($value)
{
  $GLOBALS['community_test']['escaped_values'][] = $value;

  if (!empty($GLOBALS['community_test']['escape_callback']))
  {
    return call_user_func($GLOBALS['community_test']['escape_callback'], $value);
  }

  return addslashes($value);
}

function pwg_query($query)
{
  $GLOBALS['community_test']['queries'][] = $query;

  return $query;
}

function pwg_db_fetch_row($result)
{
  $GLOBALS['community_test']['fetch_row_args'][] = $result;

  if (!empty($GLOBALS['community_test']['fetch_row_returns']))
  {
    return array_shift($GLOBALS['community_test']['fetch_row_returns']);
  }

  if (array_key_exists('fetch_row_return', $GLOBALS['community_test']))
  {
    return $GLOBALS['community_test']['fetch_row_return'];
  }

  return false;
}

function pwg_db_fetch_assoc()
{
  if (!empty($GLOBALS['community_test']['fetch_assoc_return']))
  {
    return array_shift($GLOBALS['community_test']['fetch_assoc_return']);
  }

  return false;
}

function get_pwg_token()
{
  return 'test-token';
}

function make_picture_url($params)
{
  $GLOBALS['community_test']['picture_urls'][] = $params;

  return 'picture-url-' . $params['image_id'];
}

function single_update($table, $update, $where)
{
  $GLOBALS['community_test']['single_updates'][] = array(
    'table' => $table,
    'update' => $update,
    'where' => $where,
  );
}

function add_tags($tag_ids, $image_ids)
{
  $GLOBALS['community_test']['tag_updates'][] = array(
    'tag_ids' => $tag_ids,
    'image_ids' => $image_ids,
  );
}

function sync_metadata($image_ids)
{
  $GLOBALS['community_test']['metadata_sync_calls'][] = $image_ids;
}

function invalidate_user_cache()
{
  $GLOBALS['community_test']['invalidate_user_cache_calls']++;
}

function tag_id_from_tag_name($tag_name)
{
  return strlen($tag_name);
}

function add_uploaded_file($tmp_name, $original_name, $categories, $level, $image_id = null)
{
  $GLOBALS['community_test']['add_uploaded_file_calls'][] = array(
    'tmp_name' => $tmp_name,
    'original_name' => $original_name,
    'categories' => $categories,
    'level' => $level,
    'image_id' => $image_id,
  );

  if (isset($GLOBALS['community_test']['add_uploaded_file_callback']))
  {
    return call_user_func(
      $GLOBALS['community_test']['add_uploaded_file_callback'],
      $tmp_name,
      $original_name,
      $categories,
      $level,
      $image_id
    );
  }

  return 987;
}

function ws_images_addSimple($params, $service)
{
  if (!isset($_FILES['image']))
  {
    return new PwgError(405, 'The image (file) is missing');
  }

  if (isset($_FILES['image']['error']) && $_FILES['image']['error'] != 0)
  {
    return new PwgError(500, 'Upload failed');
  }

  if ($params['image_id'] > 0)
  {
    $query = '
SELECT COUNT(*)
  FROM '. IMAGES_TABLE .'
  WHERE id = '. $params['image_id'] .'
;';
    list($count) = pwg_db_fetch_row(pwg_query($query));
    if ($count == 0)
    {
      return new PwgError(404, 'image_id not found');
    }
  }

  $image_id = add_uploaded_file(
    $_FILES['image']['tmp_name'],
    $_FILES['image']['name'],
    $params['category'],
    8,
    $params['image_id'] > 0 ? $params['image_id'] : null
  );

  $info_columns = array(
    'name',
    'author',
    'comment',
    'level',
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

  single_update(
    IMAGES_TABLE,
    $update,
    array('id' => $image_id)
  );

  if (isset($params['tags']) and !empty($params['tags']))
  {
    $tag_ids = array();
    if (is_array($params['tags']))
    {
      foreach ($params['tags'] as $tag_name)
      {
        $tag_ids[] = tag_id_from_tag_name($tag_name);
      }
    }
    else
    {
      $tag_names = preg_split('~(?<!\\\\),~', $params['tags']);
      foreach ($tag_names as $tag_name)
      {
        $tag_ids[] = tag_id_from_tag_name(preg_replace('#\\\\*,#', ',', $tag_name));
      }
    }

    add_tags($tag_ids, array($image_id));
  }

  $url_params = array('image_id' => $image_id);

  if (!empty($params['category']))
  {
    $query = '
SELECT id, name, permalink
  FROM '. CATEGORIES_TABLE .'
  WHERE id = '. $params['category'][0] .'
;';
    $result = pwg_query($query);
    $category = pwg_db_fetch_assoc($result);

    $url_params['section'] = 'categories';
    $url_params['category'] = $category;
  }

  sync_metadata(array($image_id));

  return array(
    'image_id' => $image_id,
    'url' => make_picture_url($url_params),
  );
}

function ws_images_upload($params, $service)
{
  if (isset($GLOBALS['community_ws_images_upload_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_delegate'], $params, $service);
  }

  return array(
    'image_id' => 456,
    'category' => array('id' => $params['category'][0]),
  );
}

function ws_images_uploadAsync($params, $service)
{
  if (isset($GLOBALS['community_ws_images_upload_async_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_upload_async_delegate'], $params, $service);
  }

  return array(
    'image_id' => !empty($params['image_id']) ? $params['image_id'] : 654,
    'message' => 'chunks uploaded = 1',
  );
}

function community_test_reset_runtime()
{
  global $conf, $user, $community;

  if (session_status() !== PHP_SESSION_ACTIVE)
  {
    session_id('test-session');
    session_start();
  }

  $GLOBALS['community_test'] = array(
    'queries' => array(),
    'escaped_values' => array(),
    'fetch_row_args' => array(),
    'fetch_row_returns' => array(),
    'fetch_assoc_return' => array(),
    'query2array_returns' => array(),
    'status_headers' => array(),
    'is_admin' => false,
    'add_uploaded_file_calls' => array(),
    'single_updates' => array(),
    'tag_updates' => array(),
    'metadata_sync_calls' => array(),
    'picture_urls' => array(),
    'invalidate_user_cache_calls' => 0,
  );

  unset(
    $GLOBALS['community_ws_images_add_delegate'],
    $GLOBALS['community_ws_images_add_chunk_delegate'],
    $GLOBALS['community_ws_images_add_simple_delegate'],
    $GLOBALS['community_ws_images_upload_delegate'],
    $GLOBALS['community_ws_images_upload_async_delegate']
  );

  $_GET = array();
  $_POST = array();
  $_REQUEST = array();
  $_SESSION = array();
  $_FILES = array();

  $conf = array(
    'community' => array('user_albums' => false),
    'community_cache_key' => 'cache-key',
    'available_permission_levels' => array(0, 2, 4, 8, 16),
    'upload_dir' => '/tmp/community-upload-tests',
    'api_key_forbidden_methods' => array(),
    'uniqueness_mode' => 'md5sum',
  );

  $user = array(
    'id' => 2,
    'status' => 'normal',
    'level' => 0,
    'forbidden_categories' => '',
  );

  $community = array();
}

function community_test_register_core_upload_methods($arr)
{
  global $conf;

  $service = &$arr[0];

  $service->addMethod(
    'pwg.images.addChunk',
    'ws_images_add_chunk',
    array(
      'data' => array(),
      'original_sum' => array(),
      'type' => array(
        'default' => 'file',
        'info' => 'Must be "file", for backward compatiblity "high" and "thumb" are allowed.',
      ),
      'position' => array(),
    ),
    'Add a chunk of a file.',
    PHPWG_ROOT_PATH . 'include/ws_functions/pwg.images.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.images.add',
    'ws_images_add',
    array(
      'thumbnail_sum' => array('default' => null),
      'high_sum' => array('default' => null),
      'original_sum' => array(),
      'original_filename' => array(
        'default' => null,
        'Provide it if "check_uniqueness" is true and $conf["uniqueness_mode"] is "filename".'
      ),
      'name' => array('default' => null),
      'author' => array('default' => null),
      'date_creation' => array('default' => null),
      'comment' => array('default' => null),
      'categories' => array(
        'default' => null,
        'info' => 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'
      ),
      'tag_ids' => array(
        'default' => null,
        'info' => 'Comma separated ids'
      ),
      'level' => array(
        'default' => 0,
        'maxValue' => max($conf['available_permission_levels']),
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
      ),
      'check_uniqueness' => array(
        'default' => true,
        'type' => WS_TYPE_BOOL,
      ),
      'image_id' => array(
        'default' => null,
        'type' => WS_TYPE_ID,
      ),
    ),
    "Add an image.\n<br>pwg.images.addChunk must have been called before (maybe several times).\n<br>Don't use \"thumbnail_sum\" and \"high_sum\", these parameters are here for backward compatibility.",
    PHPWG_ROOT_PATH . 'include/ws_functions/pwg.images.php',
    array('admin_only' => true)
  );

  $service->addMethod(
    'pwg.images.addSimple',
    'ws_images_addSimple',
    array(
      'category' => array(
        'default' => null,
        'flags' => WS_PARAM_FORCE_ARRAY,
        'type' => WS_TYPE_ID,
      ),
      'name' => array('default' => null),
      'author' => array('default' => null),
      'comment' => array('default' => null),
      'level' => array(
        'default' => 0,
        'maxValue' => max($conf['available_permission_levels']),
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
      ),
      'tags' => array(
        'default' => null,
        'flags' => WS_PARAM_ACCEPT_ARRAY,
      ),
      'image_id' => array(
        'default' => null,
        'type' => WS_TYPE_ID,
      ),
    ),
    "Add an image.\n<br>Use the <b>_FILES[image]</b> field for uploading file.\n<br>Set the form encoding to \"form-data\".\n<br>You can update an existing photo if you define an existing image_id.",
    PHPWG_ROOT_PATH . 'include/ws_functions/pwg.images.php',
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.images.upload',
    'ws_images_upload',
    array(
      'name' => array('default' => null),
      'category' => array(
        'default' => null,
        'flags' => WS_PARAM_FORCE_ARRAY,
        'type' => WS_TYPE_ID,
      ),
      'level' => array(
        'default' => 0,
        'maxValue' => max($conf['available_permission_levels']),
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
      ),
      'format_of' => array(
        'default' => null,
        'type' => WS_TYPE_ID,
        'info' => 'id of the extended image (name/category/level are not used if format_of is provided)',
      ),
      'update_mode' => array(
        'default' => false,
        'type' => WS_TYPE_BOOL,
        'info' => 'true if the update mode is active',
      ),
      'pwg_token' => array(),
    ),
    "Add an image.\n<br>Use the <b>\$_FILES[image]</b> field for uploading file.\n<br>Set the form encoding to \"form-data\".",
    null,
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.images.uploadAsync',
    'ws_images_uploadAsync',
    array(
      'chunk' => array('type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'chunk_sum' => array(),
      'chunks' => array('type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'original_sum' => array(),
      'category' => array(
        'default' => null,
        'flags' => WS_PARAM_FORCE_ARRAY,
        'type' => WS_TYPE_ID,
      ),
      'filename' => array(),
      'name' => array('default' => null),
      'author' => array('default' => null),
      'comment' => array('default' => null),
      'date_creation' => array('default' => null),
      'level' => array(
        'default' => 0,
        'maxValue' => max($conf['available_permission_levels']),
        'type' => WS_TYPE_INT | WS_TYPE_POSITIVE,
      ),
      'tag_ids' => array(
        'default' => null,
        'info' => 'Comma separated ids',
      ),
      'image_id' => array(
        'default' => null,
        'type' => WS_TYPE_ID,
      ),
    ),
    "Add a chunk of an image and merge it when all chunks are uploaded.",
    null,
    array('admin_only' => true, 'post_only' => true)
  );
}

function community_test_get_registered_method($service, $method_name)
{
  $reflection = new ReflectionObject($service);
  $methods_property = $reflection->getProperty('_methods');
  $methods_property->setAccessible(true);
  $methods = $methods_property->getValue($service);

  return $methods[$method_name];
}

function community_test_build_service($method_name, $request = array(), $is_admin = false)
{
  global $pwg_event_handlers, $user;

  $files = $_FILES;

  community_test_reset_runtime();

  $_FILES = $files;

  $GLOBALS['community_test']['is_admin'] = $is_admin;
  $user['id'] = $is_admin ? 1 : 2;
  $user['status'] = $is_admin ? 'admin' : 'normal';

  $_SESSION['community_user_id'] = $user['id'];
  $_SESSION['community_cache_key'] = 'cache-key';
  $_SESSION['community_user_permissions'] = array(
    'upload_categories' => array(1),
    'create_categories' => array(),
    'create_whole_gallery' => false,
    'permission_ids' => array(11),
    'user_album' => false,
  );

  $_REQUEST = array_merge(array('method' => $method_name), $request);
  $_POST = array('_community_test_post' => '1');

  $pwg_event_handlers['ws_add_methods'] = array();
  add_event_handler('ws_add_methods', 'community_test_register_core_upload_methods');
  add_event_handler('ws_add_methods', 'community_switch_user_to_admin', EVENT_HANDLER_PRIORITY_NEUTRAL + 5);
  add_event_handler('ws_add_methods', 'community_add_methods', EVENT_HANDLER_PRIORITY_NEUTRAL + 5);
  add_event_handler('ws_add_methods', 'community_ws_replace_methods', EVENT_HANDLER_PRIORITY_NEUTRAL + 5);

  $service = new PwgServer();
  trigger_notify('ws_add_methods', array(&$service));

  return $service;
}

require_once dirname(__DIR__) . '/main.inc.php';