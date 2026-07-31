<?php

define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');
define('IN_WS', true);
define('CATEGORIES_TABLE', 'piwigo_categories');
define('IMAGE_CATEGORY_TABLE', 'piwigo_image_category');
define('IMAGES_TABLE', 'piwigo_images');
define('ACTIVITY_TABLE', 'piwigo_activity');
define('LOUNGE_TABLE', 'piwigo_lounge');
define('TAGS_TABLE', 'piwigo_tags');

if (!defined('MKGETDIR_DEFAULT'))
{
  define('MKGETDIR_DEFAULT', 0);
}

if (!defined('MKGETDIR_DIE_ON_ERROR'))
{
  define('MKGETDIR_DIE_ON_ERROR', 0);
}

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

function get_l10n_args($key, $args = null)
{
  return array('key' => $key, 'args' => $args);
}

function get_root_url()
{
  return '/';
}

function get_absolute_root_url()
{
  return 'https://example.test/';
}

function get_cat_info($category_id)
{
  if (isset($GLOBALS['community_test']['category_info']))
  {
    return $GLOBALS['community_test']['category_info'];
  }

  return array('id' => (int) $category_id, 'upper_names' => array('Album '.$category_id));
}

function get_cat_display_name($upper_names, $url = null, $single_link = false)
{
  return implode(' / ', $upper_names);
}

function get_cat_display_name_from_id($category_id, $url = null)
{
  return 'Album '.$category_id;
}

function empty_lounge()
{
  $GLOBALS['community_test']['empty_lounge_calls']++;
}

class CommunityTestLogger
{
  public function debug($message, $channel = null)
  {
    $GLOBALS['community_test']['debug_logs'][] = array(
      'message' => $message,
      'channel' => $channel,
    );
  }
}

function pwg_db_fetch_row($result = null)
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

function pwg_query($query)
{
  $GLOBALS['community_test']['queries'][] = $query;

  if (isset($GLOBALS['community_test']['query_callback']))
  {
    return call_user_func($GLOBALS['community_test']['query_callback'], $query);
  }

  return $query;
}

function pwg_db_real_escape_string($value)
{
  $GLOBALS['community_test']['escaped_values'][] = $value;

  if (isset($GLOBALS['community_test']['escape_callback']))
  {
    return call_user_func($GLOBALS['community_test']['escape_callback'], $value);
  }

  return addslashes($value);
}

function pwg_db_num_rows($result)
{
  if (!empty($GLOBALS['community_test']['num_rows_returns']))
  {
    return array_shift($GLOBALS['community_test']['num_rows_returns']);
  }

  if (array_key_exists('num_rows_return', $GLOBALS['community_test']))
  {
    return $GLOBALS['community_test']['num_rows_return'];
  }

  return 0;
}

function query2array($query, $key_field = null, $value_field = null)
{
  $GLOBALS['community_test']['queries'][] = $query;

  if (isset($GLOBALS['community_test']['query2array_callback']))
  {
    return call_user_func($GLOBALS['community_test']['query2array_callback'], $query, $key_field, $value_field);
  }

  if (!empty($GLOBALS['community_test']['query2array_returns']))
  {
    return array_shift($GLOBALS['community_test']['query2array_returns']);
  }

  if (false !== strpos($query, 'FROM '.CATEGORIES_TABLE) && preg_match('/IN \(([^)]+)\)/', $query, $matches))
  {
    $ids = preg_split('/\s*,\s*/', trim($matches[1]));

    if (null === $key_field && 'id' === $value_field)
    {
      return $ids;
    }
  }

  return array();
}

function mass_inserts($table, $columns, $rows)
{
  $GLOBALS['community_test']['mass_inserts'][] = array(
    'table' => $table,
    'columns' => $columns,
    'rows' => $rows,
  );
}

