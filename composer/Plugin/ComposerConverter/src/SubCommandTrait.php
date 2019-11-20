<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

/**
 * Helper for when your command can be run as a sub-command.
 */
trait SubCommandTrait {

  private $isSubCommand = FALSE;

  public function setSubCommand($subcommand = TRUE) {
    $this->isSubCommand = $subcommand;
  }

  public function isSubCommand() {
    return $this->isSubCommand;
  }

}
