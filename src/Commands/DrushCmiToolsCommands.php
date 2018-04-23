<?php

namespace Drupal\drush_cmi_tools\Commands;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Serialization\Yaml;
use Drush\Commands\DrushCommands;
use Drush\Drupal\Commands\config\ConfigCommands;

/**
 * A Drush commandfile.
 */
class DrushCmiToolsCommands extends DrushCommands {

  /**
   * Export configuration to a directory and apply an ignore list.
   *
    * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option destination
   *   An arbitrary directory that should receive the exported files. An alternative to label argument.
   * @option ignore-list
   *   Path to YAML file containing config to ignore from exports
   * @usage drush config-export-plus --destination=/some/folder --ignore-list=./config-ignore.yml
   *   Export configuration; Save files in a backup directory named config-export.
   *
   * @command config:export-plus
   * @aliases cexy,config-export-plus
   */
  public function exportPlus(array $options = ['destination' => null, 'ignore-list' => null]) {
    $this->logger()->debug(dt('Starting export'));

    // Do the actual config export operation
    // Determine which target directory to use.
    if (($target = $options['destination']) && $target !== TRUE) {
      $destination_dir = $target;
      // It is important to be able to specify a destination directory that
      // does not exist yet, for exporting on remote systems
      drush_mkdir($destination_dir);
    } else {
      $this->logger()->error((dt('You must provide a --destination option')));
      return;
    }
    $patterns = [];
    if ($ignore_list = $options['ignore-list']) {
      if (!is_file($ignore_list)) {
        $this->logger()->error(dt('The file specified in --ignore-list option does not exist.'));
        return;
      }
      if ($string = file_get_contents($ignore_list)) {
        $ignore_list_error = FALSE;
        $parsed = FALSE;
        try {
          $parsed = Yaml::decode($string);
        }
        catch (InvalidDataTypeException $e) {
          $ignore_list_error = TRUE;
        }
        if (!isset($parsed['ignore']) || !is_array($parsed['ignore'])) {
          $ignore_list_error = TRUE;
        }
        if ($ignore_list_error) {
          $this->logger()->error(dt('The file specified in --ignore-list option is in the wrong format. It must be valid YAML with a top-level ignore key.'));
          return;
        }
        foreach ($parsed['ignore'] as $ignore) {
          // Allow for accidental .yml extension.
          if (substr($ignore, -4) === '.yml') {
            $ignore = substr($ignore, 0, -4);
          }
          $patterns[] =  '/^' . str_replace('\*', '(.*)', preg_quote($ignore)) . '\.yml/';
        }
      }
    }

    $drushExportOptions = [
      'diff' => FALSE,

    ];
    $result = \Drupal::service('config.export.commands')->doExport($drushExportOptions, $destination_dir, FALSE);
    $file_service =  \Drupal::service('file_system');
    foreach ($patterns as $pattern) {
      foreach (file_scan_directory($destination_dir, $pattern) as $file_url => $file) {
        $file_service->unlink($file_url);
        $this->logger()->info("Removed $file_url according to ignore list.");
      }
    }

    return $result;
  }

  /**
   * Import config from a config directory resepecting live content and a delete list.
   *
    * @param array $options An associative array of options whose values come from cli, aliases, config, etc.
   * @option preview
   *   Format for displaying proposed changes. Recognized values: list, diff. Defaults to list.
   * @option source
   *   An arbitrary directory that holds the configuration files.
   * @option delete-list
   *   Path to YAML file containing config to delete before importing. Useful when you need to remove items from active config store before importing.
   * @option install
   *   Directory that holds the files to import once only.
   * @usage drush config-import-plus --delete-list=./config-delete.yml --install=/some/install/folder --source=/some/export/folder
   *   Import configuration; do not enable or disable the devel module, regardless of whether or not it appears in the imported list of enabled modules.
   * @validate-module-enabled config
   *
   * @command config:import-plus
   * @aliases cimy,config-import-plus
   */
  public function importPlus(array $options = ['preview' => null, 'source' => null, 'delete-list' => null, 'install' => null]) {
    $this->logger()->debug(dt('Starting import'));
    // Determine source directory.
    if ($target = $options['source']) {
      $source_dir = $target;
    }
    else {
      $this->logger()->error(dt('You must provide a --source option'));
      return;
    }
    /** @var \Drupal\Core\Config\StorageInterface $active_storage */
    $active_storage = \Drupal::service('config.storage');
    $source_storage = new StorageReplaceDataWrapper($active_storage);
    $file_storage = new FileStorage($source_dir);
    foreach ($file_storage->listAll() as $name) {
      $data = $file_storage->read($name);
      $source_storage->replaceData($name, $data);
    }
    if ($delete_list = $options['delete-list']) {
      if (!is_file($delete_list)) {
        $this->logger()->error(dt('The file specified in --delete-list option does not exist.'));
        return;
      }
      if ($string = file_get_contents($delete_list)) {
        $delete_list_error = FALSE;
        $parsed = FALSE;
        try {
          $parsed = Yaml::decode($string);
        }
        catch (InvalidDataTypeException $e) {
          $delete_list_error = TRUE;
        }
        if (!isset($parsed['delete']) || !is_array($parsed['delete'])) {
          $delete_list_error = TRUE;
        }
        if ($delete_list_error) {
          $this->logger()->error(dt('The file specified in --delete-list option is in the wrong format. It must be valid YAML with a top-level delete key.'));
          return;
        }
        foreach ($parsed['delete'] as $delete) {
          // Allow for accidental .yml extension.
          if (substr($delete, -4) === '.yml') {
            $delete = substr($delete, 0, -4);
          }
          if ($source_storage->exists($delete)) {
            $source_storage->delete($delete);
            $this->logger()->info("Deleted $delete as per delete list.");
          }
          else {
            $this->logger()->info("Ignored deleting $delete, does not exist.");
          }
        }
      }
    }
    if ($install = $options['install']) {
      $file_storage = new FileStorage($install);
      foreach ($file_storage->listAll() as $name) {
        if (!$source_storage->exists($name)) {
          $data = $file_storage->read($name);
          $source_storage->replaceData($name, $data);
          $this->logger()->info("Installed $name for first time.");
        }
      }
    }

    /** @var \Drupal\Core\Config\ConfigManagerInterface $config_manager */
    $config_manager = \Drupal::service('config.manager');
    $storage_comparer = new StorageComparer($source_storage, $active_storage, $config_manager);


    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $this->logger()->info(dt('There are no changes to import.'));
      return;
    }

    // Copy active storage to the temporary directory.
    $temp_dir = drush_tempdir();
    $temp_storage = new FileStorage($temp_dir);
    $source_dir_storage = new FileStorage($source_dir);
    foreach ($source_dir_storage->listAll() as $name) {
      if ($data = $active_storage->read($name)) {
        $temp_storage->write($name, $data);
      }
    }
    drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, $source_dir);
    $output = drush_shell_exec_output();
    $this->logger()->info(implode("\n", $output));

    if ($this->io()->confirm(dt('Import the listed configuration changes?'))) {
      \Drupal::service('config.import.commands')->doImport($storage_comparer);
    }
  }

}
