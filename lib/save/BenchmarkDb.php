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
// See the License for the specific langauge governing permissions and
// limitations under the License.


/**
 * 
 */
require_once(dirname(dirname(__FILE__)) . '/util.php');
date_default_timezone_set('UTC');

class BenchmarkDb {
  
  /**
   * config file path
   */
  const BENCHMARK_DB_CONFIG_FILE = '~/.ch_benchmark';
  
  /**
   * an optional archiver
   */
  protected $archiver;
  
  /**
   * tracks artifacts saved using the saveArtifact method - a hash indexed by
   * column name where the value is the artifact URL
   */
  private $artifacts = array();
  
  /**
   * the directory where CSV files will be written to
   */
  protected $dir;
  
  /**
   * db options
   */
  protected $options;
  
  /**
   * data rows indexed by table name
   */
  private $rows = array();
  
  /**
   * stores references to schemas retrieved from getSchema
   */
  private $schemas = array();
  
  /**
   * default table prefix
   */
  public $tablePrefix = '';
  
  /**
   * used to track the results from the validate function
   */
  private $valid;
  
  /**
   * if set to FALSE by implementing classes, the method signature for
   * importCsv($table, $csv, $schema) will be changed to 
   * importCsv($table, $rows, $schema)
   */
  protected $writeCsv = TRUE;
  
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  private function BenchmarkDb($options) {}
    
  /**
   * adds benchmark meta data to $row
   * @param array $row the row to add metadata to
   * @return void
   */
  private final function addBenchmarkMeta(&$row) {
    // find benchmark.ini
    if ($ini = get_benchmark_ini()) {
      if (isset($ini['meta-version'])) $row['benchmark_version'] = $ini['meta-version']; 
    }
  }
  
  /**
   * Adds a $row to $table
   * @param string $table 
   * @param array $row 
   * @return boolean
   */
  public final function addRow($table, $row) {
    $added = FALSE;
    if ($table && is_array($row)) {
      $this->addBenchmarkMeta($row);
      // add system meta information
      foreach(get_sys_info() as $k => $v) if (!isset($row[sprintf('meta_%s', $k)])) $row[sprintf('meta_%s', $k)] = $v;
      
      $added = TRUE;
      if (!isset($this->rows[$table])) $this->rows[$table] = array();
      $this->rows[$table][] = array_merge($row, $this->artifacts);
    }
    return $added;
  }
  
