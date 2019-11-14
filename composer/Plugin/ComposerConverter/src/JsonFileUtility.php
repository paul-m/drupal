<?php

namespace Drupal\Composer\Plugin\ComposerConverter;

use Composer\Json\JsonFile;

class JsonFileUtility {

  /**
   * The JsonFile object we're working on.
   *
   * @var \Composer\Json\JsonFile
   */
  protected $jsonFile;
  protected $jsonFileContents;

  public function __construct(JsonFile $json_file) {
    $this->jsonFile = $json_file;
  }

  protected function getContents() {
    if (empty($this->jsonFileContents)) {
      $this->jsonFileContents = $this->jsonFile->read();
    }
    return $this->jsonFileContents;
  }

  public function getCombinedRequire() {
    $json_contents = $this->getContents();
    return array_merge(
      $json_contents['require'] ?? [],
      $json_contents['require-dev'] ?? []
    );
  }

  public function getExtra() {
    $json_contents = $this->getContents();
    return $json_contents['extra'] ?? [];
  }

  public function getRepositories() {
    $json_contents = $this->getContents();
    return $json_contents['repositories'] ?? [];
  }

  public function getSortPackages() {
    $json_contents = $this->getContents();
    return $json_contents['config']['sort-packages'] ?? FALSE;
  }

}
