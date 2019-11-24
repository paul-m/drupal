<?php

namespace Drupal\Composer\Plugin\ComposerConverter\Extension;

class ExtensionCollection {

  /**
   * All the extensions keyed by their machine name.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[]
   */
  protected $extensions;

  /**
   * Arrays of extensions keyed by project name.
   *
   * @var \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension[][]
   */
  protected $projectExtensions;

  public function __construct(Extension ...$extensions) {
    $this->extensions = $extensions;
  }

  public function getPathForExtension($machine_name) {
    /* @var $extension \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension */
    $extension = $this->extensions[$machine_name] ?? NULL;
    if (!$extension) {
      return NULL;
    }
    return $extension->getInfoFile()->getPath();
  }

  public function getExtensionsForProject($project_name) {
    if (!$this->projectExtensions) {
      $this->sortProjectExtensions();
    }
    return $this->projectExtensions[$project_name] ?? [];
  }

  public function getProjectNames() {
    if (!$this->projectExtensions) {
      $this->sortProjectExtensions();
    }
    return array_keys($this->projectExtensions);
  }

  protected function sortProjectExtensions() {
    $this->projectExtensions = [];
    /* @var $extension \Drupal\Composer\Plugin\ComposerConverter\Extension\Extension */
    foreach($this->extensions as $machine_name => $extension) {
      


    }
  }

}
