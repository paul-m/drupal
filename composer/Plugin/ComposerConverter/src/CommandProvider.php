<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

/**
 * Command provider API.
 */
class CommandProvider implements CommandProviderCapability {

  /**
   * {@inheritdoc}
   */
  public function getCommands() {
    return [
      new ConvertCommand(),
      new ExtensionReconcileCommand(),
    ];
  }

}
