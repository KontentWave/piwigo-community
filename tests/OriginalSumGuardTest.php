<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OriginalSumGuardTest extends TestCase
{
  protected function setUp(): void
  {
    community_test_reset_runtime();
  }

  public function testHelperLifecycleRegistersScopedWrappersWithoutStatusElevation()
  {
    global $user;

    $service = community_test_build_service('pwg.images.checkUpload');

    $this->assertSame('community_ws_images_exist', community_test_get_registered_method($service, 'pwg.images.exist')['callback']);
    $this->assertSame('community_ws_images_check_files', community_test_get_registered_method($service, 'pwg.images.checkFiles')['callback']);
    $this->assertSame('community_ws_images_check_upload', community_test_get_registered_method($service, 'pwg.images.checkUpload')['callback']);
    $this->assertSame('community_ws_session_get_status', community_test_get_registered_method($service, 'pwg.session.getStatus')['callback']);
    $this->assertSame('normal', $user['status']);
    $this->assertSame(array('normal'), $GLOBALS['community_test']['later_handler_statuses']);
    $this->assertSame(array(2), $GLOBALS['community_test']['two_factor_eligible_checks']);

    $result = $service->invoke('pwg.images.checkUpload', array());

    $this->assertSame(array('ready_for_upload' => true, 'message' => ''), $result);
    $this->assertCount(1, $GLOBALS['community_test']['helper_delegate_calls']['pwg.images.checkUpload']);
    $this->assertSame('normal', $user['status']);
  }

  public function testHelperLifecycleSeparatesUploadAndCategoryCreationCapabilities()
  {
    $service = community_test_build_service('pwg.images.checkUpload');
    $_SESSION['community_user_permissions']['upload_categories'] = array();
    $_SESSION['community_user_permissions']['create_categories'] = array(1);

    $checkUpload = $service->invoke('pwg.images.checkUpload', array());
    $status = $service->invoke('pwg.session.getStatus', array());

    $this->assertInstanceOf(PwgError::class, $checkUpload);
    $this->assertCount(0, $GLOBALS['community_test']['helper_delegate_calls']['pwg.images.checkUpload']);
    $this->assertSame('normal', $status['status']);
    $this->assertArrayNotHasKey('upload_file_types', $status);
    $this->assertArrayNotHasKey('upload_form_chunk_size', $status);
  }

  public function testSessionStatusPreservesRealIdentityAndAddsOnlyUploaderConfiguration()
  {
    $service = community_test_build_service('pwg.session.getStatus');

    $result = $service->invoke('pwg.session.getStatus', array());

    $this->assertSame('normal', $result['status']);
    $this->assertSame('contributor', $result['username']);
    $this->assertSame('jpg,jpeg,png', $result['upload_file_types']);
    $this->assertSame(512, $result['upload_form_chunk_size']);
    $this->assertSame(array('square', 'medium'), $result['available_sizes']);
    $this->assertCount(1, $GLOBALS['community_test']['helper_delegate_calls']['pwg.session.getStatus']);
  }

  public function testSessionStatusPreservesRemoteSyncCompatibility()
  {
    $_SERVER['HTTP_USER_AGENT'] = 'PiwigoRemoteSync/2.0';
    $service = community_test_build_service('pwg.session.getStatus');
    $_SERVER['HTTP_USER_AGENT'] = 'PiwigoRemoteSync/2.0';

    $result = $service->invoke('pwg.session.getStatus', array());

    $this->assertSame('normal', $result['status']);
    $this->assertArrayNotHasKey('save_visits', $result);
    $this->assertArrayNotHasKey('connected_with', $result);
    $this->assertSame('jpg,jpeg,png', $result['upload_file_types']);
  }

  public function testHelperGrantRevocationIsHonoredImmediatelyBeforeDisclosure()
  {
    $service = community_test_build_service('pwg.images.checkUpload');
    $_SESSION['community_user_permissions']['upload_categories'] = array();

    $result = $service->invoke('pwg.images.checkUpload', array());

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(0, $GLOBALS['community_test']['helper_delegate_calls']['pwg.images.checkUpload']);
  }

  public function testImagesExistSafelyReturnsOnlyEligibleOwnedMatches()
  {
    $service = community_test_build_service('pwg.images.exist');
    $GLOBALS['community_test']['query2array_returns'] = array(
      array(array('id' => 12, 'md5sum' => md5('owned'))),
      array(array('id' => 12, 'added_by' => 2, 'category_id' => 1)),
    );

    $result = $service->invoke('pwg.images.exist', array('md5sum_list' => md5('owned').' '.md5('new')));

    $this->assertSame(array(md5('owned') => 12, md5('new') => null), $result);
    $this->assertCount(0, $GLOBALS['community_test']['helper_delegate_calls']['pwg.images.exist']);
    $this->assertSame(array(md5('owned'), md5('new')), $GLOBALS['community_test']['escaped_values']);
  }

  public function testImagesExistRejectsMalformedAndMixedForeignMatchesAtomically()
  {
    $service = community_test_build_service('pwg.images.exist');

    $malformed = $service->invoke('pwg.images.exist', array('md5sum_list' => array(md5('owned'))));
    $this->assertInstanceOf(PwgError::class, $malformed);
    $this->assertCount(0, $GLOBALS['community_test']['queries']);

    $GLOBALS['community_test']['query2array_returns'] = array(
      array(
        array('id' => 12, 'md5sum' => md5('owned')),
        array('id' => 13, 'md5sum' => md5('foreign')),
      ),
      array(
        array('id' => 12, 'added_by' => 2, 'category_id' => 1),
        array('id' => 13, 'added_by' => 9, 'category_id' => 1),
      ),
    );

    $mixed = $service->invoke('pwg.images.exist', array('md5sum_list' => md5('owned').','.md5('foreign')));
    $this->assertInstanceOf(PwgError::class, $mixed);
  }

  public function testImagesExistFilenameModeEscapesEveryCandidate()
  {
    global $conf;

    $service = community_test_build_service('pwg.images.exist');
    $conf['uniqueness_mode'] = 'filename';
    $GLOBALS['community_test']['query2array_returns'] = array(array());
    $filenames = "quote'jpg|back\\slash.jpg;plain.jpg";

    $result = $service->invoke('pwg.images.exist', array('filename_list' => $filenames));

    $this->assertSame(array("quote'jpg" => null, 'back\\slash.jpg' => null, 'plain.jpg' => null), $result);
    $this->assertSame(array("quote'jpg", 'back\\slash.jpg', 'plain.jpg'), $GLOBALS['community_test']['escaped_values']);
  }

  public function testImagesExistRejectsUnauthorizedAlbumAndGenericSessionBeforeReturningIds()
  {
    global $user;

    $service = community_test_build_service('pwg.images.exist');
    $GLOBALS['community_test']['query2array_returns'] = array(
      array(array('id' => 12, 'md5sum' => md5('owned'))),
      array(array('id' => 12, 'added_by' => 2, 'category_id' => 9)),
    );
    $wrongAlbum = $service->invoke('pwg.images.exist', array('md5sum_list' => md5('owned')));
    $this->assertInstanceOf(PwgError::class, $wrongAlbum);

    $user['status'] = 'generic';
    $GLOBALS['community_test']['query2array_returns'] = array(
      array(array('id' => 12, 'md5sum' => md5('owned'))),
      array(array('id' => 12, 'added_by' => 2, 'category_id' => 1)),
      array(),
    );
    $wrongSession = $service->invoke('pwg.images.exist', array('md5sum_list' => md5('owned')));
    $this->assertInstanceOf(PwgError::class, $wrongSession);
  }

  public function testCheckFilesAuthorizesBeforeHashingAndPreservesCompatibility()
  {
    $service = community_test_build_service('pwg.images.checkFiles');
    $path = tempnam(sys_get_temp_dir(), 'community-check-files-');
    file_put_contents($path, 'owned-file');
    $GLOBALS['community_test']['temporary_files'][] = $path;
    $GLOBALS['community_test']['query2array_returns'] = array(
      array(array('id' => 12, 'added_by' => 2, 'category_id' => 1)),
      array(array('path' => $path)),
    );

    $result = $service->invoke('pwg.images.checkFiles', array(
      'image_id' => '12',
      'file_sum' => md5('owned-file'),
      'thumbnail_sum' => md5('legacy-thumb'),
    ));

    $this->assertSame(array('thumbnail' => 'equals', 'file' => 'equals'), $result);
    $this->assertCount(0, $GLOBALS['community_test']['helper_delegate_calls']['pwg.images.checkFiles']);
  }

  public function testCheckFilesRejectsMalformedChecksumAndForeignObjectBeforePathLookup()
  {
    $service = community_test_build_service('pwg.images.checkFiles');

    $malformed = $service->invoke('pwg.images.checkFiles', array('image_id' => '12', 'file_sum' => '../bad'));
    $this->assertInstanceOf(PwgError::class, $malformed);
    $this->assertCount(0, $GLOBALS['community_test']['queries']);

    $GLOBALS['community_test']['query2array_returns'] = array(array(array('id' => 12, 'added_by' => 9, 'category_id' => 1)));
    $foreign = $service->invoke('pwg.images.checkFiles', array('image_id' => '12', 'file_sum' => md5('file')));
    $this->assertInstanceOf(PwgError::class, $foreign);
    $this->assertCount(1, $GLOBALS['community_test']['queries']);
  }

  public function testCheckFilesRejectsCategorylessImageWithoutAuthorizedLoungeProvenance()
  {
    $service = community_test_build_service('pwg.images.checkFiles');
    $GLOBALS['community_test']['query2array_returns'] = array(
      array(array('id' => 12, 'added_by' => 2, 'category_id' => null, 'lounge_category_id' => null)),
    );

    $result = $service->invoke('pwg.images.checkFiles', array('image_id' => '12', 'file_sum' => md5('file')));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(1, $GLOBALS['community_test']['queries']);
  }

  public function testHelperAdministratorAndExplicitBypassKeepCoreCallbacks()
  {
    $admin = community_test_build_service('pwg.images.exist', array(), true);
    $this->assertSame('community_test_ws_images_exist', community_test_get_registered_method($admin, 'pwg.images.exist')['callback']);
    $this->assertSame('community_test_ws_session_getStatus', community_test_get_registered_method($admin, 'pwg.session.getStatus')['callback']);

    $bypass = community_test_build_service('pwg.images.checkFiles', array('faked_by_community' => 'false'));
    $this->assertSame('community_test_ws_images_checkFiles', community_test_get_registered_method($bypass, 'pwg.images.checkFiles')['callback']);
  }

  public function testAmbientElevationImplementationIsRemoved()
  {
    $main = file_get_contents(dirname(__DIR__).'/main.inc.php');

    $this->assertStringNotContainsString('community_switch_user_to_admin', $main);
    $this->assertStringNotContainsString("\$user['status'] = 'admin'", $main);
  }

  public function testContentCreationLifecycleRegistersPostOnlyCommunityWrappers()
  {
    $service = community_test_build_service('pwg.categories.add', array('name' => 'Child', 'parent' => 1));

    $categoryMethod = community_test_get_registered_method($service, 'pwg.categories.add');
    $tagMethod = community_test_get_registered_method($service, 'pwg.tags.add');

    $this->assertSame('community_ws_categories_add', $categoryMethod['callback']);
    $this->assertSame(array('post_only' => true), $categoryMethod['options']);
    $this->assertSame('community_ws_tags_add', $tagMethod['callback']);
    $this->assertSame(array('post_only' => true), $tagMethod['options']);
    $this->assertArrayHasKey('pwg_token', $tagMethod['signature']);
  }

  public function testAuthorizedContentCreationDelegatesOnceWithoutGlobalMutation()
  {
    global $conf, $user;

    $service = community_test_build_service('pwg.categories.add', array('name' => 'Child', 'parent' => 1));
    $_SESSION['community_user_permissions']['create_categories'] = array(1);
    $status = $user['status'];
    $request = $_REQUEST;
    $post = $_POST;
    $configuration = $conf;

    $categoryResult = $service->invoke('pwg.categories.add', array(
      'name' => '<b>Child</b><script>bad()</script>',
      'parent' => 1,
      'comment' => '<em>Comment</em><script>bad()</script>',
      'visible' => true,
      'status' => 'public',
      'commentable' => true,
      'pwg_token' => 'test-token',
    ));

    $this->assertSame(21, $categoryResult['id']);
    $this->assertCount(1, $GLOBALS['community_test']['category_delegate_calls']);
    $this->assertSame('Childbad()', $GLOBALS['community_test']['category_delegate_calls'][0]['name']);
    $this->assertSame('Commentbad()', $GLOBALS['community_test']['category_delegate_calls'][0]['comment']);
    $this->assertSame(1, $GLOBALS['community_test']['community_cache_invalidations']);
    $this->assertSame($status, $user['status']);
    $this->assertSame($request, $_REQUEST);
    $this->assertSame($post, $_POST);
    $this->assertNotSame($configuration['community_cache_key'], $conf['community_cache_key']);
    unset($configuration['community_cache_key'], $conf['community_cache_key']);
    $this->assertSame($configuration, $conf);

    $service = community_test_build_service('pwg.tags.add', array('name' => 'Landscape'));
    $tagResult = $service->invoke('pwg.tags.add', array('name' => 'Landscape', 'pwg_token' => 'test-token'));

    $this->assertSame(31, $tagResult['id']);
    $this->assertCount(1, $GLOBALS['community_test']['tag_delegate_calls']);
    $this->assertCount(1, $GLOBALS['community_test']['activity_calls']);
    $this->assertSame('normal', $user['status']);
  }

  #[DataProvider('provideDeniedCategoryCreationRequests')]
  public function testCategoryCreationRejectsInvalidAuthorizationAndInputsBeforeDelegation($permissions, $params)
  {
    $service = community_test_build_service('pwg.categories.add', $params);
    $_SESSION['community_user_permissions'] = array_merge($_SESSION['community_user_permissions'], $permissions);

    $result = $service->invoke('pwg.categories.add', $params);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(0, $GLOBALS['community_test']['category_delegate_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['invalidate_user_cache_calls']);
  }

  public function testCategoryCreationSupportsAuthorizedRoot()
  {
    $service = community_test_build_service('pwg.categories.add', array('name' => 'Root'));
    $_SESSION['community_user_permissions']['create_whole_gallery'] = true;

    $root = $service->invoke('pwg.categories.add', array('name' => 'Root', 'parent' => 0, 'pwg_token' => 'test-token'));

    $this->assertSame(21, $root['id']);
    $this->assertCount(1, $GLOBALS['community_test']['category_delegate_calls']);
  }

  public function testContentCreationRejectsStaleTokenBeforeDelegation()
  {
    $service = community_test_build_service('pwg.categories.add', array('name' => 'Child', 'parent' => 1));
    $_SESSION['community_user_permissions']['create_categories'] = array(1);
    $GLOBALS['community_test']['pwg_token'] = 'rotated-token';

    $categoryResult = $service->invoke('pwg.categories.add', array('name' => 'Child', 'parent' => 1, 'pwg_token' => 'test-token'));
    $tagResult = $service->invoke('pwg.tags.add', array('name' => 'Landscape', 'pwg_token' => 'test-token'));

    $this->assertInstanceOf(PwgError::class, $categoryResult);
    $this->assertInstanceOf(PwgError::class, $tagResult);
    $this->assertCount(0, $GLOBALS['community_test']['category_delegate_calls']);
    $this->assertCount(0, $GLOBALS['community_test']['tag_delegate_calls']);
    $this->assertCount(0, $GLOBALS['community_test']['activity_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['community_cache_invalidations']);
  }

  public function testCategoryCreationRechecksAuthorizationBeforeDelegation()
  {
    $service = community_test_build_service('pwg.categories.add', array('name' => 'Child', 'parent' => 1));
    $_SESSION['community_user_permissions']['create_categories'] = array(1);
    $GLOBALS['community_test']['category_before_second_authorization'] = function () {
      $_SESSION['community_user_permissions']['create_categories'] = array();
    };

    $result = $service->invoke('pwg.categories.add', array('name' => 'Child', 'parent' => 1, 'pwg_token' => 'test-token'));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(0, $GLOBALS['community_test']['category_delegate_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['community_cache_invalidations']);
  }

  public function testTagCreationRechecksUploadGrantBeforeDelegation()
  {
    $service = community_test_build_service('pwg.tags.add', array('name' => 'Landscape'));
    $permissionReads = 0;
    $GLOBALS['community_test']['query_callback'] = function ($query) use (&$permissionReads) {
      if (false !== strpos($query, COMMUNITY_PERMISSIONS_TABLE))
      {
        $permissionReads++;
      }

      return $query;
    };
    $GLOBALS['community_test']['tag_before_second_authorization'] = function () {
      $_SESSION['community_user_permissions']['upload_categories'] = array();
    };

    $result = community_ws_tags_add(array('name' => 'Landscape', 'pwg_token' => 'test-token'), $service);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(0, $GLOBALS['community_test']['tag_delegate_calls']);
  }

  #[DataProvider('provideDeniedTagCreationRequests')]
  public function testTagCreationRequiresUploadGrantValidNameAndToken($permissions, $params)
  {
    $service = community_test_build_service('pwg.tags.add', $params);
    $_SESSION['community_user_permissions'] = array_merge($_SESSION['community_user_permissions'], $permissions);

    $result = $service->invoke('pwg.tags.add', $params);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertCount(0, $GLOBALS['community_test']['tag_delegate_calls']);
    $this->assertCount(0, $GLOBALS['community_test']['activity_calls']);
  }

  public function testContentCreationAdministratorAndExplicitBypassKeepCoreCallbacks()
  {
    $admin = community_test_build_service('pwg.categories.add', array('name' => 'Admin', 'position' => 'first'), true);
    $this->assertSame('community_test_ws_categories_add', community_test_get_registered_method($admin, 'pwg.categories.add')['callback']);
    $this->assertSame(array('admin_only' => true), community_test_get_registered_method($admin, 'pwg.categories.add')['options']);
    $this->assertSame('community_test_ws_tags_add', community_test_get_registered_method($admin, 'pwg.tags.add')['callback']);

    $bypass = community_test_build_service('pwg.tags.add', array('name' => 'Tag', 'faked_by_community' => 'false'));
    $this->assertSame('community_test_ws_categories_add', community_test_get_registered_method($bypass, 'pwg.categories.add')['callback']);
    $this->assertSame('community_test_ws_tags_add', community_test_get_registered_method($bypass, 'pwg.tags.add')['callback']);
  }

  public function testBrowserAlbumCreationSendsTokenAndHandlesStructuredFailure()
  {
    $template = file_get_contents(dirname(__DIR__).'/template/add_photos.tpl');

    $this->assertStringContainsString('pwg_token: pwg_token', $template);
    $this->assertStringContainsString('dataType: "json"', $template);
    $this->assertStringContainsString('responseJSON', $template);
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
    $coreCompletionMethod = community_test_get_registered_method($service, 'pwg.images.uploadCompleted');
    $communityCompletionMethod = community_test_get_registered_method($service, 'community.images.uploadCompleted');

    $this->assertSame('community_ws_images_add_simple', $addSimpleMethod['callback']);
    $this->assertSame(array('post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('community_ws_images_add', $addMethod['callback']);
    $this->assertSame('community_ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('community_ws_images_upload', $uploadMethod['callback']);
    $this->assertSame(array('post_only' => true), $uploadMethod['options']);
    $this->assertSame('community_ws_images_upload_async', $uploadAsyncMethod['callback']);
    $this->assertSame(array('post_only' => true), $uploadAsyncMethod['options']);
    $this->assertSame(array(), $addMethod['options']);
    $this->assertSame(array('post_only' => true), $chunkMethod['options']);
    $this->assertSame('community_ws_images_upload_completed', $coreCompletionMethod['callback']);
    $this->assertSame(array('post_only' => true), $coreCompletionMethod['options']);
    $this->assertSame('community_ws_images_upload_completed_compat', $communityCompletionMethod['callback']);
    $this->assertSame(array('post_only' => true), $communityCompletionMethod['options']);
  }

  public function testNonAdminMutationLifecycleRegistersCommunityWrappersWithoutAmbientMutation()
  {
    global $conf, $user;

    $main = file_get_contents(dirname(__DIR__).'/main.inc.php');
    $this->assertStringNotContainsString("'pwg.images.setInfo' == \$_REQUEST['method']", $main);
    $this->assertStringNotContainsString("\$conf['available_permission_levels'][] = 16", $main);

    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => '12'));
    $status = $user['status'];
    $post = $_POST;
    $request = $_REQUEST;
    $configuration = $conf;

    $setInfoMethod = community_test_get_registered_method($service, 'pwg.images.setInfo');
    $deleteMethod = community_test_get_registered_method($service, 'pwg.images.delete');

    $this->assertSame('community_ws_images_set_info', $setInfoMethod['callback']);
    $this->assertSame($GLOBALS['community_test']['core_mutation_methods']['pwg.images.setInfo']['signature'], $setInfoMethod['signature']);
    $this->assertSame(array('post_only' => true), $setInfoMethod['options']);
    $this->assertSame('community_ws_images_delete', $deleteMethod['callback']);
    $this->assertSame($GLOBALS['community_test']['core_mutation_methods']['pwg.images.delete']['signature'], $deleteMethod['signature']);
    $this->assertSame(array('post_only' => true), $deleteMethod['options']);
    $this->assertSame($status, $user['status']);
    $this->assertSame($post, $_POST);
    $this->assertSame($request, $_REQUEST);
    $this->assertSame($configuration, $conf);
  }

  public function testMutationLifecycleAllowsOwnedEligibleEditAndMultiImageDeleteExactlyOnce()
  {
    global $conf, $user;

    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => '12'));
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);
    $this->configureMutationFixture(array(
      12 => array('added_by' => 2, 'categories' => array(1, 2)),
      13 => array('added_by' => 2, 'categories' => array(2)),
    ));
    $status = $user['status'];
    $post = $_POST;
    $request = $_REQUEST;
    $configuration = $conf;
    $setInfoCalls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = function ($params) use (&$setInfoCalls, &$user, $status) {
      $setInfoCalls++;
      TestCase::assertSame($status, $user['status']);
      TestCase::assertSame(12, $params['image_id']);
      TestCase::assertSame('<b>safe</b>bad()', $params['name']);
      TestCase::assertArrayNotHasKey('level', $params);
      return 'edited';
    };

    $editResult = $service->invoke('pwg.images.setInfo', array(
      'image_id' => '12',
      'name' => '<b>safe</b><script>bad()</script>',
      'level' => 0,
      'single_value_mode' => 'replace',
      'multiple_value_mode' => 'append',
    ));

    $this->assertSame('edited', $editResult);
    $this->assertSame(1, $setInfoCalls);
    $this->assertSame($status, $user['status']);
    $this->assertSame($post, $_POST);
    $this->assertSame($request, $_REQUEST);
    $this->assertSame($configuration, $conf);

    $service = community_test_build_service('pwg.images.delete', array('image_id' => array('12', '13')));
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);
    $this->configureMutationFixture(array(
      12 => array('added_by' => 2, 'categories' => array(1, 2)),
      13 => array('added_by' => 2, 'categories' => array(2)),
    ));
    $deleteCalls = 0;
    $GLOBALS['community_ws_images_delete_delegate'] = function ($params) use (&$deleteCalls) {
      $deleteCalls++;
      TestCase::assertSame(array(12, 13), $params['image_id']);
      return 2;
    };

    $deleteResult = $service->invoke('pwg.images.delete', array(
      'image_id' => '12,13',
      'pwg_token' => 'test-token',
    ));

    $this->assertSame(2, $deleteResult);
    $this->assertSame(1, $deleteCalls);
    $this->assertSame('normal', $user['status']);
  }

  #[DataProvider('provideInvalidMutationImageIds')]
  public function testMutationLifecycleRejectsMalformedImageIdsWithoutDelegation($method, $imageIds)
  {
    $request = array('image_id' => $imageIds);
    if ('pwg.images.delete' === $method)
    {
      $request['pwg_token'] = 'test-token';
    }
    $service = community_test_build_service($method, $request);
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = $GLOBALS['community_ws_images_delete_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke($method, $request);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(0, $calls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testMutationIdNormalizerRejectsOverflowWithoutLossyConversion()
  {
    $this->assertNull(community_normalize_mutation_image_id((string) PHP_INT_MAX.'0'));
  }

  #[DataProvider('provideDeniedMutationTargets')]
  public function testMutationLifecycleRejectsMissingForeignMixedAndWrongAlbumTargetsAtomically($method, $images, $target)
  {
    $request = array('image_id' => $target);
    if ('pwg.images.delete' === $method)
    {
      $request['pwg_token'] = 'test-token';
    }
    $service = community_test_build_service($method, $request);
    $this->configureMutationFixture($images);
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = $GLOBALS['community_ws_images_delete_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke($method, $request);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame('Access denied', $result->message());
    $this->assertSame(0, $calls);
    $this->assertNoMutationSideEffects();
  }

  #[DataProvider('provideMutationMethods')]
  public function testMutationLifecycleRejectsCategoryCreationOnlyUserBeforeObjectQuery($method)
  {
    $params = array('image_id' => 12);
    if ('pwg.images.delete' === $method)
    {
      $params['pwg_token'] = 'test-token';
    }
    $service = community_test_build_service($method, $params);
    $_SESSION['community_user_permissions']['upload_categories'] = array();
    $_SESSION['community_user_permissions']['create_categories'] = array(1);
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = $GLOBALS['community_ws_images_delete_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke($method, $params);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame(0, $calls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
  }

  public function testSetInfoLifecyclePreservesAuthorizedCategoryAndTagMutationCompatibility()
  {
    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => 12));
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);
    $this->configureMutationFixture(
      array(12 => array('added_by' => 2, 'categories' => array(1))),
      array(1, 2),
      array(4, 5)
    );
    $calls = array();
    $GLOBALS['community_ws_images_set_info_delegate'] = function ($params) use (&$calls) {
      $calls[] = $params;
      return null;
    };

    $appendResult = $service->invoke('pwg.images.setInfo', array(
      'image_id' => 12,
      'categories' => '2,auto',
      'tag_ids' => '4,5',
      'multiple_value_mode' => 'append',
    ));
    $replaceResult = $service->invoke('pwg.images.setInfo', array(
      'image_id' => 12,
      'categories' => '1,3;2',
      'tag_ids' => '',
      'multiple_value_mode' => 'replace',
    ));

    $this->assertNull($appendResult);
    $this->assertNull($replaceResult);
    $this->assertCount(2, $calls);
    $this->assertSame('2,auto', $calls[0]['categories']);
    $this->assertSame('4,5', $calls[0]['tag_ids']);
    $this->assertSame('', $calls[1]['tag_ids']);
  }

  #[DataProvider('provideInvalidSetInfoMutations')]
  public function testSetInfoLifecycleRejectsInvalidModesCategoriesTagsAndFieldsBeforeDelegation($params)
  {
    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => 12));
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);
    $this->configureMutationFixture(
      array(12 => array('added_by' => 2, 'categories' => array(1))),
      array(1, 2),
      array(4, 5)
    );
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke('pwg.images.setInfo', array_merge(array('image_id' => 12), $params));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(0, $calls);
    $this->assertNoMutationSideEffects();
  }

  public function testSetInfoLifecycleRejectsRequestGlobalTagListWithoutChangingIt()
  {
    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => 12));
    $_REQUEST['tag_list'] = array('<script>new tag</script>');
    $request = $_REQUEST;
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke('pwg.images.setInfo', array('image_id' => 12));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(0, $calls);
    $this->assertSame($request, $_REQUEST);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertNoMutationSideEffects();
  }

  #[DataProvider('provideMutationMethods')]
  public function testGenericMutationLifecycleRequiresCurrentSessionProvenance($method)
  {
    global $user;

    $params = array('image_id' => 12);
    if ('pwg.images.delete' === $method)
    {
      $params['pwg_token'] = 'test-token';
    }
    $service = community_test_build_service($method, $params);
    $user['status'] = 'generic';
    $this->configureMutationFixture(array(
      12 => array('added_by' => 2, 'categories' => array(1), 'current_session' => false),
    ));
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = $GLOBALS['community_ws_images_delete_delegate'] = function () use (&$calls) {
      $calls++;
      return 1;
    };

    $denied = $service->invoke($method, $params);
    $this->assertInstanceOf(PwgError::class, $denied);
    $this->assertSame(0, $calls);

    $this->configureMutationFixture(array(
      12 => array('added_by' => 2, 'categories' => array(1), 'current_session' => true),
    ));
    $allowed = $service->invoke($method, $params);
    $this->assertSame(1, $allowed);
    $this->assertSame(1, $calls);
  }

  public function testMutationLifecycleRechecksRevokedGrantImmediatelyBeforeDelegation()
  {
    $service = community_test_build_service('pwg.images.setInfo', array('image_id' => 12));
    $_SESSION['community_user_permissions']['upload_categories'] = array(1, 2);
    $this->configureMutationFixture(
      array(12 => array('added_by' => 2, 'categories' => array(1))),
      array(2),
      array(),
      function ($query) {
        if (false !== strpos($query, 'FROM '.CATEGORIES_TABLE))
        {
          $_SESSION['community_user_permissions']['upload_categories'] = array();
        }
      }
    );
    $calls = 0;
    $GLOBALS['community_ws_images_set_info_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke('pwg.images.setInfo', array(
      'image_id' => 12,
      'categories' => '2',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(401, $result->code());
    $this->assertSame(0, $calls);
  }

  #[DataProvider('provideInvalidDeleteTokens')]
  public function testDeleteLifecycleRejectsInvalidTokenBeforeAuthorizationQueries($token, $includeToken)
  {
    $params = array('image_id' => 12);
    if ($includeToken)
    {
      $params['pwg_token'] = $token;
    }
    $service = community_test_build_service('pwg.images.delete', $params);
    $calls = 0;
    $GLOBALS['community_ws_images_delete_delegate'] = function () use (&$calls) {
      $calls++;
    };

    $result = $service->invoke('pwg.images.delete', $params);

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(0, $calls);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertNoMutationSideEffects();
  }

  public function testMutationLifecycleAdministratorAndExplicitBypassKeepCoreCallbacks()
  {
    $adminService = community_test_build_service('pwg.images.setInfo', array('image_id' => 12), true);
    $this->assertSame('community_test_ws_images_setInfo', community_test_get_registered_method($adminService, 'pwg.images.setInfo')['callback']);
    $this->assertSame('community_test_ws_images_delete', community_test_get_registered_method($adminService, 'pwg.images.delete')['callback']);

    $bypassService = community_test_build_service('pwg.images.delete', array(
      'image_id' => 12,
      'pwg_token' => 'test-token',
      'faked_by_community' => 'false',
    ));
    $this->assertSame('community_test_ws_images_setInfo', community_test_get_registered_method($bypassService, 'pwg.images.setInfo')['callback']);
    $this->assertSame('community_test_ws_images_delete', community_test_get_registered_method($bypassService, 'pwg.images.delete')['callback']);
  }

  public function testBrowserUsesOneAuthoritativeCompletionRequestWithFailureHandling()
  {
    $template = file_get_contents(dirname(__DIR__).'/template/add_photos.tpl');

    $this->assertSame(1, substr_count($template, 'method=community.images.uploadCompleted'));
    $this->assertSame(0, substr_count($template, 'method=pwg.images.uploadCompleted'));
    $this->assertStringContainsString('completionFailed', $template);
  }

  public function testUploadCompletedLifecycleScopesFinalizationAndPreservesStatus()
  {
    global $user;

    $images = array(
      12 => array('added_by' => 2, 'associated' => false, 'lounge' => true, 'state' => 'moderation_pending', 'notified_on' => null),
      13 => array('added_by' => 2, 'associated' => true, 'lounge' => false, 'state' => null, 'notified_on' => null),
      99 => array('added_by' => 3, 'associated' => false, 'lounge' => true, 'state' => null, 'notified_on' => null),
    );
    $service = community_test_build_service('pwg.images.uploadCompleted');
    $this->configureCompletionFixture($images);
    $status = $user['status'];

    $result = $service->invoke('pwg.images.uploadCompleted', array(
      'image_id' => '13,12',
      'category_id' => 1,
      'pwg_token' => 'test-token',
    ));

    $this->assertSame($status, $user['status']);
    $this->assertSame(array(array('image_id' => 12, 'category_id' => 1)), $result['moved_from_lounge']);
    $this->assertSame(array(array(array(12), array(1))), $GLOBALS['community_test']['associate_images_to_categories_calls']);
    $this->assertTrue($GLOBALS['community_test']['completion_images'][99]['lounge']);
    $this->assertSame(1, $GLOBALS['community_test']['invalidate_user_cache_calls']);
    $this->assertSame('ws_images_uploadCompleted', $GLOBALS['community_test']['trigger_notify_calls'][1][0]);
    $this->assertSame(array(12, 13), $GLOBALS['community_test']['trigger_notify_calls'][1][1]['image_ids']);
    $this->assertSame(1, $GLOBALS['community_test']['trigger_notify_calls'][1][1]['category_id']);
    $this->assertSame(0, $GLOBALS['community_test']['empty_lounge_calls']);
    $this->assertStringNotContainsString('empty_lounge_running', implode("\n", $GLOBALS['community_test']['queries']));
    $this->assertSame('User: %s', $GLOBALS['community_test']['mail_notification_calls'][0][1][3]['key']);
    $this->assertSame('contributor', $GLOBALS['community_test']['mail_notification_calls'][0][1][3]['args']);
    $this->assertSame('Album: %s', $GLOBALS['community_test']['mail_notification_calls'][0][1][2]['key']);
    $this->assertSame('Album 1', $GLOBALS['community_test']['mail_notification_calls'][0][1][2]['args']);
  }

  /**
   * @dataProvider invalidCompletionBatchProvider
   */
  public function testUploadCompletedLifecycleRejectsInvalidBatchAtomically($imageIds, $categoryId)
  {
    $service = community_test_build_service('community.images.uploadCompleted');
    $this->configureCompletionFixture(array(
      12 => array('added_by' => 2, 'associated' => true, 'lounge' => false, 'state' => null, 'notified_on' => null),
      14 => array('added_by' => 3, 'associated' => true, 'lounge' => false, 'state' => null, 'notified_on' => null),
    ));

    $result = $service->invoke('community.images.uploadCompleted', array(
      'image_id' => $imageIds,
      'category_id' => $categoryId,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(array(), $GLOBALS['community_test']['associate_images_to_categories_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['mail_notification_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['invalidate_user_cache_calls']);
  }

  public static function invalidCompletionBatchProvider()
  {
    return array(
      'missing' => array(null, 1),
      'empty' => array('', 1),
      'malformed' => array('12,nope', 1),
      'duplicate' => array('12,12', 1),
      'zero' => array('0', 1),
      'negative' => array('-12', 1),
      'missing image' => array('15', 1),
      'foreign owned' => array('14', 1),
      'mixed owned' => array('12,14', 1),
      'wrong category' => array('12', 2),
      'unauthorized category' => array('12', 3),
    );
  }

  public function testUploadCompletedLifecycleRejectsInvalidTokenBeforeQueriesOrEffects()
  {
    $service = community_test_build_service('community.images.uploadCompleted');

    $result = $service->invoke('community.images.uploadCompleted', array(
      'image_id' => '12',
      'category_id' => 1,
      'pwg_token' => 'invalid',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertSame(array(), $GLOBALS['community_test']['mail_notification_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
  }

  public function testUploadCompletedLifecycleRejectsMissingTokenBeforeQueriesOrEffects()
  {
    $service = community_test_build_service('pwg.images.uploadCompleted');

    $result = $service->invoke('pwg.images.uploadCompleted', array(
      'image_id' => '12',
      'category_id' => 1,
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(array(), $GLOBALS['community_test']['queries']);
    $this->assertSame(array(), $GLOBALS['community_test']['mail_notification_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['invalidate_user_cache_calls']);
  }

  public function testGenericUploadCompletedLifecycleRequiresCurrentSessionProvenance()
  {
    global $user;

    $service = community_test_build_service('community.images.uploadCompleted');
    $user['status'] = 'generic';
    $this->configureCompletionFixture(array(
      12 => array('added_by' => 2, 'associated' => true, 'lounge' => false, 'state' => null, 'notified_on' => null),
    ), array());

    $result = $service->invoke('community.images.uploadCompleted', array(
      'image_id' => '12',
      'category_id' => 1,
      'pwg_token' => 'test-token',
    ));

    $this->assertInstanceOf(PwgError::class, $result);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
  }

  public function testGenericUploadCompletedLifecycleAcceptsCurrentSessionProvenance()
  {
    global $user;

    $service = community_test_build_service('community.images.uploadCompleted');
    $user['status'] = 'generic';
    $this->configureCompletionFixture(array(
      12 => array('added_by' => 2, 'associated' => true, 'lounge' => false, 'state' => 'moderation_pending', 'notified_on' => null),
    ), array(12));

    $result = $service->invoke('community.images.uploadCompleted', array(
      'image_id' => '12',
      'category_id' => 1,
      'pwg_token' => 'test-token',
    ));

    $this->assertIsArray($result);
    $this->assertCount(1, $result['pending']);
    $this->assertSame('generic', $user['status']);
    $this->assertCount(1, $GLOBALS['community_test']['mail_notification_calls']);
  }

  public function testUploadCompletedLifecycleSequentialRetryIsIdempotentAcrossPublicNames()
  {
    $service = community_test_build_service('pwg.images.uploadCompleted');
    $this->configureCompletionFixture(array(
      12 => array('added_by' => 2, 'associated' => false, 'lounge' => true, 'state' => 'moderation_pending', 'notified_on' => null),
    ));
    $params = array('image_id' => '12', 'category_id' => 1, 'pwg_token' => 'test-token');

    $first = $service->invoke('pwg.images.uploadCompleted', $params);
    $second = $service->invoke('community.images.uploadCompleted', $params);

    $this->assertSame(array(array('image_id' => 12, 'category_id' => 1)), $first['moved_from_lounge']);
    $this->assertCount(1, $second['pending']);
    $this->assertCount(1, $GLOBALS['community_test']['associate_images_to_categories_calls']);
    $this->assertCount(2, $GLOBALS['community_test']['trigger_notify_calls']);
    $this->assertCount(1, $GLOBALS['community_test']['mail_notification_calls']);
    $this->assertSame(1, $GLOBALS['community_test']['invalidate_user_cache_calls']);
    $updates = array_filter($GLOBALS['community_test']['queries'], function ($query) {
      return false !== strpos($query, 'SET notified_on = NOW()');
    });
    $this->assertCount(1, $updates);
  }

  public function testUploadCompletedLifecycleOverlappingCallsFinalizeAndNotifyOnce()
  {
    if (!function_exists('pcntl_fork') || !function_exists('stream_socket_pair'))
    {
      $this->markTestSkipped('pcntl and stream sockets are required for the completion overlap regression');
    }

    $service = community_test_build_service('pwg.images.uploadCompleted');
    $this->configureCompletionFixture(array(
      12 => array('added_by' => 2, 'associated' => false, 'lounge' => true, 'state' => 'moderation_pending', 'notified_on' => null),
    ));
    $params = array('image_id' => '12', 'category_id' => 1, 'pwg_token' => 'test-token');
    $locks = community_acquire_completion_locks(array(12));
    $this->assertIsArray($locks);

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $this->assertNotFalse($sockets);
    $pid = pcntl_fork();
    $this->assertNotSame(-1, $pid);

    if (0 === $pid)
    {
      fclose($sockets[0]);
      community_release_completion_locks($locks);
      fwrite($sockets[1], "ready\n");
      $result = $service->invoke('pwg.images.uploadCompleted', $params);
      fwrite($sockets[1], json_encode(array(
        'result' => $result,
        'associations' => count($GLOBALS['community_test']['associate_images_to_categories_calls']),
        'hooks' => count($GLOBALS['community_test']['trigger_notify_calls']),
        'mail' => count($GLOBALS['community_test']['mail_notification_calls']),
      ))."\n");
      fclose($sockets[1]);
      exit(0);
    }

    fclose($sockets[1]);
    $this->assertSame('ready', trim(fgets($sockets[0])));
    community_release_completion_locks($locks);
    $child = json_decode(trim(fgets($sockets[0])), true);
    fclose($sockets[0]);
    pcntl_waitpid($pid, $status);

    $retry = $service->invoke('community.images.uploadCompleted', $params);

    $this->assertTrue(pcntl_wifexited($status));
    $this->assertSame(0, pcntl_wexitstatus($status));
    $this->assertSame(1, $child['associations']);
    $this->assertSame(2, $child['hooks']);
    $this->assertSame(1, $child['mail']);
    $this->assertCount(1, $retry['pending']);
    $this->assertSame(array(), $GLOBALS['community_test']['associate_images_to_categories_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['mail_notification_calls']);
  }

  private function configureCompletionFixture($images, $sessionImageIds = null)
  {
    $GLOBALS['community_test']['completion_images'] = $images;
    $GLOBALS['community_test']['completion_session_image_ids'] = null === $sessionImageIds ? array_keys($images) : $sessionImageIds;
    $GLOBALS['community_test']['fetch_assoc_return'] = array(array('nb_photos' => count($images)));
    $GLOBALS['community_test']['query2array_callback'] = function ($query, $keyField, $valueField) {
      if (false !== strpos($query, 'FROM '.CATEGORIES_TABLE))
      {
        return false !== strpos($query, 'id = 1') ? array(array('id' => 1)) : array();
      }
      if (false !== strpos($query, 'FROM '.ACTIVITY_TABLE))
      {
        return $GLOBALS['community_test']['completion_session_image_ids'];
      }
      if (false !== strpos($query, 'FROM '.IMAGES_TABLE.' AS i'))
      {
        preg_match('/i\.id IN \(([^)]+)\)/', $query, $matches);
        $ids = array_map('intval', explode(',', $matches[1]));
        $rows = array();
        foreach ($ids as $id)
        {
          if (!isset($GLOBALS['community_test']['completion_images'][$id]))
          {
            continue;
          }
          $image = $GLOBALS['community_test']['completion_images'][$id];
          if (2 != $image['added_by'])
          {
            continue;
          }
          $rows[] = array(
            'id' => $id,
            'level' => 0,
            'added_by' => $image['added_by'],
            'state' => $image['state'],
            'notified_on' => $image['notified_on'],
            'associated_image_id' => $image['associated'] ? $id : null,
            'lounge_image_id' => $image['lounge'] ? $id : null,
          );
        }
        return $rows;
      }

      return array();
    };
    $GLOBALS['community_test']['associate_images_to_categories_callback'] = function ($imageIds) {
      foreach ($imageIds as $imageId)
      {
        $GLOBALS['community_test']['completion_images'][$imageId]['associated'] = true;
      }
    };
    $GLOBALS['community_test']['query_callback'] = function ($query) {
      if (false !== strpos($query, 'DELETE') && false !== strpos($query, LOUNGE_TABLE))
      {
        preg_match('/image_id IN \(([^)]+)\)/', $query, $matches);
        foreach (array_map('intval', explode(',', $matches[1])) as $imageId)
        {
          $GLOBALS['community_test']['completion_images'][$imageId]['lounge'] = false;
        }
      }
      if (false !== strpos($query, 'SET notified_on = NOW()'))
      {
        preg_match('/image_id IN \(([^)]+)\)/', $query, $matches);
        foreach (array_map('intval', explode(',', $matches[1])) as $imageId)
        {
          if (isset($GLOBALS['community_test']['completion_images'][$imageId]))
          {
            $GLOBALS['community_test']['completion_images'][$imageId]['notified_on'] = 'now';
          }
        }
      }

      return $query;
    };
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

  public function testUploadAsyncCleanupRetainsStableLockAndExactUnrelatedState()
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

    $unrelatedStateFile = $paths['state_dir'].'/keep.txt';
    file_put_contents($unrelatedStateFile, 'keep');
    community_cleanup_upload_async_state_directory($paths);

    $this->assertFileExists($paths['lock_file']);
    $this->assertDirectoryExists($paths['state_dir']);
    $this->assertFileExists($unrelatedStateFile);
  }

  public function testLegacyCleanupRetainsHeldLockAndExactUnrelatedState()
  {
    $originalSum = md5('legacy-cleanup');
    $paths = community_get_legacy_add_state_paths($originalSum);

    $this->assertTrue(community_ensure_directory($paths['chunks_dir']));
    file_put_contents($paths['lock_file'], 'lock');
    file_put_contents($paths['manifest_file'], json_encode(array('expires_at' => time() - 1)));
    file_put_contents($paths['merged_file'], 'merged');
    file_put_contents($paths['chunks_dir'].'/file-00000.chunk', 'chunk');

    $lockHandle = fopen($paths['lock_file'], 'c+');
    $this->assertNotFalse($lockHandle);
    $this->assertTrue(flock($lockHandle, LOCK_EX));
    $lockStat = fstat($lockHandle);

    community_cleanup_legacy_add_artifacts($paths);

    clearstatcache(true, $paths['lock_file']);
    $this->assertFileExists($paths['lock_file']);
    $this->assertSame($lockStat['ino'], stat($paths['lock_file'])['ino']);
    $this->assertFileDoesNotExist($paths['manifest_file']);
    $this->assertFileDoesNotExist($paths['merged_file']);
    $this->assertFileDoesNotExist($paths['chunks_dir']);

    $unrelatedStateFile = $paths['state_dir'].'/keep.txt';
    file_put_contents($unrelatedStateFile, 'keep');
    community_cleanup_legacy_add_state_directory($paths);

    $this->assertFileExists($paths['lock_file']);
    $this->assertFileExists($unrelatedStateFile);

    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
  }

  #[DataProvider('provideUploadStateLockLifecycles')]
  public function testUploadStateCleanupCannotSplitLockIdentityAcrossProcesses($pathFactory, $cleanupFunction)
  {
    if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec') || !function_exists('stream_socket_pair'))
    {
      $this->markTestSkipped('pcntl and stream sockets are required for the process-level lock regression');
    }

    $originalSum = md5('process-lock-'.$pathFactory);
    $paths = call_user_func($pathFactory, $originalSum);
    $this->assertTrue(community_ensure_directory($paths['state_dir']));

    $ownerHandle = fopen($paths['lock_file'], 'c+');
    $this->assertNotFalse($ownerHandle);
    $this->assertTrue(flock($ownerHandle, LOCK_EX));
    $ownerStat = fstat($ownerHandle);

    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $this->assertNotFalse($sockets);

    $pid = pcntl_fork();
    $this->assertNotSame(-1, $pid);

    if (0 === $pid)
    {
      fclose($sockets[0]);
      fclose($ownerHandle);

      $contenderHandle = fopen($paths['lock_file'], 'c+');
      $contenderStat = fstat($contenderHandle);
      $blocked = !flock($contenderHandle, LOCK_EX | LOCK_NB);
      fwrite($sockets[1], json_encode(array('blocked' => $blocked, 'inode' => $contenderStat['ino']))."\n");

      fgets($sockets[1]);
      flock($contenderHandle, LOCK_EX);
      fwrite($sockets[1], "acquired\n");
      fgets($sockets[1]);

      flock($contenderHandle, LOCK_UN);
      fclose($contenderHandle);
      fclose($sockets[1]);
      pcntl_exec(PHP_BINARY, array('-r', 'exit(0);'));
      exit(1);
    }

    fclose($sockets[1]);
    $contenderState = json_decode(trim(fgets($sockets[0])), true);
    $this->assertTrue($contenderState['blocked']);
    $this->assertSame($ownerStat['ino'], $contenderState['inode']);

    flock($ownerHandle, LOCK_UN);
    fclose($ownerHandle);
    fwrite($sockets[0], "continue\n");
    $this->assertSame('acquired', trim(fgets($sockets[0])));

    call_user_func($cleanupFunction, $paths);
    clearstatcache(true, $paths['lock_file']);
    $this->assertFileExists($paths['lock_file']);
    $this->assertSame($ownerStat['ino'], stat($paths['lock_file'])['ino']);

    $thirdHandle = fopen($paths['lock_file'], 'c+');
    $this->assertNotFalse($thirdHandle);
    $this->assertFalse(flock($thirdHandle, LOCK_EX | LOCK_NB));
    fclose($thirdHandle);

    fwrite($sockets[0], "release\n");
    fclose($sockets[0]);
    pcntl_waitpid($pid, $status);
    $this->assertTrue(pcntl_wifexited($status));
    $this->assertSame(0, pcntl_wexitstatus($status));
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
    $_FILES['file'] = community_test_create_uploaded_chunk('multipart-upload', 'upload.jpg');

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
    $_FILES['file'] = community_test_create_uploaded_chunk('multipart-format', 'format.jpg');

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

  public function testNonAdminAddLifecycleAcceptsMixedCaseChecksumWithPersistedChunks()
  {
    $chunkContents = 'mixed-case-legacy-upload';
    $service = community_test_build_service(
      'pwg.images.add',
      array(
        'categories' => '1',
        'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      )
    );

    $chunkResult = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode($chunkContents),
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'position' => 0,
    ));

    $params = array(
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'categories' => '1',
      'check_uniqueness' => false,
    );
    $delegateCalls = 0;

    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($source, $filename, $categories, $level, $imageId, $originalSum) use (&$delegateCalls) {
      $delegateCalls++;
      TestCase::assertSame('AaBbCcDd00112233445566778899EeFf', $originalSum);

      return 42;
    };

    $result = $service->invoke('pwg.images.add', $params);

    $this->assertTrue($chunkResult);
    $this->assertSame(42, $result['image_id']);
    $this->assertSame(1, $delegateCalls);
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

  public function testNonAdminAddChunkLifecyclePersistsMixedCaseChecksum()
  {
    $service = community_test_build_service('pwg.images.addChunk');

    $result = $service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode('chunk-data'),
      'original_sum' => 'AaBbCcDd00112233445566778899EeFf',
      'position' => 0,
    ));

    $this->assertTrue($result);
    $paths = community_get_legacy_add_state_paths('AaBbCcDd00112233445566778899EeFf');
    $manifest = community_read_json_file($paths['manifest_file']);
    $this->assertSame('AaBbCcDd00112233445566778899EeFf', $manifest['original_sum']);
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

    $this->assertTrue($service->invoke('pwg.images.addChunk', array(
      'data' => base64_encode('chunk-data'),
      'original_sum' => $params['original_sum'],
      'position' => 0,
    )));
    $GLOBALS['community_test']['queries'] = array();
    $GLOBALS['community_test']['escaped_values'] = array();

    $GLOBALS['community_test']['escape_callback'] = function ($value) {
      return str_replace("'", "\\'", $value);
    };
    $GLOBALS['community_test']['fetch_row_return'] = array('0');
    $GLOBALS['community_test']['add_uploaded_file_callback'] = function ($source, $filename) use (&$delegateCalls, $params) {
      $delegateCalls++;
      TestCase::assertSame($params['original_filename'], $filename);

      return 99;
    };

    $result = $service->invoke('pwg.images.add', $params);

    $this->assertSame(99, $result['image_id']);
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
    $completionMethod = community_test_get_registered_method($service, 'pwg.images.uploadCompleted');

    $this->assertSame('ws_images_addSimple', $addSimpleMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $addSimpleMethod['options']);
    $this->assertSame('ws_images_add', $addMethod['callback']);
    $this->assertSame('ws_images_add_chunk', $chunkMethod['callback']);
    $this->assertSame('community_test_ws_images_upload', $uploadMethod['callback']);
    $this->assertSame('community_test_ws_images_uploadAsync', $uploadAsyncMethod['callback']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $uploadMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $uploadAsyncMethod['options']);
    $this->assertSame(array('admin_only' => true), $addMethod['options']);
    $this->assertSame(array('admin_only' => true, 'post_only' => true), $chunkMethod['options']);
    $this->assertSame('ws_images_uploadCompleted', $completionMethod['callback']);
    $this->assertSame(array('admin_only' => true), $completionMethod['options']);
  }

  public function testUploadCompletedLifecycleFakedByCommunityFalseKeepsExistingBehavior()
  {
    $service = community_test_build_service(
      'pwg.images.uploadCompleted',
      array(
        'image_id' => '12',
        'category_id' => '1',
        'pwg_token' => 'test-token',
        'faked_by_community' => 'false',
      )
    );

    $method = community_test_get_registered_method($service, 'pwg.images.uploadCompleted');

    $this->assertSame('ws_images_uploadCompleted', $method['callback']);
    $this->assertSame(array('admin_only' => true), $method['options']);
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

    $this->assertSame('community_test_ws_images_upload', $method['callback']);
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

    $this->assertSame('community_test_ws_images_uploadAsync', $method['callback']);
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

  private function configureMutationFixture($images, $existingCategories = array(1), $existingTags = array(), $beforeQuery = null)
  {
    $GLOBALS['community_test']['query2array_callback'] = function ($query, $keyField, $valueField) use (&$images, $existingCategories, $existingTags, $beforeQuery) {
      if (isset($beforeQuery))
      {
        $beforeQuery($query);
      }

      if (false !== strpos($query, 'FROM '.IMAGES_TABLE.' AS images'))
      {
        preg_match('/images\.id IN \(([^)]+)\)/', $query, $matches);
        $targetIds = array_map('intval', explode(',', $matches[1]));
        $rows = array();
        foreach ($targetIds as $imageId)
        {
          if (!isset($images[$imageId]))
          {
            continue;
          }
          $categories = $images[$imageId]['categories'];
          if (empty($categories))
          {
            $rows[] = array('id' => $imageId, 'added_by' => $images[$imageId]['added_by'], 'category_id' => null);
          }
          foreach ($categories as $categoryId)
          {
            $rows[] = array('id' => $imageId, 'added_by' => $images[$imageId]['added_by'], 'category_id' => $categoryId);
          }
        }
        return $rows;
      }

      if (false !== strpos($query, 'FROM '.ACTIVITY_TABLE))
      {
        preg_match('/object_id IN \(([^)]+)\)/', $query, $matches);
        $targetIds = array_map('intval', explode(',', $matches[1]));
        return array_values(array_filter($targetIds, function ($imageId) use ($images) {
          return !empty($images[$imageId]['current_session']);
        }));
      }

      if (false !== strpos($query, 'FROM '.CATEGORIES_TABLE))
      {
        preg_match('/id IN \(([^)]+)\)/', $query, $matches);
        $targetIds = array_map('intval', explode(',', $matches[1]));
        return array_values(array_intersect($existingCategories, $targetIds));
      }

      if (false !== strpos($query, 'FROM '.TAGS_TABLE))
      {
        preg_match('/id IN \(([^)]+)\)/', $query, $matches);
        $targetIds = array_map('intval', explode(',', $matches[1]));
        return array_values(array_intersect($existingTags, $targetIds));
      }

      return array();
    };
  }

  private function assertNoMutationSideEffects()
  {
    $this->assertSame(array(), $GLOBALS['community_test']['single_updates']);
    $this->assertSame(array(), $GLOBALS['community_test']['set_tag_calls']);
    $this->assertSame(array(), $GLOBALS['community_test']['trigger_notify_calls']);
    $this->assertSame(0, $GLOBALS['community_test']['invalidate_user_cache_calls']);
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

  public static function provideInvalidMutationImageIds()
  {
    return array(
      'setInfo zero' => array('pwg.images.setInfo', 0),
      'setInfo negative' => array('pwg.images.setInfo', -1),
      'setInfo malformed' => array('pwg.images.setInfo', 'abc'),
      'delete empty scalar' => array('pwg.images.delete', ''),
      'delete empty array' => array('pwg.images.delete', array()),
      'delete zero' => array('pwg.images.delete', '0'),
      'delete negative' => array('pwg.images.delete', '-1'),
      'delete malformed' => array('pwg.images.delete', '12x'),
      'delete duplicate scalar' => array('pwg.images.delete', '12,12'),
      'delete duplicate array' => array('pwg.images.delete', array('12', 12)),
      'delete overflow' => array('pwg.images.delete', (string) PHP_INT_MAX.'0'),
    );
  }

  public static function provideDeniedMutationTargets()
  {
    return array(
      'missing edit' => array('pwg.images.setInfo', array(), 12),
      'foreign edit' => array('pwg.images.setInfo', array(12 => array('added_by' => 3, 'categories' => array(1))), 12),
      'wrong album edit' => array('pwg.images.setInfo', array(12 => array('added_by' => 2, 'categories' => array(1, 9))), 12),
      'missing delete' => array('pwg.images.delete', array(), '12'),
      'mixed delete' => array('pwg.images.delete', array(
        12 => array('added_by' => 2, 'categories' => array(1)),
        13 => array('added_by' => 3, 'categories' => array(1)),
      ), '12,13'),
      'wrong album delete' => array('pwg.images.delete', array(12 => array('added_by' => 2, 'categories' => array(1, 9))), '12'),
    );
  }

  public static function provideInvalidSetInfoMutations()
  {
    return array(
      'invalid single mode' => array(array('single_value_mode' => 'merge')),
      'invalid multiple mode' => array(array('multiple_value_mode' => 'merge')),
      'malformed category' => array(array('categories' => '1,bad')),
      'overflow category' => array(array('categories' => (string) PHP_INT_MAX.'0')),
      'overflow category rank' => array(array('categories' => '1,'.(string) PHP_INT_MAX.'0')),
      'duplicate category' => array(array('categories' => '1;1')),
      'unknown category' => array(array('categories' => '3')),
      'unauthorized category' => array(array('categories' => '9')),
      'mixed category' => array(array('categories' => '1;9')),
      'malformed tag' => array(array('tag_ids' => '4,bad')),
      'duplicate tag' => array(array('tag_ids' => '4,4')),
      'unknown tag' => array(array('tag_ids' => '6')),
      'empty append tags' => array(array('tag_ids' => '', 'multiple_value_mode' => 'append')),
      'array text field' => array(array('name' => array('bad'))),
      'array file field' => array(array('file' => array('bad'))),
    );
  }

  public static function provideMutationMethods()
  {
    return array(
      'setInfo' => array('pwg.images.setInfo'),
      'delete' => array('pwg.images.delete'),
    );
  }

  public static function provideInvalidDeleteTokens()
  {
    return array(
      'missing' => array(null, false),
      'invalid' => array('wrong-token', true),
    );
  }

  public static function provideDeniedCategoryCreationRequests()
  {
    $allowed = array('create_categories' => array(1));

    return array(
      'missing token' => array($allowed, array('name' => 'Child', 'parent' => 1)),
      'invalid token' => array($allowed, array('name' => 'Child', 'parent' => 1, 'pwg_token' => 'wrong')),
      'missing parent without root grant' => array($allowed, array('name' => 'Child', 'pwg_token' => 'test-token')),
      'negative parent' => array($allowed, array('name' => 'Child', 'parent' => -1, 'pwg_token' => 'test-token')),
      'fractional parent' => array($allowed, array('name' => 'Child', 'parent' => '1.5', 'pwg_token' => 'test-token')),
      'array parent' => array($allowed, array('name' => 'Child', 'parent' => array(1), 'pwg_token' => 'test-token')),
      'unauthorized parent' => array($allowed, array('name' => 'Child', 'parent' => 2, 'pwg_token' => 'test-token')),
      'upload grant only' => array(array('create_categories' => array()), array('name' => 'Child', 'parent' => 1, 'pwg_token' => 'test-token')),
      'empty name' => array($allowed, array('name' => ' ', 'parent' => 1, 'pwg_token' => 'test-token')),
      'missing name' => array($allowed, array('parent' => 1, 'pwg_token' => 'test-token')),
      'non scalar name' => array($allowed, array('name' => array('Child'), 'parent' => 1, 'pwg_token' => 'test-token')),
      'invalid status' => array($allowed, array('name' => 'Child', 'parent' => 1, 'status' => 'hidden', 'pwg_token' => 'test-token')),
      'position' => array($allowed, array('name' => 'Child', 'parent' => 1, 'position' => 'last', 'pwg_token' => 'test-token')),
      'empty position' => array($allowed, array('name' => 'Child', 'parent' => 1, 'position' => '', 'pwg_token' => 'test-token')),
      'zero position' => array($allowed, array('name' => 'Child', 'parent' => 1, 'position' => '0', 'pwg_token' => 'test-token')),
    );
  }

  public static function provideDeniedTagCreationRequests()
  {
    return array(
      'category creation only' => array(array('upload_categories' => array(), 'create_categories' => array(1)), array('name' => 'Tag', 'pwg_token' => 'test-token')),
      'no grant' => array(array('upload_categories' => array(), 'create_categories' => array()), array('name' => 'Tag', 'pwg_token' => 'test-token')),
      'missing token' => array(array('upload_categories' => array(1)), array('name' => 'Tag')),
      'invalid token' => array(array('upload_categories' => array(1)), array('name' => 'Tag', 'pwg_token' => 'wrong')),
      'empty name' => array(array('upload_categories' => array(1)), array('name' => ' ', 'pwg_token' => 'test-token')),
      'non scalar name' => array(array('upload_categories' => array(1)), array('name' => array('Tag'), 'pwg_token' => 'test-token')),
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

  public static function provideUploadStateLockLifecycles()
  {
    return array(
      'uploadAsync' => array('community_get_upload_async_state_paths', 'community_cleanup_upload_async_state_directory'),
      'legacy add' => array('community_get_legacy_add_state_paths', 'community_cleanup_legacy_add_state_directory'),
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