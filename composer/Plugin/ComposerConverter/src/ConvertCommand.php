<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Composer\Command\InitCommand;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Composer\Semver\Semver;

/**
 */
class ConvertCommand extends InitCommand {

  /**
   * Contents of the composer.json file before we modified it.
   *
   * @var string
   */
  private $composerBackupContents;

  /**
   * Full path to the backup file we made of the original composer.json file.
   *
   * @var string
   */
  private $composerBackupPath;
  private $rootComposerJsonPath;
  protected $userCanceled = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('drupal-legacy-convert')
      ->setDescription('Convert your Drupal project to a Composer-based one.')
      ->setDefinition(array(
        new InputOption('package-name', NULL, InputOption::VALUE_REQUIRED, 'The new package name, to replace drupal/drupal.', 'drupal/legacy-project-converted'),
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('no-update', null, InputOption::VALUE_NONE, 'Perform conversion but does not perform update.'),
        new InputOption('sort-packages', null, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
        new InputOption('prefer-projects', NULL, InputOption::VALUE_NONE, 'When possible, use d.o project name instead of extension name.'),
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

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output) {
    parent::initialize($input, $output);
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    $style_io = new SymfonyStyle($input, $output);
    $output->writeln('<info>The following actions will be performed:</info>');
    $item_list = [
      'Make a backup of your composer.json file.',
      'Replace composer.json with one named drupal/converted-project.',
      'Copy config for: Repositories, patches, config for drupal/core-* plugins.',
      'Add requires for extensions on the file system.',
      'Configure drupal/core-composer-scaffold based on drupal-composer/drupal-scaffold config.',
    ];
    $style_io->listing($item_list);
    if (!$input->getOption('no-interaction')) {
      $helper = $this->getHelper('question');
      $this->userCanceled = !$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', false));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output) {
    if ($this->userCanceled) {
      return;
    }
    $io = $this->getIO();

    $working_dir = realpath($input->getOption('working-dir'));
    $this->rootComposerJsonPath = $working_dir . '/composer.json';

    if (!file_exists($this->rootComposerJsonPath)) {
      $io->write('<error>Unable to convert file because it does not exist: ' . $this->rootComposerJsonPath . '</error>');
      $io->write('Change to the root directory of your project, or use the --working-dir option.');
      return 1;
    }

    // Make a backup of the composer.json file.
    $this->composerBackupPath = $this->createBackup($this->rootComposerJsonPath);
    $this->composerBackupContents = file_get_contents($this->composerBackupPath);

    // Replace composer.json with our template.
    $drupal_class_file = $this->locateDrupalClassFile($working_dir);
    $core_minor = $this->determineDrupalCoreVersion($drupal_class_file);
    $template_contents = file_get_contents(__DIR__ . '/../templates/template.composer.json');
    $template_contents = str_replace('%core_minor%', $core_minor, $template_contents);
    if (file_put_contents($this->rootComposerJsonPath, $template_contents) === FALSE) {
      $io->write('<error>Unable to replace composer.json file.</error>');
      $this->revertComposerFile();
      return 1;
    }

    // Copy config for: Repositories, patches, config for drupal/core-* plugins.
    $this->copyRepositories($this->rootComposerJsonPath);
    $this->copyExtra($this->rootComposerJsonPath);

    // @todo: Configure drupal/core-composer-scaffold based on
    //        drupal-composer/drupal-scaffold config.',
    ;

    // Add packages we know we should bring forward from the old composer.json.
    // Figure out if we should sort packages.
    $sort_packages = $input->getOption('sort-packages') || (new JsonFileUtility(new JsonFile($this->rootComposerJsonPath)))->getSortPackages();
    $contents = file_get_contents($this->rootComposerJsonPath);
    $manipulator = new JsonManipulator($contents);
    $reconciler = new ExtensionReconciler($this->composerBackupPath, $working_dir);
    foreach ($reconciler->getSpecifiedExtensions($this->composerBackupPath) as $package => $constraint) {
      error_log("$package -> $constraint");
      $manipulator->addLink('require', $package, $constraint, $sort_packages);
    }
    foreach ($reconciler->getSpecifiedExtensions($this->composerBackupPath, TRUE) as $package => $constraint) {
      $manipulator->addLink('require-dev', $package, $constraint, $sort_packages);
    }
    file_put_contents($this->rootComposerJsonPath, $manipulator->getContents());


    // Package names of packages we should add, such as cweagans/composer-patches.
    // For normal packages, the key and value are both the package name. For Drupal
    // extensions, the key is the extension machine name, and the value is the
    // package name.
    $add_packages = [];

    // If extra has patch info, require cweagans/composer-patches.
    if ($this->hasPatchesConfig()) {
      $add_packages['cweagans/composer-patches'] = 'cweagans/composer-patches';
    }

    // Add requires for extensions on the file system.
    $add_packages = array_merge($add_packages, $reconciler->getUnreconciledPackages($input->getOption('prefer-projects')));

    // Add all the packages we need.
    if ($add_packages) {
      // @todo Does this use my newly defined repos or the stale ones?
      $composer = $this->getComposer(true, $input->getOption('no-plugins'));
      $repos = $composer->getRepositoryManager()->getRepositories();

      $platform_overrides = $composer->getConfig()->get('platform') ?: array();
      // initialize $this->repos as it is used by the parent InitCommand
      $this->repos = new CompositeRepository(array_merge(
          array(new PlatformRepository(array(), $platform_overrides)), $repos
      ));
      if ($composer->getPackage()->getPreferStable()) {
        $prefer_stable = 'stable';
      }
      else {
        $prefer_stable = $composer->getPackage()->getMinimumStability();
      }
      $phpVersion = $this->repos->findPackage('php', '*')->getPrettyVersion();
      $requirements = $this->determineRequirements($input, $output, $add_packages, $phpVersion, $prefer_stable, !$input->getOption('no-update'));

      // Add our new dependencies.
      $contents = file_get_contents($this->rootComposerJsonPath);
      $manipulator = new JsonManipulator($contents);
      foreach ($this->formatRequirements($requirements) as $package => $constraint) {
        $manipulator->addLink('require', $package, $constraint, $sort_packages);
      }
      file_put_contents($this->rootComposerJsonPath, $manipulator->getContents());
    }

    // @todo: Alert the user that they have unreconciled extensions.
    /*
      if ($exotic = $reconciler->getExoticPackages()) {
      }
     */
  }

  public function revertComposerFile($hardExit = true) {
    $io = $this->getIO();

    $io->writeError("\n" . '<error>Conversion failed, reverting ' . $this->rootComposerJsonPath . ' to its original contents.</error>');
    file_put_contents($this->rootComposerJsonPath, $this->composerBackupContents);

    if ($hardExit) {
      exit(1);
    }
  }

  /**
   *
   * @param string $root_file_path
   * @return string
   */
  protected function createBackup($root_file_path) {
    $backup_path = dirname($root_file_path) . '/backup.composer.json';
    if (!copy($root_file_path, $backup_path)) {
      throw new \Excecption('Unable to back up to ' . $backup_path);
    }
    return $backup_path;
  }

  protected function copyExtra($root_file_path) {
    $extras_to_copy = [
      'drupal-core-project-message',
      'drupal-scaffold',
      'installer-paths',
      // Merge plugin.
      'merge-plugin',
      // Patch plugin.
      'patches',
      'patches-file',
      'patches-ignore',
      'enable-patching',
    ];
    $extra_json = (new JsonFileUtility(new JsonFile($this->composerBackupPath)))->getExtra();

    foreach (array_keys($extra_json) as $extra) {
      if (!in_array($extra, $extras_to_copy)) {
        unset($extra_json[$extra]);
      }
    }

    $manipulator = new JsonManipulator(file_get_contents($root_file_path));
    foreach ($extra_json as $name => $value) {
      $manipulator->addSubNode('extra', $name, $value);
    }
    file_put_contents($root_file_path, $manipulator->getContents());
  }

  /**
   * Copy config for: Repositories, patches, config for drupal/core-* plugins.
   */
  protected function copyRepositories($root_file_path) {
    // Ensure that new root has Drupal Composer facade repo.
    $required_repositories = [
      'drupal_composer_facade' => [
        'type' => 'composer',
        'url' => 'https://packages.drupal.org/8'
      ],
    ];

    $backup_repositories_json = (new JsonFileUtility(new JsonFile($this->composerBackupPath)))->getRepositories();

    $manipulator = new JsonManipulator(file_get_contents($root_file_path));
    $manipulator->addMainKey('repositories', array_merge($backup_repositories_json, $required_repositories));
    file_put_contents($root_file_path, $manipulator->getContents());
  }

  protected function hasPatchesConfig() {
    $patch_config_keys = [
      'patches',
      'patches-file',
      'patches-ignore',
      'enable-patching',
    ];
    $extra_json_keys = array_keys((new JsonFileUtility(new JsonFile($this->composerBackupPath)))->getExtra());
    foreach ($patch_config_keys as $patch_config) {
      if (in_array($patch_config, $extra_json_keys)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @return mixed|string
   * @throws \Exception
   */
  protected function determineDrupalCoreVersion($drupal_class_file) {
    // Default to a reasonable previous minor version. When the user updates, they'll
    // get the newest. This is not optimal, but might be required if, for instance,
    // they have never installed this project and don't have a \Drupal class to load.
    $drupal_core_constraint = '^8.7';

    if ($drupal_class_file) {
      $core_version = DrupalInspector::determineDrupalCoreVersionFromDrupalPhp(file_get_contents($drupal_class_file));
      if (!Semver::satisfiedBy([$core_version], "*")) {
        throw new \Exception("Drupal core version $core_version is invalid.");
      }
      // Use major and minor. We know major and minor are present because this
      // version comes from \Drupal::VERSION.
      if (preg_match('/^(\d+).(\d+)./', $core_version, $matches)) {
        $drupal_core_constraint = '^' . $matches[1] . '.' . $matches[2];
      }
    }

    return $drupal_core_constraint;
  }

  protected function locateDrupalClassFile($working_dir) {
    // Basic drupal/drupal or legacy file layout.
    $drupal_class = $working_dir . '/core/lib/Drupal.php';
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    // Both drupal-composer/drupal-scaffold and drupal/core-composer-scaffold default
    // to a docroot of 'web'. If we find it there, we're done.
    $drupal_class = $working_dir . '/web/core/lib/Drupal.php';
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    // Try with drupal/core-composer-scaffold configuration.
    $extra = (new JsonFileUtility(new JsonFile($this->composerBackupPath)))->getExtra();
    $drupal_class = realpath($working_dir . ($extra['drupal-scaffold']['locations']['web-root'] ?? '') . 'core/lib/Drupal.php');
    if (file_exists($drupal_class)) {
      return $drupal_class;
    }
    return FALSE;
  }

}
