<?php

namespace Grasmash\ComposerConverter\Composer;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Command\BaseCommand;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Figure out whether we can or should update your setup for Composer.
 *
 * Rules:
 * - We only ever talk about drupal/drupal sites.
 * - DRUPAL_ROOT is always where the drupal/drupal composer.json file lives.
 * - We want to warn people if they have extensions outside their installer
 *   paths.
 */
class ComposerizeStatusCommand extends BaseCommand {

  /** @var InputInterface */
  protected $input;
  protected $baseDir;
  protected $composerConverterDir;
  protected $templateComposerJson;
  protected $rootComposerJsonPath;
  protected $drupalRoot;
  protected $drupalRootRelative;
  protected $drupalCoreVersion;

  /** @var Filesystem */
  protected $fs;

  public function configure() {
    $this->setName('composerize-status');
    $this->setDescription("Determine the status and eligibility for updating this project.");
    $this->addOption('drupal-root', 'r', InputOption::VALUE_REQUIRED, 'The relative path to the Drupal root directory.', '.');
    $this->addUsage('--drupal-root=./web');
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *
   * @return int
   */
  public function execute(InputInterface $input, OutputInterface $output) {
    $io = $this->getIO();
    $root = $input->getOption('drupal-root');

    if (!file_exists($root . '/composer.json')) {
      $io->writeError('No composer.json file found in root directory: ' . $root);
      return 1;
    }

    $composer_json = json_decode(file_get_contents($root . '/composer.json'), TRUE);

    // Figure out if we have the things we need.
    $found = FALSE;
    if (isset($composer_json['name']) && $composer_json['name'] == 'drupal/drupal') {
      if (isset($composer_json['type']) && $composer_json['type'] == 'project') {
        $found = TRUE;
        $io->write('Found drupal/drupal project.');
      }
    }
    if (!$found) {
      $io->write('Unable to find a drupal/drupal project in root directory: ' . $root);
      $io->write('This command only works with drupal/drupal.');
      return 1;
    }

    // Discover extensions.
    $finder = new Finder();
    $finder->in($root)
      ->exclude(['core'])
      ->name('*.info.yml')
      ->filter(function ($info_file_name) {
        $info = Yaml::parseFile($info_file_name);
        if (isset($info['hidden']) && $info['hidden'] === TRUE) {
          return FALSE;
        }
        if (isset($info['package']) && strtolower($info['package']) == 'testing') {
          return FALSE;
        }
      });

    // Key is project, value is array of extensions in that project.
    $projects = [];
    // List of just the extensions not arranged into projects.
    $all_extensions = [];
    /* @var $file \SplFileInfo */
    foreach ($finder as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension = basename($file->getPathname(), '.info.yml');
      $projects[$project][$extension] = $extension;
      $all_extensions[$extension] = $extension;
    }

    // Reconcile extensions against require and require-dev.
    $requires = array_merge($composer_json['require'], $composer_json['require-dev']);
    // Concern ourselves with drupal/ namespaced packages.
    $requires = array_filter($requires, function ($item) {
      return strpos($item, 'drupal/') === 0;
    }, ARRAY_FILTER_USE_KEY);
    // Make a list of module names from our package names.
    $requires_project_names = [];
    foreach ($requires as $package => $constraint) {
      $boom_package = explode('/', $package);
      $project_name = $boom_package[1];
      $requires_project_names[$project_name] = $project_name;
    }

    // D.O's Composer facade allows you to require an extension by extension
    // name or by project name, so we have to reconcile that. We prefer to
    // tell people which project to require rather than a module.
    $need_these_packages = [];
    foreach ($projects as $project => $extensions) {
      if ($project == '__unknown_project') {
        continue;
      }
      foreach ($extensions as $extension) {
        if (!in_array($extension, $requires_project_names) && !in_array($project, $requires_project_names)) {
          $need_these_packages[$project] = $project;
        }
      }
    }

    foreach ($need_these_packages as $key => $value) {
      $need_these_packages[$key] = 'drupal/' . $value;
    }

    if (!empty($need_these_packages)) {
      $io->write('We suggest installing the following packages: ' . implode(', ', $need_these_packages));
    }
    if (isset($projects['__unknown_project'])) {
      $io->write('The following extensions do not seem to have associated contrib projects: ' . implode(', ', $projects['__unknown_project']));
    }

    if (empty($need_these_packages) && !isset($projects['__unknown_project'])) {
      $io->write('No unaccounted-for extensions.');
    }

    return 0;
  }

}