  /**
   * returns a BenchmarkDb object based on command line arguments. returns NULL
   * if there are any problems with the command line arguments
   * @return BenchmarkDb
   */
  public static function &getDb() {
    $db = NULL;
    $options = parse_args(array('db:', 'db_and_csv:', 'db_callback_header:', 
                                'db_host:', 'db_librato_aggregate', 
                                'db_librato_color:', 'db_librato_count:',
                                'db_librato_description:',
                                'db_librato_display_max:', 
                                'db_librato_display_min:',
                                'db_librato_display_name:',
                                'db_librato_display_units_long:',
                                'db_librato_display_units_short:',
                                'db_librato_display_stacked',
                                'db_librato_display_transform:',
                                'db_librato_max:', 'db_librato_min:',
                                'db_librato_measure_time:', 'db_librato_name:',
                                'db_librato_period:', 'db_librato_source:', 
                                'db_librato_sum:', 'db_librato_summarize_function:',
                                'db_librato_sum_squares:', 'db_librato_type:',
                                'db_librato_value:',
                                'db_mysql_engine:', 'db_name:', 
                                'db_port:', 'db_pswd:', 'db_prefix:', 
                                'db_suffix:', 'db_user:', 'output:', 'params_file:', 
                                'remove:', 'skip_validations', 'store:', 'v' => 'verbose'), 
                          $aparams = array('db_librato_aggregate', 
                                'db_librato_color', 'db_librato_count',
                                'db_librato_description',
                                'db_librato_display_max', 
                                'db_librato_display_min',
                                'db_librato_display_name',
                                'db_librato_display_units_long',
                                'db_librato_display_units_short',
                                'db_librato_display_stacked',
                                'db_librato_display_transform',
                                'db_librato_max', 'db_librato_min',
                                'db_librato_measure_time', 'db_librato_name',
                                'db_librato_period', 'db_librato_source', 
                                'db_librato_sum', 'db_librato_summarize_function',
                                'db_librato_sum_squares', 'db_librato_type',
                                'db_librato_value', 'remove'), 
                          'save_');
    
    // merge settings with config file
    $cfile = BenchmarkDb::BENCHMARK_DB_CONFIG_FILE;
    if (isset($options['params_file']) && !file_exists($options['params_file']) && 
        !file_exists($options['params_file'] = trim(shell_exec('pwd')) . '/' . $options['params_file'])) print_msg(sprintf('--params_file %s is not a valid file', $options['params_file']), TRUE, __FILE__, __LINE__, TRUE);
    else if (isset($options['params_file'])) $cfile = $options['params_file'];
    merge_options_with_config($options, $cfile);
    // convert array parameters found in config file
    foreach($aparams as $aparam) {
      if (isset($options[$aparam]) && !is_array($options[$aparam])) {
        $p = array();
        foreach(explode(',', $options[$aparam]) as $v) {
          if (preg_match('/^"(.*)"$/', $v) || preg_match("/^'(.*)'\$/", $v)) $p[] = strip_quotes($v);
          else {
            foreach(explode(' ', trim($v)) as $v) $p[] = trim($v); 
          }
        }
        $options[$aparam] = $p;
      }
    }
    
    if (!isset($options['remove'])) $options['remove'] = array();
    // output directory
    if (!isset($options['output'])) $options['output'] = trim(shell_exec('pwd'));
    
    // default table suffix
    if (!isset($options['db_suffix']) && ($ini = get_benchmark_ini()) && isset($ini['meta-version'])) $options['db_suffix'] = '_' . str_replace('.', '_', $ini['meta-version']);
    
    $impl = 'BenchmarkDb';
    if (isset($options['db'])) {
      switch($options['db']) {
        case 'bigquery':
          $impl .= 'BigQuery';
          break;
        case 'callback':
          $impl .= 'Callback';
          break;
        case 'librato':
          $impl .= 'Librato';
          break;
        case 'mysql':
          $impl .= 'MySql';
          break;
        case 'postgresql':
          $impl .= 'PostgreSql';
          break;
        default:
          $err = '--db ' . $options['db'] . ' is not valid';
          break;
      }
      // invalid --db argument
      if (isset($err)) {
        print_msg($err, isset($options['verbose']), __FILE__, __LINE__, TRUE);
        return $db;
      }
    }
    if ($impl != 'BenchmarkDb') require_once(sprintf('%s/%s.php', dirname(__FILE__), $impl));
    
    $db = new $impl($options);
    $db->options = $options;
    $db->dir = $options['output'];
    if (!$db->validateDependencies()) $db = NULL;
    else if (!isset($options['skip_validations']) && !$db->validate()) $db = NULL;
    
    if ($db && isset($options['store'])) {
      require_once('BenchmarkArchiver.php');
      $db->archiver =& BenchmarkArchiver::getArchiver();
      if (!$db->archiver) $db = NULL;
    }
    
    return $db;
  }
  
