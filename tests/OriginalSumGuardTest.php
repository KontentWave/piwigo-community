<?php

use PHPUnit\Framework\Attributes\DataProvider;
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
      'pwg.images.addSimple',
      array(
        'category' => '1',
      )
    );

    $addSimpleMethod = community_test_get_registered_method($service, 'pwg.images.addSimple');
    $addMethod = community_test_get_registered_method($service, 'pwg.images.add');
    $chunkMethod = community_test_get_registered_method($service, 'pwg.images.addChunk');
    $uploadMethod = community_test_get_registered_method($service, 'pwg.images.upload');

    $this->assertSame('community_ws_images_add_simple', $addSimpleMethod['callback']);
    $this->assertSame(array('post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('community_ws_images_add', $addMethod['callback']);
    $this->assertSame('community_ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('community_ws_images_upload', $uploadMethod['callback']);
    $this->assertSame(array('post_only' => true), $uploadMethod['options']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
  }

  public function testNonAdminUploadLifecycleAuthorizedSingleCategoryDelegatesOnceWithoutStatusElevation()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_ws_images_upload_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, &$user, $service, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame(array(1), $forwardedParams['category']);
      TestCase::assertSame('upload.jpg', $forwardedParams['name']);
      TestCase::assertSame('test-token', $forwardedParams['pwg_token']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 456, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $this->assertSame(array('image_id' => 456, 'category' => array('id' => 1)), $result);
    $this->assertSame('normal', $user['status']);
    $this->assertSame(1, $delegateCalls);
  }

  public function testNonAdminUploadLifecycleRejectsUnauthorizedCategoryWithoutDelegateCall()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 456, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '2',
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
  }

  public function testNonAdminUploadLifecycleRejectsMixedCategoriesAtomically()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => array('1', '2'),
        'name' => 'upload.jpg',
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 456, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => array('1', '2'),
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testNonAdminUploadLifecycleAllowsFormatOfOwnersOwnImage()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'format.jpg',
        'format_of' => 77,
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_test']['fetch_row_return'] = array('1');
    $GLOBALS['community_ws_images_upload_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, &$user, $service, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame(array(1), $forwardedParams['category']);
      TestCase::assertSame(77, $forwardedParams['format_of']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 77, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'format.jpg',
      'format_of' => 77,
      'pwg_token' => 'test-token',
    ));

    $this->assertSame(array('image_id' => 77, 'category' => array('id' => 1)), $result);
    $this->assertSame(1, $delegateCalls);
    $this->assertStringContainsString('WHERE id = 77', $GLOBALS['community_test']['queries'][0]);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testNonAdminUploadLifecycleDeniesFormatOfAnotherUsersImage()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'format.jpg',
        'format_of' => 77,
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_test']['fetch_row_return'] = array('0');
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'format.jpg',
      'format_of' => 77,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testGenericUserUploadLifecycleLimitsFormatOfToCurrentSession()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'format.jpg',
        'format_of' => 77,
        'pwg_token' => 'test-token',
      )
    );

    $user['status'] = 'generic';
    $delegateCalls = 0;
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('0'),
    );
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'format.jpg',
      'format_of' => 77,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertCount(2, $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('session_idx', $GLOBALS['community_test']['queries'][1]);
  }

  public function testNonAdminUploadLifecycleRejectsUpdateModeBeforeDelegateCall()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'update_mode' => true,
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'update_mode' => true,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testGenericUserUploadLifecycleRejectsUpdateModeBeforeSessionMutationCheck()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'update_mode' => true,
        'pwg_token' => 'test-token',
      )
    );

    $user['status'] = 'generic';
    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'category' => array('id' => 1));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'update_mode' => true,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testNonAdminUploadLifecycleRejectsUpdateModeTargetDriftBeforeCoreMutation()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'update_mode' => true,
        'pwg_token' => 'test-token',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function ($forwardedParams) use (&$delegateCalls) {
      $delegateCalls++;
      $images = query2array('core-update-mode-reresolution');
      if (!empty($images))
      {
        return array('image_id' => (int) $images[0]['id'], 'category' => array('id' => $forwardedParams['category'][0]));
      }

      return array('image_id' => 0, 'category' => array('id' => $forwardedParams['category'][0]));
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'update_mode' => true,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testNonAdminAddSimpleLifecycleAuthorizedSingleCategoryDelegatesOnceWithoutStatusElevation()
  {
    global $user;

    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'upload.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1')
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($tmpName, $originalName, $categories, $level, $imageId) use (&$delegateCalls, &$user, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame('/tmp/uploaded-file', $tmpName);
      TestCase::assertSame('upload.jpg', $originalName);
      TestCase::assertSame(array(1), $categories);
      TestCase::assertSame(8, $level);
      TestCase::assertNull($imageId);

      return 321;
    };
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array('id' => 1, 'name' => 'Album', 'permalink' => 'album'),
    );

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1'));

    $this->assertSame(array('image_id' => 321, 'url' => 'picture-url-321'), $result);
    $this->assertSame('normal', $user['status']);
    $this->assertSame(1, $delegateCalls);
    $this->assertCount(1, $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertCount(1, $GLOBALS['community_test']['single_updates']);
    $this->assertSame(array(array(321)), $GLOBALS['community_test']['metadata_sync_calls']);
  }

  public function testNonAdminAddSimpleLifecycleAuthorizedMultiCategoryDelegatesOnce()
  {
    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'upload.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => array('1', '2'))
    );
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);

    $delegateCalls = 0;
    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($tmpName, $originalName, $categories) use (&$delegateCalls) {
      $delegateCalls++;
      TestCase::assertSame(array(1, 2), $categories);

      return 654;
    };
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array('id' => 1, 'name' => 'Album', 'permalink' => 'album'),
    );

    $result = $service->invoke('pwg.images.addSimple', array('category' => array('1', '2')));

    $this->assertSame(array('image_id' => 654, 'url' => 'picture-url-654'), $result);
    $this->assertSame(1, $delegateCalls);
  }

  #[DataProvider('provideRejectedAddSimpleCategories')]
  public function testNonAdminAddSimpleLifecycleRejectsInvalidOrUnauthorizedCategoriesAtomically($input, $expectedCode)
  {
    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'upload.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => 1)
    );

    $result = $service->invoke('pwg.images.addSimple', array('category' => $input));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame($expectedCode, $result->code());
    if (401 === $expectedCode)
    {
      $this->assertSame('Access denied', $result->message());
    }
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['single_updates']);
    $this->assertSame(array(), $GLOBALS['community_test']['metadata_sync_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testNonAdminAddSimpleLifecycleRejectsWhenUserHasOnlyCategoryCreationRights()
  {
    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'upload.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1')
    );

    $_SESSION['community_user_permissions']['upload_categories'] = array();
    $_SESSION['community_user_permissions']['create_categories'] = array(1);

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1'));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testNonAdminAddSimpleLifecycleAllowsReplacementOfOwnersOwnImage()
  {
    global $user;

    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'replacement.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1', 'image_id' => 77)
    );

    $GLOBALS['community_test']['fetch_row_return'] = array('1');
    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($tmpName, $originalName, $categories, $level, $imageId) use (&$user) {
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame(77, $imageId);
      TestCase::assertSame(array(1), $categories);

      return 77;
    };
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array('id' => 1, 'name' => 'Album', 'permalink' => 'album'),
    );

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1', 'image_id' => 77));

    $this->assertSame(array('image_id' => 77, 'url' => 'picture-url-77'), $result);
    $this->assertStringContainsString('WHERE id = 77', $GLOBALS['community_test']['queries'][0]);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testNonAdminAddSimpleLifecycleDeniesReplacementOfAnotherUsersImage()
  {
    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'replacement.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1', 'image_id' => 77)
    );

    $GLOBALS['community_test']['fetch_row_return'] = array('0');

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1', 'image_id' => 77));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testGenericUserAddSimpleLifecycleLimitsReplacementToCurrentSession()
  {
    global $user;

    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'replacement.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1', 'image_id' => 77)
    );

    $user['status'] = 'generic';
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('0'),
    );

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1', 'image_id' => 77));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertCount(2, $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('session_idx', $GLOBALS['community_test']['queries'][1]);
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
  }

  public function testGenericUserAddSimpleLifecycleAllowsReplacementForCurrentSessionImage()
  {
    global $user;

    $_FILES['image'] = array(
      'tmp_name' => '/tmp/uploaded-file',
      'name' => 'replacement.jpg',
      'error' => 0,
    );

    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1', 'image_id' => 77)
    );

    $user['status'] = 'generic';
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('1'),
      array('1'),
    );
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array('id' => 1, 'name' => 'Album', 'permalink' => 'album'),
    );
    $GLOBALS['community_test']['queries'] = array();
    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($tmpName, $originalName, $categories, $level, $imageId) {
      TestCase::assertSame(77, $imageId);

      return 77;
    };

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1', 'image_id' => 77));

    $this->assertSame(array('image_id' => 77, 'url' => 'picture-url-77'), $result);
    $this->assertCount(4, $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
    $this->assertStringContainsString('session_idx', $GLOBALS['community_test']['queries'][1]);
    $this->assertStringContainsString('WHERE id = 77', $GLOBALS['community_test']['queries'][2]);
    $this->assertStringContainsString('FROM '. CATEGORIES_TABLE, $GLOBALS['community_test']['queries'][3]);
  }

  #[DataProvider('provideInvalidChecksums')]
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

  #[DataProvider('provideInvalidChecksums')]
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
      'pwg.images.addSimple',
      array(
        'category' => '1',
      ),
      true
    );

    $addSimpleMethod = community_test_get_registered_method($service, 'pwg.images.addSimple');
    $addMethod = community_test_get_registered_method($service, 'pwg.images.add');
    $chunkMethod = community_test_get_registered_method($service, 'pwg.images.addChunk');
    $uploadMethod = community_test_get_registered_method($service, 'pwg.images.upload');

    $this->assertSame('ws_images_addSimple', $addSimpleMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('ws_images_add', $addMethod['callback']);
    $this->assertSame('ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('ws_images_upload', $uploadMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $uploadMethod['options']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
  }

  public function testAddSimpleLifecycleFakedByCommunityFalseKeepsExistingBehavior()
  {
    $_REQUEST['faked_by_community'] = 'false';
    $service = community_test_build_service(
      'pwg.images.addSimple',
      array(
        'category' => '1',
        'faked_by_community' => 'false',
      )
    );

    $method = community_test_get_registered_method($service, 'pwg.images.addSimple');

    $this->assertSame('ws_images_addSimple', $method['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $method['options']);
  }

  public function testUploadLifecycleFakedByCommunityFalseKeepsExistingBehavior()
  {
    $_REQUEST['faked_by_community'] = 'false';
    $service = community_test_build_service(
      'pwg.images.upload',
      array(
        'category' => '1',
        'name' => 'upload.jpg',
        'pwg_token' => 'test-token',
        'faked_by_community' => 'false',
      )
    );

    $method = community_test_get_registered_method($service, 'pwg.images.upload');

    $this->assertSame('ws_images_upload', $method['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $method['options']);
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

  public static function provideRejectedAddSimpleCategories()
  {
    return array(
      'missing' => array(null, 401),
      'zero' => array('0', 1003),
      'negative' => array('-1', 1003),
      'malformed string' => array('abc', 1003),
      'unauthorized scalar' => array('2', 401),
      'mixed scalar list' => array(array('1', '2'), 401),
      'empty array' => array(array(), 401),
      'malformed array member' => array(array('1', 'abc'), 1003),
    );
  }
}