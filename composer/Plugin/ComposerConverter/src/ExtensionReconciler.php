<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;
use Composer\Json\JsonFile;

class ExtensionReconciler {

  protected $exoticSetupExtensions = NULL;
  protected $needThesePackages = NULL;

  /**
   *
   * @var \Composer\Json\JsonFile
   */
  protected $composerJsonFile;
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
   * @param string $composer_json_path
   *   Full path to a composer.json file we'll reconcile against.
   * @param string $working_dir
   *   Relative working directory as specified from Composer.
   */
  public function __construct($composer_json_path, $working_dir) {
    $this->composerJsonFile = new JsonFile($composer_json_path);
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
    // Find all the extensions in the file system, sorted by D.O project name.
    $this->projects = [];
    $extension_objects = [];
    /* @var $file \SplFileInfo */
    foreach ($this->findInfoFiles(realpath($this->workingDir)) as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension_name = basename($file->getPathname(), '.info.yml');
      $this->projects[$project][$extension_name] = $extension_name;
      $e = new Extension();
      $e->name = basename($file->getPathname(), '.info.yml');
      $e->pathname = $file->getPathname();
      $e->project = isset($info['project']) ? $info['project'] : NULL;
      $extension_objects[$e->name] = $e;
    }

    // Reconcile extensions against require and require-dev.
    $require = (new JsonFileUtility($this->composerJsonFile))->getCombinedRequire();

    // Concern ourselves with drupal/ namespaced packages.
    $require = array_filter($require, function ($item) {
      return strpos($item, 'drupal/') === 0;
    }, ARRAY_FILTER_USE_KEY);

    // Make a list of extension/project names from our package names.
    $required_ext_or_proj_names = [];
    foreach (array_keys($require) as $package) {
      $boom_package = explode('/', $package);
      $extension_or_project_name = $boom_package[1];
      $required_ext_or_proj_names[$extension_or_project_name] = $extension_or_project_name;
      // If our extension is already required and belongs to a project, then
      // we should include that project in the list so that we account for other
      // extensions in the same project.
      if (isset($extension_objects[$extension_or_project_name]) || !empty($extension_objects[$extension_or_project_name]->project)) {
        $p = $extension_objects[$extension_or_project_name]->project;
        $required_ext_or_proj_names[$p] = $p;
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
      if (isset($required_ext_or_proj_names[$project])) {
        continue;
      }
      // Loop through all discovered extensions for this project to find out if
      // they're already in the composer.json.
      foreach ($extensions as $extension) {
        if (!in_array($extension, $required_ext_or_proj_names)) {
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
      ->exclude(['core', 'vendor'])
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