  /**
   * returns the schema for the $table specified. return value is an array of
   * column/meta pairs
   * @param string $table the table to get the schema for
   * @return array
   */
  public final function getSchema($table) {
    if (!isset($this->schemas[$table])) {
      $this->schemas[$table] = array();
      $files = array(sprintf('%s/schema/common.json', dirname(__FILE__)), sprintf('%s/schema/%s.json', dirname(__FILE__), $table));
      foreach($files as $file) {
        if (file_exists($file)) {
          foreach(json_decode(file_get_contents($file), TRUE) as $col) {
            $remove = FALSE;
            foreach($this->options['remove'] as $check) {
              $check = trim($check);
              if ($check && ($col['name'] == $check || preg_match(sprintf('/^%s$/', str_replace('*', '.*', $check)), $col['name']))) {
                $remove = TRUE;
                break;
              }
            }
            if ($remove) {
              // print_msg(sprintf('Removing column %s from schema because of --remove %s flag', $col['name'], $check), isset($this->options['verbose']), __FILE__, __LINE__);
              continue;
            }
            $this->schemas[$table][$col['name']] = $col;
          }
        }
      }
      // remove steady state columns for the fio and wsat tables
      if ($table == 'fio' || $table == 'wsat') {
        foreach(array_keys($this->schemas[$table]) as $col) if (preg_match('/^ss_/', $col)) unset($this->schemas[$table][$col]);
      }
      ksort($this->schemas[$table]); 
      // move indexes to the end and remove columns if they are no longer in the schema
      $indexes = array();
      foreach(array_keys($this->schemas[$table]) as $key) {
        if (isset($this->schemas[$table][$key]['type']) && $this->schemas[$table][$key]['type'] == 'index') {
          $indexes[$key] = $this->schemas[$table][$key];
          unset($this->schemas[$table][$key]);
        }
      }
      $cols = array_keys($this->schemas[$table]);
      
      foreach($indexes as $key => $index) {
        if ($index['cols'] = array_intersect($index['cols'], $cols)) $this->schemas[$table][$key] = $index;
        else print_msg(sprintf('Removing index %s because it all of the columns associated with it have been removed from the schema', $key), isset($this->options['verbose']), __FILE__, __LINE__);
      }
    }
    
    return $this->schemas[$table];
  }
  
  /**
   * returns the actual table name to use for $table (applies --db_prefix and 
   * --db_suffix)
   * @param string $table the base name of the table
   * @return string
   */
  protected final function getTableName($table) {
    $prefix = isset($this->options['db_prefix']) ? $this->options['db_prefix'] : $this->tablePrefix;
    $suffix = isset($this->options['db_suffix']) ? $this->options['db_suffix'] : '';
    return $prefix . $table . $suffix;
  }
  
  /**
   * this method should be overriden by sub-classes to import CSV data into the 
   * underlying datastore. It should return TRUE on success, FALSE otherwise
   * @param string $table the name of the table to import to
   * @param string $csv the CSV file to import. If the instance attribute 
   * $writeCsv is set to FALSE, this parameter will change to an array of rows
   * each with the same column name indeces as $schema
   * @param array $schema the table schema
   * @return boolean
   */
  protected function importCsv($table, $csv, $schema) {
    return TRUE;
  }
  
