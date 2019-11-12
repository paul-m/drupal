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

  private $json;
  private $file;

  /**
   * Contents of the composer.json file before we modified it.
   *
   * @var string
   */
  private $composerBackup;

  /**
   * Full path to the backup file we made of the original composer.json file.
   *
   * @var string
   */
  private $composerBackupPath;
  protected $userCanceled = FALSE;

  /**
   * Many operations to perform, keyed by operation.
   *
   * Current allowable operations:
   * - backupProject
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
    'Backup the existing project' => 'backupProject',
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
    $root_file_path = $working_dir . '/composer.json';
    $root_file = new JsonFile(Factory::getComposerFile());

    // Make a backup of the composer.json file.
    $backup_root_file_path = $this->backupOriginal($root_file_path);

    // Replace composer.json with one named drupal/converted-project.
    if (!copy(__DIR__ . '/../templates/template.composer.json', $root_file_path)) {
      throw new \Exception('Unable to copy to ' . $root_file->getPath());
    }

    // @todo: Copy config for: Repositories, patches, config for drupal/core-*
    //        plugins.
    $this->copyRepositories($backup_root_file_path, $root_file_path);

    // Add requires for extensions on the file system.
    $reconciler = new ExtensionReconciler($this->getComposer()->getPackage(), $working_dir);
    if ($packages = $reconciler->getUnreconciledPackages()) {
      error_log(print_r($packages, true));
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

      $contents = file_get_contents($root_file_path);
      $manipulator = new JsonManipulator($contents);
      $sort_packages = $input->getOption('sort-packages') || $this->getComposer()->getConfig()->get('sort-packages');
      foreach ($this->formatRequirements($requirements) as $package => $constraint) {
        $manipulator->addLink('require', $package, $constraint, $sort_packages);
      }
      file_put_contents($root_file_path, $manipulator->getContents());
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

    $io->writeError("\n" . '<error>Conversion failed, reverting ' . $this->file . ' to its original content.</error>');
    file_put_contents($this->json->getPath(), $this->composerBackup);

    if ($hardExit) {
      exit(1);
    }
  }

  /**
   *
   * @param string $root_file_path
   * @return string
   */
  protected function backupOriginal($root_file_path) {
    $backup_path = dirname($root_file_path) . '/backup.composer.json';
    if (!copy($root_file_path, $backup_path)) {
      throw new \Excecption('Unable to back up to ' . $backup_path);
    }
    return $backup_path;
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
          case 'backupProject':
            $this->opBackupProject($json);
            break;

          case 'renamePackage':
            $this->opRenamePackage($json, $this->queue[$operation]);
            break;

          case 'addDependency':
          case 'addDevDependency':
            foreach ($this->queue[$operation] as $add) {
              $this->opAddDependency($json, $add[0], $add[1], isset($add[2]), $sort_packages);
            }
            break;

          case 'removeDependency':
            foreach ($this->queue[$operation] as $remove) {
              $this->opRemoveDependency($json, $remove);
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

  protected function opBackupProject(JsonFile $json) {
    // Generate unique file name.
    // Copy original to new.
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

  /**
   * Copy config for: Repositories, patches, config for drupal/core-* plugins.
   */
  protected function copyRepositories($backup_root_file_path, $root_file_path) {
    // Ensure that new root has Drupal Composer facade repo.
    $add_these_repositories = [
      'drupal_composer_facade' => [
        'type' => 'composer',
        'url' => 'https://packages.drupal.org/8'
      ],
      'dunk_me' => [
        'type' => 'composer',
        'url' => 'https://packages.drup__al.org/8'
      ],
    ];
    /*    $file = new JsonFile($backup_root_file_path);
      $json = $file->read();
      $repositories = $json['repositories'] ?? [];
      foreach ($repositories as $repo_name => $repo) {
      if ($repo['url'] == 'https://packages.drupal.org/8') {
      unset($add_these_repositories['drupal_composer_facade']);
      }
      $add_these_repositories[$repo_name] = $repo;
      } */

    $contents = file_get_contents($root_file_path);
    $manipulator = new JsonManipulator($contents);
    foreach ($add_these_repositories as $repo_name => $repo) {
      if (!$manipulator->addRepository($repo_name, $repo)) {
        error_log('it was false.');
      }
    }
    file_put_contents($root_file_path, $manipulator->getContents());
  }

}
