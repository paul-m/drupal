<?php

namespace Drupal\Tests\Composer\Plugin\ComposerConverter;

use PHPUnit\Framework\TestCase;
use Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler;
use Drupal\Composer\Plugin\ComposerConverter\JsonFileUtility;

/**
 * @coversDefaultClass Drupal\Composer\Plugin\ComposerConverter\ExtensionReconciler
 * @group ComposerConverter
 */
class ExtensionReconcilerTest extends TestCase {

  public function provideGetSpecifiedExtensions() {
    return [
      'none' => [
        [], [], [],
      ],
      'required_project' => [
        ['drupal/project' => '^1.0'],
        ['drupal/project' => '^1.0'],
        ['project' => ['module', 'module2']],
      ],
      'required_extension' => [
        ['drupal/module2' => '^1.0'],
        ['drupal/module2' => '^1.0'],
        ['project' => ['module', 'module2']],
      ],
      'required_project_and_extension' => [
        [
          'drupal/module2' => '^1.0',
          'drupal/project2' => '^2.0',
        ],
        ['drupal/module2' => '^1.0', 'drupal/project2' => '^2.0'],
        [
          'project' => ['module', 'module2'],
          'project2' => ['module3', 'module4'],
        ],
      ],
    ];
  }

  /**
   * @dataProvider provideGetSpecifiedExtensions
   */
  public function testGetSpecifiedExtensions($expected, $require, $projects) {
    $from = $this->getMockBuilder(JsonFileUtility::class)
      ->disableOriginalConstructor()
      ->setMethods(['getRequire'])
      ->getMock();
    $from->expects($this->once())
      ->method('getRequire')
      ->willReturn($require);

    $reconiler = $this->getMockBuilder(ExtensionReconciler::class)
      // Make sure we don't run processNeededPackages().
      ->setMethods(['processNeededPackages'])
      ->setConstructorArgs([$from, ''])
      ->getMock();

    $ref_projects = new \ReflectionProperty($reconiler, 'projects');
    $ref_projects->setAccessible(TRUE);
    $ref_projects->setValue($reconiler, $projects);

    $this->assertSame($expected, $reconiler->getSpecifiedExtensions());
  }

}
