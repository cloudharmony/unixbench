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
 * Librato Metrics implementation of the BenchmarkDb class. For more details,
 * see https://metrics.librato.com
 */
class BenchmarkDbLibrato extends BenchmarkDb {
  /**
   * used to track the results from the validate function
   */
  private $valid;
  
  /**
   * base API URL
   */
  const LIBRATO_METRICS_API_URL = 'https://metrics-api.librato.com/v1/metrics';
  
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbLibrato($options) {
    $this->writeCsv = FALSE;
  }

  
  /**
   * this method should be overriden by sub-classes to import CSV data into the 
   * underlying datastore. It should return TRUE on success, FALSE otherwise
   * @param string $table the name of the table to import to
   * @param string $rows the data rows to import
   * @param array $schema the table schema
   * @return boolean
   */
  protected function importCsv($table, $rows, $schema) {
    $imported = FALSE;
    
    // default type is gauge
    if (!isset($this->options['db_librato_type'])) $this->options['db_librato_type'] = array();
    if (!$this->options['db_librato_type']) $this->options['db_librato_type'][] = 'gauge';
    
    $request = array();
    $sources = array();
    $measureTimes = array();
    foreach($rows as $row) {
      $attr = isset($this->options['db_librato_value']) ? 'db_librato_value' : 'db_librato_count';
      foreach($this->options[$attr] as $i => $valueCol) {
        $value = isset($row[$valueCol]) ? $row[$valueCol]*1 : NULL;
        if (is_numeric($value) && $value > 0) {
          $name = $this->substituteTokens(isset($this->options['db_librato_name']) ? $this->options['db_librato_name'][$i] : $this->getTableName($table), $row);
          $type = isset($this->options['db_librato_type'][$i]) ? $this->options['db_librato_type'][$i] : $this->options['db_librato_type'][0];
          $record = array();
          $record['name'] = $name;
          $record[$attr == 'db_librato_value' ? 'value' : 'count'] = $value;
          
          // extra parameters
          foreach(array('db_librato_aggregate', 'db_librato_color', 
                        'db_librato_description', 
                        'db_librato_display_max', 'db_librato_display_min',
                        'db_librato_display_name', 
                        'db_librato_display_units_long',
                        'db_librato_display_units_short',
                        'db_librato_display_stacked',
                        'db_librato_display_transform',
                        'db_librato_max', 'db_librato_min',
                        'db_librato_measure_time', 'db_librato_period', 
                        'db_librato_source', 'db_librato_sum', 
                        'db_librato_summarize_function',
                        'db_librato_sum_squares') as $param) {
            if ($pval = isset($this->options[$param]) ? (array_key_exists($i, $this->options[$param]) ? $this->options[$param][$i] : $this->options[$param][0]) : NULL) {
              switch($param) {
                case 'db_librato_count':
                case 'db_librato_max':
                case 'db_librato_min':
                case 'db_librato_sum':
                case 'db_librato_sum_squares':
                  if ($type == 'gauge' && isset($row[$pval]) && is_numeric($row[$pval])) $record[str_replace('db_librato_', '', $param)] = $row[$pval]*1;
                  break;
                case 'db_librato_measure_time':
                  if (isset($row[$pval]) && ($timestamp = strtotime($row[$pval]))) {
                    if (!in_array($timestamp, $measureTimes)) $measureTimes[] = $timestamp;
                    $record['measure_time'] = $timestamp;
                  }
                  break;
                case 'db_librato_source':
                  if ($source = $this->substituteTokens($pval, $row)) {
                    if (!in_array($source, $sources)) $sources[] = $source;
                    $record['source'] = $source;
                  }
                  break;
                case 'db_librato_display_name':
                case 'db_librato_description':
                  $record[str_replace('db_librato_', '', $param)] = $this->substituteTokens($pval, $row);
                  break;
                case 'db_librato_period':
                  if (is_numeric($pval) && $pval > 0) $record[str_replace('db_librato_', '', $param)] = $pval*1;
                  break;
                case 'db_librato_color':
                case 'db_librato_display_max':
                case 'db_librato_display_min':
                case 'db_librato_display_units_long':
                case 'db_librato_display_units_short':
                case 'db_librato_display_stacked':
                case 'db_librato_display_transform':
                case 'db_librato_aggregate':
                case 'db_librato_summarize_function':
                  if ($type == 'gauge' || ($param != 'db_librato_aggregate' && $param != 'db_librato_summarize_function')) {
                    if (!isset($record['attributes'])) $record['attributes'] = array();
                    $record['attributes'][str_replace('db_librato_', '', $param)] = $param == 'db_librato_display_stacked' || $param == 'db_librato_aggregate' ? TRUE : (isset($row[$pval]) ? $row[$pval] : $pval);
                  }
                  break;
              }
            }
          }
          
          if (!isset($request[$type . 's'])) $request[$type . 's'] = array();
          $request[$type . 's'][] = $record;
        }
        else print_msg(sprintf('Skipping --%s %s because %s ', $attr, $valueCol, $value === NULL ? 'it is not present in the results' : 'it is not numeric or greater than 0'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
    }
    
    if ($request) {
      // remove duplciate measure_time if they are all the same
      if ($measureTimes && count($measureTimes) == 1) {
        print_msg(sprintf('Removing duplicate measure_time values because they are all the same: %d', $measureTimes[0]), isset($this->options['verbose']), __FILE__, __LINE__);
        $request['measure_time'] = $measureTimes[0];
        foreach(array_keys($request) as $type) {
          if (is_array($request[$type])) {
            foreach(array_keys($request[$type]) as $i) {
              if (isset($request[$type][$i]['measure_time'])) unset($request[$type][$i]['measure_time']);
            } 
          }
        }
      }
      // remove duplciate sources if they are all the same
      if ($sources && count($sources) == 1) {
        print_msg(sprintf('Removing duplicate source values because they are all the same: %s', $sources[0]), isset($this->options['verbose']), __FILE__, __LINE__);
        $request['source'] = $sources[0];
        foreach(array_keys($request) as $type) {
          if (is_array($request[$type])) {
            foreach(array_keys($request[$type]) as $i) {
              if (isset($request[$type][$i]['source'])) unset($request[$type][$i]['source']);
            }
          }
        }
      }
      
      $file = sprintf('%s/librato-import.json', $this->options['output']);
      if ($fp = fopen($file, 'w')) {
        $metrics = (isset($request['counters']) ? count($request['counters']) : 0) + (isset($request['gauges']) ? count($request['gauges']) : 0);
        fwrite($fp, json_encode($request));
        fclose($fp);
        $curl = ch_curl(self::LIBRATO_METRICS_API_URL, 'POST', array('Content-Type' => 'application/json'), $file, sprintf('%s:%s', $this->options['db_user'], $this->options['db_pswd']));
        
        // API response
        if ($curl === NULL) print_msg(sprintf('Librato API POST request to %s failed - unknown error', self::LIBRATO_METRICS_API_URL), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        else if ($curl === FALSE) print_msg(sprintf('Librato API POST request to %s resulted in a non 2XX response code', self::LIBRATO_METRICS_API_URL), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        else {
          $imported = TRUE;
          print_msg(sprintf('Librato API POST request to %s successful. %d metrics were created', self::LIBRATO_METRICS_API_URL, $metrics), isset($this->options['verbose']), __FILE__, __LINE__);
          unlink($file);
        }
      }
      else print_msg(sprintf('Unable to open file %s for writing', $file), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    
    return $imported;
  }
  
  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if (!isset($this->valid) && parent::validate()) {
      $this->valid = TRUE;
      $validate = array(
        'db_librato_color' => array('color' => TRUE),
        'db_librato_display_max' => array('min' => 0),
        'db_librato_display_min' => array('min' => 0),
        'db_librato_period' => array('min' => 0),
        'db_librato_type' => array('option' => array('counter', 'gauge')),
        'db_user' => array('required' => TRUE),
        'db_pswd' => array('required' => TRUE)
      );
      if ($validated = validate_options($this->options, $validate)) {
        $this->valid = FALSE;
        foreach($validated as $param => $err) print_msg(sprintf('--%s is not valid: %s', $param, $err), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      else {
        $valueCol = 'db_librato_value';
        if (!isset($this->options['db_librato_value'])) {
          $valueCol = 'db_librato_count';
          if (!isset($this->options['db_librato_count']) || !isset($this->options['db_librato_sum'])) {
            $this->valid = FALSE;
            print_msg(sprintf('If --db_librato_value is not set, both --db_librato_count and --db_librato_sum MUST be specified'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          }
          else if (count($this->options['db_librato_count']) != count($this->options['db_librato_sum'])) {
            $this->valid = FALSE;
            print_msg(sprintf('--db_librato_count and --db_librato_sum must be repeated the same number of times'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          }
          else if (isset($this->options['db_librato_name']) && count($this->options['db_librato_name']) != count($this->options['db_librato_count'])) {
            $this->valid = FALSE;
            print_msg(sprintf('--db_librato_name and --db_librato_count must be repeated the same number of times'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
          }
        }
        else if (isset($this->options['db_librato_name']) && count($this->options['db_librato_name']) != count($this->options['db_librato_value'])) {
          $this->valid = FALSE;
          print_msg(sprintf('--db_librato_name and --db_librato_value must be repeated the same number of times'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
        else if (isset($this->options['db_librato_count']) || isset($this->options['db_librato_sum']) || isset($this->options['db_librato_max']) || isset($this->options['db_librato_min']) || isset($this->options['db_librato_sum_squares'])) {
          $this->valid = FALSE;
          print_msg(sprintf('--db_librato_value cannot be set with --db_librato_count, --db_librato_sum, --db_librato_max, --db_librato_min or --db_librato_sum_squares because these parameters are mutually exclusive'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        }
        
        if ($this->valid) {
          foreach(array('db_librato_aggregate', 'db_librato_color', 
                        'db_librato_count', 'db_librato_description', 
                        'db_librato_display_max', 'db_librato_display_min',
                        'db_librato_display_name', 
                        'db_librato_display_units_long',
                        'db_librato_display_units_short',
                        'db_librato_display_stacked',
                        'db_librato_display_transform',
                        'db_librato_max', 'db_librato_min',
                        'db_librato_measure_time', 'db_librato_period', 
                        'db_librato_source', 'db_librato_sum', 
                        'db_librato_summarize_function',
                        'db_librato_sum_squares', 'db_librato_type') as $param) {
            if (isset($this->options[$param]) && count($this->options[$param]) != count($this->options[$valueCol]) && count($this->options[$param]) != 1) {
              $this->valid = FALSE;
              print_msg(sprintf('--%s can only be set once or %d times (once for each --%s)', $param, count($this->options[$valueCol]), $valueCol), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
            }
          }
        }
      }
      
      if ($this->valid) {        
        // validate credentials using GET request
        $curl = ch_curl(self::LIBRATO_METRICS_API_URL, 'GET', NULL, NULL, sprintf('%s:%s', $this->options['db_user'], $this->options['db_pswd']), '200-299', TRUE);
        $this->valid = ($response = json_decode($curl, TRUE)) ? TRUE : FALSE;
        if ($curl === NULL) print_msg(sprintf('Librato API GET request to %s failed', self::LIBRATO_METRICS_API_URL), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        else if ($curl === FALSE) print_msg(sprintf('Librato API GET request to %s resulted in non 200 response code - API credentials may be invalid', self::LIBRATO_METRICS_API_URL), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        else if ($curl && !$response) print_msg(sprintf('Librato API GET request to %s successful, but body did not contain valid JSON', self::LIBRATO_METRICS_API_URL), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        else print_msg(sprintf('Librato API GET request to %s successful. There are %d existing metrics', self::LIBRATO_METRICS_API_URL, count($response['metrics'])), isset($this->options['verbose']), __FILE__, __LINE__);
      } 
    }
    return $this->valid;
  }
  
}
?>
