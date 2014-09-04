#!/usr/bin/php -q
<?php
/**
 * parses UnixBench output and produces relevant metrics
 */
$status = 1;
if (isset($argv[1]) && file_exists($argv[1]) && ($buffer = file_get_contents($argv[1])) && 
    preg_match_all('/Index Score.*\s+([0-9\.]+)\s/msU', $buffer, $m)) {
  printf("score=%s\n", trim($m[1][0]));
  // multicore
  if (count($m[1]) > 1 && preg_match('/running\s+([0-9]+)\s+parallel copies/msU', $buffer)) {
    printf("multicore_score=%s\n", trim($m[1][1]));
  }
}
exit($status);
?>