<?php
/**
 * Invokes an http request and returns the status code (or response body if 
 * $retBody is TRUE) on success, NULL on failure or FALSE if the response code 
 * is not within the $success range
 * @param string  $url the target url
 * @param string $method the http method
 * @param array $headers optional request headers to include in the request
 * @param string $file optional file to pipe into the curl process as the 
 * body
 * @param string $auth optional [user]:[pswd] to use for http authentication
 * @param string $success the http response code/range that consistitutes 
 * a successful request. defaults to 200 to 299. This parameter may be a comma
 * separated list of values or ranges (e.g. "200,404" or "200-299,404")
 * @param boolean $retBody whether or not to return the response body. If 
 * FALSE (default), the status code is returned
 * @return mixed
 */
function ch_curl($url, $method='HEAD', $headers=NULL, $file=NULL, $auth=NULL, $success='200-299', $retBody=FALSE) {
  global $ch_curl_options;
  if (!isset($ch_curl_options)) $ch_curl_options = parse_args(array('v' => 'verbose'));
  
  if (!is_array($headers)) $headers = array();
  $ofile = $retBody ? '/tmp/' . rand() : '/dev/null';
  $curl = sprintf('curl -s -X %s%s -w "%s\n" -o %s', $method, $method == 'HEAD' ? ' -I' : '', '%{http_code}', $ofile);
  if ($auth) $curl .= sprintf(' -u "%s"', $auth);
  if (is_array($headers)) {
    foreach($headers as $header => $val) $curl .= sprintf(' -H "%s:%s"', $header, $val); 
  }
  // input file
  if (($method == 'POST' || $method == 'PUT') && file_exists($file)) {
    $curl .= sprintf(' --data-binary @%s', $file);
    if (!isset($headers['Content-Length']) && !isset($headers['content-length'])) $curl .= sprintf(' -H "Content-Length:%d"', filesize($file));
    if (!isset($headers['Content-Type']) && !isset($headers['content-type'])) $curl .= sprintf(' -H "Content-Type:%d"', get_mime_type($file));
  }
  $curl .= sprintf(' "%s"', $url);
  $ok = array();
  foreach(explode(',', $success) as $range) {
    if (is_numeric($range)) $ok[$range*1] = TRUE;
    else if (preg_match('/^([0-9]+)\s*\-\s*([0-9]+)$/', $range, $m) && $m[1] <= $m[2]) {
      for($i=$m[1]; $i<=$m[2]; $i++) $ok[$i] = TRUE;
    }
  }
  $ok = array_keys($ok);
  sort($ok);
  
  $cmd = sprintf('%s 2>/dev/null;echo $?', $curl);
  print_msg(sprintf('Invoking curl request: %s (expecting response %s)', $curl, $success), isset($ch_curl_options['verbose']), __FILE__, __LINE__);
  
  // execute curl
  $result = shell_exec($cmd);
  $output = explode("\n", trim($result));
  $response = NULL;
  
  // interpret callback response
  if (count($output) == 2) {
    $status = $output[0]*1;
    $ecode = $output[1]*1;
    if ($ecode) print_msg(sprintf('curl failed with exit code %d', $ecode), isset($ch_curl_options['verbose']), __FILE__, __LINE__, TRUE);
    else if (in_array($status, $ok)) {
      print_msg(sprintf('curl successful with status code %d', $status), isset($ch_curl_options['verbose']), __FILE__, __LINE__);
      $response = $retBody && file_exists($ofile) ? file_get_contents($ofile) : $status;
    }
    else {
      $response = FALSE;
      print_msg(sprintf('curl failed because to status code %d in not in allowed range %s', $status, $success), isset($ch_curl_options['verbose']), __FILE__, __LINE__, TRUE);
    }
  }
  if ($retBody && file_exists($ofile)) unlink($ofile);
  
  return $response;
}


/**
 * returns the contents of benchmark.ini as a hash
 */