  /**
   * Saves rows of data previously added via 'addRow'
   * @return boolean
   */
  public final function save() {
    $saved = FALSE;
    if ($this->rows) {
      foreach(array_keys($this->rows) as $table) {
        $csv = sprintf('%s/%s.csv', $this->dir, $table);
        $fp = fopen($csv, 'w');
        print_msg(sprintf('Saving %d rows to CSV file %s', count($this->rows[$table]), basename($csv)), isset($this->options['verbose']), __FILE__, __LINE__);
        
        $schema = $this->getSchema($table);
        
        // write headers
        foreach(array_keys($schema) as $i => $col) if ($schema[$col]['type'] != 'index') fwrite($fp, sprintf('%s%s', $i > 0 ? ',' : '', $col));
        fwrite($fp, "\n");
        
        foreach($this->rows[$table] as $row) {
          foreach(array_keys($schema) as $i => $col) {
            if ($schema[$col]['type'] != 'index') {
              fwrite($fp, sprintf('%s%s', $i > 0 ? ',' : '', isset($row[$col]) ? (strpos($row[$col], ',') ? '"' . str_replace('"', '\"', $row[$col]) . '"' : $row[$col]) : ''));
            }
          }
          fwrite($fp, "\n");
        }
        fclose($fp);
        
        if (isset($this->options['db'])) {
          if ($this->importCsv($table, $this->writeCsv ? $csv : $this->rows[$table], $schema)) {
            print_msg(sprintf('Successfully imported data to table %s in %s db', $table, $this->options['db']), isset($this->options['verbose']), __FILE__, __LINE__);
            if (!isset($this->options['db_and_csv']) || !$this->options['db_and_csv']) {
              exec(sprintf('rm -f %s', $csv));
              print_msg(sprintf('Deleted CSV file %s', $csv), isset($this->options['verbose']), __FILE__, __LINE__);
            }
            $saved = TRUE;
          }
          else {
            print_msg(sprintf('Failed to import CSV to table %s in %s db', $table, $this->options['db']), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
            $saved = FALSE;
          }
        }
        else $saved = TRUE;
      }
    }
    return $saved;
  }
  
  /**
   * saves an artifact if an archiver has been set. returns TRUE on success, 
   * FALSE is an archiver was not set and NULL on failure
   * @param string $file path to the artifact to save
   * @param string $col the name of the column in $table where the URL for this
   * artifact should be written
   * @return boolean
   */
  public final function saveArtifact($file, $col) {
    $saved = file_exists($file) ? ($this->archiver ? NULL : FALSE) : NULL;
    if (file_exists($file) && $this->archiver && ($url = $this->archiver->save($file))) {
      $this->artifacts[$col] = $url;
      $saved = TRUE;
    }
    return $saved;
  }
  
  /**
   * this method substitutes any tokens present in $str the the corresponding 
   * benchmark or row metadata. Benchmark metata tokens include {benchmark} and
   * {version}
   * @param string $str the string containing tokens
   * @param array $row the data row (hash of key/value pairs)
   * @return string
   */
  protected function substituteTokens($str, &$row) {
    if (preg_match_all('/\{([^\}]+)\}/', $str, $m)) {
      if (!isset($this->benchmarkIni)) $this->benchmarkIni = get_benchmark_ini();
      $meta = array('benchmark' => isset($this->benchmarkIni['meta-id']) ? $this->benchmarkIni['meta-id'] : NULL,
                    'version' => isset($this->benchmarkIni['meta-version']) ? $this->benchmarkIni['meta-version'] : NULL);
      foreach($m[1] as $i => $token) {
        $sub = '';
        if (isset($row[$token])) $sub = $row[$token];
        else if (isset($meta[$token])) $sub = $meta[$token];
        $str = str_replace($m[0][$i], $sub, $str);
      }
      // remove leading and trailing dash (-) and underscore (_)
      $str = trim($str);
      $str = trim(str_replace('--', '-', $str));
      $str = trim(str_replace('[]', '', $str));
      $str = trim(str_replace('()', '', $str));
      $str = trim($str);
      while($str && (substr($str, 0, 1) == '-' || substr($str, 0, 1) == '_')) $str = trim(substr($str, 1));
      while($str && (substr($str, -1) == '-' || substr($str, -1) == '_')) $str = trim(substr($str, 0, -1));
      $str = trim($str);
    }
    return $str;
  }
  
  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if (!isset($this->valid)) {
      $this->valid = FALSE;
      $dir = $this->options['output'];
      if (!is_dir($dir)) print_msg(sprintf('%s is not a valid output directory', $dir), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      else if (!is_writable($dir)) print_msg(sprintf('%s is not writable', $dir), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      else {
        print_msg(sprintf('Set output directory to %s', $dir), isset($this->options['verbose']), __FILE__, __LINE__);
        $this->valid = TRUE;
      } 
    }
    return $this->valid;
  }
  
  /**
   * validates dependencies for the chosen benchmark db. returns TRUE if 
   * present, FALSE otherwise
   * @return boolean
   */
  private final function validateDependencies() {
    $dependencies = array();
    if (isset($this->options['db'])) {
      switch($this->options['db']) {
        case 'bigquery':
          $dependencies['bq'] = 'Google Cloud SDK';
          break;
        case 'callback':
        case 'librato':
          $dependencies['curl'] = 'curl';
          break;
        case 'mysql':
          $dependencies['mysql'] = 'mysql';
          break;
        case 'postgresql':
          $dependencies['psql'] = 'postgresql';
          break;
        default:
          $err = '--db ' . $options['db'] . ' is not valid';
          break;
      }
    }
    if ($this->archiver) $dependencies['curl'] = 'curl';
    
    if ($dependencies = validate_dependencies($dependencies)) {
      foreach($dependencies as $dependency) print_msg(sprintf('Missing dependence %s', $dependency), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    return count($dependencies) > 0 ? FALSE : TRUE;
  }
  
}
?>
