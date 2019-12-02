<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Command;

use Composer\Factory;
use Composer\Json\JsonFile;
use Composer\Json\JsonManipulator;
use Composer\Repository\CompositeRepository;
use Composer\Repository\PlatformRepository;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

class ExtensionReconcileCommand extends ConvertCommandBase {

  private $rootComposerJsonPath;
  protected $userCanceled = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function configure() {
    $this
      ->setName('drupal:reconcile-extensions')
      ->setDescription('Declare your extensions in composer.json.')
      ->setDefinition([
        new InputOption('dry-run', NULL, InputOption::VALUE_NONE, 'Display all the changes that would occur, without performing them.'),
        new InputOption('no-update', NULL, InputOption::VALUE_NONE, 'Perform conversion but does not perform update.'),
        new InputOption('sort-packages', NULL, InputOption::VALUE_NONE, 'Sorts packages when adding/updating a new dependency'),
        new InputOption('prefer-projects', NULL, InputOption::VALUE_NONE, 'When possible, use d.o project name instead of extension name.'),
      ])
      ->setHelp(
        <<<EOT
This command does the following things:
 * Determine if there are any extension on the file system which are not
   represented in the root composer.json.
 * Declare these extensions within composer.json so that you can use Composer
   to manage them.
 * Remove the existing extensions from the file system.
EOT
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function interact(InputInterface $input, OutputInterface $output) {
    if (!$input->getOption('no-interaction')) {
      $style = new SymfonyStyle($input, $output);
      $output->write('<info>The following actions will be performed:</info>');
      $item_list = [
        'Determine if there are any extension on the file system which are not represented in composer.json.',
        'Declare these extensions within composer.json so that you can use Composer to manage them.',
        'Remove the existing extensions from the file system.',
      ];
      $style->listing($item_list);
      if (!$input->getOption('no-interaction')) {
        $helper = $this->getHelper('question');
        $this->userCanceled = !$helper->ask($input, $output, new ConfirmationQuestion('Continue? ', FALSE));
      }
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

    // Make a reconciler for our root composer.json.
    $io->write(' - Scanning the filesystem for extensions not in the composer.json file...');
    $reconciler = new ExtensionReconciler(
      new JsonFileUtility(new JsonFile($this->rootComposerJsonPath)),
      $working_dir,
      $input->getOption('prefer-projects')
    );
    // Get our list of packages for our unreconciled extensions.
    $add_packages = $reconciler->getUnreconciledPackages();

    // Add all the packages we need. We have to do some basic solving here, much
    // like composer require does.
    if ($add_packages) {
      // Use the factory to create a new Composer object, so that we can use
      // changes in our root composer.json.
      $composer = Factory::create($io, $this->rootComposerJsonPath);

      // Populate $this->repos so that InitCommand can use it.
      $this->repos = new CompositeRepository(array_merge(
          [new PlatformRepository([], $composer->getConfig()->get('platform') ?: [])],
          $composer->getRepositoryManager()->getRepositories()
      ));

      // Figure out prefer-stable.
      if ($composer->getPackage()->getPreferStable()) {
        $preferred_stability = 'stable';
      }
      else {
        $preferred_stability = $composer->getPackage()->getMinimumStability();
      }
      $php_version = $this->repos->findPackage('php', '*')->getPrettyVersion();

      // Do some constraint resolution.
      $requirements = $this->determineRequirements($input, $output, $add_packages, $php_version, $preferred_stability);
      if ($requirements) {
        // Add our new dependencies.
        $manipulator = new JsonManipulator(file_get_contents($this->rootComposerJsonPath));
        $sort_packages = $input->getOption('sort-packages') || (new JsonFileUtility(new JsonFile($this->rootComposerJsonPath)))->getSortPackages();
        foreach ($this->formatRequirements($requirements) as $package => $constraint) {
          $manipulator->addLink('require', $package, $constraint, $sort_packages);
        }
        file_put_contents($this->rootComposerJsonPath, $manipulator->getContents());
      }

      if (!$input->getOption('dry-run')) {
        $unreconciled = $reconciler->getAllUnreconciledExtensions();
        $io->write(' - Removing these extensions from the file system: <info>' . implode('</info>, <info>', array_keys($unreconciled)) . '</info>');
        $remove_paths = [];
        foreach ($unreconciled as $machine_name => $extension) {
          $remove_paths[] = dirname($extension->getInfoFile());
        }
        (new Filesystem())->remove($remove_paths);
      }
    }

    // Alert the user that they have unreconciled extensions.
    if ($exotic = $reconciler->getExoticPackages()) {
      $style = new SymfonyStyle($input, $output);
      $io->write(' - Discovered extensions which are not in the original composer.json, and which do not have drupal.org projects. These extensions will need to be added manually if you wish to manage them through Composer:');
      $style->listing($exotic);
    }

    if (!$this->isSubCommand()) {
      $io->write(['<info>Finished!</info>', '']);
    }
  }

}
