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
 * PostgreSQL implementation of the BenchmarkDb class
 */
class BenchmarkDbPostgreSql extends BenchmarkDb {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbPostgreSql($options) {}
    
  
  /**
   * returns the postgresl data type corresponding with the MySQL $type 
   * specified
   * @param string $type the mysql type
   * @return string
   */
  private function getDataType($type) {
    $ptype = str_replace(' unsigned', '', $type);
    $ptype = str_replace('tinyint', 'smallint', $ptype);
    return $ptype;
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
    
    // create table if it doesn't already exist
    if (!($exists = $this->psql('\d+ ' . $table))) {
      print_msg(sprintf('Table %s does not exist - attempting to create', $table), isset($this->options['verbose']), __FILE__, __LINE__);
      $indexes = array();
      // determine indexes
      foreach(array_keys($schema) as $i => $col) {
        if ($schema[$col]['type'] == 'index') {
          $indexes[$col] = sprintf('CREATE %sINDEX %s_%s ON %s(%s)', isset($schema[$col]['modifier']) ? strtoupper($schema[$col]['modifier']) . ' ' : '', $table, $col, $table, implode(', ', $schema[$col]['cols']));
          if (isset($schema[$col]['modifier']) && strtolower($schema[$col]['modifier']) == 'unique') {
            $ncols = array();
            foreach($schema[$col]['cols'] as $c) $ncols[] = 'NEW.' . $c;
            // add this rule to avoid duplicates with null values in unique indexes
            $indexes[$col . '_unique'] = sprintf('CREATE RULE %s_%s_duplicate_ignore AS ON INSERT TO %s WHERE (EXISTS (SELECT 1 FROM %s WHERE concat(%s) = concat(%s))) DO INSTEAD NOTHING', $table, $col, $table, $table, implode(', ', $schema[$col]['cols']), implode(', ', $ncols));
          }
        }
      }
      // create table ddl
      $query = 'CREATE TABLE ' . $table . '(';
      foreach(array_keys($schema) as $i => $col) {
        if ($schema[$col]['type'] != 'index') {
          $type = $this->getDataType($schema[$col]['type']);
          $query .= sprintf('%s%s %s', $i>0 ? ', ' : '', $col, $type);
        }
      }
      $query .= ')';
      
      if ($this->psql($query) !== NULL) {
        print_msg(sprintf('Table %s created successfully - creating indexes', $table), isset($this->options['verbose']), __FILE__, __LINE__);
        $exists = TRUE;
        foreach($indexes as $col => $query) {
          if ($this->psql($query) !== NULL) print_msg(sprintf('Created index %s successfully', $col), isset($this->options['verbose']), __FILE__, __LINE__);
          else print_msg(sprintf('Failed to create index %s', $col), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
      }
      else print_msg(sprintf('Unable to create table %s', $table), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    
    // import csv if table exists
    if ($exists) {
      // first create a temporary staging table (because COPY ignores rules)
      $tempTable = sprintf('%s_%d', $table, rand());
      if ($this->psql(sprintf('CREATE TABLE %s (LIKE %s)', $tempTable, $table)) !== NULL) {
        print_msg(sprintf('Created temp staging table %s successfully', $tempTable), isset($this->options['verbose']), __FILE__, __LINE__);
        $cols = array();
        foreach(array_keys($schema) as $col) if ($schema[$col]['type'] != 'index') $cols[] = $col;
        // copy CSV to the temporary staging table
        $query = sprintf("\COPY %s(%s) FROM '%s' CSV HEADER", $tempTable, implode(', ', $cols), $csv);
        if ($this->psql($query) !== NULL) {
          print_msg(sprintf('CSV file %s imported to temp staging table %s successfully', $csv, $tempTable), isset($this->options['verbose']), __FILE__, __LINE__);
          // now insert records from temporary table into actual table
          // this will apply duplicate rules to enforce unique indexes with null values
          if ($this->psql(sprintf('INSERT INTO %s SELECT * FROM %s', $table, $tempTable)) !== NULL) {
            print_msg(sprintf('Successfully copied data from temp staging table %s to live table %s', $tempTable, $table), isset($this->options['verbose']), __FILE__, __LINE__);
            $imported = TRUE;
          }
          else print_msg(sprintf('Unable to copy data from temp staging table %s to live table %s', $tempTable, $table), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          // finally drop the temp staging table
          if ($this->psql(sprintf('DROP TABLE %s', $tempTable)) !== NULL) print_msg(sprintf('Successfully dropped temp staging table %s', $tempTable), isset($this->options['verbose']), __FILE__, __LINE__);
          else print_msg(sprintf('Failed to dropped temp staging table %s', $tempTable), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
        else print_msg(sprintf('Failed to import CSV file %s', $csv), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      else print_msg(sprintf('Unable to create temp staging table for import data from %s', $csv), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    
    return $imported;
  }
  
  /**
   * executes a mysql command. returns the results as an array of arrays 
   * each representing a row. returns NULL on error
   * @param string $query the query to execute
   * @return array
   */
  private function psql($query='SELECT * FROM pg_catalog.pg_tables') {
    $cmd = sprintf('%spsql -t --no-align -w%s%s%s%s -c %s;echo $?',
                   isset($this->options['db_pswd']) ? 'PGPASSWORD="' . $this->options['db_pswd'] . '";' : '',
                   isset($this->options['db_host']) ? ' -h ' . $this->options['db_host'] : '', 
                   isset($this->options['db_name']) ? ' -d ' . $this->options['db_name'] : '',
                   isset($this->options['db_port']) ? ' -p ' . $this->options['db_port'] : '',
                   isset($this->options['db_user']) ? ' -U ' . $this->options['db_user'] : '',
                   '"' . str_replace('"', '\"', $query) . '" 2>/dev/null');
    putenv('PGPASSWORD=' . $this->options['db_pswd']);
    print_msg(sprintf('Attempting to query PostgreSQL using: %s', isset($this->options['db_pswd']) ? str_replace($this->options['db_pswd'], '***', $cmd) : $cmd), isset($this->options['verbose']), __FILE__, __LINE__);
    $result = shell_exec($cmd);
    $rows = explode("\n", trim($result));
    if (count($rows)) {
      $ecode = $rows[count($rows) - 1]*1;
      unset($rows[count($rows) - 1]);
      if (!$ecode) {
        foreach(array_keys($rows) as $i) $rows[$i] = explode("|", $rows[$i]);
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
      if ($this->psql('create table ' . $table . ' (id int)') !== NULL) {
        // try to load some data into the table
        $fp = fopen($tmp = '/tmp/' . $table, 'w');
        fwrite($fp, rand() . "\n" . rand());
        fclose($fp);
        $query = sprintf("\COPY %s FROM '%s' CSV", $table, $tmp);
        $imported = $this->psql($query);
        unlink($tmp);
        $this->psql('drop table ' . $table);
        $dropped = $this->psql('\d+ ' . $table);
        if ($dropped !== NULL) {
          print_msg(sprintf('Unable to drop test table %s - check that user has DROP TABLE permissions', $table), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          $valid = FALSE;
        }
        else if ($imported !== NULL) print_msg(sprintf('Validated PostgreSQL connection by creating and importing data to a temporary table %s - table has been removed', $table), isset($this->options['verbose']), __FILE__, __LINE__);
        else {
          $valid = FALSE;
          print_msg(sprintf('PostgreSQL connection successful, but unable to import data using COPY. Check these permissions for this user'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
      }
      else {
        $valid = FALSE;
        print_msg(sprintf('PostgreSQL connection failed - %s', $this->psql() !== NULL ? 'user cannot create tables' : 'unable to connect to server'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
    }
    return $valid;
  }
  
}
?>
