<?php

$input = json_decode($argv[1] ?? '{}', true);
if (!is_array($input))
{
  fwrite(STDERR, "invalid input\n");
  exit(2);
}

$root = sys_get_temp_dir().'/community-sec02-root/';
@mkdir($root.'admin/include', 0777, true);
@mkdir($root.'include', 0777, true);
foreach (array('admin/include/functions.php', 'admin/include/tabsheet.class.php', 'include/functions_picture.inc.php') as $stub)
{
  if (!file_exists($root.$stub))
  {
    file_put_contents($root.$stub, "<?php\n");
  }
}

define('PHPWG_ROOT_PATH', $root);
define('COMMUNITY_PATH', dirname(__DIR__, 2).'/');
define('COMMUNITY_BASE_URL', '/admin.php?page=plugin-community');
define('ACCESS_ADMINISTRATOR', 'admin');
define('PATTERN_ID', '/^\d+$/');
define('COMMUNITY_PERMISSIONS_TABLE', 'community_permissions');
define('COMMUNITY_PENDINGS_TABLE', 'community_pendings');
define('CATEGORIES_TABLE', 'categories');
define('IMAGES_TABLE', 'images');
define('IMAGE_CATEGORY_TABLE', 'image_category');
define('USERS_TABLE', 'users');
define('USER_INFOS_TABLE', 'user_infos');
define('GROUPS_TABLE', 'groups');
define('IMG_THUMB', 'thumb');
define('IMG_MEDIUM', 'medium');

class CommunitySec02Stop extends RuntimeException
{
}

class CommunitySec02Template
{
  public function set_filenames($value)
  {
  }

  public function set_filename($name, $value)
  {
  }

  public function assign($name, $value = null)
  {
    if (is_array($name))
    {
      $GLOBALS['sec02']['assignments'] = array_merge($GLOBALS['sec02']['assignments'], $name);
      return;
    }

    $GLOBALS['sec02']['assignments'][$name] = $value;
  }

  public function append($name, $value)
  {
    $GLOBALS['sec02']['appends'][$name][] = $value;
  }

  public function assign_var_from_handle($name, $handle)
  {
  }
}

class tabsheet
{
  public function set_id($value)
  {
  }

  public function select($value)
  {
  }

  public function assign()
  {
  }
}

class SrcImage
{
  public function __construct($row)
  {
  }
}

class DerivativeImage
{
  public static function url($type, $image)
  {
    return '/derivative.jpg';
  }
}

function load_language()
{
}

function check_status($status)
{
  $GLOBALS['sec02']['events'][] = 'check_status';
}

function check_pwg_token()
{
  $GLOBALS['sec02']['events'][] = 'check_token';
  $token = $_POST['pwg_token'] ?? null;
  if (!is_string($token) || 'current-token' !== $token)
  {
    throw new CommunitySec02Stop('invalid token');
  }
}

function get_pwg_token()
{
  return 'current-token';
}

function check_input_parameter($name, $source, $is_array, $pattern)
{
  $GLOBALS['sec02']['events'][] = 'check_input:'.$name;
  if (!array_key_exists($name, $source))
  {
    throw new CommunitySec02Stop('missing parameter '.$name);
  }

  $values = $is_array ? $source[$name] : array($source[$name]);
  if ($is_array && !is_array($values))
  {
    throw new CommunitySec02Stop('invalid parameter '.$name);
  }

  foreach ($values as $value)
  {
    if (!is_scalar($value) || !preg_match($pattern, (string) $value))
    {
      throw new CommunitySec02Stop('invalid parameter '.$name);
    }
  }
}

function l10n($message)
{
  $args = func_get_args();
  return count($args) > 1 ? vsprintf($message, array_slice($args, 1)) : $message;
}

function get_root_url()
{
  return '/';
}

function boolean_to_string($value)
{
  return $value ? 'true' : 'false';
}

function get_boolean($value)
{
  return in_array($value, array(true, 1, '1', 'true'), true);
}

