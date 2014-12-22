<?php
/**
 * checks if the current user has sudo privileges. returns TRUE or FALSE
 * @return boolean
 */
function ch_check_sudo() {
  return shell_exec('sudo -n uptime 2>&1|grep "load"|wc -l')*1 === 1;
}

/**
 * attempts to start collectd rrd stats for the current test iteration. returns
 * TRUE on success, FALSE otherwise
 * @param string $dir the base directory where collectd rrd files are stored
 * @param boolean $verbose enable verbose output
 * @return boolean
 */
function ch_collectd_rrd_start($dir, $verbose) {
  $started = FALSE;
  if (is_dir($dir) && ch_check_sudo()) {
    exec(sprintf('sudo rm -rf %s/*.bak', $dir));
    $d = dir($dir);
    while($entry = $d->read()) {
      if (!preg_match('/[a-zA-Z0-9]+/', $entry)) continue;
      $rdir = sprintf('%s/%s', $dir, $entry);
      if (is_dir($rdir) && !preg_match('/\.bak$/', $entry)) {
        $rdirBak = $rdir . '.bak';
        exec(sprintf('sudo mv %s %s', $rdir, $rdirBak));
        if (is_dir($rdirBak)) {
          $started = TRUE;
          print_msg(sprintf('Successfully renamed existing collectd rrd directory from %s to %s', $rdir, $rdirBak), $verbose, __FILE__, __LINE__);
        }
        else {
          $started = FALSE;
          print_msg(sprintf('Unable to rename existing collectd rrd directory from %s to %s', $rdir, $rdirBak), $verbose, __FILE__, __LINE__, TRUE);
        }
      }
    }
    $d->close();
  }
  return $started;
}

/**
 * attempts to stop collectd rrd stats for the current test iteration. returns
 * TRUE on success, FALSE otherwise
 * @param string $dir the base directory where collectd rrd files are stored
 * @param string $saveTo the directory where the rrd zip file should be created
 * @param array $options test options - used to construct collectd rrd results 
 * directories
 * @param boolean $verbose enable verbose output
 * @return boolean
 */
function ch_collectd_rrd_stop($dir, $saveTo, $verbose) {
  $stopped = FALSE;
  if (is_dir($dir) && ch_check_sudo()) {
    $tdir = '/tmp/' . rand();
    exec(sprintf('sudo mkdir %s', $tdir));
    print_msg(sprintf('Attempting to save collectd rrd files in %s to %s/collectd-rrd.zip using tmp directory %s', $dir, $saveTo, $tdir), $verbose, __FILE__, __LINE__);
    
    $rename = array();
    $d = dir($dir);
    while($entry = $d->read()) {
      if (!preg_match('/[a-zA-Z0-9]+/', $entry)) continue;
      $rdir = sprintf('%s/%s', $dir, $entry);
      if (substr($rdir, -1) == '/') $rdir = substr($rdir, 0, -1);
      if (!is_dir($rdir)) continue;
      if (is_dir($rdir)) {
        print_msg(sprintf('Evaluating collectd rrd directory %s', $rdir), $verbose, __FILE__, __LINE__);
        if (preg_match('/\.bak$/', $entry)) $rename[] = $rdir;
        else {
          exec($cmd = sprintf('sudo mv %s %s', $rdir, $tdir));
          print_msg(sprintf('Successfully moved test collectd rrd directory %s to %s', basename($rdir), $tdir), $verbose, __FILE__, __LINE__);
        }
      }
    }
    $d->close();
    foreach($rename as $rdir) {
      $renameTo = substr($rdir, 0, -4);
      exec($cmd = sprintf('sudo rm -rf %s;sudo mv %s %s', $renameTo, $rdir, $renameTo));
      if (is_dir($renameTo)) print_msg(sprintf('Successfully renamed backup collectd rrd directory from %s to %s', $rdir, $renameTo), $verbose, __FILE__, __LINE__);
      else print_msg(sprintf('Unable to rename existing collectd rrd directory from %s to %s', $rdir, $renameTo), $verbose, __FILE__, __LINE__, TRUE);
    }
    
    if ((shell_exec(sprintf('find %s -maxdepth 1 -type d 2>/dev/null | wc -l', $tdir))*1 < 2)) print_msg(sprintf('collectd rrd directory %s did not contain any files', $dir), $verbose, __FILE__, __LINE__, TRUE);
    else {
      $zip = sprintf('%s/collectd-rrd.zip', $saveTo);
      exec($cmd = sprintf('cd %s;sudo zip -r collectd-rrd.zip *;sudo mv collectd-rrd.zip %s', $tdir, $saveTo, $tdir));
      if ($stopped = file_exists($zip) && filesize($zip)) print_msg(sprintf('Successfully saved collectd rrd files to %s', $zip), $verbose, __FILE__, __LINE__);
      else print_msg(sprintf('Unable to save collectd rrd files: %s', $cmd), $verbose, __FILE__, __LINE__, TRUE);
    }
  }
  else print_msg(sprintf('collectd rrd directory %s does not exist or user does not have sudo access', $dir), $verbose, __FILE__, __LINE__, TRUE);
  
  // sleep for 10 seconds to allow collectd to restart
  sleep(10);
  
  return $stopped;
}

