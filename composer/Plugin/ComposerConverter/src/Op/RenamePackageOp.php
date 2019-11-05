<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Op;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Helper\HelperSet;

class RenamePackageOp { // implements OpInterface {

  protected $oldName;
  protected $newName;

  public function __construct($old_name, $new_name) {
    $this->newName = $new_name;
    $this->oldName = $old_name;
  }

  public function needsInteraction() {
    return empty($this->newName);
  }

  public function interact(InputInterface $input, OutputInterface $output, HelperSet $helper_set) {
    $helper = $helper_set->get('question');
    $question = new Question('Please enter a new name for this root Composer package: ', 'drupal/legacy-project-converted');
    $this->newName = $helper->ask($input, $output, $question);
  }

  public function performOp() {

  }

  public function summarize() {
    $message = 'This package will be renamed';
    if ($this->oldName) {
      $message = 'This package will be renamed from ' . $this->oldName;
    }
    if ($this->newName) {
      $message .= ' to ' . $this->newName;
    }
    $message .= '.';
    return $message;
  }

}
