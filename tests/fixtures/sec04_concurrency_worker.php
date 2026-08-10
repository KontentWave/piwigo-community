<?php

if ($argc < 8)
{
  fwrite(STDERR, "usage: worker table_prefix photo_limit byte_limit photos bytes ready_file release_file\n");
  exit(2);
}

$table_prefix = $argv[1];
$photo_limit = (int) $argv[2];
$byte_limit = (int) $argv[3];
$target_photos = (int) $argv[4];
$target_bytes = (int) $argv[5];
$ready_file = $argv[6];
$release_file = $argv[7];

define('PHPWG_ROOT_PATH', dirname(__DIR__, 4).'/');
include PHPWG_ROOT_PATH.'local/config/database.inc.php';

mysqli_report(MYSQLI_REPORT_OFF);
$socket = str_starts_with($conf['db_host'], '/') ? $conf['db_host'] : null;
$host = isset($socket) ? 'localhost' : $conf['db_host'];
$mysqli = new mysqli($host, $conf['db_user'], $conf['db_password'], $conf['db_base'], 0, $socket);
if ($mysqli->connect_errno)
{
  fwrite(STDERR, "connection-failed\n");
  exit(3);
}

define('COMMUNITY_QUOTA_LOCKS_TABLE', $table_prefix.'_locks');
define('COMMUNITY_QUOTA_RESERVATIONS_TABLE', $table_prefix.'_reservations');
define('IMAGES_TABLE', $table_prefix.'_images');
define('IMAGE_FORMAT_TABLE', $table_prefix.'_formats');

class PwgError
{
  private $code;

  public function __construct($code, $message)
  {
    $this->code = $code;
  }

  public function code()
  {
    return $this->code;
  }
}

function l10n($message)
{
  return $message;
}

function pwg_query($query)
{
  global $mysqli;

  $result = $mysqli->query($query);
  if (false === $result)
  {
    throw new RuntimeException($mysqli->error, $mysqli->errno);
  }

  return $result;
}

function pwg_db_fetch_assoc($result)
{
  return $result instanceof mysqli_result ? $result->fetch_assoc() : false;
}

function pwg_db_real_escape_string($value)
{
  global $mysqli;

  return $mysqli->real_escape_string($value);
}

function community_get_user_permissions($user_id, $use_cache = true)
{
  global $photo_limit, $byte_limit;

  return array(
    'nb_photos' => $photo_limit,
    'storage' => intdiv($byte_limit, 1024 * 1024),
  );
}

$user = array('id' => 2);
require dirname(__DIR__, 2).'/include/quota_reservation.inc.php';

if ('-' !== $ready_file)
{
  $GLOBALS['community_quota_after_user_lock_callback'] = function () use ($ready_file, $release_file) {
    file_put_contents($ready_file, 'locked');
    $deadline = microtime(true) + 10;
    while (!is_file($release_file) && microtime(true) < $deadline)
    {
      usleep(10000);
    }

    if (!is_file($release_file))
    {
      throw new RuntimeException('barrier-timeout');
    }
  };
}

$result = community_quota_reserve(
  'concurrency',
  'upload-'.basename($ready_file),
  hash('sha256', $ready_file),
  $target_photos,
  $target_bytes,
  60
);

echo json_encode(array(
  'accepted' => is_array($result),
  'code' => $result instanceof PwgError ? $result->code() : null,
));

?>