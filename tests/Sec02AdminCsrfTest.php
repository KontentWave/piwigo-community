<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Sec02AdminCsrfTest extends TestCase
{
  private function runController($controller, $method = 'GET', $post = array(), $get = array(), $fetchAssoc = array())
  {
    $payload = json_encode(array(
      'controller' => $controller,
      'method' => $method,
      'post' => $post,
      'get' => $get,
      'fetch_assoc' => $fetchAssoc,
    ));
    $command = array(PHP_BINARY, __DIR__.'/fixtures/sec02_controller_runner.php', $payload);
    $pipes = array();
    $process = proc_open($command, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    $this->assertIsResource($process);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $this->assertSame(0, $exitCode, $stderr);
    $result = json_decode($stdout, true);
    $this->assertIsArray($result, $stdout.$stderr);
    return $result;
  }

  private function assertNoMutation($result)
  {
    $this->assertSame(array(), $result['mutation_queries']);
    $this->assertSame(array(), $result['mass_inserts']);
    $this->assertSame(array(), $result['mass_updates']);
    $this->assertSame(array(), $result['single_updates']);
    $this->assertSame(array(), $result['config_updates']);
    $this->assertSame(0, $result['community_cache_updates']);
    $this->assertSame(0, $result['user_cache_updates']);
    $this->assertSame(array(), $result['delete_elements']);
    $this->assertSame(array(), $result['redirects']);
  }

  private static function permissionPost($action = 'permission_save', $token = 'current-token')
  {
    return array(
      'action' => $action,
      'pwg_token' => $token,
      'who' => 'any_registered_user',
      'category' => '2',
      'moderated' => 'true',
      'nb_photos' => '-1',
      'storage' => '-1',
    );
  }

  public static function protectedMutationProvider()
  {
    yield 'permission save' => array('permissions', self::permissionPost());
    yield 'permission delete' => array('permissions', array('action' => 'permission_delete', 'pwg_token' => 'current-token', 'permission_id' => '7'));
    yield 'configuration save' => array('config', array('action' => 'config_save', 'pwg_token' => 'current-token', 'user_albums_parent' => '0'));
    yield 'album owner save' => array('album', array('action' => 'album_owner_save', 'pwg_token' => 'current-token', 'community_user' => '4'));
    yield 'pending validation' => array('pendings', array('action' => 'pending_validate', 'pwg_token' => 'current-token', 'photos' => array('11'), 'level' => '0'));
    yield 'pending rejection' => array('pendings', array('action' => 'pending_reject', 'pwg_token' => 'current-token', 'photos' => array('11'), 'level' => '0'));
  }

  #[DataProvider('protectedMutationProvider')]
  public function testMissingInvalidAndStaleTokensStopBeforeQueriesOrEffects($controller, $post)
  {
    foreach (array(null, 'invalid-token', 'stale-token') as $token)
    {
      if (null === $token)
      {
        unset($post['pwg_token']);
      }
      else
      {
        $post['pwg_token'] = $token;
      }

      $get = 'album' === $controller ? array('cat_id' => '10') : array();
      $result = $this->runController($controller, 'POST', $post, $get);

      $this->assertSame(array('check_status', 'check_token'), $result['events']);
      $this->assertSame(array(), $result['queries']);
      $this->assertNoMutation($result);
    }
  }

  public static function invalidActionProvider()
  {
    yield 'missing action' => array(null);
    yield 'unknown action' => array('not_a_mutation');
    yield 'non-scalar action' => array(array('config_save', 'pending_reject'));
  }

  #[DataProvider('invalidActionProvider')]
  public function testMissingUnknownAndNonScalarActionsHaveZeroSideEffects($action)
  {
    $posts = array(
      'permissions' => self::permissionPost($action),
      'config' => array('action' => $action, 'pwg_token' => 'current-token', 'user_albums_parent' => '0'),
      'album' => array('action' => $action, 'pwg_token' => 'current-token', 'community_user' => '4'),
      'pendings' => array('action' => $action, 'pwg_token' => 'current-token', 'photos' => array('11'), 'level' => '0'),
    );

    foreach ($posts as $controller => $post)
    {
      if (null === $action)
      {
        unset($post['action']);
      }
      $get = 'album' === $controller ? array('cat_id' => '10') : array();
      $fetch = 'album' === $controller ? array(array('id' => 10, 'name' => 'Album', 'community_user' => null), false) : array();
      $result = $this->runController($controller, 'POST', $post, $get, $fetch);
      $this->assertNoMutation($result);
    }
  }

  public function testPermissionCreateUpdateDuplicateAndDeleteRemainExact()
  {
    $create = $this->runController('permissions', 'POST', self::permissionPost());
    $this->assertCount(1, $create['mass_inserts']);
    $this->assertCount(0, $create['mass_updates']);
    $this->assertSame(1, $create['community_cache_updates']);

    $updatePost = self::permissionPost();
    $updatePost['edit'] = '7';
    $update = $this->runController('permissions', 'POST', $updatePost);
    $this->assertCount(0, $update['mass_inserts']);
    $this->assertCount(1, $update['mass_updates']);
    $this->assertSame('7', $update['mass_updates'][0]['rows'][0]['id']);
    $this->assertSame(1, $update['community_cache_updates']);

    $duplicate = $this->runController('permissions', 'POST', $updatePost, array(), array(array('id' => '9')));
    $this->assertCount(1, $duplicate['mutation_queries']);
    $this->assertStringContainsString('WHERE id = 7', $duplicate['mutation_queries'][0]);
    $this->assertSame('9', $duplicate['mass_updates'][0]['rows'][0]['id']);
    $this->assertSame(1, $duplicate['community_cache_updates']);

    $delete = $this->runController('permissions', 'POST', array(
      'action' => 'permission_delete',
      'permission_id' => '7',
      'pwg_token' => 'current-token',
    ));
    $this->assertCount(1, $delete['mutation_queries']);
    $this->assertStringContainsString('WHERE id = 7', $delete['mutation_queries'][0]);
    $this->assertSame(1, $delete['community_cache_updates']);
    $this->assertCount(1, $delete['redirects']);
  }

  public function testGetDeleteCannotMutateInvalidateOrRedirect()
  {
    $result = $this->runController('permissions', 'GET', array(), array('delete' => '7'));
    $this->assertNoMutation($result);
  }

  public function testConfigurationSaveSerializesOnceAfterTokenValidation()
  {
    $result = $this->runController('config', 'POST', array(
      'action' => 'config_save',
      'pwg_token' => 'current-token',
      'user_albums' => '1',
      'user_albums_parent' => '5',
    ));

    $this->assertCount(1, $result['config_updates']);
    $this->assertSame('community', $result['config_updates'][0]['name']);
    $this->assertSame(array('user_albums' => true, 'user_albums_parent' => '5'), unserialize($result['config_updates'][0]['value']));
  }

  public function testAlbumOwnerAssignmentReassignmentAndRemovalRemainExact()
  {
    $assign = $this->runController('album', 'POST', array(
      'action' => 'album_owner_save',
      'pwg_token' => 'current-token',
      'community_user' => '4',
    ), array('cat_id' => '10'), array(array('id' => 10, 'name' => 'Album', 'community_user' => 4), false));
    $this->assertCount(2, $assign['single_updates']);
    $this->assertSame(array('community_user' => null), $assign['single_updates'][0]['update']);
    $this->assertSame(array('community_user' => '4'), $assign['single_updates'][1]['update']);

    $remove = $this->runController('album', 'POST', array(
      'action' => 'album_owner_save',
      'pwg_token' => 'current-token',
      'community_user' => '0',
    ), array('cat_id' => '10'), array(array('id' => 10, 'name' => 'Album', 'community_user' => null), false));
    $this->assertCount(1, $remove['single_updates']);
    $this->assertSame(array('community_user' => '0'), $remove['single_updates'][0]['update']);
  }

  public function testPendingValidationAndRejectionRemainExact()
  {
    $validate = $this->runController('pendings', 'POST', array(
      'action' => 'pending_validate',
      'pwg_token' => 'current-token',
      'photos' => array('11', '12'),
      'level' => '2',
    ));
    $this->assertCount(2, $validate['mutation_queries']);
    $this->assertCount(0, $validate['delete_elements']);
    $this->assertSame(1, $validate['user_cache_updates']);

    $reject = $this->runController('pendings', 'POST', array(
      'action' => 'pending_reject',
      'pwg_token' => 'current-token',
      'photos' => array('11', '12'),
      'level' => '0',
    ));
    $this->assertCount(1, $reject['mutation_queries']);
    $this->assertCount(1, $reject['delete_elements']);
    $this->assertSame(array('11', '12'), $reject['delete_elements'][0]['ids']);
    $this->assertTrue($reject['delete_elements'][0]['physical']);
    $this->assertSame(1, $reject['user_cache_updates']);
  }

  #[DataProvider('protectedMutationProvider')]
  public function testRecognizedActionsCheckTokenBeforeActionParameters($controller, $post)
  {
    $get = 'album' === $controller ? array('cat_id' => '10') : array();
    $fetch = 'album' === $controller ? array(array('id' => 10, 'name' => 'Album', 'community_user' => null), false) : array();
    $result = $this->runController($controller, 'POST', $post, $get, $fetch);
    $tokenIndex = array_search('check_token', $result['events'], true);
    $this->assertNotFalse($tokenIndex);
    foreach ($result['events'] as $index => $event)
    {
      if (str_starts_with($event, 'check_input:') && 'check_input:cat_id' !== $event)
      {
        $this->assertGreaterThan($tokenIndex, $index, $event);
      }
    }
  }

  public function testControllersAssignCurrentTokenToAffectedTemplates()
  {
    foreach (array('permissions', 'config', 'pendings') as $controller)
    {
      $result = $this->runController($controller);
      $this->assertSame('current-token', $result['assignments']['PWG_TOKEN'] ?? null, $controller);
    }

    $album = $this->runController('album', 'GET', array(), array('cat_id' => '10'), array(array('id' => 10, 'name' => 'Album', 'community_user' => null), false));
    $this->assertSame('current-token', $album['assignments']['PWG_TOKEN'] ?? null);
  }

  public function testTemplatesUseCanonicalTokenAndActionFields()
  {
    $permissions = file_get_contents(dirname(__DIR__).'/template/admin_permissions.tpl');
    $config = file_get_contents(dirname(__DIR__).'/template/admin_config.tpl');
    $album = file_get_contents(dirname(__DIR__).'/template/admin_album.tpl');
    $pendings = file_get_contents(dirname(__DIR__).'/template/admin_pendings.tpl');

    foreach (array($permissions, $config, $album, $pendings) as $template)
    {
      $this->assertStringContainsString('name="pwg_token" value="{$PWG_TOKEN}"', $template);
    }

    $this->assertStringContainsString('name="action" value="permission_save"', $permissions);
    $this->assertStringContainsString('method="post"', $permissions);
    $this->assertStringContainsString('name="action" value="permission_delete"', $permissions);
    $this->assertStringNotContainsString('U_DELETE', $permissions);
    $this->assertStringContainsString('aria-label=', $permissions);
    $this->assertStringContainsString('name="action" value="config_save"', $config);
    $this->assertStringContainsString('name="action" value="album_owner_save"', $album);
    $this->assertStringContainsString('name="action" value="pending_validate"', $pendings);
    $this->assertStringContainsString('name="action" value="pending_reject"', $pendings);
    $this->assertStringNotContainsString('&validate=true', $pendings);
    $this->assertStringNotContainsString('&reject=', $pendings);
    $this->assertStringContainsString("data.action = 'pending_validate'", $pendings);
    $this->assertStringContainsString("data.action = 'pending_reject'", $pendings);
  }
}