<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Op;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\HelperSet;

class AddDependencyOp implements OpInterface {

  protected $dependency;
  protected $constraint;

  public function __construct($dependency, $constraint) {
    $this->dependency = $dependency;
    $this->constraint = $constraint;
  }

  public function needsInteraction() {
    return FALSE;
  }

  public function interact(InputInterface $input, OutputInterface $output, HelperSet $helper_set) {
  }

  public function performOp() {

  }

  public function summarize() {
    return 'Add package: ' . $this->dependency . ' (' . $this->constraint . ')';
  }

}