/**
 * Invokes an http request and returns the status code (or response body if 
 * $retBody is TRUE) on success, NULL on failure or FALSE if the response code 
 * is not within the $success range
 * @param string  $url the target url
 * @param string $method the http method
 * @param array $headers optional request headers to include (hash or array)
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
    foreach($headers as $header => $val) $curl .= sprintf(' -H "%s%s"', is_numeric($header) ? '' : $header . ':', $val); 
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
 * invokes 1 or more http requests using curl, waits until they are completed, 
 * and returns the associated results. Return value is a hash containing the 
 * following keys. Note: elements in urls, response and results may be arrays 
 * if any 'url' values in $requests are arrays
 *   urls:     ordered array of URLs
 *   request:  ordered array of request headers (lowercase keys)
 *   response: ordered array of response headers (lowercase keys)
 *   results:  ordered array of curl result values - includes the following:
 *             speed:              transfer rate (bytes/sec)
 *             time:               total time for the operation
 *             transfer:           total bytes transferred
 *             url:                actual URL used
 *   status:   ordered array of status codes
 *   lowest_status: the lowest status code returned
 *   highest_status: the highest status code returned
 *   body:     response body (only included when $retBody is TRUE)
 * returns NULL if any of the curl commands fail
 * @param array $requests array defining the http requests to invoke. Each 
 * element in this array is a hash with the following possible keys:
 *   method:  http method (default is GET)
 *   headers: hash or array defining http headers to append
 *   url:     the URL - may be an array to specify multiple (will use keep 
 *            alive)
 *   input:   optional command to pipe into the curl process as the body
 *   body:    optional string or file to pipe into the curl process as the 
 *            body. Alternatively, if this is a numeric value, a file will 
 *            be created containing random bytes corresponding with the 
 *            numeric value (created in $dir). This file will be deleted 
 *            when the PHP process terminates
 *   range:   optional request byte range
 * @param int $timeout the max allowed time in seconds for each request (i.e. 
 * --max-time). Default is 60. If < 1, no timeout will be set
 * @param string $dir optional directory where temporary files should be 
 * written to - /tmp if not specified
 * @param boolean $retBody if TRUE, the response body will be included in the 
 * return
 * @param boolean $insecure bypass certificate validation
 * @return array
 */
