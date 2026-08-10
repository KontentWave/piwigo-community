<?php

if (2 !== $argc)
{
  fwrite(STDERR, "usage: worker table_prefix\n");
  exit(2);
}

$table_prefix = $argv[1].'_';
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

$prefixeTable = $table_prefix;
define('CATEGORIES_TABLE', $table_prefix.'categories');
define('CONFIG_TABLE', $table_prefix.'config');

class PluginMaintain
{
  protected $plugin_id;

  public function __construct($plugin_id)
  {
    $this->plugin_id = $plugin_id;
  }
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

function pwg_db_num_rows($result)
{
  return $result instanceof mysqli_result ? $result->num_rows : 0;
}

function pwg_db_fetch_assoc($result)
{
  return $result instanceof mysqli_result ? $result->fetch_assoc() : false;
}

function pwg_db_fetch_row($result)
{
  return $result instanceof mysqli_result ? $result->fetch_row() : false;
}

function single_insert($table, $data, $options = array())
{
  global $mysqli;

  $columns = array();
  $values = array();
  foreach ($data as $column => $value)
  {
    $columns[] = '`'.trim($column, '`').'`';
    $values[] = isset($value) ? "'".$mysqli->real_escape_string((string) $value)."'" : 'NULL';
  }

  pwg_query('INSERT INTO `'.$table.'` ('.implode(',', $columns).') VALUES ('.implode(',', $values).')');
}

function conf_update_param($param, $value, $update_global = false)
{
  global $conf, $mysqli;

  $stored = is_array($value) ? serialize($value) : (string) $value;
  pwg_query("INSERT INTO `".CONFIG_TABLE."` (param, value) VALUES ('".$mysqli->real_escape_string($param)."', '".$mysqli->real_escape_string($stored)."') ON DUPLICATE KEY UPDATE value = VALUES(value)");
  if ($update_global)
  {
    $conf[$param] = $value;
  }
}

function table_exists($table)
{
  global $mysqli;

  $result = $mysqli->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".$mysqli->real_escape_string($table)."'");
  return $result instanceof mysqli_result && 1 === $result->num_rows;
}

require dirname(__DIR__, 2).'/maintain.class.php';

$tables = array(
  $table_prefix.'community_quota_reservations',
  $table_prefix.'community_quota_locks',
  $table_prefix.'community_permissions',
  $table_prefix.'community_pendings',
  CATEGORIES_TABLE,
  CONFIG_TABLE,
);

try
{
  pwg_query('CREATE TABLE `'.CATEGORIES_TABLE.'` (`id` smallint unsigned NOT NULL AUTO_INCREMENT, `name` varchar(255) NOT NULL, `community_user` mediumint unsigned DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB');
  pwg_query('CREATE TABLE `'.CONFIG_TABLE.'` (`param` varchar(255) NOT NULL, `value` text, PRIMARY KEY (`param`)) ENGINE=InnoDB');

  $errors = array();
  $maintain = new community_maintain('community');
  $maintain->install('test', $errors);

  pwg_query("INSERT INTO `{$table_prefix}community_permissions` (`type`, `user_id`, `category_id`, `nb_photos`, `storage`) VALUES ('user', 2, 1, 5, 10)");
  pwg_query("INSERT INTO `{$table_prefix}community_quota_locks` (`user_id`, `updated_at`) VALUES (2, NOW())");
  pwg_query("INSERT INTO `{$table_prefix}community_quota_reservations` (`reservation_id`, `user_id`, `transport`, `logical_upload_id`, `request_identity`, `reserved_photos`, `reserved_bytes`, `created_at`, `refreshed_at`, `expires_at`) VALUES ('0123456789abcdef0123456789abcdef', 2, 'async', 'upload', 'request', 1, 1234, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");

  $maintain->update('old', 'new', $errors);
  $maintain->install('new', $errors);

  $permission_count = (int) pwg_db_fetch_row(pwg_query("SELECT COUNT(*) FROM `{$table_prefix}community_permissions` WHERE user_id = 2 AND nb_photos = 5 AND storage = 10"))[0];
  $reservation_count = (int) pwg_db_fetch_row(pwg_query("SELECT COUNT(*) FROM `{$table_prefix}community_quota_reservations` WHERE reservation_id = '0123456789abcdef0123456789abcdef' AND reserved_bytes = 1234"))[0];
  $engines = array();
  $engine_result = pwg_query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('{$table_prefix}community_quota_locks', '{$table_prefix}community_quota_reservations') ORDER BY TABLE_NAME");
  while ($row = pwg_db_fetch_assoc($engine_result))
  {
    $engines[$row['TABLE_NAME']] = $row['ENGINE'];
  }
  $index_names = array();
  $index_result = pwg_query("SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table_prefix}community_quota_reservations'");
  while ($row = pwg_db_fetch_assoc($index_result))
  {
    $index_names[] = $row['INDEX_NAME'];
  }

  $maintain->uninstall();

  echo json_encode(array(
    'errors' => $errors,
    'permission_count' => $permission_count,
    'reservation_count' => $reservation_count,
    'engines' => $engines,
    'indexes' => $index_names,
    'quota_tables_removed' => !table_exists($table_prefix.'community_quota_locks') && !table_exists($table_prefix.'community_quota_reservations'),
  ));
}
catch (Throwable $error)
{
  fwrite(STDERR, $error->getMessage()."\n");
  exit(4);
}
finally
{
  foreach ($tables as $table)
  {
    $mysqli->query('DROP TABLE IF EXISTS `'.$table.'`');
  }
  $mysqli->close();
}

?>
