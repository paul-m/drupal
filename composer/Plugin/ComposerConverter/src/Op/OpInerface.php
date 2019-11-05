<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Op;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\HelperSet;

interface OpInterface {

  public function performOp();

  public function needsInteraction();

  public function interact(InputInterface $input, OutputInterface $output, HelperSet $helper_set);

  public function summarize();

}