function get_benchmark_ini() {
  global $benchmark_ini;
  if (!isset($benchmark_ini)) {
    $dirs = array(dirname(__FILE__));
    while(($dir = dirname($dirs[count($dirs) - 1])) != '/') $dirs[] = $dir;
    foreach($dirs as $dir) {
      if (file_exists($file = sprintf('%s/benchmark.ini', $dir))) {
        $benchmark_ini = array();
        foreach(file($file) as $line) {
          if (preg_match('/^([A-Za-z][^=]+)=(.*)$/', trim($line), $m)) $benchmark_ini[$m[1]] = $m[2];
        }
        break;
      }
    }
  }
  return $benchmark_ini;
}

/**
 * returns the mime type for the $file specified. uses /etc/mime.types
 * @param string $file the file to return the mime type for
 * @return string
 */
function get_mime_type($file) {
  $type = 'application/octet-stream';
  $pieces = explode('.', $file);
  $extension = strtolower($pieces[count($pieces) - 1]);
  foreach(file('/etc/mime.types') as $line) {
    if (preg_match('/^([a-z][\S]+)\s+([a-z].*)$/', $line, $m)) {
      $types = explode(' ', $m[2]);
      if (in_array($extension, $types)) {
        $type = $m[1];
        break;
      }
    }
  }
  return $type;
}

/**
 * returns system information. A hash containing the following keys:
 *   cpu        => CPU model information
 *   cpu_cache  => CPU cache size
 *   cpu_cores  => number of CPU cores
 *   cpu_speed  => CPU clock speed (MHz)
 *   hostname   => system hostname
 *   memory_gb  => system memory in gigabytes (rounded to whole number)
 *   memory_bb  => system memory in megabytes (rounded to whole number)
 *   os_info    => operating system name and version
 * @return array
 */
function get_sys_info() {
  global $sys_info;
  if (!is_array($sys_info)) {
    $sys_info = array();
		if ($lines = explode("\n", file_get_contents('/proc/cpuinfo'))) {
			foreach($lines as $line) {
				if (preg_match('/(.*):(.*)/', trim($line), $m)) {
					$key = trim($m[1]);
					$val = preg_replace('/\s+/', ' ', trim($m[2]));
					foreach(array('cores' => 'processor', 
												'name' => 'model name',
												'speed' => 'mhz',
												'cache' => 'cache size') as $k => $match) {
						if ($k == 'name') $val = str_replace('@ ', '', str_replace('CPU ', '', str_replace('Quad-Core ', '', str_replace('Processor ', '', str_replace('(tm)', '', str_replace('(R)', '', $val))))));
						if (preg_match("/$match/i", $key)) $sys_info[sprintf('cpu%s', $k != 'name' ? '_' . $k : '')] = $k == 'cores' ? $val + 1 : $val;
					}
				}
			}
		}
		$sys_info['hostname'] = trim(shell_exec('hostname'));
		if (preg_match('/Mem:\s+([0-9]+)\s+([0-9]+)\s+([0-9]+)/', shell_exec('free -m'), $m)) {
      $mb = $m[1]*1;
      $sys_info['memory_mb'] = round($m[1]);
      $sys_info['memory_gb'] = round($m[1]/1024);
		}
		$issue = file_get_contents('/etc/issue');
		foreach(explode("\n", $issue) as $line) {
			if (!isset($attr) && trim($line)) {
			  $attr = trim($line);
			  break;
		  }
		}
		// remove superfluous information
		if ($attr) {
			$attr = str_replace('(\l).', '', $attr);
			$attr = str_replace('\n', '', $attr);
			$attr = str_replace('\l', '', $attr);
			$attr = str_replace('\r', '', $attr);
			$attr = str_replace('Welcome to', '', $attr);
			$sys_info['os_info'] = trim($attr);
		}
  }
  return $sys_info;
}


/**
 * merges config options into $options
 * @param array $options the options to merge into
 * @param string $config the config file to merge with
 * @return void
 */