function ch_curl_mt($requests, $timeout=60, $dir='/tmp', $retBody=FALSE, $insecure=FALSE) {
  $fstart = microtime(TRUE);
  $script = sprintf('%s/%s', $dir, 'curl_script_' . rand());
  $fp = fopen($script, 'w');
  fwrite($fp, "#!/bin/sh\n");
  $ifiles = array();
  $ofiles = array();
  $bfiles = array();
  $result = array('urls' => array(), 'request' => array(), 'response' => array(), 'results' => array(), 'status' => array(), 'lowest_status' => 0, 'highest_status' => 0);
  if ($retBody) $result['body'] = array();
  foreach($requests as $i => $request) {
    if (isset($request['body'])) {
      if (file_exists($request['body'])) $file = $request['body'];
      // create random file
      else if (is_numeric($request['body']) && $request['body'] > 0) {
        $file = sprintf('%s/curl_input_%d', $dir, $request['body']);
        if (!file_exists($file)) {
          exec(sprintf('dd if=/dev/urandom of=%s bs=%d count=1 2>/dev/null', $file, $request['body']));
          if (file_exists($file) && filesize($file) != $request['body']) unlink($file);
          if (file_exists($file)) register_shutdown_function('unlink', $file);
          else $file = NULL;
        }
      }
      else {
        $ifiles[$i] = sprintf('%s/%s', $dir, 'curl_input_' . rand());
        $f = fopen($ifiles[$i], 'w');
        fwrite($f, $request['body']);
        fclose($f); 
        $file = $ifiles[$i];
      }
      $request['input'] = 'cat ' . $file;
      $request['headers']['content-length'] = filesize($file);
    }
    if (!isset($request['headers'])) $request['headers'] = array();
    $method = isset($request['method']) ? strtoupper($request['method']) : 'GET';
    $body = '/dev/null';
    if ($retBody) {
      $bfiles[$i] = sprintf('%s/%s', $dir, 'curl_body_' . rand());
      $body = $bfiles[$i];
    }
    $cmd = (isset($request['input']) ? $request['input'] . ' | curl --data-binary @-' : 'curl') . ($method == 'HEAD' ? ' -I' : '') . ' -s -D - -w "transfer=%{' . ($method == 'GET' ? 'size_download' : 'size_upload') . '}\nspeed=%{' . ($method == 'GET' ? 'speed_download' : 'speed_upload') . '}\ntime=%{time_total}\nurl=%{url_effective}\n" -X ' . $method . ($insecure ? ' --insecure' : '') . (is_numeric($timeout) && $timeout>0 ? ' --max-time ' . $timeout : '');
    $result['request'][$i] = $request['headers'];
    foreach($request['headers'] as $header => $val) $cmd .= sprintf(' -H "%s%s"', is_numeric($header) ? '' : $header . ':', $val);
    if (isset($request['range'])) $cmd .= ' -r ' . $request['range'];
    $result['urls'][$i] = $request['url'];
    if (!is_array($request['url'])) $request['url'] = array($request['url']);
    foreach($request['url'] as $url) $cmd .= sprintf(' -o %s', $body);
    foreach($request['url'] as $url) $cmd .= sprintf(' "%s"', $url);
    $ofiles[$i] = sprintf('%s/%s', $dir, 'curl_output_' . rand());
    fwrite($fp, sprintf("%s > %s 2>&1 &\n", $cmd, $ofiles[$i]));
  }
  fwrite($fp, "wait\n");
  fclose($fp);
  exec(sprintf('chmod 755 %s', $script));
  $start = microtime(TRUE);
  exec($script);
  $curl_time = microtime(TRUE) - $start;
  foreach(array_keys($requests) as $i) {
    foreach(file($ofiles[$i]) as $line) {
      // status code
      if (preg_match('/HTTP[\S]+\s+([0-9]+)\s/', $line, $m)) {
        $status = $m[1]*1;
        if (isset($result['status'][$i]) && !is_array($result['status'][$i])) $result['status'][$i] = array($result['status'][$i]);
        if (isset($result['status'][$i]) && is_array($result['status'][$i])) $result['status'][$i][] = $status;
        else $result['status'][$i] = $status;
        
        if ($result['lowest_status'] === 0 || $status < $result['lowest_status']) $result['lowest_status'] = $status;
        if ($status > $result['highest_status']) $result['highest_status'] = $status;
      }
      // response header
      else if (preg_match('/^([^:]+):\s+"?([^"]+)"?$/', trim($line), $m)) {
        $k = trim(strtolower($m[1]));
        if (isset($result['response'][$i][$k]) && !is_array($result['response'][$i][$k])) $result['response'][$i][$k] = array($result['response'][$i][$k]);
        if (isset($result['response'][$i][$k]) && is_array($result['response'][$i][$k])) $result['response'][$i][$k][] = $m[2];
        else $result['response'][$i][$k] = $m[2];
      }
      // result value
      else if (preg_match('/^([^=]+)=(.*)$/', trim($line), $m)) {
        $k = trim(strtolower($m[1]));
        if (isset($result['results'][$i][$k]) && !is_array($result['results'][$i][$k])) $result['results'][$i][$k] = array($result['results'][$i][$k]);
        if (isset($result['results'][$i][$k]) && is_array($result['results'][$i][$k])) $result['results'][$i][$k][] = $m[2];
        else $result['results'][$i][$k] = $m[2];
      }
      // body
      if (isset($bfiles[$i]) && file_exists($bfiles[$i])) {
        $result['body'][$i] = file_get_contents($bfiles[$i]);
        unlink($bfiles[$i]);
      }
    }
    unlink($ofiles[$i]);
  }
  foreach($ifiles as $ifile) unlink($ifile);
  unlink($script);
  if (!$result['highest_status']) {
    $result = NULL;
  }
  
  return $result;
}

