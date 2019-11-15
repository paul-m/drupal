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

  public function getJsonFile() {
    return $this->jsonFile;
  }

  public function getCombinedRequire() {
    return array_merge(
      $this->getRequire(),
      $this->getRequire(TRUE)
    );
  }

  public function getRequire($dev = FALSE) {
    $key = 'require';
    if ($dev) {
      $key .= '-dev';
    }
    $json_contents = $this->getContents();
    return $json_contents[$key] ?? [];
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
