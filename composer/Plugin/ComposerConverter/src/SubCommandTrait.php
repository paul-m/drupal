<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

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
