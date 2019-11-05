<?php

namespace Drupal\Composer\Plugin\ComposerStatus;

use Composer\Command\BaseCommand;
use Composer\Composer;
use Composer\Package\RootPackageInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Figure out whether we can or should update your setup for Composer.
 */
class StatusCommand extends BaseCommand {

  /**
   * The Composer object.
   *
   * @var \Composer\Composer
   */
  protected $composer;
  protected $needThesePackages;
  protected $exoticSetupExtensions;

  public function __construct(Composer $composer, $name = null) {
    $this->composer = $composer;
    parent::__construct($name);
  }

  /**
   * {@inheritdoc}
   */
  public function configure() {
    $this->setName('drupal-legacy-converter');
    $this->setDescription("Convert your Drupal to use Composer.");
  }

  /**
   * {@inheritdoc}
   */
  public function execute(InputInterface $input, OutputInterface $output) {
    $io = $this->getIO();
    $root_package = $this->composer->getPackage();

    $style = new SymfonyStyle($input, $output);

    $style->title('Drupal Composer Status');

    // A series of steps the user can take to perform their conversion.
    $things_to_do = [];

    if ($root_package->getName() == 'drupal/drupal') {
      $things_to_do[] = 'Rename the project so it is not drupal/drupal.';
    }
    if ($root_package->getName() == 'drupal-composer/drupal-project') {
      $things_to_do[] = 'Read up on how to convert to drupal/recommended-project: http://docs/here';
    }

    $requires = array_merge($root_package->getRequires(), $root_package->getDevRequires());

    $mappings = [
      'drupal/core' => 'drupal/core-recommended',
      'webflo/drupal-core-strict' => 'drupal/core-recommended',
      'webflo/drupal-core-require-dev' => 'drupal/core-dev',
    ];
    foreach ($mappings as $old => $new) {
      if (isset($requires[$old])) {
        $things_to_do[] = "Change $old to $new.";
      }
    }

    if (isset($requires['drupal/core'])) {
      $things_to_do[] = 'Add drupal/core-dev to the require-dev section.';
    }

    if (isset($requires['drupal-composer/drupal-scaffold'])) {
      $things_to_do[] = 'Read up on how to convert to drupal/core-composer-scaffold: http://docs/here';
    }

    // Deal with extensions as packages.
    $this->processNeededPackages($root_package, $input->getOption('working-dir'));
    if (!empty($this->needThesePackages)) {
      foreach ($this->needThesePackages as $need) {
        $things_to_do[] = "Add an extension: composer require $need";
      }
    }
    if ($this->exoticSetupExtensions) {
      $things_to_do[] = 'Determine how to require these extensions: ' . implode(', ', $this->exoticSetupExtensions);
    }

    // If we have extras for patching, but don't require the patch plugin then
    // tell the user.
    $extra = $root_package->getExtra();
    $patch_keys_present = [];
    foreach (['patches', 'patches-file', 'enable-patching'] as $patch_key) {
      if (isset($extra[$patch_key])) {
        $patch_keys_present[$patch_key] = $patch_key;
      }
    }
    if ($patch_keys_present) {
      if (!isset($requires['cweagans/composer-patches'])) {
        $things_to_do[] = 'Project specifies patches in extra (' . implode(', ', $patch_keys_present) . '), but does not require the cweagans/composer-patches plugin.';
      }
    }

    $io->write('Things to do:');
    $style->listing($things_to_do);

    return 0;
  }

  /**
   * Process the requirements and determine extensions not accounted-for.
   *
   * @param RootPackageInterface $root_package
   *   The package we're inspecting.
   * @param string $working_dir
   *   Relative working directory as specified from Composer.
   */
  protected function processNeededPackages(RootPackageInterface $root_package, $working_dir) {
    $projects = [];
    /* @var $file \SplFileInfo */
    foreach ($this->findInfoFiles(realpath($working_dir)) as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension = basename($file->getPathname(), '.info.yml');
      $projects[$project][$extension] = $extension;
    }

    // Reconcile extensions against require and require-dev.
    $requires = array_merge($root_package->getRequires(), $root_package->getDevRequires());
    // Concern ourselves with drupal/ namespaced packages.
    $requires = array_filter($requires, function ($item) {
      return strpos($item, 'drupal/') === 0;
    }, ARRAY_FILTER_USE_KEY);
    // Make a list of module names from our package names.
    $requires_ext_or_proj_names = [];
    foreach (array_keys($requires) as $package) {
      $boom_package = explode('/', $package);
      $extension_or_project_name = $boom_package[1];
      $requires_ext_or_proj_names[$extension_or_project_name] = $extension_or_project_name;
    }

    // Handle exotic extensions which don't have a project name. These could
    // need a special repo or to not be required at all, so we just punt on
    // them.
    $this->exoticSetupExtensions = [];
    if (isset($projects['__unknown_project'])) {
      foreach ($projects['__unknown_project'] as $extension) {
        $this->exoticSetupExtensions[$extension] = $extension;
      }
      unset($projects['__unknown_project']);
    }

    // D.O's Composer facade allows you to require an extension by extension
    // name or by project name, so we have to reconcile that.
    $this->needThesePackages = [];
    foreach ($projects as $project => $extensions) {
      // The user has already required the project in their composer.json by
      // project name. This also covers extensions where the extension name is
      // the same as the d.o project name.
      if (isset($requires_ext_or_proj_names[$project])) {
        continue;
      }
      // Loop through all discovered extensions for this project to find out if
      // they're already in the composer.json.
      foreach ($extensions as $extension) {
        if (!in_array($extension, $requires_ext_or_proj_names)) {
          $this->needThesePackages[$extension] = $extension;
        }
      }
    }
    // Convert extension names to package names.
    foreach ($this->needThesePackages as $key => $value) {
      $this->needThesePackages[$key] = 'drupal/' . $value;
    }
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

}
