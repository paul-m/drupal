<?php

namespace Drupal\BuildTests\Composer\Plugin\ComposerConverter;

use Drupal\BuildTests\Framework\BuildTestBase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

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

    $this->executeCommand('composer drupal-legacy-convert --no-interaction');
    $this->assertCommandSuccessful();

    $this->assertContains('drupal/converted-project', file_get_contents($this->getWorkspaceDirectory() . '/composer.json'));
  }

  public function testUnreconciledExtensionNotifier() {
    // Set up a finder so that we don't copy extension in someone's dev environment.
    // @todo Change this once https://www.drupal.org/project/drupal/issues/3095809 is
    //       in.
    $finder = new Finder();
    $finder->files()
      ->ignoreUnreadableDirs()
      ->in($this->getDrupalRoot())
      ->notPath('#^sites/default/files#')
      ->notPath('#^sites/simpletest#')
      ->notPath('#^vendor#')
      ->notPath('#^sites/default/settings\..*php#')
      ->notPath('#^modules#')
      ->notPath('#^profiles#')
      ->notPath('#^themes#')
      ->ignoreDotFiles(FALSE)
      ->ignoreVCS(FALSE);
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

    file_put_contents($exotic_module_info_path, "name: Test Exotic Module");
    $process = $this->executeCommand('composer install');
    $this->assertCommandSuccessful();
    $this->assertContains('This project has extensions on the file system which might require manual updating', $process->getOutput());
    $this->assertNotContains('This project has extensions on the file system which are not reflected in the composer.json file', $process->getOutput());

    file_put_contents($unreconciled_module_info_path, "name: Test Module\nproject: Other");
    $process = $this->executeCommand('composer install');
    $this->assertCommandSuccessful();
    $this->assertContains('This project has extensions on the file system which might require manual updating', $process->getOutput());
    $this->assertContains('This project has extensions on the file system which are not reflected in the composer.json file', $process->getOutput());
  }

}
