<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Package\RootPackageInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class ExtensionReconciler {

  protected $exoticSetupExtensions = NULL;
  protected $needThesePackages = NULL;
  protected $rootPackage;
  protected $workingDir;

  /**
   * All unreconciled extensions organized by project.
   *
   * Only extensions which have a project can be found here.
   *
   * @var string[][]
   */
  protected $projects = NULL;

  /**
   * Construct a reconciler.
   *
   * @param RootPackageInterface $root_package
   *   The package we're inspecting.
   * @param string $working_dir
   *   Relative working directory as specified from Composer.
   */
  public function __construct(RootPackageInterface $root_package, $working_dir) {
    $this->rootPackage = $root_package;
    $this->workingDir = $working_dir;
  }

  /**
   * Get packages for extensions in filesystem, but not in composer.json.
   *
   * @return string[]
   *   Array of extension package names, such as drupal/ajax_example, keyed by
   *   the extension name, such as ajax_example.
   */
  public function getUnreconciledPackages() {
    if ($this->needThesePackages === NULL) {
      $this->processNeededPackages();
    }
    return $this->needThesePackages;
  }

  /**
   * Get packages for extensions in filesystem we don't know how to deal with.
   *
   * These could be path repos or other stuff we can't figure out.
   */
  public function getExoticPackages() {
    if ($this->exoticSetupExtensions === NULL) {
      $this->processNeededPackages();
    }
    return $this->exoticSetupExtensions;
  }

  /**
   * Process our package and filesystem into reconcilable information.
   */
  protected function processNeededPackages() {
    $this->projects = [];
    $extension_objects = [];
    /* @var $file \SplFileInfo */
    foreach ($this->findInfoFiles(realpath($this->workingDir)) as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension = basename($file->getPathname(), '.info.yml');
      $this->projects[$project][$extension] = $extension;
      $e = new Extension();
      $e->name = basename($file->getPathname(), '.info.yml');
      $e->pathname = $file->getPathname();
      $e->project = isset($info['project']) ? $info['project'] : NULL;
      $extension_objects[$e->name] = $e;
    }

    // Reconcile extensions against require and require-dev.
    $requires = array_merge($this->rootPackage->getRequires(), $this->rootPackage->getDevRequires());
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
      // If our extension is already required and belongs to a project, then
      // we should include that project in the list so that we account for other
      // extensions in the same project.
      if (isset($extension_objects[$extension_or_project_name]) || !empty($extension_objects[$extension_or_project_name]->project)) {
        $p = $extension_objects[$extension_or_project_name]->project;
        $requires_ext_or_proj_names[$p] = $p;
      }
    }

    // Handle exotic extensions which don't have a project name. These could
    // need a special repo or to not be required at all, so we just punt on
    // them.
    $this->exoticSetupExtensions = [];
    if (isset($this->projects['__unknown_project'])) {
      foreach ($this->projects['__unknown_project'] as $extension) {
        $this->exoticSetupExtensions[$extension] = $extension;
      }
      unset($this->projects['__unknown_project']);
    }

    // D.O's Composer facade allows you to require an extension by extension
    // name or by project name, so we have to reconcile that.
    $this->needThesePackages = [];
    foreach ($this->projects as $project => $extensions) {
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
      // Test paths can include unmarked test extensions, especially themes.
      ->notPath('tests')
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
