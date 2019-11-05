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

use Drupal\Composer\Plugin\ComposerConverter\Op\RenamePackageOp;
use Drupal\Composer\Plugin\ComposerConverter\Op\AddDependencyOp;

/**
 */
class ConvertCommand extends InitCommand {

  private $newlyCreated;
  private $json;
  private $file;
  private $composerBackup;

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
        new InputArgument('core-version', InputArgument::OPTIONAL, 'Minimum Drupal core version to require.', '^8.9'),

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
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.')
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
    $this->reconciler = new ExtensionReconciler($this->getComposer()->getPackage(), $input->getOption('working-dir'));
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    if (function_exists('pcntl_async_signals')) {
      pcntl_async_signals(true);
      pcntl_signal(SIGINT, array($this, 'revertComposerFile'));
      pcntl_signal(SIGTERM, array($this, 'revertComposerFile'));
      pcntl_signal(SIGHUP, array($this, 'revertComposerFile'));
    }

    $this->file = Factory::getComposerFile();
    $io = $this->getIO();

    $this->newlyCreated = !file_exists($this->file);
    if (!is_readable($this->file)) {
      $io->writeError('<error>' . $this->file . ' is not readable.</error>');

      return 1;
    }

    $this->json = new JsonFile($this->file);
    $this->composerBackup = file_get_contents($this->json->getPath());

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

  protected function interact(InputInterface $input, OutputInterface $output) {
    $add = new AddDependencyOp('crell/api-problem', '@stable');
    $this->getIO()->write($add->summarize());
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

}
