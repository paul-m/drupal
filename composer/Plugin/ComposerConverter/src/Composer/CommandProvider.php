<?php

namespace Grasmash\ComposerConverter\Composer;

use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;

use Composer\Composer;
use Composer\IO\IOInterface;

class CommandProvider implements CommandProviderCapability {

  /**
   * The Composer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  /**
   * The IO object.
   *
   * @var \Composer\IO\IOInterface
   */
  protected $io;

  public function __construct($injection) {
    $this->composer = $injection['composer'] ?? NULL;
    $this->io = $injection['io'] ?? NULL;
  }

  public function getCommands() {
    return array(
      new ComposerizeDrupalCommand(),
      new ComposerizeStatusCommand($this->composer),
    );
  }

}