/**
 * returns the country and state associated with $hostname if the geoiplookup
 * command is present and returns such information. return value is a hash with
 * 2 keys: country and state on success, NULL on failure
 * @param string $hostname hostname to lookup
 * @param boolean $verbose verbose print option
 * @return array
 */
function geoiplookup($hostname, $verbose=FALSE) {
  $info = NULL;
  $cmd = sprintf('geoiplookup %s 2>/dev/null', $hostname);
  if ($buffer = shell_exec($cmd)) {
    foreach(explode("\n", $buffer) as $line) {
      if (preg_match('/\s+([A-Z]{2}),\s+([A-Z]{2}),/', $line, $m)) {
        $info = array('country' => $m[1], 'state' => $m[2]);
        print_msg(sprintf('Determined state %s and country %s for hostname %s', $info['state'], $info['country'], $hostname), $verbose, __FILE__, __LINE__);
        break;
      }
      else if (preg_match('/\s+([A-Z]{2}),/', $line, $m)) {
        $info = array('country' => $m[1]);
        print_msg(sprintf('Determined country %s for hostname %s', $info['country'], $hostname), $verbose, __FILE__, __LINE__);
      }
    }
  }
  return $info;
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
 * returns the free space in MB on the volume containing $dir
 * @param string $dir the directory to return volume free space for
 * @return float
 */
function get_free_space($dir) {
  $free = NULL;
  if (is_dir($dir)) {
  	$stats = array();
  	$dfm = shell_exec('df -m');
  	foreach(explode("\n", $dfm) as $line) {
  		if (isset($last) && preg_match('/^\s+[0-9]+/', $line)) $line = $last . ' ' . $line;
  		if (preg_match('/(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)/', $line, $m) && is_numeric($m[2]) && is_numeric($m[4]) && is_numeric($m[4])) {
  			$stats[$m[6]] = array('filesystem' => $m[1], 'free' => $m[4], 'mount' => $m[6], 'size' => $m[3] + $m[4], 'used' => $m[3], 'used_perc' => $m[5]);
  		}
  		else if (substr($line, 0, 1) == '/') $last = $line;
  		else $last = NULL;
  	}
		$dmount = '/';
		foreach(array_keys($stats) as $mountpoint) {
			if (strpos($dir, $mountpoint) === 0 && strlen($mountpoint) > strlen($dmount)) $dmount = $mountpoint;
		}
		$stats = $stats[$dmount];
  	$free = $stats ? $stats['free'] : NULL;
  }
  return $free;
}

/**
 * returns the hostname from a string containing either a hostname or a URL
 * @param string $url the url to return the hostname from
 * @return string
 */
function get_hostname($url) {
  $pieces = explode('/', preg_match('/^https?:\/\/([^:^\/]+)[:\/]/', $url, $m) || preg_match('/^https?:\/\/([^:^\/]+)$/', $url, $m) ? $m[1] : $url);
  return $pieces[0];
}

/**
 * returns the arithmetic mean value from an array of points
 * @param array $points an array of numeric data points
 * @param int $round desired rounding precision, default is 4
 * @access public
 * @return float
 */
function get_mean($points, $round=4) {
	$stat = array_sum($points)/count($points);
	if ($round) $stat = round($stat, $round);
	return $stat;
}

/**
 * returns the median value from an array of points
 * @param array $points an array of numeric data points
 * @param int $round desired rounding precision, default is 4
 * @access public
 * @return float
 */
function get_median($points, $round=4) {
	sort($points);
	$nmedians = count($points);
	$nmedians2 = floor($nmedians/2);
  $stat = $nmedians % 2 ? $points[$nmedians2] : ($points[$nmedians2 - 1] + $points[$nmedians2])/2;
	if ($round) $stat = round($stat, $round);
	return $stat;
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
 * returns a percentile from $values
 * @param array $values array of numeric values get $percentile from
 * @param string $percentile the percentile to return (1-100) - for example, if
 * $percentile == 90, the top 90th percentile value is returned 
 * @param boolean $lowerIsBetter TRUE if a lower value is better
 * @return float
 */
function get_percentile($values, $percentile, $lowerIsBetter=FALSE) {
  $val = NULL;
	if (is_array($values) && $percentile >= 1 && $percentile < 100) {
		$lowerIsBetter ? rsort($values) : sort($values);
    $idx = round(count($values)*($percentile*.01)) - 1;
    if ($idx < 0) $idx = 0;
    else if ($idx >= count($values)) $idx = count($values) - 1;
    $val = $values[$idx];
	}
	return $val;
}

/**
 * returns all of the parameters prefixed with $prefix. To do so - both command
 * line arguments and values in env (prefixed with bm_param_$prefix) are 
 * searched
 * @param string $prefix the prefix to search for
 * @return array
 */
function get_prefixed_params($prefix) {
  $params = array();
	foreach(string_to_hash(shell_exec('env')) as $key => $val) {
		if (preg_match('/^bm_param_' . $prefix . '(.*)$/', $key, $m)) $params[$m[1]] = trim($val) ? trim($val) : TRUE;
	}
  foreach($_SERVER['argv'] as $arg) {
    if (preg_match('/^\-\-' . $prefix . '(.*)$/', $arg, $m)) {
      $pieces = explode('=', $m[1]);
      $params[trim(strtolower($pieces[0]))] = isset($pieces[1]) ? trim($pieces[1]) : TRUE;
    }
  }
  return $params;
}

/**
 * returns information about the cloud service associated with $hostname if 
 * known, NULL otherwise. the return value is a hash with the following keys:
 *   providerId
 *   serviceId
 *   region (optional)
 *   city (optional)
 *   state (optional)
 *   country (optional)
 * @param string $hostname hostname to lookup
 * @param boolean $verbose verbose print option
 * @return array
 */
function get_service_info($hostname, $verbose=FALSE) {
  $info = NULL;
  $cache = sprintf('/tmp/.ch_service_lookup_' . $hostname);
  if (!file_exists($cache) || !($response = json_decode(file_get_contents($cache), TRUE))) {
    $cmd = sprintf('curl "https://cloudharmony.com/api/identify/%s" > %s 2>/dev/null', $hostname, $cache);
    print_msg(sprintf('Attempting to lookup service information for hostname %s', $hostname), $verbose, __FILE__, __LINE__);
    exec($cmd);
  }
  else print_msg(sprintf('Got service information for hostname %s from cache file %s', $hostname, $cache), $verbose, __FILE__, __LINE__);
  
  if ($response = json_decode(file_get_contents($cache), TRUE)) {
    print_msg(sprintf('Successfully retrieved service information for hostname %s: providerId %s; serviceId %s; serviceType %s; region %s; city %s; state %s; country %s', $hostname, $response['providerId'], $response['serviceId'], $response['serviceType'], isset($response['region']) ? $response['region'] : '', isset($response['city']) ? $response['city'] : '', isset($response['state']) ? $response['state'] : '', isset($response['country']) ? $response['country'] : ''), $verbose, __FILE__, __LINE__);
    $info = $response;
  }
  else {
    print_msg(sprintf('Failed to lookup service information for hostname %s', $hostname), $verbose, __FILE__, __LINE__, TRUE);
    if (file_exists($cache)) unlink($cache);
  }
  return $info;
}

/**
 * returns valid cloud service type identifiers
 */
function get_service_types() {
  return array('cdn', 'dns', 'compute', 'storage', 'paas', 'dbaas');
}

/**
 * computes a standard deviation for the $points specified
 * @param array $points an array of numeric data points
 * @param int $type the type of standard deviation metric to return. One of 
 * the following numeric identifiers:
 *   1 = sample standard deviation (DEFAULT)
 *   2 = population standard deviation
 *   3 = relative sample standard deviation
 *   4 = relative population standard deviation
 *   5 = sample variance
 *   6 = population variance
 * @param int $round desired rounding precision, default is 6
 * @access public
 * @return float
 */
function get_std_dev($points, $type=1, $round=6) {
  if (count($points) == 1) return 0;
  
  $mean = array_sum($points)/count($points);
  $variance = 0.0;
  foreach ($points as $i) $variance += pow($i - $mean, 2);
  $variance /= ($type == 1 || $type == 3 || $type == 5 ? count($points) - 1 : count($points));
	if ($type == 5 || $type == 6) return $variance;
  $stddev = (float) sqrt($variance);
	if ($type > 2) $stddev = 100 * ($stddev/$mean);
	if ($round) $stddev = round($stddev, $round);
	return $stddev;
}

/**
 * returns the summation of squares of values in $points
 * @param array $points an array of numeric data points
 * @param int $round desired rounding precision, default is 4
 * @access public
 * @return float
 */
function get_sum_squares($points, $round=4) {
  $sum = 0;
  foreach($points as $point) $sum += ($point*$point);
  return round($sum, $round);
}

/**
 * returns system information. A hash containing the following keys:
 *   cpu        => CPU model information
 *   cpu_cache  => CPU cache size
 *   cpu_cores  => number of CPU cores
 *   cpu_speed  => CPU clock speed (MHz)
 *   hostname   => system hostname
 *   memory_gb  => system memory in gigabytes (rounded to whole number)
 *   memory_mb  => system memory in megabytes (rounded to whole number)
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
 * returns TRUE if the Linux kernel is 64 bit, FALSE otherwise
 * @return boolean
 */
function is_64bit() {
  global $is_64bit;
  if (!isset($is_64bit)) {
    $is_64bit = preg_match('/64/', shell_exec('uname -i')) || preg_match('/64/', shell_exec('uname -m')) || preg_match('/64/', shell_exec('uname -p'));
  }
  return $is_64bit;
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
      print_msg(sprintf('Added option %s=%s from config %s', $key, preg_match('/pswd/', $key) || preg_match('/key/', $key) ? '***' : $m[2], $config), isset($options['verbose']), __FILE__, __LINE__);
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
   if (!isset($options[$key]) && preg_match('/^meta_/', $key) && getenv('bm_' . str_replace('meta_', '', $key)) !== FALSE) $options[$key] = getenv('bm_' . str_replace('meta_', '', $key));
   if (!isset($options[$key]) && getenv("bm_param_${paramPrefix}${key}") !== FALSE) $options[$key] = getenv("bm_param_${paramPrefix}${key}");
   // convert booleans
   if (isset($options[$key]) && !strpos($long, ':')) $options[$key] = $options[$key] === '0' ? FALSE : TRUE;
   // set array parameters
   if (isset($arrayArgs) && is_array($arrayArgs)) {
     if (isset($options[$key]) && in_array($key, $arrayArgs) && !is_array($options[$key])) {
       $pieces = explode(preg_match('/\|/', $options[$key]) || preg_match('/,\s*[A-Z]{2}$/', $options[$key]) ? '|' : ',', $options[$key]);
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
 * this function parses key/value pairs in the string $blob. the return value
 * is a hash the corresponding key/value pairs. empty lines, or lines 
 * beginning with ; or # are ignored. for lines without an = character, the 
 * entire line will be the key and the value will be TRUE
 * @param string $blob the string to parse
 * @param boolean $ini if true, the parsing will be segmented where sections 
 * that begin with a bracket enclosed string define the segments. for example,
 * if the function encountered a line [globals], all of the key value pairs 
 * following that line will be placed into a 'global' sub-hash in the return 
 * value (until the next section is encountered)
 * @param array $excludeKeys array of regular expressions representing keys
 * that should not be included in the return hash
 * @param array $includeKeys array of regular expressions representing keys
 * that should be included in the return hash
 */
function string_to_hash($blob, $ini=FALSE, $excludeKeys=NULL, $includeKeys=NULL) {
	$hash = array();
	$iniSection = NULL;
	foreach(explode("\n", $blob) as $line) {
		$line = trim($line);
		$firstChar = $line ? substr($line, 0, 1) : NULL;
		if ($firstChar && $firstChar != ';' && $firstChar != '#') {
			// ini section
			if ($ini && preg_match('/^\[(.*)\]$/', $line, $m)) $iniSection = $m[1];
			else {
				if ($split = strpos($line, '=')) {
					$key = substr($line, 0, $split);
					$value = substr($line, $split + 1);
				}
				else {
					$key = $line;
					$value = TRUE;
				}
				if (is_array($excludeKeys)) {
					foreach($excludeKeys as $regex) if (preg_match($regex, $key)) $key = NULL;
				}
				if (is_array($includeKeys)) {
					$found = FALSE;
					foreach($includeKeys as $regex) if (preg_match($regex, $key)) $found = TRUE;
					if (!$found) $key = NULL;
				}
				if ($key) {
					if ($ini && $iniSection) {
						if (!isset($hash[$iniSection])) $hash[$iniSection] = array();
						$hash[$iniSection][$key] = $value;
					}
					else $hash[$key] = $value;
				}
			}
		}
	}
	return $hash;
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
 * removes a percentage of values from the bottom and top of $points
 * @param array $points an array of numeric data points
 * @param int $bottom percentage of values in $points to remove from the bottom
 * @param int $top percentage of values in $points to remove from the top
 * @access public
 * @return array
 */
function trim_points($points, $bottom=NULL, $top=NULL) {
  if (($bottom > 0 && $bottom < 40) || ($top > 0 && $top < 40)) {
    $numMetrics = count($points);
    if (($bottom > 0 && $bottom < 40) && ($discard = round($numMetrics*($bottom*0.01)))) $points = array_slice($points, $discard);
    if (($top > 0 && $top < 40) && ($discard = round($numMetrics*($top*0.01)))) $points = array_slice($points, 0, $discard*-1);
  }
  return $points; 
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
 *   color:    argument is a hex color
 *   min:      argument numeric and >= this value
 *   max:      argument numeric and <= this value
 *   option:   argument must be found in this value (array)
 *   required: argument is required
 *   url:      argument is a URL
 *   write:    argument is in the file system path and writeable
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
          case 'color':
            if ($val && !preg_match('/^#[a-zA-Z0-9]{6}$/', $val)) $err = sprintf('%s is not a valid hex color (e.g. #ffffff)', $val);
            break;
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
          case 'url':
            if ($val && !preg_match('/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i', $val)) $err = sprintf('%s is not a valid URL', $val);
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