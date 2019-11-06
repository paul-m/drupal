<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Util\Silencer;
use Composer\Command\InitCommand;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\ArrayInput;

/**
 */
class ConvertCommand extends InitCommand {

  private $json;
  private $file;
  private $composerBackup;
  protected $userCanceled = FALSE;

  /**
   * Many operations to perform, keyed by operation.
   *
   * Current allowable operations:
   * - renamePackage
   *   - New package name.
   * - addDependency
   * - addDevDependency
   *   - Array of arrays: ['package/name', 'constraint']
   * - removeDependency
   *   - Array of strings: 'package/name'
   * - removeExtension
   *   - Array of extension info file paths.
   * - performUpdate
   *   - Any truthy value.
   *
   * @var string[][]
   */
  protected $queue;

  /**
   * The order to perform queue operations.
   *
   * @var string[]
   */
  protected $queueOrder = [
    'Add dependencies' => 'addDependency',
    'Add development dependencies' => 'addDevDependency',
    'Remove dependencies' => 'removeDependency',
    'Remove extensions from the file system' => 'removeExtension',
    'Rename the package' => 'renamePackage',
    'Perform Composer update' => 'performUpdate',
    'Next steps' => 'nextSteps',
  ];

  protected function configure() {
    $this
      ->setName('drupal-legacy-convert')
      ->setDescription('Convert your Drupal project to a Composer-based one.')
      ->setDefinition(array(
        new InputOption('package-name', NULL, InputOption::VALUE_REQUIRED, 'The new package name, to replace drupal/drupal.', 'drupal/legacy-project-converted'),
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('no-update', null, InputOption::VALUE_NONE, 'Perform conversion but does not perform update.'),
        new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
      ))
      ->setHelp(
        <<<EOT
This command will change your composer.json file. By default it will also
try to peform a 'composer update' after it makes changes. It is highly
advisable to work on a backup installation, or to use git or other VCS so
you can undo the changes here. Never perform this operation on a production
site.
EOT
      )
    ;
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    $output->writeln('<info>Gathering information...</info>');
    $reconciler = new ExtensionReconciler(
      $this->getComposer()->getPackage(), $input->getOption('working-dir'
    ));

    // Always perform the update unless the user tells us not to.
    $this->queue['performUpdate'] = TRUE;
    if ($input->getOption('no-update') || $input->getOption('dry-run')) {
      unset($this->queue['performUpdate']);
    }

    // New package name.
    $this->queue['renamePackage'] = $input->getOption('package-name');

    // Start adding stuff we need in order to convert drupal/drupal to
    // drupal/legacy-project.
    $this->queue['removeDependency'] = [
      'drupal/core'
    ];

    $legacy_dependencies = [
      'composer/installers' => '^1.2',
      'drupal/core-composer-scaffold' => '^8.9',
      'drupal/core-project-message' => '^8.9',
      'drupal/core-recommended' => '^8.9',
      'drupal/core-vendor-hardening' => '^8.9',
    ];
    foreach ($legacy_dependencies as $package => $constraint) {
      $this->queue['addDependency'][] = [$package, $constraint];
    }
    $this->queue['addDevDependency'][] = ['drupal/core-dev', '^8.9', TRUE];

    // Deal with unreconciled extensions that we know we can require.
    if ($packages = $reconciler->getUnreconciledPackages()) {
      $composer = $this->getComposer(true, $input->getOption('no-plugins'));
      $repos = $composer->getRepositoryManager()->getRepositories();

      $platformOverrides = $composer->getConfig()->get('platform') ?: array();
      // initialize $this->repos as it is used by the parent InitCommand
      $this->repos = new CompositeRepository(array_merge(
          array(new PlatformRepository(array(), $platformOverrides)), $repos
      ));
      if ($composer->getPackage()->getPreferStable()) {
        $preferredStability = 'stable';
      }
      else {
        $preferredStability = $composer->getPackage()->getMinimumStability();
      }
      $phpVersion = $this->repos->findPackage('php', '*')->getPrettyVersion();
      $requirements = $this->determineRequirements($input, $output, $packages, $phpVersion, $preferredStability, !$input->getOption('no-update'));

      foreach ($this->formatRequirements($requirements) as $package => $constraint) {
        $this->queue['addDependency'][] = [$package, $constraint];
      }
    }

    if ($exotic = $reconciler->getExoticPackages()) {
      $this->queue['nextSteps'][] = 'Deal with these extensions: ' . implode(', ', $exotic);
    }
  }

  protected function interact(InputInterface $input, OutputInterface $output) {
    if (!empty($this->queue) && !$input->getOption('no-interaction')) {
      // @todo Wall of text type warning here with something like press space
      //   to continue.
      $output->writeln('<info>The following actions will be performed:</info>');
      $this->describeQueue($input, $output);
      $helper = $this->getHelper('question');
      $this->userCanceled = !$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', false));
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    if ($this->userCanceled) {
      return;
    }

    // If it's a dry run and the user shouldn't interact, then we should
    // describe the queue and then stop.
    if ($input->getOption('dry-run') && $input->getOption('no-interaction')) {
      $this->describeQueue($input, $output);
      return;
    }

    $io = $this->getIO();
    $io->write('<info>Executing...</info>');

    if (function_exists('pcntl_async_signals')) {
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, array($this, 'revertComposerFile'));
      pcntl_signal(SIGTERM, array($this, 'revertComposerFile'));
      pcntl_signal(SIGHUP, array($this, 'revertComposerFile'));
    }

    // Check on our composer.json file to see if it will work with us.
    $this->file = Factory::getComposerFile();

    if (!is_readable($this->file)) {
      $io->writeError('<error>' . $this->file . ' is not readable.</error>');
      return 1;
    }

    $this->json = new JsonFile($this->file);
    $this->composerBackup = file_get_contents($this->json->getPath());

    // check for writability by writing to the file as is_writable can not be
    // trusted on network-mounts see
    // https://github.com/composer/composer/issues/8231 and
    // https://bugs.php.net/bug.php?id=68926
    if (!is_writable($this->file) && !Silencer::call('file_put_contents', $this->file, $this->composerBackup)) {
      $io->writeError('<error>' . $this->file . ' is not writable.</error>');
      return 1;
    }

    try {
      return $this->performQueue($this->json, $input, $output);
    }
    catch (\Exception $e) {
      $this->revertComposerFile(false);
      throw $e;
    }
  }

