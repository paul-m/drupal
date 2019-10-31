<?php

namespace Grasmash\ComposerConverter\Composer;

use Composer\Command\BaseCommand;
use Composer\Json\JsonFile;
use Composer\Config\JsonConfigSource;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Composer\Package\RootPackage;
use Composer\Composer;

/**
 * Figure out whether we can or should update your setup for Composer.
 *
 * Rules:
 * - We only ever talk about drupal/drupal sites.
 * - DRUPAL_ROOT is always where the drupal/drupal composer.json file lives.
 * - We want to warn people if they have extensions outside their installer
 *   paths.
 * - Extensions in the file system are there for a reason.
 */
class ComposerizeStatusCommand extends BaseCommand {

  /**
   * The Composer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;

  public function __construct(Composer $composer, $name = null) {
    $this->composer = $composer;
    parent::__construct($name);
  }

  /**
   * {@inheritdoc}
   */
  public function configure() {
    $this->setName('composerize-status');
    $this->setDescription("Determine the status and eligibility for compozerizing this project.");
    $this->addOption('project-root', 'r', InputOption::VALUE_REQUIRED, 'The relative path to the root composer.json file.', '.');
    $this->addUsage('--project-root=.');
  }

  protected function infoBanner() {
    return [
      'The Drupal Composerizer Report',
      '- Only works on drupal/drupal projects.',
      '- Tells you what you should do and then you do it.',
      '- Reports all extensions in the codebase.',
    ];
  }

  protected function isDrupalDrupalPackage($json) {
    if (isset($json['name']) && $json['name'] == 'drupal/drupal') {
      if (isset($json['type']) && $json['type'] == 'project') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output) {
    $io = $this->getIO();
    $root = $input->getOption('project-root');

    $root_package = $this->composer->getPackage();

    $style = new SymfonyStyle($input, $output);
    $style->block($this->infoBanner(), NULL, 'warning', ' ', TRUE);

    $style->title('Drupal Composerizer');

    $composer_json_file = $root . '/composer.json';

    if (!file_exists($composer_json_file)) {
      $io->writeError('<info>No composer.json file found in root directory: ' . $root . '</info>');
      return 1;
    }

    $composer_json = json_decode(file_get_contents($composer_json_file), TRUE);

    if ($this->isDrupalDrupalPackage($composer_json)) {
      $io->write('Found drupal/drupal project.');
    }
    else {
      $io->write('Unable to find a drupal/drupal project in root directory: ' . $root);
      $io->write('<info>This command only works with drupal/drupal.</info>');
      return 1;
    }

    // Key is project, value is array of extensions in that project.
    $projects = [];
    /* @var $file \SplFileInfo */
    foreach ($this->findInfoFiles($root) as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension = basename($file->getPathname(), '.info.yml');
      $projects[$project][$extension] = $extension;
    }

    // Reconcile extensions against require and require-dev.
    $requires = array_merge($composer_json['require'], $composer_json['require-dev']);
    // Concern ourselves with drupal/ namespaced packages.
    $requires = array_filter($requires, function ($item) {
      return strpos($item, 'drupal/') === 0;
    }, ARRAY_FILTER_USE_KEY);
    // Make a list of module names from our package names.
    $requires_project_names = [];
    foreach (array_keys($requires) as $package) {
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

    // Convert project names to package names.
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

    $io->write('Checking for cweagans/composer-patches configuration...');
    $io->write($this->getPatchStatus($composer_json));

    return 0;
  }

  /**
   * Find all the info files in the codebase.
   *
   * Exclude hidden extensions and those in the 'testing' package.
   *
   * @param string $root
   *
   * @return \Symfony\Component\Finder\Finder
   *   Finder object ready for iteration.
   */
  protected function findInfoFiles($root) {
    // Discover extensions.
    $finder = new Finder();
    $finder->in($root)
      ->exclude(['core'])
      ->name('*.info.yml')
      ->filter(function ($info_file) {
        $info = Yaml::parseFile($info_file);
        if (isset($info['hidden']) && $info['hidden'] === TRUE) {
          return FALSE;
        }
        if (isset($info['package']) && strtolower($info['package']) == 'testing') {
          return FALSE;
        }
      });
    return $finder;
  }

  protected function getPatchStatus($composer_json) {
    $messages = [];
    $requires = array_merge($composer_json['require'] ?? [], $composer_json['require-dev'] ?? []);

    if (!isset($requires['cweagans/composer-patches'])) {
      $messages[] = '<info>This project does not require the cweagans/composer-patches plugin.</info>';
    }

    if (isset($composer_json['extra']['patches'])) {
      $messages[] = 'Contains patches for these packages: ' . implode(', ', array_keys($composer_json['extra']['patches']));
    }

    if (isset($composer_json['extra']['patches-file'])) {
      $messages[] = 'Uses a patches-file configuration.';
    }

    if (isset($composer_json['extra']['enable-patching'])) {
      $messages[] = 'Enables patching from dependencies.';
    }
    return $messages;
  }

}
