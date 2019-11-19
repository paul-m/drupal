<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

class CommandProvider implements CommandProviderCapability {

  public function getCommands() {
    return [
      new ConvertCommand(),
      new ExtensionReconcileCommand(),
    ];
  }

}