function merge_options_with_config(&$options, $config) {
  foreach(explode("\n", shell_exec('cat ' . $config . ' 2>/dev/null')) as $line) {
    if (substr(trim($line), 0, 1) == '#') continue;
    if (preg_match('/([A-Za-z_]+)\s*=?\s*(.*)$/', $line, $m) && !isset($options[$key = strtolower($m[1])])) {
      print_msg(sprintf('Added option %s=%s from config %s', $key, $m[2], $config), isset($options['verbose']), __FILE__, __LINE__);
      $options[$key] = $m[2] ? trim($m[2]) : TRUE;
    }
  }
}


/**
 * this method creates an arguments hash containing the command line args as 
 * a where the key is the long argument name and the value is the value for
 * that argument. boolean arguments will automatically be converted to PHP bools
 * @param array $opts the options definition - a hash of short/long argument 
 * names. if the key is numeric, it will be assumed to not have a short argument 
 * option. if the argument name has a colon (:) at the end, it will be assumed to 
 * be an argument that requires a value - otherwise it will be assumed to be a 
 * flag argument
 * @param array $arrayArgs if specified, options with these names will be 
 * forced to arrays even if they only contain a single argument value and 
 * arguments that repeat that area not included in this argument will be set 
 * to the first specified value (others will be discarded)
 * @param string $paramPrefix an optional prefix to apply when evaluating 
 * bm_param_ environment variables
 * @return array
 */
function parse_args($opts, $arrayArgs=NULL, $paramPrefix='') {
  global $argv;
  $key = NULL;
  $val = NULL;
  $options = array();
  foreach($argv as $arg) {
    if (preg_match('/^\-\-?([^=]+)\=?(.*)$/', $arg, $m)) {
      if ($key && isset($options[$key])) {
        if (!is_array($options[$key])) $options[$key] = array($options[$key]);
        $options[$key][] = $val;
      }
      else if ($key) $options[$key] = $val;
      $key = $m[1];
      $val = isset($m[2]) ? $m[2] : '';
    }
    else if ($key) $val .= ' ' . $arg;
  }
  if ($key && isset($options[$key])) {
    if (!is_array($options[$key])) $options[$key] = array($options[$key]);
    $options[$key][] = $val;
  }
  else if ($key) $options[$key] = $val;

  foreach($opts as $short => $long) {
    $key = str_replace(':', '', $long);
    if (preg_match('/[a-z]:?$/', $short) && isset($options[$short = substr($short, 0, 1)])) {
      if (isset($options[$key])) {
        if (!is_array($options[$key])) $options[$key] = array($options[$key]);
        $options[$key][] = $options[$short];
      }
      else $options[$key] = $options[$short];
      unset($options[$short]);
    }
    // check for environment variable
    if (!isset($options[$key]) && preg_match('/^meta_/', $key) && getenv('bm_' . str_replace('meta_', '', $key))) $options[$key] = getenv('bm_' . str_replace('meta_', '', $key));
    if (!isset($options[$key]) && getenv("bm_param_${paramPrefix}${key}")) $options[$key] = getenv("bm_param_${paramPrefix}${key}");
    // convert booleans
    if (isset($options[$key]) && !strpos($long, ':')) $options[$key] = $options[$key] === '0' ? FALSE : TRUE;
    // set array parameters
    if (is_array($arrayArgs)) {
      if (isset($options[$key]) && in_array($key, $arrayArgs) && !is_array($options[$key])) {
        $pieces = explode(',', $options[$key]);
        $options[$key] = array();
        foreach($pieces as $v) $options[$key][] = trim($v);
      }
      else if (isset($options[$key]) && !in_array($key, $arrayArgs) && is_array($options[$key])) $options[$key] = $options[$key][0];
    }
    // remove empty values
    if (!isset($options[$key])) unset($options[$key]);
  }
  
  // remove quotes
  foreach(array_keys($options) as $i) {
    if (is_array($options[$i])) {
      foreach(array_keys($options[$i]) as $n) $options[$i][$n] = strip_quotes($options[$i][$n]);
    }
    else $options[$i] = strip_quotes($options[$i]);
  }

  return $options;
}


  
/**
 * Prints a message to stdout
 * @param string $msg the message to print
 * @param boolean $verbose whether or not verbose output mode is enabled. If 
 * not enabled, message will not be printed (unless $err==TRUE)
 * @param string $file optional name of the file generating the message
 * @param int $line optional line number in the file generating the message
 * @param boolean $err if this message an error?
 * @return void
 */
