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
 * Base class for a benchmark archiver
 */
require_once(dirname(dirname(__FILE__)) . '/util.php');
date_default_timezone_set('UTC');

abstract class BenchmarkArchiver {
  
  /**
   * default artifact prefix
   */
  const DEFAULT_PREFIX = '{benchmark}_{version}/{meta_compute_service_id|meta_provider_id}/{meta_instance_id}/{meta_storage_config}/{meta_region}/{date|meta_test_id}/{meta_resource_id|hostname}/{meta_run_id|rand}-{iteration}';
  
  /**
   * archiver options
   */
  protected $options;
  
  /**
   * stores random strings (to avoid duplicates)
   */
  private $randStrings = array();
  
  /**
   * returns a BenchmarkArchiver object based on command line arguments
   * @return BenchmarkArchiver
   */
  public static function &getArchiver() {
    $archiver = NULL;
    $options = parse_args(array('store:', 'store_container:', 'store_endpoint:', 'store_insecure', 'store_key:', 'store_prefix:', 'store_public', 'store_region:', 'store_secret:', 'v' => 'verbose'), NULL, 'save_');
    merge_options_with_config($options, BenchmarkDb::BENCHMARK_DB_CONFIG_FILE);
    $impl = 'BenchmarkArchiver';
    switch($options['store']) {
      case 'azure':
        $impl .= 'Azure';
        break;
      case 'google':
        $impl .= 'Google';
        break;
      case 's3':
        $impl .= 'S3';
        break;
      default:
        $err = '--store ' . $options['store'] . ' is not valid';
        break;
    }
    // invalid --store argument
    if (isset($err)) {
      print_msg($err, isset($options['verbose']), __FILE__, __LINE__, TRUE);
      return $archiver;
    }
    
    require_once(sprintf('%s/%s.php', dirname(__FILE__), $impl));
    
    $archiver = new $impl($options);
    $archiver->options = $options;
    if (!$archiver->validate()) $archiver = NULL;
    
    return $archiver;
  }
  
  /**
   * returns the object URI (minus container) for $file (appends --store_prefix
   * if applicable). Includes substitution of the following dynamic values:
   *   {date[_format]} => a date string (optionally 
   *                      formatted per [format] - see
   *                      http://php.net/manual/en/function.date.php
   *                      for valid format options - 
   *                      default format is Y-m-d)
   *   {benchmark}     => benchmark name (block-storage)
   *                      (meta-id value in benchmark.ini)
   *   {version}       => benchmark version (1.0)
   *                      (meta-version value in benchmark.ini)
   *   {iteration}     => iteration number
   *   {hostname}      => the compute instance hostname
   *   {meta_*}        => any of the meta_* runtime 
   *                      parameters. If a meta_* value
   *                      is designated but was not set, 
   *                      at runtime, it will be removed 
   *                      from the prefix
   *   {rand}          => a random number
   * @param string $file the file to return the URI
   * @return string
   */
  protected final function getObjectUri($file) {
    $prefix = isset($this->options['store_prefix']) ? $this->options['store_prefix'] : BenchmarkArchiver::DEFAULT_PREFIX;
    if (preg_match_all('/{([^}]+)}/', $prefix, $m)) {
      $ini = get_benchmark_ini();
      $options = file_exists($options = dirname($file) . '/.options') ? unserialize(file_get_contents($options)) : NULL;
      foreach($m[1] as $value) {
        $sub = '';
        foreach(explode('|', $value) as $check) {
          $check = trim($check);
          if (preg_match('/^date_?(.*)$/', $check, $d)) {
            $format = $d[1] ? $d[1] : 'Y-m-d';
            $sub = date($format, filemtime($file));
          }
          else if ($check == 'benchmark') $sub = isset($ini['meta-id']) ? $ini['meta-id'] : '';
          else if ($check == 'version') $sub = isset($ini['meta-version']) ? str_replace('.', '_', $ini['meta-version']) : '';
          else if ($check == 'iteration') $sub = is_numeric(basename(dirname($file))) ? basename(dirname($file))*1 : 1;
          else if ($check == 'hostname') $sub = trim(shell_exec('hostname'));
          else if ($check == 'rand') $sub = '[rand]';
          else if ($options && isset($options[$check])) $sub = trim(strtolower(str_replace(' ', '_', $options[$check])));
          if ($sub == 'not_specified' || $sub == 'na') $sub = '';
          if ($sub) break;
        }
        $prefix = str_replace('{' . $value . '}', $sub, $prefix);
        if (!$sub) $prefix = str_replace('//', '/', $prefix);
      }
      if (!isset($this->randStrings[$prefix])) $this->randStrings[$prefix] = rand();
      $prefix = str_replace('[rand]', $this->randStrings[$prefix], $prefix);
    }
    return str_replace('//', '/', sprintf('%s/%s', $prefix, basename($file)));
  }
   
  /**
   * saves a file and returns the URL. returns NULL on error
   * @param string $file local path to the file that should be saved
   * @return string
   */
  public abstract function save($file);
  
  /**
   * validation method - must be implemented by subclasses. Returns TRUE if 
   * archiver options are valid, FALSE otherwise
   * @return boolean
   */
  protected abstract function validate();
  
}
?>
