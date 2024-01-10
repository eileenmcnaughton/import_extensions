<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

namespace Civi\Import\DataSource;

use GuzzleHttp\Client;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Exception as ReaderException;
use League\Csv\Reader;
use CRM_ImportExtensions_ExtensionUtil as E;

/**
 * Objects that implement the DataSource interface can be used in CiviCRM
 * imports.
 */
class UploadedFile extends \CRM_Import_DataSource {

  use DataSourceTrait;

  /**
   * @var \League\Csv\Reader
   */
  private Reader $reader;

  /**
   * Provides information about the data source.
   *
   * @return array
   *   collection of info about this data source
   */
  public function getInfo(): array {
    return [
      'title' => ts('Uploaded file'),
      'template' => 'CRM/Import/DataSource/UploadedFile.tpl',
    ];
  }


  /**
   * This is function is called by the form object to get the DataSource's form
   * snippet.
   *
   * It should add all fields necessary to get the data
   * uploaded to the temporary table in the DB.
   *
   * @param \CRM_Contact_Import_Form_DataSource|\CRM_Import_Form_DataSourceConfig $form
   *
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(\CRM_Import_Forms $form): void {
    if (\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer')) {
      $this->setUserJobID(\CRM_Utils_Request::retrieveValue('user_job_id', 'Integer'));
    }
    $form->add('hidden', 'hidden_dataSource', 'CRM_Import_DataSource_UploadedFile');
    $form->addElement('checkbox', 'isFirstRowHeader', ts('First row contains column headers'));

    $fullPathFiles = \CRM_Utils_File::findFiles($this->getResolvedFilePath(), '*.csv');
    foreach ($fullPathFiles as $file) {
      $fileName = basename($file);
      $availableFiles[$fileName] = $fileName;
    }
    $form->assign('upload_message', $this->hasConfiguredFilePath() ? '' : E::ts(
      'Your system administrator has not defined an upload location. The file/s available are sample data only'
    ));
    if ($fullPathFiles) {
      $form->add('select', 'file_name', E::ts('Select File'), $availableFiles, TRUE, ['class' => 'crm-select2 huge']);
    }
    else {
      $form->assign('upload_message', E::ts('There are no uploaded files available'));
    }
    $form->setDataSourceDefaults($this->getDefaultValues());
  }

  /**
   * Get default values for excel dataSource fields.
   *
   * @return array
   */
  public function getDefaultValues(): array {
    return ['isFirstRowHeader' => 1];
  }

  /**
   * Initialize the datasource, based on the submitted values stored in the
   * user job.
   *
   * Generally this will include transferring the data to a database table.
   *
   * @throws \CRM_Core_Exception
   */
  public function initialize(): void {
    try {
      $result = $this->uploadToTable();
      $this->updateUserJobDataSource([
        'table_name' => $result['import_table_name'],
        'column_headers' => $result['column_headers'],
        'number_of_columns' => $result['number_of_columns'],
      ]);
    }
    catch (ReaderException $e) {
      throw new \CRM_Core_Exception(ts('Spreadsheet not loaded.') . '' . $e->getMessage());
    }
  }

  /**
   * @return \GuzzleHttp\Client
   */
  public function getGuzzleClient(): Client {
    return $this->guzzleClient ?? new Client(['base_uri' => $this->getSubmittedValue('url')]);
  }

