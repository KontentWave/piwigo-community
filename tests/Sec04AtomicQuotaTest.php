<?php

use PHPUnit\Framework\TestCase;

class Sec04AtomicQuotaTest extends TestCase
{
  private function createDatabaseConnection()
  {
    $conf = array();
    include dirname(__DIR__, 3).'/local/config/database.inc.php';

    mysqli_report(MYSQLI_REPORT_OFF);
    $socket = str_starts_with($conf['db_host'], '/') ? $conf['db_host'] : null;
    $host = isset($socket) ? 'localhost' : $conf['db_host'];
    $connection = new mysqli($host, $conf['db_user'], $conf['db_password'], $conf['db_base'], 0, $socket);
    $this->assertSame(0, $connection->connect_errno, 'MariaDB connection is required for the SEC-04 concurrency gate');

    return $connection;
  }

  private function startConcurrencyWorker(array $arguments)
  {
    $command = array_merge(array(PHP_BINARY, __DIR__.'/fixtures/sec04_concurrency_worker.php'), $arguments);
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $this->assertIsResource($process);

    return array($process, $pipes);
  }

  private function finishConcurrencyWorker($process, array $pipes)
  {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $this->assertSame(0, proc_close($process), $stderr);

    $result = json_decode($stdout, true);
    $this->assertIsArray($result, $stdout.$stderr);
    return $result;
  }

  private function waitForBarrier($filepath)
  {
    $deadline = microtime(true) + 10;
    while (!is_file($filepath) && microtime(true) < $deadline)
    {
      usleep(10000);
    }

    $this->assertFileExists($filepath, 'Concurrency worker did not reach its lock barrier');
  }

  private function runDirectUploadFixture(array $input)
  {
    $command = array(PHP_BINARY, __DIR__.'/fixtures/sec03_upload_runner.php', json_encode($input));
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $this->assertIsResource($process);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $this->assertSame(0, proc_close($process), $stderr);

    $result = json_decode($stdout, true);
    $this->assertIsArray($result, $stdout.$stderr);
    return $result;
  }

  private function runMaintenanceFixture()
  {
    $tablePrefix = 'community_sec04_'.bin2hex(random_bytes(6));
    $command = array(PHP_BINARY, __DIR__.'/fixtures/sec04_maintenance_worker.php', $tablePrefix);
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $this->assertIsResource($process);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $this->assertSame(0, proc_close($process), $stderr);

    $result = json_decode($stdout, true);
    $this->assertIsArray($result, $stdout.$stderr);
    return array($tablePrefix, $result);
  }

  protected function setUp(): void
  {
    community_test_reset_runtime();
  }

  public function testExactFiniteLimitsAreAcceptedAndOneUnitOverIsRejected()
  {
    $this->assertTrue(community_quota_fits(4, 1024, 1, 512, 5, 1536));
    $this->assertFalse(community_quota_fits(4, 1024, 2, 512, 5, 1536));
    $this->assertFalse(community_quota_fits(4, 1024, 1, 513, 5, 1536));
  }

  public function testUnlimitedLimitsAndOverflowProneValuesFailSafely()
  {
    $this->assertTrue(community_quota_fits(PHP_INT_MAX, PHP_INT_MAX, 1, 1, null, null));
    $this->assertFalse(community_quota_fits(PHP_INT_MAX, 0, 1, 0, PHP_INT_MAX, null));
    $this->assertFalse(community_quota_fits(0, PHP_INT_MAX, 0, 1, null, PHP_INT_MAX));
    $this->assertFalse(community_quota_fits(0, 0, -1, 0, null, null));
  }

  public function testLogicalReservationIdentityIsStableAndConflictSensitive()
  {
    $first = community_quota_logical_key(2, 'async', 'upload-1', 'chunk:0:abc');
    $retry = community_quota_logical_key(2, 'async', 'upload-1', 'chunk:0:abc');
    $differentUpload = community_quota_logical_key(2, 'async', 'upload-2', 'chunk:0:abc');
    $conflict = community_quota_logical_key(2, 'async', 'upload-1', 'chunk:0:def');

    $this->assertSame($first, $retry);
    $this->assertNotSame($first, $differentUpload);
    $this->assertNotSame($first, $conflict);
  }

