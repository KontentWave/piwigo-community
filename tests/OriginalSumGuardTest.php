<?php

use PHPUnit\Framework\TestCase;

class OriginalSumGuardTest extends TestCase
{
  protected function setUp(): void
  {
    $GLOBALS['community_test'] = array(
      'queries' => array(),
      'escaped_values' => array(),
      'fetch_row_args' => array(),
    );
    unset($GLOBALS['community_ws_images_add_delegate']);
  }

  public function testMixedCaseChecksumDelegatesToCoreHandler()
  {
    $params = array(
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
    );
    $service = new stdClass();
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, $params, $service) {
      $delegateCalls++;
      TestCase::assertSame($params, $forwardedParams);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 42);
    };

    $result = community_ws_images_add($params, $service);

    $this->assertSame(array('image_id' => 42), $result);
    $this->assertSame(1, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  /**
   * @dataProvider provideInvalidChecksums
   */
  public function testInvalidChecksumsAreRejectedBeforeDelegate($checksum)
  {
    $params = array(
      'original_sum' => $checksum,
    );
    $service = new stdClass();
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 42);
    };

    $result = community_ws_images_add($params, $service);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(WS_ERR_INVALID_PARAM, $result->code());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertSame(array(), $GLOBALS['community_test']['escaped_values']);
  }

  public function testLookupQueryEscapesChecksumBeforeSqlInterpolation()
  {
    $GLOBALS['community_test']['escape_callback'] = function ($value) {
      return str_replace("'", "\\'", $value);
    };
    $GLOBALS['community_test']['fetch_row_return'] = array('73');

    $imageId = community_find_image_id_by_original_sum("abc'OR'1'='1def012345678901234567");

    $this->assertSame(73, $imageId);
    $this->assertSame(
      array("abc'OR'1'='1def012345678901234567"),
      $GLOBALS['community_test']['escaped_values']
    );
    $this->assertStringContainsString(
      "WHERE md5sum = 'abc\\'OR\\'1\\'=\\'1def012345678901234567'",
      $GLOBALS['community_test']['queries'][0]
    );
  }

  public function testInvalidChecksumIsNotStoredForLaterResponseLookup()
  {
    $community = array();

    $result = community_capture_original_sum_from_request(
      $community,
      array('original_sum' => '../cd1234abcd1234abcd1234abcd12')
    );

    $this->assertFalse($result);
    $this->assertArrayNotHasKey('md5sum', $community);
  }

  public function testValidChecksumIsStoredForLaterResponseLookup()
  {
    $community = array();

    $result = community_capture_original_sum_from_request(
      $community,
      array('original_sum' => 'AaBbCcDd00112233445566778899EeFf')
    );

    $this->assertTrue($result);
    $this->assertSame('AaBbCcDd00112233445566778899EeFf', $community['md5sum']);
  }

  public static function provideInvalidChecksums()
  {
    return array(
      'sql quotes' => array("abcd1234abcd1234abcd1234abc'123"),
      'regex metacharacters' => array('abcd1234abcd1234abcd1234ab.c123'),
      'path traversal' => array('../cd1234abcd1234abcd1234abcd12'),
      'path separator' => array('abcd1234abcd1234/BCD1234abcd1234'),
      'too short' => array('abcd1234'),
      'too long' => array('abcd1234abcd1234abcd1234abcd123400'),
      'non hex' => array('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'),
    );
  }
}