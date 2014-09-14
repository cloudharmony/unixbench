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
 * MySQL implementation of the BenchmarkDb class
 */
class BenchmarkDbMySql extends BenchmarkDb {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbMySql($options) {}

  
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
    // create table
    if (!($exists = $this->mysql('desc ' . $table))) {
      print_msg(sprintf('Table %s does not exist - attempting to create', $table), isset($this->options['verbose']), __FILE__, __LINE__);
      $indexes = array();
      $query = 'CREATE TABLE ' . $table . '(';
      foreach(array_keys($schema) as $i => $col) {
        if ($schema[$col]['type'] == 'index') $indexes[$col] = sprintf('CREATE %sINDEX %s_%s ON %s(%s)', isset($schema[$col]['modifier']) ? $schema[$col]['modifier'] . ' ' : '', $table, $col, $table, implode(', ', $schema[$col]['cols']));
        else $query .= sprintf('%s%s %s', $i>0 ? ', ' : '', $col, $schema[$col]['type']);
      }
      $query .= ')';
      if (isset($this->options['db_mysql_engine'])) $query .= ' ENGINE=' . $this->options['db_mysql_engine'];
      
      if ($this->mysql($query) !== NULL) {
        print_msg(sprintf('Table %s created successfully - creating indexes', $table), isset($this->options['verbose']), __FILE__, __LINE__);
        $exists = TRUE;
        foreach($indexes as $col => $query) {
          if ($this->mysql($query) !== NULL) print_msg(sprintf('Created index %s successfully', $col), isset($this->options['verbose']), __FILE__, __LINE__);
          else print_msg(sprintf('Failed to create index %s', $col), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
      }
      else print_msg(sprintf('Unable to create table %s', $table), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    // import csv
    if ($exists) {
      $cols = array();
      foreach(array_keys($schema) as $col) if ($schema[$col]['type'] != 'index') $cols[] = $col;
      $query = sprintf("LOAD DATA LOCAL INFILE '%s' REPLACE INTO TABLE %s COLUMNS TERMINATED BY ',' OPTIONALLY ENCLOSED BY '\"' IGNORE 1 LINES (%s)", $csv, $table, implode(', ', $cols));
      if ($this->mysql($query) !== NULL) {
        print_msg(sprintf('CSV file %s imported successfully', $csv), isset($this->options['verbose']), __FILE__, __LINE__);
        $imported = TRUE;
      }
      else print_msg(sprintf('Failed to import CSV file %s', $csv), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    
    return $imported;
  }
  
  /**
   * executes a mysql command. returns the results as an array of arrays 
   * each representing a row. returns NULL on error
   * @param string $query the query to execute
   * @return array
   */
  private function mysql($query='show tables') {
    $cmd = sprintf('mysql -s%s%s%s%s%s%s -e %s;echo $?', 
                   isset($this->options['db_host']) ? ' -h ' . $this->options['db_host'] : '', 
                   isset($this->options['db_name']) ? ' -D ' . $this->options['db_name'] : '',
                   isset($this->options['db_port']) ? ' -P ' . $this->options['db_port'] : '',
                   isset($this->options['db_pswd']) ? ' -p"' . $this->options['db_pswd'] . '"' : '',
                   isset($this->options['db_user']) ? ' -u ' . $this->options['db_user'] : '',
                   preg_match('/infile/i', $query) ? ' --local-infile' : '',
                   '"' . str_replace('"', '\"', $query) . '" 2>/dev/null');
    print_msg(sprintf('Attempting to query MySQL using: %s', isset($this->options['db_pswd']) ? str_replace($this->options['db_pswd'], '***', $cmd) : $cmd), isset($this->options['verbose']), __FILE__, __LINE__);
    $result = shell_exec($cmd);
    $rows = explode("\n", trim($result));
    if (count($rows)) {
      $ecode = $rows[count($rows) - 1]*1;
      unset($rows[count($rows) - 1]);
      if (!$ecode) {
        foreach(array_keys($rows) as $i) $rows[$i] = explode("\t", $rows[$i]);
      }
    }
    else $ecode = 1;
    return $ecode ? NULL : $rows;
  }
  
  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if ($valid = parent::validate()) {
      $table = 'test_' . $this->getTableName(rand());
      if ($this->mysql(sprintf('CREATE TABLE %s (id int)%s', $table, isset($this->options['db_mysql_engine']) ? ' ENGINE=' . $this->options['db_mysql_engine'] : '')) !== NULL) {
        // try to load some data into the table
        $fp = fopen($tmp = '/tmp/' . $table, 'w');
        fwrite($fp, rand() . "\n" . rand());
        fclose($fp);
        $query = sprintf("LOAD DATA LOCAL INFILE '%s' INTO TABLE %s", $tmp, $table);
        $imported = $this->mysql($query);
        unlink($tmp);
        $this->mysql('drop table ' . $table);
        $dropped = $this->mysql('desc ' . $table);
        if ($dropped !== NULL) {
          print_msg(sprintf('Unable to drop test table %s - check that user has DROP TABLE permissions', $table), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          $valid = FALSE;
        }
        else if ($imported !== NULL) print_msg(sprintf('Validated MySQL connection by creating and importing data to a temporary table %s - table has been removed', $table), isset($this->options['verbose']), __FILE__, __LINE__);
        else {
          $valid = FALSE;
          print_msg(sprintf('MySQL connection successful, but unable to import data using LOAD DATA LOCAL INFILE. Check these permissions for this user'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
      }
      else {
        $valid = FALSE;
        print_msg(sprintf('MySQL connection failed - %s', $this->mysql() !== NULL ? 'user cannot create tables' : 'unable to connect to server'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
    }
    return $valid;
  }
  
}
?>
