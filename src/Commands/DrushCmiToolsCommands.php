<?php

namespace Drupal\drush_cmi_tools\Commands;

use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\SerializationInterface;
use Drupal\config\StorageReplaceDataWrapper;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\File\FileSystemInterface;
use Drush\Commands\DrushCommands;
use Drush\Drupal\Commands\config\ConfigCommands;
use Drush\Drupal\Commands\config\ConfigExportCommands;
use Drush\Drupal\Commands\config\ConfigImportCommands;

/**
 * Drush CMI Tools commands.
 */
class DrushCmiToolsCommands extends DrushCommands {

  /**
   * The filesystem.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The serializer.
   *
   * @var \Drupal\Component\Serialization\SerializationInterface
   */
  protected $serializer;

  /**
   * The config commands.
   *
   * @var \Drush\Drupal\Commands\config\ConfigCommands
   */
  protected $configCommands;

  /**
   * The standard drush config export commands.
   *
   * @var \Drush\Drupal\Commands\config\ConfigExportCommands
   */
  protected $configExportCommands;

  /**
   * The standard drush config import commands.
   *
   * @var \Drush\Drupal\Commands\config\ConfigImportCommands
   */
  protected $configImportCommands;

  /**
   * The config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $configStorage;

  /**
   * The config manager.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected $configManager;

  /**
   * CmiToolsCli constructor.
   *
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\Component\Serialization\SerializationInterface $serializer
   *   The serializer.
   * @param \Drush\Drupal\Commands\config\ConfigCommands $configCommands
   *   The config commands.
   * @param \Drush\Drupal\Commands\config\ConfigExportCommands $configExportCommands
   *   The config export commands.
   * @param \Drush\Drupal\Commands\config\ConfigImportCommands $configImportCommands
   *   The config import commands.
   * @param \Drupal\Core\Config\StorageInterface $configStorage
   *   The config storage.
   * @param \Drupal\Core\Config\ConfigManagerInterface $configManager
   *   The config manager.
   */
  public function __construct(FileSystemInterface $fileSystem, SerializationInterface $serializer, ConfigCommands $configCommands, ConfigExportCommands $configExportCommands, ConfigImportCommands $configImportCommands, StorageInterface $configStorage, ConfigManagerInterface $configManager) {
    $this->fileSystem = $fileSystem;
    $this->serializer = $serializer;
    $this->configCommands = $configCommands;
    $this->configExportCommands = $configExportCommands;
    $this->configImportCommands = $configImportCommands;
    $this->configStorage = $configStorage;
    $this->configManager = $configManager;
  }

  /**
   * Export configuration to a directory and apply an ignore list.
   *
   * @param array $options
   *   The options.
   *
   * @command config-export-plus
   * @option destination An arbitrary directory that should receive the
   *   exported files. An alternative to label argument.
   * @option ignore-list Path to YAML file containing config to ignore from
   *   exports
   * @usage drush config-export-plus --destination=/some/folder
   *   --ignore-list=./config-ignore.yml Export configuration; Save files in a
   *   backup directory named config-export.
   * @aliases cexy
   *
   * @return mixed
   *   The result.
   */
  public function exportPlus(array $options = [
    'destination' => NULL,
    'ignore-list' => NULL,
  ]) {
    $this->logger()->debug('Starting Exporting.');
    // Do the actual config export operation
    // Determine which target directory to use.
    if ($target = $options['destination']) {
      $destination_dir = $target;
      // It is important to be able to specify a destination directory that
      // does not exist yet, for exporting on remote systems.
      if (!file_exists($destination_dir)) {
        $this->fileSystem->mkdir($destination_dir, NULL, TRUE);
      }
    }
    else {
      $this->logger()->error('You must provide a --destination option');
      return NULL;
    }
    $patterns = [];
    if ($ignoreList = $options['ignore-list']) {
      if (!is_file($ignoreList)) {
        $this->logger()
          ->error('The file specified in --ignore-list option does not exist.');
        return NULL;
      }
      if ($string = file_get_contents($ignoreList)) {
        $ignore_list_error = FALSE;
        $parsed = FALSE;
        try {
          $parsed = $this->serializer->decode($string);
        }
        catch (InvalidDataTypeException $e) {
          $ignore_list_error = TRUE;
        }
        if (!isset($parsed['ignore']) || !is_array($parsed['ignore'])) {
          $ignore_list_error = TRUE;
        }
        if ($ignore_list_error) {
          $this->logger()
            ->error('The file specified in --ignore-list option is in the wrong format. It must be valid YAML with a top-level ignore key.');
          return NULL;
        }
        foreach ($parsed['ignore'] as $ignore) {
          // Allow for accidental .yml extension.
          if (substr($ignore, -4) === '.yml') {
            $ignore = substr($ignore, 0, -4);
          }
          $patterns[] = '/^' . str_replace('\*', '(.*)', preg_quote($ignore)) . '\.yml/';
        }
      }
    }

    $result = $this->configExportCommands->doExport(NULL, $destination_dir);
    foreach ($patterns as $pattern) {
      foreach ($this->fileSystem->scanDirectory($destination_dir, $pattern) as $file_url => $file) {
        $this->fileSystem->unlink($file_url);
        $this->logger()->notice("Removed $file_url according to ignore list.");
      }
    }
  }

