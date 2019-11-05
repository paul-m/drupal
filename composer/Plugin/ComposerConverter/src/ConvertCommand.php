<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PluginEvents;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\IO\IOInterface;
use Composer\Util\Silencer;
use Composer\Command\InitCommand;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Composer\Package\RootPackageInterface;

/**
 */
class ConvertCommand extends InitCommand {

  private $newlyCreated;
  private $json;
  private $file;
  private $composerBackup;
  protected $userCanceled = FALSE;

  /**
   * The package we're modifying.
   *
   * @var \Composer\Package\RootPackageInterface
   */
  protected $rootPackage;

  /**
   * Many operations to perform, keyed by operation.
   *
   * Current allowable operations:
   * - renamePackage
   * - addDependency
   * - removeDependency
   * - removeExtension
   * - performUpdate
   * - showSteps
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
    'Add dependencies:' => 'addDependency',
    'Remove dependencies:' => 'removeDependency',
    'Remove extensions from the file system:' => 'removeExtension',
    'Rename the package:' => 'renamePackage',
    'Perform Composer update' => 'performUpdate',
    'Next steps' => 'showSteps',
  ];

  /**
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler
   */
  protected $reconciler;

  protected function configure() {
    $this
      ->setName('drupal-legacy-convert')
      ->setDescription('Convert your Drupal project to a Composer-based one.')
      ->setDefinition(array(
        new InputOption('dev', null, InputOption::VALUE_NONE, 'Add requirement to require-dev.'),
        new InputOption('prefer-source', null, InputOption::VALUE_NONE, 'Forces installation from package sources when possible, including VCS information.'),
        new InputOption('prefer-dist', null, InputOption::VALUE_NONE, 'Forces installation from package dist even for dev versions.'),
        new InputOption('no-progress', null, InputOption::VALUE_NONE, 'Do not output download progress.'),
        new InputOption('no-suggest', null, InputOption::VALUE_NONE, 'Do not show package suggestions.'),
        new InputOption('no-update', null, InputOption::VALUE_NONE, 'Disables the automatic update of the dependencies.'),
        new InputOption('no-scripts', null, InputOption::VALUE_NONE, 'Skips the execution of all scripts defined in composer.json file.'),
        new InputOption('update-no-dev', null, InputOption::VALUE_NONE, 'Run the dependency update with the --no-dev option.'),
        new InputOption('update-with-dependencies', null, InputOption::VALUE_NONE, 'Allows inherited dependencies to be updated, except those that are root requirements.'),
        new InputOption('update-with-all-dependencies', null, InputOption::VALUE_NONE, 'Allows all inherited dependencies to be updated, including those that are root requirements.'),
        new InputOption('ignore-platform-reqs', null, InputOption::VALUE_NONE, 'Ignore platform requirements (php & ext- packages).'),
        new InputOption('prefer-stable', null, InputOption::VALUE_NONE, 'Prefer stable versions of dependencies.'),
        new InputOption('prefer-lowest', null, InputOption::VALUE_NONE, 'Prefer lowest versions of dependencies.'),
        new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
        new InputOption('optimize-autoloader', 'o', InputOption::VALUE_NONE, 'Optimize autoloader during autoloader dump'),
        new InputOption('classmap-authoritative', 'a', InputOption::VALUE_NONE, 'Autoload classes from the classmap only. Implicitly enables `--optimize-autoloader`.'),
        new InputOption('apcu-autoloader', null, InputOption::VALUE_NONE, 'Use APCu to cache found/not-found classes.'),
        new InputOption('prefer-projects', NULL, InputOption::VALUE_NONE, 'When possible, requires drupal.org project name rather than module name.'),
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('package-name', NULL, InputOption::VALUE_REQUIRED, 'The new package name, to replace drupal/drupal.', 'drupal/legacy-project-converted'),
      ))
      ->setHelp(
        <<<EOT
There will be help here, eventually.
EOT
      )
    ;
  }

  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
    $output->writeln('<info>Gathering information...</info>');
    $this->rootPackage = $this->getComposer()->getPackage();
    $this->reconciler = new ExtensionReconciler($this->rootPackage, $input->getOption('working-dir'));

    // Always perform the update unless the user tells us not to.
    $this->queue['performUpdate'] = TRUE;
    if ($input->getOption('no-update') || $input->getOption('dry-run')) {
      unset($this->queue['performUpdate']);
    }