function print_msg($msg, $verbose=FALSE, $file=NULL, $line=NULL, $err=FALSE) {
  if ($verbose || $err) {
  	printf("%-24s %-8s %-24s %s\n", 
  	       date('m/d/Y H:i:s T'), 
  	       run_time() . 's', 
  				 str_replace('.php', '', basename($file ? $file : __FILE__)) . ':' . ($line ? $line : __LINE__),
  				 ($err ? 'ERROR: ' : '') . $msg);
  }
}

/**
 * returns the current execution time
 * @return float
 */
$run_time_start = microtime(TRUE);
function run_time() {
	global $run_time_start;
	return round(microtime(TRUE) - $run_time_start);
}

/**
 * Trims and removes leading and trailing quotes from a string 
 * (e.g. "some string" => some string; 'some string' => some string)
 * @param string $string the string to remove quotes from
 * @return string
 */
function strip_quotes($string) {
  $string = trim($string);
  if (preg_match('/^"(.*)"$/', $string, $m)) $string = $m[1];
  else if (preg_match("/^'(.*)'\$/", $string, $m)) $string = $m[1];
  return $string;
}

/**
 * validate that the cli commands in the $dependencies array are present. 
 * returns an array containing those commands that are not valid or an empty
 * array if they are all valid
 * @param array $dependencies the cli commands to validate. this is a hash 
 * indexed by command where the valid is the package name
 * @return array
 */
function validate_dependencies($dependencies) {
  if (is_array($dependencies)) {
    foreach($dependencies as $c => $dependency) {
      $cmd = sprintf('which %s; echo $?', $c);
      $ecode = trim(exec($cmd));
      if ($ecode == 0) unset($dependencies[$c]);
    }
  }
  return $dependencies;
}

/**
 * validate script options. returns an array populated with error messages 
 * indexed by the argument name. If options are valid, the array returned
 * will be empty
 * @param array $options the option values to validate
 * @param array $validate validation hash - indexed by argument name where 
 * the value is a hash of validation constraints. The following constraints 
 * are supported:
 *   min:      argument numeric and >= this value
 *   max:      argument numeric and <= this value
 *   option:   argument must be found in this value (array)
 *   required: argument is required
 *   write:    argument is in the file syste path and writeable
 * @return array
 */
function validate_options($options, $validate) {
  $invalid = array();
  foreach($validate as $arg => $constraints) {
    foreach($constraints as $constraint => $cval) {
      $err = NULL;
      $vals = isset($options[$arg]) ? $options[$arg] : NULL;
      if (!is_array($vals)) $vals = array($vals);
      foreach($vals as $val) {
        // printf("Validate --%s=%s using constraint %s\n", $arg, $val, $constraint);
        switch($constraint) {
          case 'min':
          case 'max':
            if ($val && !is_numeric($val)) $err = sprintf('%s is not numeric', $val);
            else if (is_numeric($val) && $constraint == 'min' && $val < $cval) $err = sprintf('%d is less then minimum permitted value %d', $val, $cval);
            else if (is_numeric($val) && $constraint == 'max' && $val > $cval) $err = sprintf('%d is greater then maximum permitted value %d', $val, $cval);
            break;
          case 'option':
            if ($val && !in_array($val, $cval)) $err = sprintf('%s must be one of the following: %s', $val, implode(', ', $cval));
            break;
          case 'required':
            if ($val === NULL) $err = sprintf('argument is required', $arg);
            break;
          case 'write':
            if ($val && !file_exists($val)) $err = sprintf('%s is not a valid path', $val);
            else if ($val && !is_writable($val)) $err = sprintf('%s is not writable', $val);
            break;
        }
        if ($err) {
          $invalid[$arg] = $err; 
          break;
        }
      }
    }
  }
  return $invalid;
}
?>