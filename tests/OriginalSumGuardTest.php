<?php

use PHPUnit\Framework\TestCase;

class OriginalSumGuardTest extends TestCase
{
  protected function setUp(): void
  {
    community_test_reset_runtime();
  }

  public function testNonAdminLifecycleRegistersCommunityWrappers()
  {
    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );

    $addMethod = community_test_get_registered_method($service, 'pwg.images.add');
    $chunkMethod = community_test_get_registered_method($service, 'pwg.images.addChunk');

    $this->assertSame('community_ws_images_add', $addMethod['callback']);
    $this->assertSame('community_ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
  }

  /**
   * @dataProvider provideInvalidChecksums
   */
  public function testNonAdminAddLifecycleRejectsInvalidChecksumsBeforeDelegate($checksum)
  {
    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );

    $params = array(
      'original_sum' => $checksum,
      'categories' => '1',
    );
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 42);
    };

    $result = $service->invoke('pwg.images.add', $params);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(WS_ERR_INVALID_PARAM, $result->code());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertSame(array(), $GLOBALS['community_test']['escaped_values']);
  }

  public function testNonAdminAddLifecycleDelegatesMixedCaseChecksumExactlyOnce()
  {
    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );
    $params = array(
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'categories' => '1',
      'check_uniqueness' => false,
    );
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, $service) {
      $delegateCalls++;
      TestCase::assertSame('AaBbCcDd00112233445566778899EeFf', $forwardedParams['original_sum']);
      TestCase::assertSame('1', $forwardedParams['categories']);
      TestCase::assertFalse($forwardedParams['check_uniqueness']);
      TestCase::assertNull($forwardedParams['original_filename']);
      TestCase::assertSame(0, $forwardedParams['level']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 42);
    };

    $result = $service->invoke('pwg.images.add', $params);

    $this->assertSame(array('image_id' => 42), $result);
    $this->assertSame(1, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  /**
   * @dataProvider provideInvalidChecksums
   */
  public function testNonAdminAddChunkLifecycleRejectsInvalidChecksumsBeforeDelegate($checksum)
  {
    $service = community_test_build_service('pwg.images.addChunk');
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_chunk_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return true;
    };

    $result = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode('chunk-data'),
      'original_sum' => $checksum,
      'position' => 0,
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(WS_ERR_INVALID_PARAM, $result->code());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertSame(array(), $GLOBALS['community_test']['escaped_values']);
  }

  public function testNonAdminAddChunkLifecycleDelegatesMixedCaseChecksumExactlyOnce()
  {
    $service = community_test_build_service('pwg.images.addChunk');
    $delegateCalls = 0;

    $GLOBALS['community_ws_images_add_chunk_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, $service) {
      $delegateCalls++;
      TestCase::assertSame('AaBbCcDd00112233445566778899EeFf', $forwardedParams['original_sum']);
      TestCase::assertSame($service, $forwardedService);

      return true;
    };

    $result = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode('chunk-data'),
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'position' => 0,
    ));

    $this->assertTrue($result);
    $this->assertSame(1, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testFilenameUniquenessUsesEscapedPluginQueryAndDelegatesOnce()
  {
    global $conf;

    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );
    $conf['uniqueness_mode'] = 'filename';
    $params = array(
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'original_filename' => "evil' OR '1'='1.jpg",
      'categories' => '1',
      'check_uniqueness' => true,
    );
    $delegateCalls = 0;

    $GLOBALS['community_test']['escape_callback'] = function ($value) {
      return str_replace("'", "\\'", $value);
    };
    $GLOBALS['community_test']['fetch_row_return'] = array('0');
    $GLOBALS['community_ws_images_add_delegate'] = function ($forwardedParams) use (&$delegateCalls, $params) {
      $delegateCalls++;
      TestCase::assertSame($params['original_filename'], $forwardedParams['original_filename']);
      TestCase::assertFalse($forwardedParams['check_uniqueness']);

      return array('image_id' => 99);
    };

    $result = $service->invoke('pwg.images.add', $params);

    $this->assertSame(array('image_id' => 99), $result);
    $this->assertSame(1, $delegateCalls);
    $this->assertSame(array($params['original_filename']), $GLOBALS['community_test']['escaped_values']);
    $this->assertStringContainsString(
      "WHERE file = 'evil\\' OR \\'1\\'=\\'1.jpg'",
      $GLOBALS['community_test']['queries'][0]
    );
  }

  public function testFilenameUniquenessDuplicateKeepsExistingErrorBehavior()
  {
    global $conf;

    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );
    $conf['uniqueness_mode'] = 'filename';
    $delegateCalls = 0;
    $GLOBALS['community_test']['fetch_row_return'] = array('1');
    $GLOBALS['community_ws_images_add_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 99);
    };

    $result = $service->invoke('pwg.images.add', array(
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'original_filename' => 'duplicate.jpg',
      'categories' => '1',
      'check_uniqueness' => true,
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(500, $result->code());
    $this->assertSame('file already exists', $result->message());
    $this->assertSame(0, $delegateCalls);
  }

  public function testAdministratorLifecycleKeepsCoreCallbacksUntouched()
  {
    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      ),
      true
    );

    $addMethod = community_test_get_registered_method($service, 'pwg.images.add');
    $chunkMethod = community_test_get_registered_method($service, 'pwg.images.addChunk');

    $this->assertSame('ws_images_add', $addMethod['callback']);
    $this->assertSame('ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
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
      'backslash separator' => array('abcd1234abcd1234\\BCD1234abcd1234'),
      'too short' => array('abcd1234'),
      'too long' => array('abcd1234abcd1234abcd1234abcd123400'),
      'non hex' => array('zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz'),
    );
  }
}