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
class NewConvertCommand extends InitCommand {

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
    $this->rootComposerJsonPath = realpath($input->getOption('working-dir')) . '/composer.json';
  }

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

  protected function execute(InputInterface $input, OutputInterface $output) {
    if ($this->userCanceled) {
      return;
    }
    $working_dir = realpath($input->getOption('working-dir'));
    $io = $this->getIO();

    // Make a backup of the composer.json file.
    $this->composerBackupPath = $this->createBackup($this->rootComposerJsonPath);
    $this->composerBackupContents = file_get_contents($this->composerBackupPath);

    // Replace composer.json with our template.
    if (!copy(__DIR__ . '/../templates/template.composer.json', $this->rootComposerJsonPath)) {
      $io->write('<error>Unable to copy to ' . $this->rootComposerJsonPath . '</error>');
      $this->revertComposerFile(TRUE);
    }

    // @todo: Copy config for: Repositories, patches, config for drupal/core-*
    //        plugins.
    $this->copyRepositories($this->rootComposerJsonPath);
    $this->copyExtra($this->rootComposerJsonPath);

    // Package names of packages we should add, such as cweagans/composer-patches.
    // For normal packages, the key is the package name. For Drupal extensions, the
    // key is the extension machine name, and the value is the package name.
    $add_packages = [];

    // If extra has patch info, require cweagans/composer-patches.
    if ($this->hasPatchesConfig()) {
      $add_packages['cweagans/composer-patches'] = 'cweagans/composer-patches';
    }

    // Add requires for extensions on the file system.
    $reconciler = new ExtensionReconciler($this->getComposer()->getPackage(), $working_dir);
    $add_packages = array_merge($add_packages, $reconciler->getUnreconciledPackages());

    // Add all the packages we need.
    if ($add_packages) {
      $composer = $this->getComposer(true, $input->getOption('no-plugins'));
      // @todo Does this use my newly defined repos or the stale ones?
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

      // Figure out if we should sort packages.
      $json_contents = (new JsonFile($this->rootComposerJsonPath))->read();
      $sort_packages = $json_contents['config']['sort-packages'] ?? FALSE;
      $sort_packages = $input->getOption('sort-packages') || $sort_packages;

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

    // @todo: Configure drupal/core-composer-scaffold based on
    //        drupal-composer/drupal-scaffold config.',
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
      'merge-plugin',
      'patches',
      'patches-file',
      'patches-ignore',
      'enable-patching',
    ];
    $backup_json = (new JsonFile($this->composerBackupPath))->read();
    $extra_json = $backup_json['extra'] ?? [];

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

    $backup_json = (new JsonFile($this->composerBackupPath))->read();
    $backup_repositories_json = $backup_json['repositories'] ?? [];

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
    $json_contents = (new JsonFile($this->composerBackupPath))->read();
    $extra_json_keys = array_keys($json_contents['extra'] ?? []);
    foreach ($patch_config_keys as $patch_config) {
      if (in_array($patch_config, $extra_json_keys)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
