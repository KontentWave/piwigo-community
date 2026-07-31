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
    $uploadAsyncMethod = community_test_get_registered_method($service, 'pwg.images.uploadAsync');

    $this->assertSame('community_ws_images_add_simple', $addSimpleMethod['callback']);
    $this->assertSame(array('post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('community_ws_images_add', $addMethod['callback']);
    $this->assertSame('community_ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('community_ws_images_upload', $uploadMethod['callback']);
    $this->assertSame(array('post_only' => true), $uploadMethod['options']);
    $this->assertSame('community_ws_images_upload_async', $uploadAsyncMethod['callback']);
    $this->assertSame(array('post_only' => true), $uploadAsyncMethod['options']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
  }

  public function testNonAdminUploadAsyncLifecycleAuthorizedSingleCategoryDelegatesOnceWithoutStatusElevation()
  {
    global $user;

    $chunkContents = 'single-chunk';
    $chunkSum = md5($chunkContents);

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
        'chunk' => 1,
        'chunk_sum' => $chunkSum,
        'chunks' => 1,
        'original_sum' => $chunkSum,
        'category' => '1',
        'filename' => 'upload.jpg',
      )
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_ws_images_upload_async_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, &$user, $service, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame(array(1), $forwardedParams['category']);
      TestCase::assertSame('upload.jpg', $forwardedParams['filename']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 654, 'message' => 'chunks uploaded = 1');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);

    $result = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => $chunkSum,
      'chunks' => 1,
      'original_sum' => $chunkSum,
      'category' => '1',
      'filename' => 'upload.jpg',
    ));

    $this->assertSame(array('image_id' => 654, 'message' => 'chunks uploaded = 1'), $result);
    $this->assertSame('normal', $user['status']);
    $this->assertSame(1, $delegateCalls);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsUnauthorizedCategoryWithoutDelegateCall()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
        'chunk' => 1,
        'chunk_sum' => 'AaBbCcDd00112233445566778899EeFf',
        'chunks' => 1,
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
        'category' => '1',
        'filename' => 'upload.jpg',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'chunks uploaded = 1');
    };

    $result = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'chunks' => 1,
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'category' => '2',
      'filename' => 'upload.jpg',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
  }

  public function testNonAdminUploadAsyncLifecycleAllowsReplacementOfOwnersOwnImage()
  {
    global $user;

    $chunkContents = 'single-chunk';
    $chunkSum = md5($chunkContents);

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
        'chunk' => 1,
        'chunk_sum' => $chunkSum,
        'chunks' => 1,
        'original_sum' => $chunkSum,
        'category' => '1',
        'filename' => 'upload.jpg',
        'image_id' => 77,
      )
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_test']['fetch_row_return'] = array('1');
    $GLOBALS['community_ws_images_upload_async_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, &$user, $service, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame(77, $forwardedParams['image_id']);
      TestCase::assertSame(array(1), $forwardedParams['category']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 77, 'message' => 'chunks uploaded = 1');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);

    $result = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => $chunkSum,
      'chunks' => 1,
      'original_sum' => $chunkSum,
      'category' => '1',
      'filename' => 'upload.jpg',
      'image_id' => 77,
    ));

    $this->assertSame(array('image_id' => 77, 'message' => 'chunks uploaded = 1'), $result);
    $this->assertSame(1, $delegateCalls);
    $this->assertStringContainsString('WHERE id = 77', $GLOBALS['community_test']['queries'][0]);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testGenericUserUploadAsyncLifecycleLimitsReplacementToCurrentSession()
  {
    global $user;

    $chunkContents = 'single-chunk';
    $chunkSum = md5($chunkContents);

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
        'chunk' => 1,
        'chunk_sum' => $chunkSum,
        'chunks' => 1,
        'original_sum' => $chunkSum,
        'category' => '1',
        'filename' => 'upload.jpg',
        'image_id' => 77,
      )
    );

    $user['status'] = 'generic';
    $delegateCalls = 0;
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('0'),
    );
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'message' => 'chunks uploaded = 1');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);

    $result = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => $chunkSum,
      'chunks' => 1,
      'original_sum' => $chunkSum,
      'category' => '1',
      'filename' => 'upload.jpg',
      'image_id' => 77,
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertCount(2, $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('session_idx', $GLOBALS['community_test']['queries'][1]);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsCategoryDriftAcrossChunksBeforeDelegateCall()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
          'chunk' => 0,
        'chunk_sum' => '11111111111111111111111111111111',
        'chunks' => 2,
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
        'category' => '1',
        'filename' => 'upload.jpg',
      )
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('message' => 'chunks uploaded = 1');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
      'chunks' => 2,
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'category' => '1',
      'filename' => 'upload.jpg',
    ));

    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $secondResult = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
      'chunks' => 2,
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'category' => '2',
      'filename' => 'upload.jpg',
    ));

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $secondResult);
    $this->assertSame(401, $secondResult->code());
    $this->assertSame('Access denied', $secondResult->message());
    $this->assertSame(0, $delegateCalls);
  }

  public function testNonAdminUploadAsyncLifecycleCompletesOutOfOrderChunksWithoutStatusElevation()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $delegateCalls = 0;
    $statusBefore = $user['status'];
    $GLOBALS['community_ws_images_upload_async_delegate'] = function ($forwardedParams, $forwardedService) use (&$delegateCalls, &$user, $service, $statusBefore) {
      $delegateCalls++;
      TestCase::assertSame('normal', $user['status']);
      TestCase::assertSame($statusBefore, $user['status']);
      TestCase::assertSame(array(1), $forwardedParams['category']);
      TestCase::assertSame('upload.jpg', $forwardedParams['filename']);
      TestCase::assertSame($service, $forwardedService);

      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
    )));

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $secondResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $this->assertSame(array('message' => 'chunks uploaded = 2'), $firstResult);
    $this->assertSame(array('image_id' => 654, 'message' => 'complete'), $secondResult);
    $this->assertSame('normal', $user['status']);
    $this->assertSame(1, $delegateCalls);
  }

  public function testNonAdminUploadAsyncLifecycleDifferentUserCannotContinueExistingUploadState()
  {
    global $user;

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function ($forwardedParams) use (&$delegateCalls) {
      $delegateCalls++;

      return array('image_id' => 654, 'message' => 'complete-'.$forwardedParams['filename']);
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $user['id'] = 3;
    $_SESSION['community_user_id'] = 3;
    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $otherUserResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
    )));

    $user['id'] = 2;
    $_SESSION['community_user_id'] = 2;
    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $finalResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
    )));

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertSame(array('message' => 'chunks uploaded = 2'), $otherUserResult);
    $this->assertSame(array('image_id' => 654, 'message' => 'complete-upload.jpg'), $finalResult);
    $this->assertSame(1, $delegateCalls);
  }

  #[DataProvider('provideUploadAsyncManifestDriftCases')]
  public function testNonAdminUploadAsyncLifecycleRejectsManifestDriftAcrossChunksBeforeChunkWrite($firstChunkOverrides, $secondChunkOverrides)
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams($firstChunkOverrides)
    );

    if (!empty($firstChunkOverrides['image_id']) || !empty($secondChunkOverrides['image_id']))
    {
      $GLOBALS['community_test']['fetch_row_returns'] = array(array('1'), array('1'));
    }

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array_merge(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    ), $firstChunkOverrides)));

    $paths = $this->getUploadAsyncStatePaths($this->buildUploadAsyncParams($firstChunkOverrides));

    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $secondResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array_merge(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
    ), $secondChunkOverrides)));

    $storedChunks = glob($paths['chunks_dir'].'/*.chunk');

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $secondResult);
    $this->assertSame(401, $secondResult->code());
    $this->assertSame('Access denied', $secondResult->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertNotFalse($storedChunks);
    $this->assertCount(1, $storedChunks);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsInvalidChunkIndexBeforeChunkWrite()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams(array('chunk' => 2))
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('invalid-index');
    $result = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 2,
      'chunk_sum' => md5('invalid-index'),
    )));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(WS_ERR_INVALID_PARAM, $result->code());
    $this->assertSame('Invalid chunk index', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertFileDoesNotExist($this->getUploadAsyncStatePaths($this->buildUploadAsyncParams())['manifest_file']);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsExcessiveChunkCountBeforeChunkWrite()
  {
    global $conf;

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $conf['community']['upload_async_max_chunks'] = 1;

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $result = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(WS_ERR_INVALID_PARAM, $result->code());
    $this->assertSame('Too many chunks', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertFileDoesNotExist($this->getUploadAsyncStatePaths($this->buildUploadAsyncParams())['manifest_file']);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsOversizedChunkBeforeChunkWrite()
  {
    global $conf;

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams(array('chunks' => 1))
    );

    $conf['upload_form_chunk_size'] = 1;

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $chunkContents = str_repeat('a', 1025);
    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $result = $service->invoke('pwg.images.uploadAsync', array(
      'chunk' => 1,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 1,
      'original_sum' => md5($chunkContents),
      'category' => '1',
      'filename' => 'upload.jpg',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(413, $result->code());
    $this->assertSame('Uploaded chunk exceeds the configured chunk size limit', $result->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertFileDoesNotExist($this->getUploadAsyncStatePaths(array('original_sum' => md5($chunkContents)))['manifest_file']);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsCumulativeByteOverflowWithoutDoubleCountingStoredChunks()
  {
    global $conf;

    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams(array('original_sum' => md5('123456abcdef')))
    );

    $conf['community']['upload_async_max_bytes'] = 10;

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('123456');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('123456'),
      'original_sum' => md5('123456abcdef'),
    )));

    $paths = $this->getUploadAsyncStatePaths($this->buildUploadAsyncParams(array('original_sum' => md5('123456abcdef'))));

    $_FILES['file'] = community_test_create_uploaded_chunk('abcdef');
    $secondResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('abcdef'),
      'original_sum' => md5('123456abcdef'),
    )));

    $manifest = community_read_json_file($paths['manifest_file']);
    $storedChunks = glob($paths['chunks_dir'].'/*.chunk');

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $secondResult);
    $this->assertSame(413, $secondResult->code());
    $this->assertSame('Upload exceeds the configured cumulative size limit', $secondResult->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertSame(6, $manifest['received_bytes']);
    $this->assertNotFalse($storedChunks);
    $this->assertCount(1, $storedChunks);
  }

  public function testNonAdminUploadAsyncLifecycleTreatsIdenticalChunkRetryAsIdempotent()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $paths = $this->getUploadAsyncStatePaths($this->buildUploadAsyncParams());

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $retryResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $storedChunks = glob($paths['chunks_dir'].'/*.chunk');
    $manifest = community_read_json_file($paths['manifest_file']);

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertSame(array('message' => 'chunks uploaded = 1'), $retryResult);
    $this->assertSame(0, $delegateCalls);
    $this->assertNotFalse($storedChunks);
    $this->assertCount(1, $storedChunks);
    $this->assertSame(strlen('first-chunk'), $manifest['received_bytes']);
  }

  public function testNonAdminUploadAsyncLifecycleRejectsConflictingChunkRetry()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $paths = $this->getUploadAsyncStatePaths($this->buildUploadAsyncParams());

    $_FILES['file'] = community_test_create_uploaded_chunk('other-first');
    $retryResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('other-first'),
    )));

    $storedChunks = glob($paths['chunks_dir'].'/*.chunk');

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $retryResult);
    $this->assertSame(409, $retryResult->code());
    $this->assertSame('Chunk retry conflicts with the existing upload state', $retryResult->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertNotFalse($storedChunks);
    $this->assertCount(1, $storedChunks);
  }

  public function testNonAdminUploadAsyncLifecycleReturnsReceiptForSuccessfulRetryWithoutSecondDelegateCall()
  {
    $chunkContents = 'single-chunk';
    $params = array(
      'chunk' => 1,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 1,
      'original_sum' => md5($chunkContents),
      'category' => '1',
      'filename' => 'upload.jpg',
      'name' => 'Title',
      'author' => 'Author',
      'comment' => 'Comment',
      'date_creation' => '2024-01-01',
      'level' => 8,
      'tag_ids' => '4,5',
    );

    $service = community_test_build_service('pwg.images.uploadAsync', $params);

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function ($forwardedParams) use (&$delegateCalls) {
      $delegateCalls++;
      return array(
        'image_id' => 700,
        'message' => sprintf(
          '%s|%s|%s|%s|%s|%d|%s',
          $forwardedParams['filename'],
          $forwardedParams['name'],
          $forwardedParams['author'],
          $forwardedParams['comment'],
          $forwardedParams['date_creation'],
          $forwardedParams['level'],
          $forwardedParams['tag_ids']
        ),
      );
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $firstResult = $service->invoke('pwg.images.uploadAsync', $params);

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $retryResult = $service->invoke('pwg.images.uploadAsync', $params);

    $paths = $this->getUploadAsyncStatePaths($params);

    $this->assertSame($firstResult, $retryResult);
    $this->assertSame(1, $delegateCalls);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertFileDoesNotExist($paths['chunks_dir']);
    $this->assertFileExists($paths['receipt_file']);
  }

  public function testNonAdminUploadAsyncLifecycleSuccessfulReceiptWriteFailureFallsBackToCompletedManifest()
  {
    $chunkContents = 'single-chunk';
    $params = array(
      'chunk' => 1,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 1,
      'original_sum' => md5($chunkContents),
      'category' => '1',
      'filename' => 'upload.jpg',
    );

    $service = community_test_build_service('pwg.images.uploadAsync', $params);

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 700, 'message' => 'complete');
    };
    $GLOBALS['community_test_write_json_file_callback'] = function ($filepath) {
      if ('receipt.json' === basename($filepath))
      {
        return false;
      }

      return null;
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $firstResult = $service->invoke('pwg.images.uploadAsync', $params);

    $paths = $this->getUploadAsyncStatePaths($params);
    $storedManifest = community_read_json_file($paths['manifest_file']);

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $retryResult = $service->invoke('pwg.images.uploadAsync', $params);

    $this->assertSame(array('image_id' => 700, 'message' => 'complete'), $firstResult);
    $this->assertSame($firstResult, $retryResult);
    $this->assertSame(1, $delegateCalls);
    $this->assertFileDoesNotExist($paths['receipt_file']);
    $this->assertFileDoesNotExist($paths['chunks_dir']);
    $this->assertIsArray($storedManifest);
    $this->assertSame($params['filename'], $storedManifest['completed_request']['filename']);
    $this->assertSame($firstResult, $storedManifest['completed_result']);
  }

  public function testUploadAsyncCleanupArtifactsKeepsLockPathUntilPostUnlockCleanup()
  {
    $params = $this->buildUploadAsyncParams();
    $paths = $this->getUploadAsyncStatePaths($params);
    $manifest = array(
      'original_sum' => $params['original_sum'],
      'user_id' => 2,
      'chunks' => 2,
    );

    $this->assertTrue(community_ensure_directory($paths['state_dir']));
    $this->assertTrue(community_ensure_directory($paths['chunks_dir']));
    file_put_contents($paths['lock_file'], 'lock');
    file_put_contents($paths['manifest_file'], json_encode(array('expires_at' => time() - 1)));
    file_put_contents($paths['receipt_file'], json_encode(array('expires_at' => time() - 1)));
    file_put_contents($paths['merged_file'], 'merged');
    file_put_contents($paths['chunks_dir'].'/000000.chunk', 'chunk');

    $lockHandle = fopen($paths['lock_file'], 'c+');
    $this->assertNotFalse($lockHandle);
    $this->assertTrue(flock($lockHandle, LOCK_EX));

    community_cleanup_upload_async_artifacts($manifest, $paths, true);

    $this->assertFileExists($paths['lock_file']);
    $this->assertDirectoryExists($paths['state_dir']);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertFileDoesNotExist($paths['receipt_file']);
    $this->assertFileDoesNotExist($paths['merged_file']);
    $this->assertFileDoesNotExist($paths['chunks_dir']);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);

    community_cleanup_upload_async_state_directory($paths);

    $this->assertFileDoesNotExist($paths['lock_file']);
    $this->assertDirectoryDoesNotExist($paths['state_dir']);
  }

  public function testNonAdminUploadAsyncLifecycleExpiresStateAndCleansOnlyExactArtifacts()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams()
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
    )));

    $paths = $this->getUploadAsyncStatePaths($this->buildUploadAsyncParams());
    $manifest = community_read_json_file($paths['manifest_file']);
    $manifest['expires_at'] = time() - 1;
    community_write_json_file($paths['manifest_file'], $manifest);

    $corePrefix = '/tmp/community-upload-tests/buffer/'.$manifest['original_sum'].'-u'.$manifest['user_id'];
    $exactCoreChunk = sprintf('%s-%03uof%03u.chunk', $corePrefix, 1, $manifest['chunks']);
    $exactCoreMerged = $corePrefix.'.merged';
    $unrelatedCoreChunk = sprintf('%s-%03uof%03u.chunk', $corePrefix, 1, $manifest['chunks'] + 1);
    $unrelatedStateFile = '/tmp/community-upload-tests/buffer/community-upload-async/unrelated/keep.txt';

    if (!is_dir(dirname($exactCoreChunk)))
    {
      mkdir(dirname($exactCoreChunk), 0777, true);
    }
    if (!is_dir(dirname($unrelatedStateFile)))
    {
      mkdir(dirname($unrelatedStateFile), 0777, true);
    }

    file_put_contents($exactCoreChunk, 'core-chunk');
    file_put_contents($exactCoreMerged, 'core-merged');
    file_put_contents($unrelatedCoreChunk, 'keep');
    file_put_contents($unrelatedStateFile, 'keep');

    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $expiredResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
    )));

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $expiredResult);
    $this->assertSame(410, $expiredResult->code());
    $this->assertSame('Upload expired', $expiredResult->message());
    $this->assertSame(0, $delegateCalls);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertFileDoesNotExist($paths['chunks_dir']);
    $this->assertFileDoesNotExist($exactCoreChunk);
    $this->assertFileDoesNotExist($exactCoreMerged);
    $this->assertFileExists($unrelatedCoreChunk);
    $this->assertFileExists($unrelatedStateFile);
  }

  public function testNonAdminUploadAsyncLifecycleRevokedOwnershipBlocksFinalPersistence()
  {
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      $this->buildUploadAsyncParams(array('image_id' => 77))
    );

    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('0'),
    );

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 77, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk('first-chunk');
    $firstResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 0,
      'chunk_sum' => md5('first-chunk'),
      'image_id' => 77,
    )));

    $_FILES['file'] = community_test_create_uploaded_chunk('second-chunk');
    $secondResult = $service->invoke('pwg.images.uploadAsync', $this->buildUploadAsyncParams(array(
      'chunk' => 1,
      'chunk_sum' => md5('second-chunk'),
      'image_id' => 77,
    )));

    $this->assertSame(array('message' => 'chunks uploaded = 1'), $firstResult);
    $this->assertInstanceOf(PwgError::class, $secondResult);
    $this->assertSame(401, $secondResult->code());
    $this->assertSame('Access denied', $secondResult->message());
    $this->assertSame(0, $delegateCalls);
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

  public function testNonAdminLegacyAddLifecycleAuthorizedCategoryFinalizesWithoutStatusElevation()
  {
    global $user;

    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');
    $statusBefore = $user['status'];
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array(
        'id' => 1,
        'name' => 'Allowed album',
        'permalink' => 'allowed-album',
      ),
    );

    $chunkResult = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $addResult = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1',
      'check_uniqueness' => false,
    ));

    $this->assertTrue($chunkResult);
    $this->assertSame('normal', $user['status']);
    $this->assertSame($statusBefore, $user['status']);
    $this->assertSame(1, $addResult['image_id']);
    $this->assertSame('picture-url-1', $addResult['url']);
    $this->assertCount(1, $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertNull($GLOBALS['community_test']['add_uploaded_file_calls'][0]['categories']);
    $this->assertSame($statusBefore, $user['status']);
  }

  public function testNonAdminLegacyAddLifecycleRejectsUnauthorizedCategoryWithoutPersistingImage()
  {
    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');

    $chunkResult = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $addResult = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '2',
      'check_uniqueness' => false,
    ));

    $this->assertTrue($chunkResult);
    $this->assertInstanceOf(PwgError::class, $addResult);
    $this->assertSame(401, $addResult->code());
    $this->assertSame('Access denied', $addResult->message());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
  }

  public function testNonAdminLegacyAddLifecycleRejectsMixedAuthorizedAndUnauthorizedCategoriesAtomically()
  {
    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');

    $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $addResult = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1;2',
      'check_uniqueness' => false,
    ));

    $this->assertInstanceOf(PwgError::class, $addResult);
    $this->assertSame(401, $addResult->code());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
  }

  public function testNonAdminLegacyAddLifecycleAllowsReplacementOfOwnersOwnImage()
  {
    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
    );
    $GLOBALS['community_test']['fetch_assoc_return'] = array(
      array(
        'id' => 1,
        'name' => 'Allowed album',
        'permalink' => 'allowed-album',
      ),
    );

    $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $result = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1',
      'check_uniqueness' => false,
      'image_id' => 77,
    ));

    $this->assertSame(77, $result['image_id']);
    $this->assertCount(1, $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertSame(77, $GLOBALS['community_test']['add_uploaded_file_calls'][0]['image_id']);
    $this->assertStringContainsString('WHERE id = 77', $GLOBALS['community_test']['queries'][0]);
    $this->assertStringContainsString('added_by` = 2', $GLOBALS['community_test']['queries'][0]);
  }

  public function testGenericUserLegacyAddLifecycleLimitsReplacementToCurrentSession()
  {
    global $user;

    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');
    $user['status'] = 'generic';
    $GLOBALS['community_test']['fetch_row_returns'] = array(
      array('1'),
      array('0'),
    );

    $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $result = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1',
      'check_uniqueness' => false,
      'image_id' => 77,
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertCount(2, $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('session_idx', $GLOBALS['community_test']['queries'][1]);
  }

  public function testNonAdminLegacyAddLifecycleDifferentUserCannotReuseBufferedChunks()
  {
    global $user;

    $chunkContents = 'legacy-upload-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');

    $chunkResult = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));

    $user['id'] = 3;
    $_SESSION['community_user_id'] = 3;
    $_SESSION['community_user_permissions'] = array(
      'upload_categories' => array(1),
      'create_categories' => array(),
      'create_whole_gallery' => false,
      'permission_ids' => array(22),
      'user_album' => false,
    );

    $addResult = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1',
      'check_uniqueness' => false,
    ));

    $this->assertTrue($chunkResult);
    $this->assertInstanceOf(PwgError::class, $addResult);
    $this->assertSame(401, $addResult->code());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
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
    $uploadAsyncMethod = community_test_get_registered_method($service, 'pwg.images.uploadAsync');

    $this->assertSame('ws_images_addSimple', $addSimpleMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('ws_images_add', $addMethod['callback']);
    $this->assertSame('ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('ws_images_upload', $uploadMethod['callback']);
    $this->assertSame('ws_images_uploadAsync', $uploadAsyncMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $uploadMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $uploadAsyncMethod['options']);
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

  public function testUploadAsyncLifecycleFakedByCommunityFalseKeepsExistingBehavior()
  {
    $_REQUEST['faked_by_community'] = 'false';
    $service = community_test_build_service(
      'pwg.images.uploadAsync',
      array(
        'chunk' => 1,
        'chunk_sum' => 'AaBbCcDd00112233445566778899EeFf',
        'chunks' => 1,
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
        'category' => '1',
        'filename' => 'upload.jpg',
        'faked_by_community' => 'false',
      )
    );

    $method = community_test_get_registered_method($service, 'pwg.images.uploadAsync');

    $this->assertSame('ws_images_uploadAsync', $method['callback']);
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

  private function buildUploadAsyncParams($overrides = array())
  {
    return array_merge(
      array(
        'chunk' => 0,
        'chunk_sum' => md5('first-chunk'),
        'chunks' => 2,
        'original_sum' => md5('first-chunksecond-chunk'),
        'category' => '1',
        'filename' => 'upload.jpg',
      ),
      $overrides
    );
  }

  private function getUploadAsyncStatePaths($params)
  {
    global $user;

    $params = array_merge($this->buildUploadAsyncParams(), $params);

    return community_get_upload_async_state_paths(
      $params['original_sum'],
      isset($params['user_id']) ? $params['user_id'] : $user['id'],
      array_key_exists('session_id', $params) ? $params['session_id'] : community_get_upload_async_session_identity()
    );
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

  public static function provideUploadAsyncManifestDriftCases()
  {
    return array(
      'chunk count drift' => array(array(), array('chunks' => 3)),
      'image_id drift' => array(array('image_id' => 77), array('image_id' => 78)),
      'filename drift' => array(array(), array('filename' => 'other.jpg')),
      'name drift' => array(array('name' => 'Title'), array('name' => 'Other title')),
      'author drift' => array(array('author' => 'Alice'), array('author' => 'Bob')),
      'comment drift' => array(array('comment' => 'First'), array('comment' => 'Second')),
      'date drift' => array(array('date_creation' => '2024-01-01'), array('date_creation' => '2024-01-02')),
      'level drift' => array(array('level' => 2), array('level' => 4)),
      'tag_ids drift' => array(array('tag_ids' => '4,5'), array('tag_ids' => '4,6')),
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