    // New package name.
    $this->queue['renamePackage'] = $input->getOption('package-name');

    // Pretend we derived this information.
    $this->queue['addDependency'] = [
      ['crell/api-problem', '^8.9'],
    ];
    $this->queue['removeDependency'] = [
      'phpspec/prophecy',
      'symfony/debug',
    ];

    if ($packages = $this->reconciler->getUnreconciledPackages()) {
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
      foreach($this->formatRequirements($requirements) as $package => $constraint) {
        $this->queue['addDependency'][] = [
          $package,
          $constraint,
        ];
      }
    }

    if ($exotic = $this->reconciler->getExoticPackages()) {
      $this->queue['showSteps'][] = 'Deal with these extensions: ' . implode(', ', $exotic);
    }
  }

  protected function interact(InputInterface $input, OutputInterface $output) {
    if (!empty($this->queue) && !$input->getOption('no-interaction')) {
      $output->writeln('<info>The following actions will be performed:</info>');
      $this->describeQueue($input, $output);
      $helper = $this->getHelper('question');
      if (!$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', false))) {
        $this->userCanceled = TRUE;
      }
    }
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    if ($this->userCanceled) {
      return;
    }

    // If it's a dry run and the user didn't interact, then we should describe
    // the queue and then stop.
    if ($input->getOption('dry-run') && $input->getOption('no-interaction')) {
      $this->describeQueue($input, $output);
      return;
    }

    $output->writeln('executing!');


    /*    if (function_exists('pcntl_async_signals')) {
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, array($this, 'revertComposerFile'));
      pcntl_signal(SIGTERM, array($this, 'revertComposerFile'));
      pcntl_signal(SIGHUP, array($this, 'revertComposerFile'));
      }
     */
    $this->file = Factory::getComposerFile();
    $io = $this->getIO();

    $this->newlyCreated = !file_exists($this->file);
    if (!is_readable($this->file)) {
      $io->writeError('<error>' . $this->file . ' is not readable.</error>');

      return 1;
    }

    $this->json = new JsonFile($this->file);
    $this->composerBackup = file_get_contents($this->json->getPath());

    $this->performQueue($this->json, $input, $output);
    return;

    // check for writability by writing to the file as is_writable can not be trusted on network-mounts
    // see https://github.com/composer/composer/issues/8231 and https://bugs.php.net/bug.php?id=68926
    if (!is_writable($this->file) && !Silencer::call('file_put_contents', $this->file, $this->composerBackup)) {
      $io->writeError('<error>' . $this->file . ' is not writable.</error>');

      return 1;
    }

    $composer = $this->getComposer(true, $input->getOption('no-plugins'));
    $repos = $composer->getRepositoryManager()->getRepositories();


    $root_package = $composer->getPackage();
    if ($root_package->getName() != 'drupal/drupal') {
      $this->getIO()->write('<error>This command only operates on drupal/drupal packages.</error>');
      return 1;
    }

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

    $packages = $this->reconciler->getUnreconciledPackages();

    $requirements = $this->determineRequirements($input, $output, $packages, $phpVersion, $preferredStability, !$input->getOption('no-update'));

    $requireKey = $input->getOption('dev') ? 'require-dev' : 'require';
    $removeKey = $input->getOption('dev') ? 'require' : 'require-dev';
    $requirements = $this->formatRequirements($requirements);

    // validate requirements format
    $versionParser = new VersionParser();
    foreach ($requirements as $package => $constraint) {
      if (strtolower($package) === $composer->getPackage()->getName()) {
        $io->writeError(sprintf('<error>Root package \'%s\' cannot require itself in its composer.json</error>', $package));

        return 1;
      }
      $versionParser->parseConstraints($constraint);
    }

    $sortPackages = $input->getOption('sort-packages') || $composer->getConfig()->get('sort-packages');

    if (!$this->updateFileCleanly($this->json, $requirements, $requireKey, $removeKey, $sortPackages)) {
      $composerDefinition = $this->json->read();
      foreach ($requirements as $package => $version) {
        $composerDefinition[$requireKey][$package] = $version;
        unset($composerDefinition[$removeKey][$package]);
      }
      $this->json->write($composerDefinition);
    }

    $io->writeError('<info>' . $this->file . ' has been ' . ($this->newlyCreated ? 'created' : 'updated') . '</info>');

    if ($input->getOption('no-update')) {
      return 0;
    }

    try {
      return $this->doUpdate($input, $output, $io, $requirements);
    }
    catch (\Exception $e) {
      $this->revertComposerFile(false);
      throw $e;
    }
  }

  private function doUpdate(InputInterface $input, OutputInterface $output, IOInterface $io, array $requirements) {
    // Update packages
    $this->resetComposer();
    $composer = $this->getComposer(true, $input->getOption('no-plugins'));
    $composer->getDownloadManager()->setOutputProgress(!$input->getOption('no-progress'));

    $updateDevMode = !$input->getOption('update-no-dev');
    $optimize = $input->getOption('optimize-autoloader') || $composer->getConfig()->get('optimize-autoloader');
    $authoritative = $input->getOption('classmap-authoritative') || $composer->getConfig()->get('classmap-authoritative');
    $apcu = $input->getOption('apcu-autoloader') || $composer->getConfig()->get('apcu-autoloader');

    $commandEvent = new CommandEvent(PluginEvents::COMMAND, 'require', $input, $output);
    $composer->getEventDispatcher()->dispatch($commandEvent->getName(), $commandEvent);

    $install = Installer::create($io, $composer);

    $install
      ->setVerbose($input->getOption('verbose'))
      ->setPreferSource($input->getOption('prefer-source'))
      ->setPreferDist($input->getOption('prefer-dist'))
      ->setDevMode($updateDevMode)
      ->setRunScripts(!$input->getOption('no-scripts'))
      ->setSkipSuggest($input->getOption('no-suggest'))
      ->setOptimizeAutoloader($optimize)
      ->setClassMapAuthoritative($authoritative)
      ->setApcuAutoloader($apcu)
      ->setUpdate(true)
      ->setUpdateWhitelist(array_keys($requirements))
      ->setWhitelistTransitiveDependencies($input->getOption('update-with-dependencies'))
      ->setWhitelistAllDependencies($input->getOption('update-with-all-dependencies'))
      ->setIgnorePlatformRequirements($input->getOption('ignore-platform-reqs'))
      ->setPreferStable($input->getOption('prefer-stable'))
      ->setPreferLowest($input->getOption('prefer-lowest'))
    ;

    $status = $install->run();
    if ($status !== 0) {
      $this->revertComposerFile(false);
    }

    return $status;
  }

  private function updateFileCleanly($json, array $new, $requireKey, $removeKey, $sortPackages) {
    $contents = file_get_contents($json->getPath());

    $manipulator = new JsonManipulator($contents);

    foreach ($new as $package => $constraint) {
      if (!$manipulator->addLink($requireKey, $package, $constraint, $sortPackages)) {
        return false;
      }
      if (!$manipulator->removeSubNode($removeKey, $package)) {
        return false;
      }
    }

    file_put_contents($json->getPath(), $manipulator->getContents());

    return true;
  }

  public function revertComposerFile($hardExit = true) {
    $io = $this->getIO();

    if ($this->newlyCreated) {
      $io->writeError("\n" . '<error>Installation failed, deleting ' . $this->file . '.</error>');
      unlink($this->json->getPath());
    }
    else {
      $io->writeError("\n" . '<error>Installation failed, reverting ' . $this->file . ' to its original content.</error>');
      file_put_contents($this->json->getPath(), $this->composerBackup);
    }

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

          case 'showSteps':
            // Next steps only show during perform.
            break;
        }
      }
    }
  }

  protected function performQueue(JsonFile $json, InputInterface $input, OutputInterface $output) {
    $style = new SymfonyStyle($input, $output);
    $io = $this->getIO();
    foreach ($this->queueOrder as $description => $operation) {
      if (isset($this->queue[$operation])) {
        $io->write($description);
        switch ($operation) {
          case 'renamePackage':
            $old_name = $this->rootPackage->getName();
            $new_name = $this->queue[$operation];
            $io->write("Renaming $old_name to $new_name");
            $this->opRenamePackage($json, $new_name);
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
            foreach ($this->queue[$operation] as $add) {
              $this->opAddDependency(
                $this->json, $add[0], $add[1], isset($add[2]), $input->getOption('sort-packages') || $this->getComposer()->getConfig()->get('sort-packages')
              );
            }
            break;

          case 'removeDependency':
            $style->listing($this->queue[$operation]);
            foreach ($this->queue[$operation] as $remove) {
              $this->opRemoveDependency($this->json, $remove);
            }
            break;

          case 'performUpdate':
            // Update will be handled in execute().
            break;

          case 'showSteps':
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

}
