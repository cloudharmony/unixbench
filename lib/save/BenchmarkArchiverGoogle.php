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
 * 
 */
class BenchmarkArchiverGoogle extends BenchmarkArchiver {
  
  /**
   * date format for signatures
   */
  const SIGNATURE_DATE_FORMAT = 'D, d M Y H:i:s O';
  
  /**
   * default endpoint for GCS
   */
  const DEFAULT_GOOGLE_ENDPOINT = 'storage.googleapis.com';
  
  /**
   * Constructor is protected to implement the singleton pattern using 
   * the BenchmarkArchiver::getArchiver static method
   * @param array $options archiver command line arguments
   */
  protected function BenchmarkArchiverGoogle($options) {}
    
  /**
   * returns the API endpoint to use based on the runtime arguments
   */
  protected function getEndpoint() {
    $endpoint = isset($this->options['store_endpoint']) ? $this->options['store_endpoint'] : BenchmarkArchiverGoogle::DEFAULT_GOOGLE_ENDPOINT;
    if (!preg_match('/^http/', $endpoint)) $endpoint = isset($this->options['store_insecure']) && $this->options['store_insecure'] ? 'http://' : 'https://' . $endpoint;

    return $endpoint;
  }

  /**
   * returns the headers to use for a request including auth signature
   * @param string $method the http method the headers are for
   * @param array $headers optional array of existing headers
   * @param string $object optional object to create the signature for
   * @param array $params optional URL parameters
   * @return array
   */
  private function getHeaders($method='HEAD', $headers=NULL, $object=NULL, $params=NULL) {
    if (!is_array($headers)) $headers = array();
    $headers['date'] = gmdate(self::SIGNATURE_DATE_FORMAT);
    $headers['Authorization'] = $this->sign($method, $headers, $object, $params);
    return $headers;
  }

  /**
   * Returns the GCS API URL to use for the specified $container and $object
   * @param string $container the container to return the URL for
   * @param string $object optional object to include in the URL
   * @param array $params optional URL parameters
   * @return string
   */
  private function getUrl($object=NULL, $params=NULL) {
    $url = $this->getEndpoint();
    $url = sprintf('%s/%s%s', $url, $this->options['store_container'], $object ? '/' . $object : '');
    if (is_array($params)) {
      foreach(array_keys($params) as $i => $param) {
        $url .= ($i ? '&' : '?') . $param . ($params[$param] ? '=' . $params[$param] : '');
      }
    }
    return $url;
  }

  /**
   * saves a file and returns the URL. returns NULL on error
   * @param string $file local path to the file that should be saved
   * @return string
   */
  public function save($file) {
    $object = $this->getObjectUri($file);
    $url = $this->getUrl($object);
    $headers = array();
    $headers['Content-Length'] = filesize($file);
    $headers['Content-Type'] = get_mime_type($file);
    if (isset($this->options['store_public'])) $headers['x-goog-acl'] = 'public-read';
    $headers = $this->getHeaders('PUT', $headers, $object);
    $curl = ch_curl($url, 'PUT', $headers, $file, NULL, 200);
    if ($curl === 200) print_msg(sprintf('Upload of file %s to GCS successful. URL is %s (%s)', $file, $url, isset($this->options['store_public']) ? 'URL is publicly accessible' : 'URL is private'), isset($this->options['verbose']), __FILE__, __LINE__);
    else {
      $url = NULL;
      print_msg(sprintf('Upload of file %s to GCS failed', $file), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    return $url;
  }

  /**
   * returns an authorization signature for the parameters specified
   * @param string $method the http method
   * @param array $headers http headers
   * @param string $container optional container
   * @param string $object optional object to create the signature for
   * @return string
   */
  private function sign($method, $headers, $object=NULL) {
    // add goog headers to signature
    $goog_headers = array();
    foreach($headers as $key => $val) {
      if (preg_match('/^x-goog/', $key)) $goog_headers[strtolower($key)] = $val;
    }
    ksort($goog_headers);
    $goog_string = '';
    foreach($goog_headers as $key => $val) $goog_string .= $key . ':' . trim($val) . "\n";

    $uri = $this->options['store_container'];
    if ($object) $uri .= '/' . $object;
    $string = sprintf("%s\n\n%s\n%s\n%s/%s", 
                      strtoupper($method),
                      isset($headers['Content-Type']) ? $headers['Content-Type'] : '',
                      $headers['date'], 
                      $goog_string,
                      $uri);
    print_msg(sprintf('Signing string %s', str_replace("\n", '\n', $string)), isset($this->options['verbose']), __FILE__, __LINE__);
		$signature = base64_encode(extension_loaded('hash') ? hash_hmac('sha1', $string, $this->options['store_secret'], TRUE) : pack('H*', sha1((str_pad($this->options['store_secret'], 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) . pack('H*', sha1((str_pad($this->options['store_secret'], 64, chr(0x00)) ^ (str_repeat(chr(0x36), 64))) . $string)))));
		return sprintf('GOOG1 %s:%s', $this->options['store_key'], $signature);
  }

  /**
   * validation method - must be implemented by subclasses. Returns TRUE if 
   * archiver options are valid, FALSE otherwise
   * @return boolean
   */
  protected function validate() {
    if ($valid = isset($this->options['store_key']) && isset($this->options['store_secret']) && isset($this->options['store_container'])) {
      $valid = FALSE;
      print_msg(sprintf('Validating GCS connection using --store_key %s, --store_container %s', $this->options['store_key'], $this->options['store_container']), isset($this->options['verbose']), __FILE__, __LINE__); 
      $curl = ch_curl($this->getUrl(), 'HEAD', $this->getHeaders(), NULL, NULL, '200,404');
      if ($curl === 200) {
        $valid = TRUE;
        print_msg(sprintf('GCS authentication and bucket validation successful'), __FILE__, __LINE__);
      }
      else if ($curl === 404) print_msg(sprintf('GCS authentication successful but bucket %s does not exist', $this->options['store_container']), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      else print_msg(sprintf('GCS authentication failed'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    else print_msg(sprintf('--store_key, --store_secret and --store_container are required'), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);

    return $valid;
  }
  
}
?>
