<?php

namespace Drupal\BuildTests\Composer\Plugin\ComposerConverter;

use Drupal\BuildTests\Framework\BuildTestBase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @group ComposerConverter
 * @requires externalCommand composer
 */
class ComposerConverterTest extends BuildTestBase {

  /**
   * Perform a conversion on the core codebase.
   *
   * This test really only shows that the command is discovered and that it performs
   * without error on a basic code path.
   */
  public function testConvert() {
    $this->copyCodebase();

    $this->executeCommand('composer install');
    $this->assertCommandSuccessful();

    $this->executeCommand('composer drupal:legacy-convert --no-interaction');
    $this->assertCommandSuccessful();

    $this->assertContains('drupal/converted-project', file_get_contents($this->getWorkspaceDirectory() . '/composer.json'));
  }

  public function testUnreconciledExtensionNotifier() {
    // Set up a finder so that we don't copy extensions in someone's dev
    // environment.
    $finder = $this->getCodebaseFinder();
    $finder->notPath('#^modules#')
      ->notPath('#^profiles#')
      ->notPath('#^themes#');
    $this->copyCodebase($finder->getIterator());

    $process = $this->executeCommand('composer install');
    $this->assertCommandSuccessful();
    $this->assertNotContains('This project has extensions on the file system which might require manual updating', $process->getOutput());
    $this->assertNotContains('This project has extensions on the file system which are not reflected in the composer.json file', $process->getOutput());

    // Exotic modules don't have associated d.o projects.
    $exotic_module_info_path = $this->getWorkspaceDirectory() . '/modules/test_exotic_module/test_exotic_module.info.yml';
    $unreconciled_module_info_path = $this->getWorkspaceDirectory() . '/modules/test_module/test_module.info.yml';

    (new Filesystem())->mkdir([
      dirname($exotic_module_info_path),
      dirname($unreconciled_module_info_path),
    ]);

    file_put_contents($exotic_module_info_path, "name: Test Exotic Module\ntype: module");
    $process = $this->executeCommand('composer install');
    $this->assertCommandSuccessful();
    $this->assertContains('This project has extensions on the file system which might require manual updating', $process->getOutput());
    $this->assertNotContains('This project has extensions on the file system which are not reflected in the composer.json file', $process->getOutput());

    file_put_contents($unreconciled_module_info_path, "name: Test Module\nproject: Other\ntype: module");
    $process = $this->executeCommand('composer install');
    $this->assertCommandSuccessful();
    $this->assertContains('This project has extensions on the file system which might require manual updating', $process->getOutput());
    $this->assertContains('This project has extensions on the file system which are not reflected in the composer.json file', $process->getOutput());
  }

}
