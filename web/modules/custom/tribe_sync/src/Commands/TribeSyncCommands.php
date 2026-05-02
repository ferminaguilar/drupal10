<?php

namespace Drupal\tribe_sync\Commands;

use Drush\Commands\DrushCommands;

/**
 * Tribal Data Sync: ArcGIS Leadership & EPA ID Bridge.
 */
class TribeSyncCommands extends DrushCommands {

  /**
   * Syncs Tribal data with Upsert logic, multi-email handling, and state mapping.
   *
   * @command tribe:sync
   * @option dry-run Preview the merged data mapping without saving.
  * @aliases t-sync
   */
  public function sync($options = ['dry-run' => FALSE]) {
    $dry_run = $options['dry-run'];
    try {
      $result = \Drupal::service('tribe_sync.manager')->sync($dry_run);
    }
    catch (\Exception $e) {
      $this->logger()->error($e->getMessage());
      return;
    }

    if ($dry_run) {
      $this->output()->writeln("<comment>DRY RUN: Diagnostic Preview</comment>");
      $this->io()->table(['Tribe', 'Job Title', 'State Code'], $result['rows'] ?? []);
      return;
    }

    $this->output()->writeln(sprintf(
      '<info>Sync finished successfully. Processed %d of %d records.</info>',
      $result['processed'] ?? 0,
      $result['total'] ?? 0
    ));
  }

}
