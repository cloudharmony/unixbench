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
 * Object Storage implementation of the BenchmarkDb class
 */
class BenchmarkDbStore extends BenchmarkDb {
  
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbStore($options) {
	 $this->stripCsvQuotes = TRUE;
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
    $path = $this->options['db_store_path'];
    $prefix = str_replace('{table}', $table, $path);
    $saveTo = dirname($this->archiver->getObjectUri($csv, $prefix));
    if (!preg_match('/\.csv$/i', $saveTo)) {
      $saveTo = sprintf('%s/%s', $saveTo, sprintf('%s.csv', $table));
    }
    return $this->archiver->save($csv, $saveTo, FALSE);
  }
  
  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if ($valid = parent::validate()) {
      $valid = isset($this->options['db_store_path']);
    }
    return $valid;
  }
  
}
?>
