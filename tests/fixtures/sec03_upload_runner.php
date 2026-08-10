<?php

$input = json_decode($argv[1] ?? '{}', true);
if (!is_array($input))
{
  fwrite(STDERR, "invalid input\n");
  exit(2);
}

$uploadRoot = sys_get_temp_dir().'/community-sec03-'.bin2hex(random_bytes(6));
mkdir($uploadRoot, 0777, true);

define('PHPWG_ROOT_PATH', dirname(__DIR__, 4).'/');
define('COMMUNITY_PATH', dirname(__DIR__, 2).'/');
define('IMAGES_TABLE', 'images');
define('IMAGE_CATEGORY_TABLE', 'image_category');
define('PHOTOS_ADD_BASE_URL', '/index.php?/add_photos');

class PwgError
{
  public function __construct(public $code, public $message)
  {
  }
}

class DerivativeImage
{
  public static function thumb_url($image)
  {
    return '/thumb/'.$image['file'];
  }
}

function l10n($message)
{
  $args = func_get_args();
  return count($args) > 1 ? vsprintf($message, array_slice($args, 1)) : $message;
}

function get_moment()
{
  return microtime(true);
}

function is_valid_image_extension($extension)
{
  return in_array(strtolower($extension), array('jpg', 'jpeg', 'png', 'gif', 'webp'), true);
}

function prepare_directory($path)
{
  $GLOBALS['sec03']['prepare_directory'][] = $path;
  if (!is_dir($path))
  {
    mkdir($path, 0777, true);
  }
}

function generate_key($length)
{
  return str_repeat('a', $length);
}

function add_uploaded_file($source, $filename, $categories, $level)
{
  $GLOBALS['sec03']['add_uploaded_file'][] = array($source, $filename, $categories, $level);
  $GLOBALS['sec03']['database_writes'][] = 'image';
  $GLOBALS['sec03']['hooks'][] = 'upload';
  $GLOBALS['sec03']['moderation_records'][] = 101;
  return 101;
}

function file_upload_error_message($error)
{
  return 'upload error '.$error;
}

function pwg_query($query)
{
  $GLOBALS['sec03']['queries'][] = $query;
  return $query;
}

function pwg_db_fetch_assoc($result)
{
  return array('id' => 101, 'file' => 'photo.jpg', 'path' => '/photo.jpg');
}

function pwg_db_fetch_row($result)
{
  return array(1);
}

function get_name_from_file($filename)
{
  return pathinfo($filename, PATHINFO_FILENAME);
}

function get_root_url()
{
  return '/';
}

function get_cat_display_name_from_id($categoryId, $url)
{
  return 'Album '.$categoryId;
}

function sec03_remove_path($path)
{
  if (is_dir($path) && !is_link($path))
  {
    foreach (scandir($path) as $entry)
    {
      if ('.' !== $entry && '..' !== $entry)
      {
        sec03_remove_path($path.'/'.$entry);
      }
    }
    return rmdir($path);
  }

  return !file_exists($path) || unlink($path);
}

$GLOBALS['sec03'] = array(
  'prepare_directory' => array(),
  'add_uploaded_file' => array(),
  'database_writes' => array(),
  'queries' => array(),
  'hooks' => array(),
  'moderation_records' => array(),
  'quota_reservations' => array(),
);

include COMMUNITY_PATH.'include/quota_reservation.inc.php';

$GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use ($input) {
  $GLOBALS['sec03']['quota_reservations'][] = compact('transport', 'logicalUploadId', 'requestIdentity', 'photos', 'bytes');
  if (!empty($input['quota_denied']))
  {
    return community_quota_error();
  }

  return array('reservation_id' => 'fixture', 'reserved_photos' => $photos, 'reserved_bytes' => $bytes);
};
$GLOBALS['community_quota_transport_release_callback'] = fn () => true;
$GLOBALS['community_quota_transport_settle_callback'] = fn () => true;

$conf = array(
  'upload_dir' => $uploadRoot,
  'picture_ext' => array('jpg', 'jpeg', 'png', 'webp'),
  'file_ext' => array('jpg', 'jpeg', 'png', 'webp', 'zip', 'pdf'),
  'upload_form_all_types' => true,
);
$user = array('id' => 7, 'status' => 'normal', 'unchanged' => true);
$_GET = array('processed' => '1');
$_POST = array('submit_upload' => '1', 'category' => '3', 'level' => 16, 'unchanged' => true);
$_SESSION = array('unchanged' => true);
$page = array('errors' => array(), 'infos' => array());

$names = $input['names'] ?? array();
$errors = $input['errors'] ?? array_fill(0, count($names), UPLOAD_ERR_OK);
$tempFiles = array();
foreach ($names as $index => $name)
{
  $tempFile = $uploadRoot.'/request-'.$index.'.tmp';
  file_put_contents($tempFile, $input['contents'][$index] ?? 'payload');
  $tempFiles[] = $tempFile;
}

$_FILES = array(
  'image_upload' => array(
    'name' => $names,
    'tmp_name' => $tempFiles,
    'error' => $errors,
  ),
);

$before = array('conf' => $conf, 'user' => $user, 'post' => $_POST, 'session' => $_SESSION);
include COMMUNITY_PATH.'include/photos_add_direct_process.inc.php';

$artifacts = array();
$iterator = new RecursiveIteratorIterator(
  new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS),
  RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $entry)
{
  $path = $entry->getPathname();
  if (!in_array($path, $tempFiles, true))
  {
    $artifacts[] = substr($path, strlen($uploadRoot) + 1);
  }
}

$result = array(
  'errors' => $page['errors'],
  'side_effects' => $GLOBALS['sec03'],
  'artifacts' => $artifacts,
  'image_ids' => $image_ids ?? array(),
  'thumbnails' => $page['thumbnails'] ?? array(),
  'state_unchanged' => $before === array('conf' => $conf, 'user' => $user, 'post' => $_POST, 'session' => $_SESSION),
);

sec03_remove_path($uploadRoot);
echo json_encode($result);