  public function testReplacementReservesOnlyPositiveStorageDeltaAndNoPhotoSlot()
  {
    $this->assertSame(
      array('photos' => 0, 'bytes' => 513),
      community_quota_upload_delta(1537, 1, 1)
    );
    $this->assertSame(
      array('photos' => 0, 'bytes' => 0),
      community_quota_upload_delta(1024, 2, 1)
    );
    $this->assertSame(
      array('photos' => 1, 'bytes' => 1537),
      community_quota_upload_delta(1537, null, null)
    );
    $this->assertSame(
      array('photos' => 0, 'bytes' => PHP_INT_MAX),
      community_quota_upload_delta(1, intdiv(PHP_INT_MAX, 1024) + 1, 1)
    );
    $GLOBALS['community_test']['fetch_assoc_return'] = array(array('filesize' => 1));
    $this->assertSame(
      array('photos' => 0, 'bytes' => 513),
      community_quota_persistence_delta(1537, null, 77, 'original.cr2')
    );
    $this->assertStringContainsString('FROM '.IMAGE_FORMAT_TABLE, end($GLOBALS['community_test']['queries']));
  }

  public function testCommunityMultipartClientDoesNotUseUnsupportedCoreChunkMode()
  {
    $template = file_get_contents(COMMUNITY_PATH.'template/add_photos.tpl');

    $this->assertStringContainsString('method=pwg.images.upload', $template);
    $this->assertStringNotContainsString('chunk_size:', $template);
  }

  public function testAdvisoryUsageIncludesCommittedAndActiveReservations()
  {
    $GLOBALS['community_quota_test_snapshot_callback'] = function ($userId, $transport, $logicalUploadId) {
      TestCase::assertSame(2, $userId);
      TestCase::assertSame('display', $transport);
      TestCase::assertSame('', $logicalUploadId);

      return array(
        'committed_photos' => 3,
        'committed_bytes' => 2048,
        'reserved_photos' => 2,
        'reserved_bytes' => 513,
      );
    };

    $this->assertSame(
      array('nb_photos' => 5, 'bytes' => 2561),
      community_quota_advisory_usage(2)
    );
  }

  public function testQuotaDenialDoesNotMutateUserRequestOrConfigurationGlobals()
  {
    global $conf, $user;

    $service = community_test_build_service('pwg.images.addSimple', array('category' => '1'));
    $_FILES['image'] = community_test_create_uploaded_chunk('immutable-upload', 'upload.jpg');
    $_GET = array('unchanged' => 'get');
    $_POST = array('unchanged' => 'post');
    $_REQUEST = array('unchanged' => 'request');
    $before = array($user, $_GET, $_POST, $_REQUEST, $conf);
    $GLOBALS['community_quota_transport_reserve_callback'] = function () {
      return community_quota_error();
    };

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1'));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame($before, array($user, $_GET, $_POST, $_REQUEST, $conf));
  }

  public function testQuotaSnapshotBypassesPermissionSessionCache()
  {
    $source = file_get_contents(COMMUNITY_PATH.'include/quota_reservation.inc.php');

    $this->assertStringContainsString('community_get_user_permissions($user_id, false)', $source);
  }

  public function testReservationLocksUserAndCommitsExactTarget()
  {
    $GLOBALS['community_quota_test_snapshot_callback'] = function () {
      return array(
        'photo_limit' => 5,
        'byte_limit' => 4096,
        'committed_photos' => 2,
        'committed_bytes' => 1024,
        'reserved_photos' => 1,
        'reserved_bytes' => 512,
        'existing' => null,
      );
    };

    $reservation = community_quota_reserve('direct', 'batch-1', 'request-a', 1, 1024, 60);

    $this->assertIsArray($reservation);
    $this->assertSame(1, $reservation['reserved_photos']);
    $this->assertSame(1024, $reservation['reserved_bytes']);
    $queries = implode("\n", $GLOBALS['community_test']['queries']);
    $this->assertMatchesRegularExpression(
      '/START TRANSACTION.*INSERT INTO '.preg_quote(COMMUNITY_QUOTA_LOCKS_TABLE, '/').'.*FOR UPDATE.*INSERT INTO '.preg_quote(COMMUNITY_QUOTA_RESERVATIONS_TABLE, '/').'.*COMMIT/s',
      $queries
    );
  }

