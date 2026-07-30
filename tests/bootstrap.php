<?php

define('PHPWG_ROOT_PATH', '/tmp/piwigo/');
define('IMAGES_TABLE', 'piwigo_images');
define('WS_ERR_INVALID_PARAM', 1003);

class PwgError
{
  private $code;
  private $message;

  public function __construct($code, $message)
  {
    $this->code = $code;
    $this->message = $message;
  }

  public function code()
  {
    return $this->code;
  }

  public function message()
  {
    return $this->message;
  }
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

require_once dirname(__DIR__) . '/include/original_sum_guard.inc.php';