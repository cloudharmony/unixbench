#!/bin/bash
# Copyright 2014 CloudHarmony Inc.
# 
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
# 
#     http://www.apache.org/licenses/LICENSE-2.0
# 
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.


if [ "$1" == "-h" ] || [ "$1" == "--help" ] ; then
  cat << EOF
Usage: run.sh [options]

This repository contains the setup and runtime configurations for the UnixBench 
benchmark. UnixBench is the original BYTE UNIX benchmark suite, updated and 
revised by many people over the years. The purpose of UnixBench is to provide a 
basic indicator of the performance of a Unix-like system; hence, multiple tests 
are used to test various aspects of the system performance. These test 
results are then compared to the scores from a baseline system to produce an 
index value, which is generally easier to handle than the raw scores. The 
entire set of index values is then combined to make an overall index for the 
system. For more information, review the project website:

https://code.google.com/p/byte-unixbench/

You may download the source there or from our S3 bucket here:

https://s3.amazonaws.com/cloudbench/software/UnixBench5.1.3.tgz

To install UnixBench:
tar zxf UnixBench5.1.3.tgz
cd UnixBench
make all


TESTING PARAMETERS
Test behavior is fixed, but you may specify the following optional meta 
attributes and installation attributes. The meta attributes will be included in 
the results (see save.sh). UnixBench should be installed and compiled before 
running this benchmark. Review the 'unixbench_dir' parameter comments below
for instructions.

--collectd_rrd              If set, collectd rrd stats will be captured from 
                            --collectd_rrd_dir. To do so, when testing starts,
                            existing directories in --collectd_rrd_dir will 
                            be renamed to .bak, and upon test completion 
                            any directories not ending in .bak will be zipped
                            and saved along with other test artifacts (as 
                            collectd-rrd.zip). User MUST have sudo privileges
                            to use this option
                            
--collectd_rrd_dir          Location where collectd rrd files are stored - 
                            default is /var/lib/collectd/rrd

--meta_compute_service      The name of the compute service this test pertains
                            to. May also be specified using the environment 
                            variable bm_compute_service
                            
--meta_compute_service_id   The id of the compute service this test pertains
                            to. Added to saved results. May also be specified 
                            using the environment variable bm_compute_service_id
                            
--meta_cpu                  CPU descriptor - if not specified, it will be set 
                            using the 'model name' attribute in /proc/cpuinfo
                            
--meta_instance_id          The compute service instance type this test pertains 
                            to (e.g. c3.xlarge). May also be specified using 
                            the environment variable bm_instance_id
                            
--meta_memory               Memory descriptor - if not specified, the system
                            memory size will be used
                            
--meta_os                   Operating system descriptor - if not specified, 
                            it will be taken from the first line of /etc/issue
                            
--meta_provider             The name of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider
                            
--meta_provider_id          The id of the cloud provider this test pertains
                            to. May also be specified using the environment 
                            variable bm_provider_id
                            
--meta_region               The compute service region this test pertains to. 
                            May also be specified using the environment 
                            variable bm_region
                            
--meta_resource_id          An optional benchmark resource identifiers. May 
                            also be specified using the environment variable 
                            bm_resource_id
                            
--meta_run_id               An optional benchmark run identifiers. May also be 
                            specified using the environment variable bm_run_id
                            
--meta_storage_config       Storage configuration descriptor. May also be 
                            specified using the environment variable 
                            bm_storage_config
                            
--meta_test_id              Identifier for the test. May also be specified 
                            using the environment variable bm_test_id
                            
--multicore_copies          Explicit number of copies to test with for 
                            multi-core testing. The default is # of CPU cores.
                            This parameter should be a minimum of 2 and maximum 
                            of 64
                            
--nomultithread             Don't run multi-threaded tests
                            
--nosinglethread            Don't run single threaded tests
                            
--output                    The output directory to use for writing test data 
                            (results log and unixbench text output). If not 
                            specified, the current working directory will be 
                            used
                            
--test                      You may optionally specify individual UnixBench 
                            tests to run. The following tests are included 
                            with UnixBench. Tests run if this parameter is not
                            set have an *:
                            
                              dhry2reg*
                              whetstone-double*
                              syscall*
                              pipe*
                              context1
                              spawn*
                              execl*
                              fstime-w
                              fstime-r
                              fstime*
                              fsbuffer-w
                              fsbuffer-r
                              fsbuffer*
                              fsdisk-w
                              fsdisk-r
                              fsdisk*
                              shell1*
                              shell8*
                              shell16
                              short
                              int
                              long
                              float
                              double
                              arithoh
                              C
                              dc
                              hanoi
                              grep
                              sysexec
                            
                            This parameter may be repeated to designate 
                            multiple tests (e.g. --test int --test long)
                            NOTE: the 'context1' test may cause pipe broken
                            errors. It is excluded by default for this reason.
                            Graphics tests are also excluded from the list.
                            
--verbose                   Show verbose output

--unixbench_dir             Directory where UnixBench is installed. If not 
                            specified, the benchmark run script will look up 
                            the directory tree from both pwd and --output for 
                            presence of a 'UnixBench' directory with an 
                            executable 'Run' script in it
                            
                            
DEPENDENCIES
This benchmark has the following dependencies:

perl        The UnixBench run script is perl based. NOTE: UnixBench makes use
            of perl-Time-HiRes (HiRes.pm). This package may need to be 
            installed separately from perl
php-cli     Test automation scripts (/usr/bin/php)
zip         Used to compress test artifacts


USAGE
# run 1 test iteration with some metadata
./run.sh --meta_compute_service_id aws:ec2 --meta_instance_id c3.xlarge --meta_region us-east-1 --meta_test_id aws-0914

# run with UnixBench installed in /usr/local/UnixBench
./run.sh --unixbench_dir /usr/local/UnixBench

# run 10 test iterations using a specific output directory
for i in {1..10}; do mkdir -p ~/unixbench-testing/$i; ./run.sh --output ~/unixbench-testing/$i; done


EXIT CODES:
  0 test successful
  1 test failed

EOF
  exit
elif [ -f "/usr/bin/php" ] && [ -f "/usr/bin/perl" ]; then
  $( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/lib/run.php $@
  exit $?
else
  echo "Error: missing dependency php-cli (/usr/bin/php), perl (/usr/bin/perl)"
  exit 1
fi
