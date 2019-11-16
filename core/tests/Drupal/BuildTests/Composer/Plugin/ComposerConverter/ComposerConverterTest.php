<?php

namespace Drupal\BuildTests\Composer\Plugin\ComposerConverter;

use Drupal\BuildTests\Framework\BuildTestBase;

/**
 * @group ComposerConverter
 * @requires externalCommand composer
 */
class ComposerConverterTest extends BuildTestBase {

  public function testThingie() {
    $this->copyCodebase();

    $this->executeCommand('composer install');
    $this->assertCommandSuccessful();

    $this->assertDirectoryExists($working_dir);
    $this->executeCommand('composer drupal-legacy-convert --no-interaction');
    $this->assertCommandSuccessful();

    $this->assertContains('drupal/converted-project', file_get_contents($this->getWorkspaceDirectory() . '/composer.json'));
  }

}