  /**
   * @throws \CRM_Core_Exception
   * @throws \Civi\Core\Exception\DBQueryException
   * @throws \PhpOffice\PhpSpreadsheet\Reader\Exception
   */
  private function uploadToTable(): array {
    $filePath = $this->getResolvedFilePath() . DIRECTORY_SEPARATOR . $this->getSubmittedValue('file_name');
    $file_type = IOFactory::identify($filePath);
    if ($file_type === 'Csv') {
      $this->reader = Reader::createFromPath($filePath);
    }
    else {
      // currently only csvs can be selected at the moment but we could do the spreadsheet stuff here.
      throw new \CRM_Core_Exception('unreachable code reached - buy a lottery ticket');
    }
    // Remove the header
    if ($this->getSubmittedValue('isFirstRowHeader')) {
      $this->reader->setHeaderOffset(0);
    }
    $tableName = $this->createTempTableFromColumns($this->getColumnNamesFromHeaders($this->getColumnNames()));
    $numColumns = count($this->getColumnNames());
    // Re-key data using the headers
    $sql = [];
    // We only load the first 10 rows in this scenario.
    $rowsToInsert = 10;
    foreach ($this->reader->getRecords() as $row) {
      $row = array_map([__CLASS__, 'trimNonBreakingSpaces'], $row);
      $row = array_map(['CRM_Core_DAO', 'escapeString'], $row);
      $sql[] = "('" . implode("', '", $row) . "')";
      \CRM_Core_DAO::executeQuery("INSERT IGNORE INTO $tableName VALUES " . implode(', ', $sql));
      $rowsToInsert--;
      if ($rowsToInsert === 0) {
        break;
      }
    }
    $this->addTrackingFieldsToTable($tableName);

    return [
      'import_table_name' => $tableName,
      'number_of_columns' => $numColumns,
      'column_headers' => $this->getColumnTitles(),
    ];
  }

  private function getColumnNames(): array {
    if ($this->getSubmittedValue('isFirstRowHeader')) {
      $header = $this->reader->getHeader();
      return array_values($header);
    }

    $row = $this->reader->fetchOne();
    $columnsHeaders = [];
    foreach (array_keys($row) as $index) {
      $columnsHeaders[] = ['column_' . $index];
    }
    return $columnsHeaders;
  }

  private function getColumnTitles(): array {
    if ($this->getSubmittedValue('isFirstRowHeader')) {
      $this->reader->setHeaderOffset(0);
      $header = $this->reader->getHeader();
      return array_values($header);
    }

    $row = $this->reader->fetchOne();
    $columnsHeaders = [];
    foreach (array_keys($row) as $index) {
      $columnsHeaders[] = ['column_' . $index];
    }
    return $columnsHeaders;
  }

  /**
   * Get array array of field names that may be submitted for this data source.
   *
   * The quick form for the datasource is added by ajax - meaning that
   * QuickForm
   * does not see them as part of the form. However, any fields listed in this
   * array will be taken from the `$_POST` and stored to the UserJob under the
   * DataSource key.
   *
   * @return array
   */
  public function getSubmittableFields(): array {
    return ['file_name', 'isFirstRowHeader'];
  }

  /**
   * Get the configured file path.
   *
   * The only way to configure a file path at the moment is to add a define
   * to civicrm.settings.php - eg
   * `define('IMPORT_EXTENSIONS_UPLOAD_FOLDER', /var/www/abc/xyz');
   * The expectation is that this is a file path that the sysadmin sets up
   * for a user (or process) to ftp files to.
   *
   * Note that this function currently respects open_base_dir restrictions.
   *
   * @return string|null
   *
   * @noinspection PhpUnhandledExceptionInspection
   */
  protected function getConfiguredFilePath(): ?string {
    $configuredFilePath = \CRM_Utils_Constant::value('IMPORT_EXTENSIONS_UPLOAD_FOLDER');
    // Only return the directory if it exists...
    return \CRM_Utils_File::isDir($configuredFilePath) ? $configuredFilePath : NULL;
  }

  /**
   * Get the resolved file path - either the configured one or fall back to the
   * sample data one.
   *
   * @return string
   */
  protected function getResolvedFilePath(): string {
    $sampleDataFilePath = E::path() . DIRECTORY_SEPARATOR . 'SampleFiles';
    return $this->getConfiguredFilePath() ?: $sampleDataFilePath;
  }

  /**
   * Has a file path been configured (to a real directory).
   *
   * @return bool
   */
  protected function hasConfiguredFilePath(): bool {
    return (bool) $this->getConfiguredFilePath();
  }

}
