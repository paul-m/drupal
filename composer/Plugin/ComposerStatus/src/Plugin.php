<?php

namespace Drupal\Composer\Plugin\ComposerStatus;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\Capability\CommandProvider as ComposerCommandProvider;

class Plugin implements PluginInterface, Capable {

  public function activate(Composer $composer, IOInterface $io) {
    // No-op, necessary for interface.
  }

  public function getCapabilities() {
    return array(
      ComposerCommandProvider::class => CommandProvider::class,
    );
  }

}
