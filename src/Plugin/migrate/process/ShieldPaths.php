<?php

namespace Drupal\shield\Plugin\migrate\process;

use Drupal\migrate\Attribute\MigrateProcess;
use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;

/**
 * Maps D7 shield settings to D9.
 *
 * The annotation is kept alongside the attribute so that core versions without
 * attribute discovery still find this plugin. Remove it once the module's
 * core_version_requirement rules those versions out.
 *
 * @MigrateProcessPlugin(
 *   id = "shield_paths"
 * )
 */
#[MigrateProcess('shield_paths')]
class ShieldPaths extends ProcessPluginBase {

  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    $all_paths = '';
    foreach (explode("\r\n", $value) as $path) {
      // Adds a leading slash to all the paths.
      $all_paths = $all_paths . '/' . $path . "\n";
    }
    return rtrim($all_paths);
  }

}
