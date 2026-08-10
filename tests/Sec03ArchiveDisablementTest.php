<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Sec03ArchiveDisablementTest extends TestCase
{
  private function runUpload(array $names, array $contents = array())
  {
    $payload = json_encode(array('names' => $names, 'contents' => $contents));
    $command = array(PHP_BINARY, __DIR__.'/fixtures/sec03_upload_runner.php', $payload);
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

  private function assertArchiveBatchRejectedWithoutSideEffects($result)
  {
    $this->assertContains('ZIP archives are not supported', $result['errors']);
    $this->assertSame(array(), $result['side_effects']['prepare_directory']);
    $this->assertSame(array(), $result['side_effects']['add_uploaded_file']);
    $this->assertSame(array(), $result['side_effects']['database_writes']);
    $this->assertSame(array(), $result['side_effects']['queries']);
    $this->assertSame(array(), $result['side_effects']['hooks']);
    $this->assertSame(array(), $result['side_effects']['moderation_records']);
    $this->assertSame(array(), $result['artifacts']);
    $this->assertSame(array(), $result['image_ids']);
    $this->assertSame(array(), $result['thumbnails']);
    $this->assertTrue($result['state_unchanged']);
  }

  public static function zipFilenameProvider()
  {
    yield 'lowercase extension' => array('photos.zip');
    yield 'uppercase extension' => array('photos.ZIP');
    yield 'mixed-case extension' => array('photos.ZiP');
  }

  #[DataProvider('zipFilenameProvider')]
  public function testHandcraftedZipUploadIsRejectedBeforeFilesystemOrPersistenceEffects($filename)
  {
    $this->assertArchiveBatchRejectedWithoutSideEffects($this->runUpload(array($filename)));
  }

  public static function mixedBatchProvider()
  {
    yield 'image followed by ZIP' => array(array('photo.jpg', 'archive.zip'));
    yield 'ZIP followed by image' => array(array('archive.zip', 'photo.jpg'));
  }

  #[DataProvider('mixedBatchProvider')]
  public function testMixedImageAndZipBatchIsRejectedAtomicallyRegardlessOfZipPosition($filenames)
  {
    $this->assertArchiveBatchRejectedWithoutSideEffects($this->runUpload($filenames));
  }

  public static function adversarialArchiveProvider()
  {
    yield 'traversal entry' => array('../photo.jpg');
    yield 'absolute path entry' => array('/tmp/photo.jpg');
    yield 'nested entry' => array('deep/tree/photo.jpg');
    yield 'symlink-like entry' => array('symlink:../../target');
    yield 'excessive entries' => array(str_repeat('entry.jpg\n', 10000));
    yield 'high ratio content' => array(str_repeat('A', 64 * 1024));
  }

  #[DataProvider('adversarialArchiveProvider')]
  public function testAdversarialArchiveContentCannotReachExtraction($contents)
  {
    $this->assertArchiveBatchRejectedWithoutSideEffects($this->runUpload(array('hostile.zip'), array($contents)));
  }

  public function testMalformedSuccessfulFilenameIsRejectedWithoutLossyConversionOrSideEffects()
  {
    $this->assertArchiveBatchRejectedWithoutSideEffects($this->runUpload(array(array('archive.zip'))));
  }

  public function testNormalEligibleImageStillUsesTheExistingUploadPathExactlyOnce()
  {
    $result = $this->runUpload(array('photo.JPEG'));

    $this->assertSame(array(), $result['errors']);
    $this->assertCount(1, $result['side_effects']['add_uploaded_file']);
    $this->assertSame('photo.JPEG', $result['side_effects']['add_uploaded_file'][0][1]);
    $this->assertSame(array('3'), $result['side_effects']['add_uploaded_file'][0][2]);
    $this->assertSame(16, $result['side_effects']['add_uploaded_file'][0][3]);
    $this->assertSame(array(101), $result['image_ids']);
    $this->assertCount(1, $result['thumbnails']);
    $this->assertTrue($result['state_unchanged']);
  }

  public function testCommunityUploaderUsesOnlyConfiguredPictureExtensionsEvenInAllTypesMode()
  {
    $source = file_get_contents(COMMUNITY_PATH.'add_photos.php');

    $this->assertStringContainsString('$conf[\'picture_ext\']', $source);
    $this->assertStringNotContainsString('$conf[\'upload_form_all_types\'] ? $conf[\'file_ext\'] : $conf[\'picture_ext\']', $source);
    $this->assertStringContainsString("'file_exts' => implode(',', \$unique_exts)", $source);
  }

  public function testCommunityRuntimeContainsNoArchiveExtractionReferences()
  {
    $source = file_get_contents(COMMUNITY_PATH.'include/photos_add_direct_process.inc.php');

    foreach (array('PclZip', 'pclzip.lib.php', 'listContent', 'PCLZIP_OPT_PATH', '->extract(', 'move_uploaded_file') as $forbiddenReference)
    {
      $this->assertStringNotContainsString($forbiddenReference, $source);
    }
  }
}