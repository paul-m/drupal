<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * Scans the file system, tells you which extensions are not accounted for.
 */
class ExtensionReconciler {

  protected $exoticSetupExtensions = NULL;
  protected $needThesePackages = NULL;

  /**
   * The composer.json file we'll inspect.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility
   */
  protected $fromUtility;

  /**
   * The full path to the Composer working directory.
   *
   * This is assumed to contain any filesystem where we can find extensions.
   *
   * @var string
   */
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
   * Whether we should solve for Drupal dependencies based on their project name.
   *
   * @var bool
   */
  protected $preferProjects;

  /**
   * Construct a reconciler.
   *
   * @param \Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility $from_utility
   *   Full path to a composer.json file we'll reconcile against.
   * @param string $working_dir
   *   Full path to the working directory as specified from Composer. This is
   *   assumed to contain any filesystem where we can find extensions.
   */
  public function __construct(JsonFileUtility $from_utility, $working_dir, $prefer_projects = FALSE) {
    $this->fromUtility = $from_utility;
    // Check whether the working dir obviously exists, because realpath() makes it
    // difficult to inject vfsStream for testing.
    if (!file_exists($working_dir)) {
      $working_dir = realpath($working_dir);
    }
    $this->workingDir = $working_dir;
    $this->preferProjects = $prefer_projects;
  }

  /**
   * Get packages for extensions in filesystem, but not in composer.json.
   *
   * @todo This only populates the packages per prefer-projects.
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
   * Get extension packages specified in the composer.json we're converting.
   *
   * @param bool $dev
   *   (optional) Whether to look in require-dev instead of require. Defaults to
   *   FALSE.
   *
   * @return string[]
   *   Composer package specifications for extensions. Key is package name and value
   *   is version constraint.
   */
  public function getSpecifiedExtensions($dev = FALSE) {
    $require_spec = [];
    if ($this->projects === NULL) {
      $this->processNeededPackages();
    }
    $require = $this->fromUtility->getRequire($dev);
    foreach ($this->projects as $project_name => $extensions) {
      // Did the user specify some extensions by their project name?
      $package = 'drupal/' . $project_name;
      if (array_key_exists($package, $require)) {
        $require_spec[$package] = $require[$package];
      }
      foreach ($extensions as $machine_name) {
        $package = 'drupal/' . $machine_name;
        if (array_key_exists($package, $require)) {
          $require_spec[$package] = $require[$package];
        }
      }
    }
    return $require_spec;
  }

  /**
   * Get packages for extensions in filesystem we don't know how to deal with.
   *
   * These could be path repos or other stuff we can't figure out.
   *
   * @return string[]
   *   Array of extension machine names we discovered, keyed by machine name.
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
    // This is our pre-parsed database of info about all the extensions. Keyed by
    // machine name.
    $extension_objects = [];
    /* @var $file \SplFileInfo */
    foreach ($this->findInfoFiles($this->workingDir) as $file) {
      $info = Yaml::parseFile($file->getPathname());
      $project = isset($info['project']) ? $info['project'] : '__unknown_project';
      $extension_name = basename($file->getPathname(), '.info.yml');
      $e = new Extension();
      $e->machineName = $extension_name;
      $e->name = $info['name'] ?? $extension_name;
      $e->pathname = $file->getPathname();
      $e->project = $info['project'] ?? NULL;
      if ($e->version = $info['version'] ?? NULL) {
        $e->semanticVersion = DrupalInspector::getSemanticVersion($e->version);
      }
      $extension_objects[$extension_name] = $e;
      $this->projects[$project][$extension_name] = $extension_name;
    }

    // Reconcile extensions against require and require-dev.
    $require = $this->fromUtility->getCombinedRequire();

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
        $this->exoticSetupExtensions[$extension] = $extension_objects[$extension]->name;
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
          if ($this->preferProjects) {
            $this->needThesePackages[$project] = $project;
          }
          else {
            $this->needThesePackages[$extension] = $extension;
          }
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