function pwg_query($query)
{
  $GLOBALS['sec02']['queries'][] = $query;
  if (preg_match('/^\s*(DELETE|INSERT|UPDATE)\b/i', $query))
  {
    $GLOBALS['sec02']['mutation_queries'][] = $query;
  }
  return $query;
}

function pwg_db_fetch_assoc($result = null)
{
  if (!empty($GLOBALS['sec02']['fetch_assoc']))
  {
    return array_shift($GLOBALS['sec02']['fetch_assoc']);
  }
  return false;
}

function pwg_db_insert_id($table)
{
  return 101;
}

function mass_inserts($table, $columns, $rows)
{
  $GLOBALS['sec02']['mass_inserts'][] = compact('table', 'columns', 'rows');
}

function mass_updates($table, $options, $rows)
{
  $GLOBALS['sec02']['mass_updates'][] = compact('table', 'options', 'rows');
}

function single_update($table, $update, $where)
{
  $GLOBALS['sec02']['single_updates'][] = compact('table', 'update', 'where');
}

function conf_update_param($name, $value)
{
  $GLOBALS['sec02']['config_updates'][] = compact('name', 'value');
}

function community_update_cache_key()
{
  $GLOBALS['sec02']['community_cache_updates']++;
}

function invalidate_user_cache()
{
  $GLOBALS['sec02']['user_cache_updates']++;
}

function delete_elements($ids, $physical)
{
  $GLOBALS['sec02']['delete_elements'][] = compact('ids', 'physical');
}

function redirect($url)
{
  $GLOBALS['sec02']['redirects'][] = $url;
  throw new CommunitySec02Stop('redirect');
}

function display_select_cat_wrapper()
{
}

function get_cat_display_name_cache($value)
{
  return (string) $value;
}

function query2array($query, $key = null, $value = null)
{
  pwg_query($query);
  return array();
}

function array_from_query($query, $column)
{
  pwg_query($query);
  return array();
}

function get_privacy_level_options()
{
  return array(0 => 'Everybody');
}

function format_date($value, $show_time = false)
{
  return $value;
}

$GLOBALS['sec02'] = array(
  'events' => array(),
  'queries' => array(),
  'mutation_queries' => array(),
  'mass_inserts' => array(),
  'mass_updates' => array(),
  'single_updates' => array(),
  'config_updates' => array(),
  'community_cache_updates' => 0,
  'user_cache_updates' => 0,
  'delete_elements' => array(),
  'redirects' => array(),
  'assignments' => array(),
  'appends' => array(),
  'fetch_assoc' => $input['fetch_assoc'] ?? array(),
  'exception' => null,
);

$_SERVER['REQUEST_METHOD'] = $input['method'] ?? 'GET';
$_GET = $input['get'] ?? array();
$_POST = $input['post'] ?? array();
$_REQUEST = array_merge($_GET, $_POST);
$_SESSION = array();

$template = new CommunitySec02Template();
$page = array('infos' => array(), 'errors' => array(), 'tab' => 'permissions');
$user = array('id' => 1, 'status' => 'admin');
$conf = array(
  'community' => array('user_albums' => true, 'user_albums_parent' => 0),
  'user_fields' => array('id' => 'id', 'username' => 'username'),
);

$controllers = array(
  'permissions' => 'admin_permissions.php',
  'config' => 'admin_config.php',
  'album' => 'admin_album.php',
  'pendings' => 'admin_pendings.php',
);

try
{
  $controller = $input['controller'] ?? '';
  if (!isset($controllers[$controller]))
  {
    throw new RuntimeException('unknown controller');
  }
  include dirname(__DIR__, 2).'/'.$controllers[$controller];
}
catch (Throwable $exception)
{
  $GLOBALS['sec02']['exception'] = $exception->getMessage();
}

$GLOBALS['sec02']['page'] = $page;
echo json_encode($GLOBALS['sec02'], JSON_UNESCAPED_SLASHES);