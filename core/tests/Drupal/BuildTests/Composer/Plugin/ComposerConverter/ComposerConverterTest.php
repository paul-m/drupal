<?php

namespace Drupal\BuildTests\Composer\Plugin\ComposerConverter;

use Drupal\BuildTests\Framework\BuildTestBase;

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

}