  public function revertComposerFile($hardExit = true) {
    $io = $this->getIO();

    $io->writeError("\n" . '<error>Conversion failed, reverting ' . $this->file . ' to its original content.</error>');
    file_put_contents($this->json->getPath(), $this->composerBackup);

    if ($hardExit) {
      exit(1);
    }
  }

  /**
   * - renamePackage
   *   - New package name.
   * - addDependency
   *   - Array of arrays: ['package/name', 'constraint']
   * - removeDependency
   *   - Array of strings: 'package/name'
   * - removeExtension
   *   - Array of extension info file paths.
   * - performUpdate
   *   - Any truthy value.
   *
   * @param InputInterface $input
   * @param OutputInterface $output
   */
  protected function describeQueue(InputInterface $input, OutputInterface $output) {
    $style = new SymfonyStyle($input, $output);
    $io = $this->getIO();
    foreach ($this->queueOrder as $description => $operation) {
      if (isset($this->queue[$operation])) {
        $io->write($description);
        switch ($operation) {
          case 'renamePackage':
            $style->listing([$this->queue[$operation]]);
            break;

          case 'addDependency':
            $style->listing(
              array_map(function ($item) {
                $message = $item[0] . ':' . $item[1];
                if (isset($item[2])) {
                  $message .= ' (dev)';
                }
                return $message;
              }, $this->queue[$operation])
            );
            break;

          case 'addDevDependency':
            $style->listing(
              array_map(function ($item) {
                $message = $item[0] . ':' . $item[1];
                if (isset($item[2])) {
                  $message .= ' (dev)';
                }
                return $message;
              }, $this->queue[$operation])
            );
            break;

          case 'removeDependency':
            $style->listing($this->queue[$operation]);
            break;

          /*          case 'removeExtension':
            $style->listing(
            );
            break; */

          case 'performUpdate':
            $style->listing(['Will perform update.']);
            break;

          case 'nextSteps':
            $style->listing($this->queue[$operation]);
            break;
        }
      }
    }
  }

  protected function performQueue(JsonFile $json, InputInterface $input, OutputInterface $output) {
    $style = new SymfonyStyle($input, $output);
    $io = $this->getIO();
    $sort_packages = $input->getOption('sort-packages') || $this->getComposer()->getConfig()->get('sort-packages');

    foreach ($this->queueOrder as $description => $operation) {
      if (isset($this->queue[$operation])) {
        $io->write(' * ' . $description);
        switch ($operation) {
          case 'renamePackage':
            $this->opRenamePackage($json, $this->queue[$operation]);
            break;

          case 'addDependency':
          case 'addDevDependency':
            foreach ($this->queue[$operation] as $add) {
              $this->opAddDependency($this->json, $add[0], $add[1], isset($add[2]), $sort_packages);
            }
            break;

          case 'removeDependency':
            foreach ($this->queue[$operation] as $remove) {
              $this->opRemoveDependency($this->json, $remove);
            }
            break;

          case 'performUpdate':
            $this->opUpdate($output);
            break;

          case 'nextSteps':
            $style->listing($this->queue[$operation]);
            break;
        }
      }
    }
  }

  protected function opRenamePackage(JsonFile $json, $new_name) {
    $contents = file_get_contents($json->getPath());
    $manipulator = new JsonManipulator($contents);

    $manipulator->removeMainKey('name');
    $manipulator->addMainKey('name', $new_name);

    file_put_contents($json->getPath(), $manipulator->getContents());
  }

  protected function opRemoveDependency(JsonFile $json, $dependency) {
    $contents = file_get_contents($json->getPath());
    $manipulator = new JsonManipulator($contents);

    foreach (['require', 'require-dev'] as $require_key) {
      $manipulator->removeSubNode($require_key, $dependency);
    }

    file_put_contents($json->getPath(), $manipulator->getContents());
  }

  protected function opAddDependency(JsonFile $json, $dependency, $constraint, $is_dev, $sort_packages) {
    $require_key = 'require';
    if ($is_dev) {
      $require_key = 'require-dev';
    }
    $contents = file_get_contents($json->getPath());
    $manipulator = new JsonManipulator($contents);

    $manipulator->addLink($require_key, $dependency, $constraint, $sort_packages);

    file_put_contents($json->getPath(), $manipulator->getContents());
  }

  protected function opUpdate(OutputInterface $output) { {
      try {
        $update_command = $this->getApplication()->find('update');
        $update_command->run(new ArrayInput(array()), $output);
      }
      catch (\Exception $e) {
        $this->getIO()->writeError('Could not install dependencies. Run `composer install` to see more information.');
      }
    }
  }

}