function mkgetdir($directory, $flags = 0)
{
  return is_dir($directory) || @mkdir($directory, 0777, true);
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

function community_test_ws_images_upload($params, $service)
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

function community_test_ws_images_uploadAsync($params, $service)
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

function community_test_ws_images_setInfo($params, $service)
{
  if (isset($GLOBALS['community_ws_images_set_info_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_set_info_delegate'], $params, $service);
  }

  return null;
}

function community_test_ws_images_delete($params, $service)
{
  if (isset($GLOBALS['community_ws_images_delete_delegate']))
  {
    return call_user_func($GLOBALS['community_ws_images_delete_delegate'], $params, $service);
  }

  return count($params['image_id']);
}

function community_test_ws_images_addSimple($params, $service)
{
  if (!empty($params['image_id']))
  {
    pwg_query('SELECT id FROM '.IMAGES_TABLE.' WHERE id = '.(int) $params['image_id'].';');
  }

  $image_id = community_call_add_uploaded_file(
    $_FILES['image']['tmp_name'],
    $_FILES['image']['name'],
    $params['category'],
    8,
    $params['image_id'] > 0 ? $params['image_id'] : null
  );

  $update = array();
  foreach (array('name', 'author', 'comment', 'level', 'date_creation') as $key)
  {
    if (isset($params[$key]))
    {
      $update[$key] = $params[$key];
    }
  }

  single_update(IMAGES_TABLE, $update, array('id' => $image_id));

  $url_params = array('image_id' => $image_id);
  if (!empty($params['category']))
  {
    $result = pwg_query('SELECT id, name, permalink FROM '.CATEGORIES_TABLE.' WHERE id = '.(int) $params['category'][0].';');
    $url_params['section'] = 'categories';
    $url_params['category'] = pwg_db_fetch_assoc($result);
  }

  return array(
    'image_id' => $image_id,
    'url' => make_picture_url($url_params),
  );
}

function community_test_create_uploaded_chunk($contents, $filename = 'chunk.bin')
{
  $temp_path = tempnam(sys_get_temp_dir(), 'community-upload-async-');
  file_put_contents($temp_path, $contents);
  $size = filesize($temp_path);

  if (!isset($GLOBALS['community_test']['temporary_files']))
  {
    $GLOBALS['community_test']['temporary_files'] = array();
  }

  $GLOBALS['community_test']['temporary_files'][] = $temp_path;

  return array(
    'tmp_name' => $temp_path,
    'name' => $filename,
    'size' => false === $size ? strlen($contents) : $size,
    'error' => 0,
    'type' => 'application/octet-stream',
  );
}

function community_test_delete_path($path)
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

        community_test_delete_path($path.'/'.$entry);
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

function community_test_reset_runtime()
{
  global $conf, $user, $community, $logger;

  if (!empty($GLOBALS['community_test']['temporary_files']))
  {
    foreach ($GLOBALS['community_test']['temporary_files'] as $temporary_file)
    {
      if (is_string($temporary_file) && file_exists($temporary_file))
      {
        @unlink($temporary_file);
      }
    }
  }

  community_test_delete_path('/tmp/community-upload-tests/buffer/community-upload-async');
  community_test_delete_path('/tmp/community-upload-tests/buffer/community-legacy-add');
  community_test_delete_path('/tmp/community-upload-tests/buffer/community-upload-completion');

  if (session_status() !== PHP_SESSION_ACTIVE)
  {
    session_id('test-session');
    session_start();
  }

  $GLOBALS['community_test'] = array(
    'queries' => array(),
    'escaped_values' => array(),
    'debug_logs' => array(),
    'fetch_row_args' => array(),
    'fetch_row_returns' => array(),
    'fetch_assoc_return' => array(),
    'query2array_returns' => array(),
    'num_rows_returns' => array(),
    'status_headers' => array(),
    'is_admin' => false,
    'add_uploaded_file_calls' => array(),
    'single_updates' => array(),
    'tag_updates' => array(),
    'set_tag_calls' => array(),
    'mass_inserts' => array(),
    'update_category_calls' => array(),
    'metadata_sync_calls' => array(),
    'picture_urls' => array(),
    'invalidate_user_cache_calls' => 0,
    'associate_images_to_categories_calls' => array(),
    'trigger_notify_calls' => array(),
    'mail_notification_calls' => array(),
    'empty_lounge_calls' => 0,
    'temporary_files' => array(),
  );

  unset(
    $GLOBALS['community_ws_images_add_delegate'],
    $GLOBALS['community_ws_images_add_chunk_delegate'],
    $GLOBALS['community_ws_images_add_simple_delegate'],
    $GLOBALS['community_ws_images_upload_delegate'],
    $GLOBALS['community_ws_images_upload_async_delegate'],
    $GLOBALS['community_ws_images_set_info_delegate'],
    $GLOBALS['community_ws_images_delete_delegate'],
    $GLOBALS['community_test_write_json_file_callback']
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
    'username' => 'contributor',
    'email' => 'contributor@example.test',
    'level' => 0,
    'forbidden_categories' => '',
  );

  $community = array();
  $logger = new CommunityTestLogger();
}

function community_test_register_core_upload_methods($arr)
{
  global $conf;

  $service = &$arr[0];

  $service->addMethod(
    'pwg.images.delete',
    'community_test_ws_images_delete',
    array(
      'image_id' => array('flags' => WS_PARAM_ACCEPT_ARRAY),
      'pwg_token' => array(),
    ),
    'Deletes image(s).',
    null,
    array('admin_only' => true, 'post_only' => true)
  );

  $service->addMethod(
    'pwg.images.setInfo',
    'community_test_ws_images_setInfo',
    array(
      'image_id' => array('type' => WS_TYPE_ID),
      'file' => array('default' => null),
      'name' => array('default' => null),
      'author' => array('default' => null),
      'date_creation' => array('default' => null),
      'comment' => array('default' => null),
      'categories' => array('default' => null, 'info' => 'String list "category_id[,rank];category_id[,rank]".<br>The rank is optional and is equivalent to "auto" if not given.'),
      'tag_ids' => array('default' => null, 'info' => 'Comma separated ids'),
      'level' => array('default' => null, 'maxValue' => max($conf['available_permission_levels']), 'type' => WS_TYPE_INT | WS_TYPE_POSITIVE),
      'single_value_mode' => array('default' => 'fill_if_empty'),
      'multiple_value_mode' => array('default' => 'append'),
      'pwg_token' => array('flags' => WS_PARAM_OPTIONAL),
    ),
    'Changes properties of an image.',
    null,
    array('admin_only' => true, 'post_only' => true)
  );

  $GLOBALS['community_test']['core_mutation_methods'] = array(
    'pwg.images.delete' => community_test_get_registered_method($service, 'pwg.images.delete'),
    'pwg.images.setInfo' => community_test_get_registered_method($service, 'pwg.images.setInfo'),
  );

  $service->addMethod(
    'pwg.images.uploadCompleted',
    'ws_images_uploadCompleted',
    array(
      'image_id' => array('default' => null, 'flags' => WS_PARAM_ACCEPT_ARRAY),
      'pwg_token' => array(),
      'category_id' => array('type' => WS_TYPE_ID),
    ),
    'Notify Piwigo you have finished uploading a set of photos.',
    PHPWG_ROOT_PATH . 'include/ws_functions/pwg.images.php',
    array('admin_only' => true)
  );

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
    'community_test_ws_images_upload',
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
    'community_test_ws_images_uploadAsync',
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
  $GLOBALS['community_ws_images_add_simple_delegate'] = 'community_test_ws_images_addSimple';
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
  $_POST = array_merge(array('_community_test_post' => '1'), $request);

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