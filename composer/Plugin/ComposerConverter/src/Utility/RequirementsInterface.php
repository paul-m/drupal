<?php

namespace Grasmash\ComposerConverter\Utility;

/**
 * Enforce compatibility with Drupal's hook_requirements() hook.
 */
interface RequirementsInterface {

  /**
   * Requirement severity -- Informational message only.
   */
  const REQUIREMENT_INFO = -1;

  /**
   * Requirement severity -- Requirement successfully met.
   */
  const REQUIREMENT_OK = 0;

  /**
   * Requirement severity -- Warning condition; proceed but flag warning.
   */
  const REQUIREMENT_WARNING = 1;

  /**
   * Requirement severity -- Error condition; abort installation.
   */
  const REQUIREMENT_ERROR = 2;

  /**
   * Check installation requirements and do status reporting.
   *
   * This hook has three closely related uses, determined by the $phase argument:
   * - Checking installation requirements ($phase == 'install').
   * - Checking update requirements ($phase == 'update').
   * - Status reporting ($phase == 'runtime').
   *
   * Note that this hook, like all others dealing with installation and updates,
   * must reside in a module_name.install file, or it will not properly abort
   * the installation of the module if a critical requirement is missing.
   *
   * During the 'install' phase, modules can for example assert that
   * library or server versions are available or sufficient.
   * Note that the installation of a module can happen during installation of
   * Drupal itself (by install.php) with an installation profile or later by hand.
   * As a consequence, install-time requirements must be checked without access
   * to the full Drupal API, because it is not available during install.php.
   * If a requirement has a severity of REQUIREMENT_ERROR, install.php will abort
   * or at least the module will not install.
   * Other severity levels have no effect on the installation.
   * Module dependencies do not belong to these installation requirements,
   * but should be defined in the module's .info.yml file.
   *
   * During installation (when $phase == 'install'), if you need to load a class
   * from your module, you'll need to include the class file directly.
   *
   * The 'runtime' phase is not limited to pure installation requirements
   * but can also be used for more general status information like maintenance
   * tasks and security issues.
   * The returned 'requirements' will be listed on the status report in the
   * administration section, with indication of the severity level.
   * Moreover, any requirement with a severity of REQUIREMENT_ERROR severity will
   * result in a notice on the administration configuration page.
   *
   * @param $phase
   *   The phase in which requirements are checked:
   *   - install: The module is being installed.
   *   - update: The module is enabled and update.php is run.
   *   - runtime: The runtime requirements are being checked and shown on the
   *     status report page.
   *
   * @return array[]
   *   An associative array where the keys are arbitrary but must be unique (it
   *   is suggested to use the module short name as a prefix) and the values are
   *   themselves associative arrays with the following elements:
   *   - title: The name of the requirement.
   *   - value: The current value (e.g., version, time, level, etc). During
   *     install phase, this should only be used for version numbers, do not set
   *     it if not applicable.
   *   - description: The description of the requirement/status.
   *   - severity: The requirement's result/severity level, one of:
   *     - REQUIREMENT_INFO: For info only.
   *     - REQUIREMENT_OK: The requirement is satisfied.
   *     - REQUIREMENT_WARNING: The requirement failed with a warning.
   *     - REQUIREMENT_ERROR: The requirement failed with an error.
   */
  public function requirements($phase);
}
