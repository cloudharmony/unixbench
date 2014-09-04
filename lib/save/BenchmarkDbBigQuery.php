<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * Google BigQuery implementation of the BenchmarkDb class
 */
class BenchmarkDbBigQuery extends BenchmarkDb {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbBigQuery($options) {}
  
  /**
   * returns the dataset identifier to use
   * @return string
   */
  private function getDatasetId() {
    return sprintf('%s%s', isset($this->options['db_host']) ? $this->options['db_host'] . ':' : '', $this->options['db_name']);
  }
  
  /**
   * returns the bigquery data type corresponding with the MySQL $type 
   * specified
   * @param string $type the mysql type
   * @return string
   */
  private function getDataType($type) {
    $btype = 'string';
    if (preg_match('/int/', $type)) $btype = 'integer';
    else if (preg_match('/float/', $type)) $btype = 'float';
    else if (preg_match('/time/', $type)) $btype = 'timestamp';
    else if (preg_match('/text/', $type)) $btype = 'string';
    return $btype;
  }
  
  /**
   * this method should be overriden by sub-classes to import CSV data into the 
   * underlying datastore. It should return TRUE on success, FALSE otherwise
   * @param string $table the name of the table to import to
   * @param string $csv the CSV file to import
   * @param array $schema the table schema
   * @return boolean
   */
  protected function importCsv($table, $csv, $schema) {
    $imported = FALSE;
    $table = $this->getTableName($table);
    $cols = array();
    // determine schema
    foreach(array_keys($schema) as $i => $col) {
      if ($schema[$col]['type'] != 'index') $cols[] = sprintf('%s:%s', $col, $this->getDataType($schema[$col]['type']));
    }
    if ($this->bq(sprintf('load --skip_leading_rows 1 %s.%s %s "%s"', $this->getDatasetId(), $table, $csv, implode(',', $cols))) !== NULL) {
      $imported = TRUE;
      print_msg(sprintf('Successfully imported CSV file %s to BigQuery', $csv), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    else print_msg(sprintf('Failed to import CSV file %s to BigQuery', $csv), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    
    return $imported;
  }
  
  /**
   * invokes a BigQuery command using the 'bq' cli. Returns the response as 
   * an array on success, NULL on failure
   * @param string $cmd the big query command to execute
   * @return string
   */
  private function bq($command=NULL) {
    if (!$command) $command = sprintf('ls %s', $this->getDatasetId());
    $cmd = sprintf('bq --format json %s 2>/dev/null; echo $?', $command);
    print_msg(sprintf('Invoking BigQuery using command: %s', $cmd), isset($this->options['verbose']), __FILE__, __LINE__);
    
    // execute callback
    $result = shell_exec($cmd);
    $output = explode("\n", trim($result));
    $response = NULL;

    // interpret callback response
    if (count($output) >= 1) {
      $response = json_decode($output[0], TRUE);
      $ecode = $output[count($output) - 1]*1;
      if ($ecode) print_msg(sprintf('BigQuery execution failed with exit code %d', $ecode), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      else {
        if (!is_array($response)) $response = array();
        print_msg(sprintf('BigQuery execution successful'), isset($this->options['verbose']), __FILE__, __LINE__);
      }
    }
    return $response;
  }

  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if ($valid = parent::validate()) {
      if (!isset($this->options['db_name'])) {
        $valid = FALSE;
        print_msg('--db_name argument is required for --db bigquery', isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      else if ($this->bq() !== NULL) {
        $valid = TRUE;
        print_msg('BigQuery connection successful', isset($this->options['verbose']), __FILE__, __LINE__);
      }
      else print_msg('BigQuery connection failed', isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    return $valid;
  }
  
}
?>