  public function testDeniedReservationRollsBackWithoutConsumingCapacity()
  {
    $GLOBALS['community_quota_test_snapshot_callback'] = function () {
      return array(
        'photo_limit' => 3,
        'byte_limit' => 2048,
        'committed_photos' => 2,
        'committed_bytes' => 1024,
        'reserved_photos' => 1,
        'reserved_bytes' => 512,
        'existing' => null,
      );
    };

    $result = community_quota_reserve('simple', 'upload-1', 'request-a', 1, 1, 60);

    $this->assertInstanceOf(PwgError::class, $result);
    $queries = implode("\n", $GLOBALS['community_test']['queries']);
    $this->assertStringContainsString('ROLLBACK', $queries);
    $this->assertStringNotContainsString('INSERT INTO '.COMMUNITY_QUOTA_RESERVATIONS_TABLE, $queries);
  }

  public function testReleaseAndSettleUseTheSameUserSerializationBoundary()
  {
    $released = community_quota_release('direct', 'batch-1', 'request-a');
    $this->assertTrue($released);

    $releaseQueries = implode("\n", $GLOBALS['community_test']['queries']);
    $this->assertMatchesRegularExpression(
      '/START TRANSACTION.*'.preg_quote(COMMUNITY_QUOTA_LOCKS_TABLE, '/').'.*FOR UPDATE.*DELETE FROM '.preg_quote(COMMUNITY_QUOTA_RESERVATIONS_TABLE, '/').'.*COMMIT/s',
      $releaseQueries
    );

    $GLOBALS['community_test']['queries'] = array();
    $settled = community_quota_settle('async', 'upload-1', 'request-a');
    $this->assertTrue($settled);

    $settleQueries = implode("\n", $GLOBALS['community_test']['queries']);
    $this->assertMatchesRegularExpression(
      '/START TRANSACTION.*'.preg_quote(COMMUNITY_QUOTA_LOCKS_TABLE, '/').'.*FOR UPDATE.*DELETE FROM '.preg_quote(COMMUNITY_QUOTA_RESERVATIONS_TABLE, '/').'.*COMMIT/s',
      $settleQueries
    );
  }

  public function testAddSimpleQuotaDenialOccursBeforeDelegateOrSideEffects()
  {
    $service = community_test_build_service(
      'pwg.images.addSimple',
      array('category' => '1')
    );
    unset($GLOBALS['community_quota_uploaded_file_request_callback']);
    $_FILES['image'] = community_test_create_uploaded_chunk('quota-sized-image', 'upload.jpg');

    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) {
      TestCase::assertSame('addSimple', $transport);
      TestCase::assertNotSame('', $logicalUploadId);
      TestCase::assertNotSame('', $requestIdentity);
      TestCase::assertSame(1, $photos);
      TestCase::assertSame(strlen('quota-sized-image'), $bytes);

      return community_quota_error();
    };

