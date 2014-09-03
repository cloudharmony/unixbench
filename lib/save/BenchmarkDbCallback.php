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
 * HTTP callback implementation of the BenchmarkDb class
 */
class BenchmarkDbCallback extends BenchmarkDb {
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkDb::getDb static method
   * @param array $options db command line arguments
   */
  protected function BenchmarkDbCallback($options) {}
  
  
  /**
   * returns http authentication header ([user]:[pswd]) if set
   * @return string
   */
  private function getAuth() {
    return isset($this->options['db_user']) && isset($this->options['db_pswd']) ? sprintf('"%s:%s"', $this->options['db_user'], $this->options['db_pswd']) : NULL;
  }
  
  /**
   * returns optional headers to append to http requests
   * @return array
   */
  private function getHeaders() {
    $headers = NULL;
    if (isset($this->options['db_callback_header'])) {
      $headers = array();
      $pieces = explode(':', $this->options['db_callback_header']);
      $headers[trim($pieces[0])] = trim($pieces[1]);
    }
    return $headers;
  }
  
  /**
   * returns the base URL to use for this callback
   * @param array $params optional parameter to append to the url
   * @return string
   */
  private function getUrl($params=NULL) {
    $url = sprintf('%s%s', preg_match('/^http/', $this->options['db_host']) ? '' : 'http://', $this->options['db_host']);
    // add custom port
    if (isset($this->options['db_port']) && is_numeric($this->options['db_port'])) {
      $pieces = explode('?', $url);
      $url = sprintf('%s:%d%s', $pieces[0], $this->options['db_port'], isset($pieces[1]) ? $pieces[1] : '');
    }
    // add url parameters
    if (is_array($params)) {
      foreach($params as $key => $val) $url .= sprintf('%s%s=%s', strpos($url, '?') ? '&': '?', urlencode($key), urlencode($val));
    }
    return $url;
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
    $benchmarkId = isset($this->benchmarkIni['meta-id']) ? $this->benchmarkIni['meta-id'] : NULL;
    $benchmarkVersion = isset($this->benchmarkIni['meta-version']) ? $this->benchmarkIni['meta-version'] : NULL;
    $db = isset($this->options['db_name']) ? $this->options['db_name'] : NULL;
    $table = $this->getTableName($table);
    $params = array('benchmark_id' => $benchmarkId, 'benchmark_version' => $benchmarkVersion, 'db_name' => $db, 'table' => $table);
    return ch_curl($this->getUrl($params), 'POST', $this->getHeaders(), $csv, $this->getAuth()) !== NULL;
  }

  /**
   * validation method - may be overriden by sub-classes, but parent method 
   * should still be invoked. returns TRUE if db options are valid, FALSE 
   * otherwise
   * @return boolean
   */
  protected function validate() {
    if ($valid = parent::validate()) {
      if (isset($this->options['db_host'])) {
        if ($valid = ch_curl($this->getUrl(), 'HEAD', $this->getHeaders(), NULL, $this->getAuth())) print_msg('Successfully validated callback using HEAD request', isset($this->options['verbose']), __FILE__, __LINE__);
        else print_msg('Unable to validate callback using HEAD request', isset($this->options['verbose']), __FILE__, __LINE__, TRUE); 
      }
      else {
        $valid = FALSE;
        print_msg('--db_host argument is required for --db callback', isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
    }
    return $valid;
  }
  
}
?>