  /**
   * Import config from a config directory.
   *
   * This command respects live content and a delete list.
   *
   * @param array $options
   *   The options.
   *
   * @command config-import-plus
   * @option preview Format for displaying proposed changes. Recognized values:
   *   list, diff. Defaults to list.
   * @option source An arbitrary directory that holds the configuration files.
   * @option delete-list Path to YAML file containing config to delete before
   *   importing. Useful when you need to remove items from active config store
   *   before importing.
   * @option install Directory that holds the files to import once only.
   * @usage drush config-import-plus --delete-list=./config-delete.yml
   *   --install=/some/install/folder --source=/some/export/folder Import
   *   configuration; do not enable or disable the devel module, regardless of
   *   whether or not it appears in the imported list of enabled modules.
   * @aliases cimy
   *
   * @return mixed
   *   The result.
   */
  public function importPlus(array $options = [
    'preview' => 'list',
    'source' => NULL,
    'delete-list' => NULL,
    'install' => NULL,
  ]) {
    $this->logger()->debug('Starting import');
    // Determine source directory.
    if ($target = $options['source']) {
      $source_dir = $target;
    }
    else {
      $this->logger()->error('You must provide a --source option');
      return NULL;
    }
    $source_storage = new StorageReplaceDataWrapper($this->configStorage);
    $file_storage = new FileStorage($source_dir);
    foreach ($file_storage->listAll() as $name) {
      $data = $file_storage->read($name);
      $source_storage->replaceData($name, $data);
    }
    if ($delete_list = $options['delete-list']) {
      if (!is_file($delete_list)) {
        $this->logger()
          ->error('The file specified in --delete-list option does not exist.');
        return NULL;
      }
      if ($string = file_get_contents($delete_list)) {
        $delete_list_error = FALSE;
        $parsed = FALSE;
        try {
          $parsed = $this->serializer->decode($string);
        }
        catch (InvalidDataTypeException $e) {
          $delete_list_error = TRUE;
        }
        if (!isset($parsed['delete']) || !is_array($parsed['delete'])) {
          $delete_list_error = TRUE;
        }
        if ($delete_list_error) {
          $this->logger()
            ->error('The file specified in --delete-list option is in the wrong format. It must be valid YAML with a top-level delete key.');
          return NULL;
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

    $storage_comparer = new StorageComparer($source_storage, $this->configStorage, $this->configManager);

    if (!$storage_comparer->createChangelist()->hasChanges()) {
      $this->logger()->info('There are no changes to import.');
      return NULL;
    }

    if ($options['preview'] == 'list') {
      $change_list = [];
      foreach ($storage_comparer->getAllCollectionNames() as $collection) {
        $change_list[$collection] = $storage_comparer->getChangelist(NULL, $collection);
      }
      $table = $this->configCommands->configChangesTable($change_list, $this->output());
      $table->render();
    }
    else {
      // Copy active storage to the temporary directory.
      $temp_dir = drush_tempdir();
      $temp_storage = new FileStorage($temp_dir);
      $source_dir_storage = new FileStorage($source_dir);
      foreach ($source_dir_storage->listAll() as $name) {
        if ($data = $this->configStorage->read($name)) {
          $temp_storage->write($name, $data);
        }
      }
      drush_shell_exec('diff -x %s -u %s %s', '*.git', $temp_dir, $source_dir);
      $output = drush_shell_exec_output();
      $this->io()->writeln(implode("\n", $output));
    }

    if ($this->io()->confirm('Import the listed configuration changes?')) {
      $this->configImportCommands->doImport($storage_comparer);
    }
  }

}
