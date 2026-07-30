<?php

define('PHPWG_ROOT_PATH', dirname(__DIR__, 3) . '/');
define('IN_WS', true);
define('IMAGES_TABLE', 'piwigo_images');

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

  if (array_key_exists('fetch_row_return', $GLOBALS['community_test']))
  {
    return $GLOBALS['community_test']['fetch_row_return'];
  }

  return false;
}

function pwg_db_fetch_assoc()
{
  return false;
}

function community_test_reset_runtime()
{
  global $conf, $user, $community;

  $GLOBALS['community_test'] = array(
    'queries' => array(),
    'escaped_values' => array(),
    'fetch_row_args' => array(),
    'status_headers' => array(),
    'is_admin' => false,
  );

  unset(
    $GLOBALS['community_ws_images_add_delegate'],
    $GLOBALS['community_ws_images_add_chunk_delegate']
  );

  $_GET = array();
  $_POST = array();
  $_REQUEST = array();
  $_SESSION = array();

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

  community_test_reset_runtime();

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