    $result = $service->invoke('pwg.images.addSimple', array('category' => '1'));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(403, $result->code());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['single_updates']);
    $this->assertSame(array(), $GLOBALS['community_test']['metadata_sync_calls']);
  }

  public function testUploadRejectsRawBodyAndCoreChunkModeBeforeDelegate()
  {
    $service = community_test_build_service(
      'pwg.images.upload',
      array('category' => '1', 'name' => 'upload.jpg', 'pwg_token' => 'test-token')
    );
    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 456);
    };

    $rawResult = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $_FILES['file'] = community_test_create_uploaded_chunk('multipart-chunk', 'upload.jpg');
    $_REQUEST['chunk'] = 0;
    $_REQUEST['chunks'] = 2;
    $chunkedResult = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $rawResult);
    $this->assertInstanceOf(PwgError::class, $chunkedResult);
    $this->assertSame(0, $delegateCalls);
  }

  public function testUploadMultipartReservesObservedBytesBeforeDelegateAndSettles()
  {
    $payload = 'single-multipart-payload';
    $service = community_test_build_service(
      'pwg.images.upload',
      array('category' => '1', 'name' => 'upload.jpg', 'pwg_token' => 'test-token')
    );
    $_FILES['file'] = community_test_create_uploaded_chunk($payload, 'upload.jpg');
    unset($GLOBALS['community_quota_uploaded_file_request_callback']);

    $events = array();
    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use (&$events, $payload) {
      $events[] = 'reserve';
      TestCase::assertSame('upload', $transport);
      TestCase::assertSame(1, $photos);
      TestCase::assertSame(strlen($payload), $bytes);
      return array('reservation_id' => 'multipart', 'reserved_photos' => $photos, 'reserved_bytes' => $bytes);
    };
    $GLOBALS['community_ws_images_upload_delegate'] = function () use (&$events) {
      $events[] = 'delegate';
      return array('image_id' => 456, 'category' => array('id' => 1));
    };
    $GLOBALS['community_quota_transport_settle_callback'] = function ($transport) use (&$events) {
      $events[] = 'settle';
      TestCase::assertSame('upload', $transport);
      return true;
    };

    $result = $service->invoke('pwg.images.upload', array(
      'category' => '1',
      'name' => 'upload.jpg',
      'pwg_token' => 'test-token',
    ));

    $this->assertSame(456, $result['image_id']);
    $this->assertSame(array('reserve', 'delegate', 'settle'), $events);
  }

  public function testUploadAsyncQuotaDenialOccursBeforeChunkWriteOrDelegate()
  {
    $chunkContents = 'quota-async-chunk';
    $params = array(
      'chunk' => 0,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 2,
      'original_sum' => md5($chunkContents.'second'),
      'category' => '1',
      'filename' => 'upload.jpg',
    );
    $service = community_test_build_service('pwg.images.uploadAsync', $params);
    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);

    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use ($chunkContents) {
      TestCase::assertSame('uploadAsync', $transport);
      TestCase::assertNotSame('', $logicalUploadId);
      TestCase::assertNotSame('', $requestIdentity);
      TestCase::assertSame(1, $photos);
      TestCase::assertSame(strlen($chunkContents), $bytes);

      return community_quota_error();
    };

    $delegateCalls = 0;
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () use (&$delegateCalls) {
      $delegateCalls++;
      return array('image_id' => 654, 'message' => 'complete');
    };

    $result = $service->invoke('pwg.images.uploadAsync', $params);
    $paths = community_get_upload_async_state_paths(
      $params['original_sum'],
      $GLOBALS['user']['id'],
      community_get_upload_async_session_identity()
    );

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(403, $result->code());
    $this->assertSame(0, $delegateCalls);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertSame(array(), glob($paths['chunks_dir'].'/*.chunk') ?: array());
  }

  public function testUploadAsyncManifestFailureReleasesExactReservationAndArtifacts()
  {
    $chunkContents = 'quota-state-failure';
    $params = array(
      'chunk' => 0,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 2,
      'original_sum' => md5($chunkContents.'second'),
      'category' => '1',
      'filename' => 'upload.jpg',
    );
    $service = community_test_build_service('pwg.images.uploadAsync', $params);
    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);

    $reserved = array();
    $released = array();
    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use (&$reserved) {
      $reserved[] = func_get_args();
      return array('reservation_id' => 'reservation', 'reserved_photos' => $photos, 'reserved_bytes' => $bytes);
    };
    $GLOBALS['community_quota_transport_release_callback'] = function ($transport, $logicalUploadId, $requestIdentity) use (&$released) {
      $released[] = func_get_args();
      return true;
    };
    $GLOBALS['community_test_write_json_file_callback'] = function ($filepath) {
      return 'manifest.json' === basename($filepath) ? false : null;
    };

    $result = $service->invoke('pwg.images.uploadAsync', $params);
    $paths = community_get_upload_async_state_paths(
      $params['original_sum'],
      $GLOBALS['user']['id'],
      community_get_upload_async_session_identity()
    );

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(1, $reserved);
    $this->assertSame(array_slice($reserved[0], 0, 3), $released[0]);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertSame(array(), glob($paths['chunks_dir'].'/*.chunk') ?: array());
  }

  public function testUploadAsyncSuccessfulReceiptSettlesOnceAndRetryDoesNotReserveAgain()
  {
    $chunkContents = 'quota-complete';
    $params = array(
      'chunk' => 1,
      'chunk_sum' => md5($chunkContents),
      'chunks' => 1,
      'original_sum' => md5($chunkContents),
      'category' => '1',
      'filename' => 'upload.jpg',
    );
    $service = community_test_build_service('pwg.images.uploadAsync', $params);

    $reserveCalls = 0;
    $settled = array();
    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use (&$reserveCalls) {
      $reserveCalls++;
      return array('reservation_id' => 'reservation', 'reserved_photos' => $photos, 'reserved_bytes' => $bytes);
    };
    $GLOBALS['community_quota_transport_settle_callback'] = function ($transport, $logicalUploadId, $requestIdentity) use (&$settled) {
      $settled[] = func_get_args();
      return true;
    };
    $GLOBALS['community_ws_images_upload_async_delegate'] = function () {
      return array('image_id' => 700, 'message' => 'complete');
    };

    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $first = $service->invoke('pwg.images.uploadAsync', $params);
    $_FILES['file'] = community_test_create_uploaded_chunk($chunkContents);
    $retry = $service->invoke('pwg.images.uploadAsync', $params);

    $this->assertSame($first, $retry);
    $this->assertSame(2, $reserveCalls);
    $this->assertCount(1, $settled);
    $this->assertSame('uploadAsync', $settled[0][0]);
  }

  public function testLegacyAddChunkQuotaDenialOccursBeforeBufferWrite()
  {
    $chunkContents = 'quota-legacy-chunk';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');

    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use ($chunkContents, $originalSum) {
      TestCase::assertSame('addChunk', $transport);
      TestCase::assertSame($originalSum, $logicalUploadId);
      TestCase::assertNotSame('', $requestIdentity);
      TestCase::assertSame(1, $photos);
      TestCase::assertSame(strlen($chunkContents), $bytes);

      return community_quota_error();
    };

    $result = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    ));
    $paths = community_get_legacy_add_state_paths($originalSum);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(403, $result->code());
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertSame(array(), glob($paths['chunks_dir'].'/*.chunk') ?: array());
    $this->assertSame(array(), $GLOBALS['community_test']['add_uploaded_file_calls']);
  }

  public function testLegacyAddFinalizationRevalidatesExistingReservationAndSettlesOnce()
  {
    $chunkContents = 'quota-legacy-final';
    $originalSum = md5($chunkContents);
    $service = community_test_build_service('pwg.images.addChunk');
    $reservations = array();
    $settled = array();

    $GLOBALS['community_quota_transport_reserve_callback'] = function ($transport, $logicalUploadId, $requestIdentity, $photos, $bytes) use (&$reservations) {
      $reservations[] = func_get_args();
      return array('reservation_id' => 'reservation', 'reserved_photos' => $photos, 'reserved_bytes' => $bytes);
    };
    $GLOBALS['community_quota_transport_settle_callback'] = function ($transport, $logicalUploadId, $requestIdentity) use (&$settled) {
      $settled[] = func_get_args();
      return true;
    };

    $this->assertTrue($service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => $originalSum,
      'position' => 0,
    )));

    $result = $service->invoke('pwg.images.add', array(
      'original_sum' => $originalSum,
      'original_filename' => 'legacy.jpg',
      'categories' => '1',
      'check_uniqueness' => false,
    ));

    $this->assertSame(1, $result['image_id']);
    $this->assertCount(2, $reservations);
    $this->assertSame($reservations[0], $reservations[1]);
    $this->assertCount(1, $settled);
    $this->assertSame(array_slice($reservations[0], 0, 3), $settled[0]);
  }

  public function testMixedDirectBatchQuotaDenialPersistsNothing()
  {
    $result = $this->runDirectUploadFixture(array(
      'names' => array('first.jpg', 'second.jpg'),
      'contents' => array('first-payload', 'second-payload'),
      'quota_denied' => true,
    ));

    $this->assertCount(1, $result['side_effects']['quota_reservations']);
    $this->assertSame(2, $result['side_effects']['quota_reservations'][0]['photos']);
    $this->assertSame(strlen('first-payloadsecond-payload'), $result['side_effects']['quota_reservations'][0]['bytes']);
    $this->assertSame(array(), $result['side_effects']['add_uploaded_file']);
    $this->assertSame(array(), $result['side_effects']['database_writes']);
    $this->assertSame(array(), $result['side_effects']['hooks']);
    $this->assertSame(array(), $result['image_ids']);
    $this->assertSame(array(), $result['artifacts']);
  }

  public function testProductionRowLockAllowsOnlyTheValidConcurrentWinnerForPhotoAndByteLimits()
  {
    $connection = $this->createDatabaseConnection();

    foreach (array(
      'photos' => array(1, 1024 * 1024, 1, 0),
      'bytes' => array(10, 1024 * 1024, 0, 700000),
    ) as $case => $limits)
    {
      $tablePrefix = 'community_sec04_'.bin2hex(random_bytes(6));
      $readyFile = tempnam(sys_get_temp_dir(), 'sec04-ready-');
      $releaseFile = tempnam(sys_get_temp_dir(), 'sec04-release-');
      unlink($readyFile);
      unlink($releaseFile);

      try
      {
        $this->assertTrue($connection->query('CREATE TABLE `'.$tablePrefix.'_locks` (`user_id` mediumint unsigned NOT NULL, `updated_at` datetime NOT NULL, PRIMARY KEY (`user_id`)) ENGINE=InnoDB'));
        $this->assertTrue($connection->query('CREATE TABLE `'.$tablePrefix.'_reservations` (`reservation_id` char(32) NOT NULL, `user_id` mediumint unsigned NOT NULL, `transport` varchar(32) NOT NULL, `logical_upload_id` char(64) NOT NULL, `request_identity` char(64) NOT NULL, `reserved_photos` bigint unsigned NOT NULL, `reserved_bytes` bigint unsigned NOT NULL, `created_at` datetime NOT NULL, `refreshed_at` datetime NOT NULL, `expires_at` datetime NOT NULL, PRIMARY KEY (`reservation_id`), UNIQUE KEY `logical_upload` (`user_id`, `transport`, `logical_upload_id`), KEY `user_expiry` (`user_id`, `expires_at`)) ENGINE=InnoDB'));
        $this->assertTrue($connection->query('CREATE TABLE `'.$tablePrefix.'_images` (`id` mediumint unsigned NOT NULL AUTO_INCREMENT, `filesize` mediumint unsigned DEFAULT NULL, `added_by` mediumint unsigned NOT NULL, PRIMARY KEY (`id`), KEY `added_by` (`added_by`)) ENGINE=InnoDB'));
        $this->assertTrue($connection->query('CREATE TABLE `'.$tablePrefix.'_formats` (`image_id` mediumint unsigned NOT NULL, `ext` varchar(16) NOT NULL, `filesize` mediumint unsigned DEFAULT NULL, PRIMARY KEY (`image_id`, `ext`)) ENGINE=InnoDB'));

        list($firstProcess, $firstPipes) = $this->startConcurrencyWorker(array($tablePrefix, $limits[0], $limits[1], $limits[2], $limits[3], $readyFile, $releaseFile));
        $this->waitForBarrier($readyFile);
        list($secondProcess, $secondPipes) = $this->startConcurrencyWorker(array($tablePrefix, $limits[0], $limits[1], $limits[2], $limits[3], '-', '-'));

        file_put_contents($releaseFile, 'release');
        $first = $this->finishConcurrencyWorker($firstProcess, $firstPipes);
        $second = $this->finishConcurrencyWorker($secondProcess, $secondPipes);

        $this->assertTrue($first['accepted'], $case.' race first request should reserve');
        $this->assertFalse($second['accepted'], $case.' race second request must fail closed');
        $this->assertSame(403, $second['code']);
      }
      finally
      {
        $connection->query('DROP TABLE IF EXISTS `'.$tablePrefix.'_formats`');
        $connection->query('DROP TABLE IF EXISTS `'.$tablePrefix.'_reservations`');
        $connection->query('DROP TABLE IF EXISTS `'.$tablePrefix.'_locks`');
        $connection->query('DROP TABLE IF EXISTS `'.$tablePrefix.'_images`');
        @unlink($readyFile);
        @unlink($releaseFile);
      }
    }

    $connection->close();
  }

  public function testMaintenanceCreatesUpdatesPreservesAndUninstallsQuotaSchema()
  {
    list($tablePrefix, $result) = $this->runMaintenanceFixture();

    $this->assertSame(array(), $result['errors']);
    $this->assertSame(1, $result['permission_count']);
    $this->assertSame(1, $result['reservation_count']);
    $this->assertSame('InnoDB', $result['engines'][$tablePrefix.'_community_quota_locks']);
    $this->assertSame('InnoDB', $result['engines'][$tablePrefix.'_community_quota_reservations']);
    $this->assertContains('logical_upload', $result['indexes']);
    $this->assertContains('user_expiry', $result['indexes']);
    $this->assertContains('expires_at', $result['indexes']);
    $this->assertTrue($result['quota_tables_removed']);
  }
}