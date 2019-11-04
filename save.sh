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
Usage: save.sh [args] [/path/to/results]

Saves UnixBench test results to CSV files, Google BigQuery, MySQL, PostgreSQL,
Librato Metrics or via HTTP callback. Test artifacts may also be saved to S3, 
Google Cloud Storage or Azure (API) compatible object storage

If the [/path/to/results] argument is not specified, 'pwd' will be assumed. 
This argument may be either the directory where test results have been written
to, or a directory containing numbered sub-directories [1..N] each containing 
results from a test iteration. The test iteration number is included in saved 
results (1 for non-numbered directories).

By default results are written to CSV files in 'pwd'. These arguments below may
be set to modify default CSV saving. These arguments may also be set in a 
line delimited config file located in ~/.ch_benchmark (e.g. db_host=localhost)

--db                        Save results to a database instead of CSV files.
                            The following argument values are supported:
                              bigquery   => save results to a Google BigQuery
                                            dataset
                              callback   => save results using an HTTP callback
                              librato    => save results to Librato Metrics 
                                            (see https://metrics.librato.com)
                              mysql      => save results to a MySQL db
                              postgresql => save results to a PostgreSQL db
                              store      => save results to the object storage
                                            repository as designated by the 
                                            --store arguments
                            For --db callback HTTP requests will be made to 
                            --db_host. A HEAD request is used for validation, 
                            and POST to submit results where CSV data is 
                            contained in the POST body (first row is a header
                            containing column names). A simple example in PHP
                            to retrieve the CSV results as a string is:
                            
                              if ($_SERVER['REQUEST_METHOD'] == 'POST') {
                                $csv = file_get_contents('php://input');
                              }
                            
--db_and_csv                If the --db argument is set, results will be saved 
                            to both CSV and --db specified
                            
--db_callback_header        If the --db argument is 'callback', this argument 
                            may specify one or more request headers to include 
                            in both the HEAD validation and POST CSV submission 
                            requests
                            
--db_host                   If the --db argument is set, this argument 
                            specifies the database server hostname. For 
                            BigQuery this parameter may be optionally used to 
                            designate a project (otherwise the default project 
                            is assumed). For 'callback', this is the full URL 
                            to post result to (if there is no http/https 
                            prefix, http will be assumed). A HEAD request to 
                            this URL is used for validation (should respond 
                            with 2XX). Callbacks are in the form of an HTTP 
                            POST where the POST body is CSV contents (1st row 
                            is header containing column names). Callback should 
                            respond with 2XX to be considered valid. The 
                            following request parameters added to the URL:
                              benchmark_id      => meta-id value in benchmark.ini
                              benchmark_version => meta-version value in 
                                                   benchmark.ini
                              db_name           => the --db_name argument value
                              table             => the table name (including 
                                                   --db_prefix)
                            This parameter is not used for Librato Metrics
                            
Librato Metrics Parameters: the following parameters are specific to 
--db librato only. More information about these parameters is available in the 
Librato API documentation: http://dev.librato.com/v1/metric-attributes 
http://dev.librato.com/v1/post/metrics and 
http://dev.librato.com/v1/put/metrics/:name

Each of these metrics may be repeated to submit to multiple gauges/counters. If
multiple are set, the ordering of each will be used to distinguish their 
properties. The only parameters that MUST be repeated are db_librato_name and
db_librato_value (or db_librato_count + db_librato_sum in place of value). If 
the others are not repeated, they will be applied to all submissions

--db_librato_aggregate      Enable service-side aggregation for the Librato 
                            Metrics gauge. Only applicable if the gauge does not 
                            already exist

--db_librato_color          Sets a default color to prefer when visually 
                            rendering the metric. Must be a seven character 
                            string that represents the hex code of the color 
                            e.g. #52D74C

--db_librato_count          Optional name of the column that designates the 
                            number of samples for each test performed (gauge
                            metrics only). Required if --db_librato_value is 
                            not set. Cannot be used if --db_librato_value is 
                            set

--db_librato_description    Text that used to explain what a gauge is measuring.
                            This parameter may also contain column name tokens 
                            that will be replaced by actual test values. The 
                            format for these is {column_name}

--db_librato_display_max    If a metric has a known theoretical maximum value, 
                            set this so the visualizations can provide 
                            perspective of the current values relative to the 
                            maximum value

--db_librato_display_min    If a metric has a known theoretical minimum value, 
                            set this so that visualizations can provide 
                            perspective of the current values relative to the 
                            minimum value

--db_librato_display_name   Name which will be used for the metric when viewing 
                            the Metrics website. This parameter may also 
                            contain column name tokens that will be replaced by 
                            actual test values. The format for these is 
                            {column_name}

--db_librato_display_units_long A string that identifies the unit of 
                            measurement e.g. Microseconds. Used in 
                            visualizations e.g. the Y-axis label on a graph. 
                            Alternatively, this can be the name of a column

--db_librato_display_units_short A terse (usually abbreviated) string that 
                            identifies the unit of measurement e.g. uS 
                            (Microseconds). Used in visualizations e.g. the 
                            tooltip for a point on a graph. Alternatively, this 
                            can be the name of a column

--db_librato_display_stacked A boolean value indicating whether or not multiple 
                            sources for a metric should be aggregated in a 
                            visualization (e.g. stacked graphs). By default 
                            counters have display_stacked enabled while gauges 
                            have it disabled

--db_librato_display_transform A linear formula that is run on each measurement 
                            prior to visualization. Useful for translating 
                            between different units (e.g. Fahrenheit -> Celsius) 
                            or scales (e.g. Microseconds -> Milliseconds). The 
                            formula may only contain: numeric characters, 
                            whitespace, parentheses, the letter x, and approved 
                            mathematical operators ('+', '-', '', '/'). The 
                            regular expression used is /^[\dxp()+-\/ ]+$/

--db_librato_max            If --db_librato_count was set, this parameter should 
                            designate the name of the column containing the 
                            largest individual measurement. Cannot be used if 
                            --db_librato_value is set

--db_librato_min            If --db_librato_count was set, this parameter should 
                            designate the name of the column containing the 
                            smallest individual measurement. Cannot be used if 
                            --db_librato_value is set

--db_librato_measure_time   Optional name of the column containing a parsable 
                            date string to associate with each test result 
                            (otherwise the time submitted is assumed)

--db_librato_name           The unique identifying name of the property being 
                            tracked. The metric name is used both to create new 
                            measurements and query existing measurements. Must 
                            be 255 or fewer characters, and may only consist of 
                            'A-Za-z0-9.:-_'. This parameter may also contain 
                            column name tokens that will be replaced by actual 
                            test values. The format for these is {column_name}. 
                            For example, the parameter
                            "{meta_compute_service_id}-{meta_region}" might be
                            replaced with "aws:ec2-us-east-1". The default 
                            name is the name of the benchmark name + version
                            + --db_prefix, --db_suffix (if specified). Tokens
                            in this string may also include {benchmark} and 
                            {version}

--db_librato_period         Number of seconds that is the standard reporting 
                            period of the metric. Setting the period enables 
                            Metrics to detect abnormal interruptions in 
                            reporting and aids in analytics. For gauge metrics 
                            that have service-side aggregation enabled, this 
                            option will define the period that aggregation 
                            occurs on

--db_librato_source         Optional string which describes the originating 
                            source of a measurement when that measurement is 
                            tracked across multiple members of a population. 
                            Examples: foo.bar.com, user-123, 77025.

                            Sources must be composed of 'A-Za-z0-9.:-_' and can 
                            be up to 255 characters in length. The word all is 
                            reserved and cannot be used as user source.

                            This parameter may also contain column name tokens 
                            that will be replaced by actual test values. The 
                            format for these is {column_name}. For example, the 
                            parameter "{meta_compute_service_id}-{meta_region}" 
                            might be replaced with "aws:ec2-us-east-1"

--db_librato_sum            If --db_librato_count was set, this MUST be set to 
                            the name of the column containing the summation of 
                            individual measurements. The combination of count 
                            and sum are used to calculate an average value for 
                            the recorded metric measurement. Cannot be used if 
                            --db_librato_value is set

--db_librato_summarize_function Determines how to calculate values when rolling 
                            up from raw values to higher resolution intervals. 
                            Must be one of: 'average', 'sum', 'count', 'min', 
                            'max'. If summarize_function is not set the 
                            behavior defaults to average

--db_librato_sum_squares    If --db_librato_count was set, this may be set to 
                            the name of the column containing the summation of 
                            the squared individual measurements. If set, a 
                            standard deviation can be calculated for the r
                            ecorded metric measurement. Cannot be used if 
                            --db_librato_value is set

--db_librato_type           Type of metric to create (gauge or counter)

--db_librato_value          the name of the column containing the value metric. 
                            This must be a numeric value. Either this or a 
                            combination of both --db_librato_count and 
                            --db_librato_sum are REQUIRED
                                                   
--db_mysql_engine           An optional explicit storage engine to use when 
                            creating MySQL tables (i.e. if a table does not 
                            already exist). If not set, the default storage 
                            engine will be used
                            
--db_name                   Name of the database where tables should be created 
                            and results stored. For Google BigQuery this should 
                            be the dataset name. This parameter is not used for 
                            Librato Metrics

--db_port                   If the --db argument is set, this argument 
                            specifies the database server port. Defaults is the
                            corresponding database server defaults (3306 for 
                            MySQL, 5432 for PostgreSQL, 80 for HTTP callbacks 
                            and 443 for HTTP callbacks). Not applicable to 
                            Google BigQuery. This parameter is not used for 
                            Librato Metrics

--db_pswd                   If the --db argument is set, this argument 
                            specifies the database server password. Default is 
                            ''. Not applicable to Google BigQuery. HTTP AUTH
                            password for --db callback, or API token for 
                            Librato Metrics

--db_prefix                 If the --db argument is set, this argument 
                            specifies an optional prefix to use for the results
                            table. Default table name is the benchmark name 
                            with no prefix

--db_store_path             If --db=store, this (required) argument specifies the 
                            object storage path under which results CSV files 
                            should be saved. If this path ends with .csv, then 
                            the default table name will be ignored. Otherwise, the 
                            CSV file will be saved in this path with the file name
                            equal to [table name].csv The following dynamic values 
                            may be included:
                              {table}         => the default table name for the CSV 
                                                 file being uploaded
                              {date[_format]} => a date string (optionally 
                                                 formatted per [format] - see
                                                 http://php.net/manual/en/function.date.php
                                                 for valid format options - 
                                                 default format is Y-m-d)
                              {benchmark}     => benchmark name (block-storage)
                                                 (meta-id value in benchmark.ini)
                              {version}       => benchmark version (e.g. 1_0)
                                                 (meta-version value in benchmark.ini)
                              {iteration}     => iteration number
                              {hostname}      => the compute instance hostname
                              {meta_*}        => any of the meta_* runtime 
                                                 parameters. If a meta_* value
                                                 is designated but was not set, 
                                                 at runtime, it will be removed 
                                                 from the prefix (including a 
                                                 trailing /). Spaces are 
                                                 replaced with _
                              {rand}          => a random number. Random numbers 
                                                 are the same for each unique
                                                 combination of other prefix 
                                                 values

--db_suffix                 If the --db argument is set, this argument 
                            specifies an optional suffix to use for the results
                            table. Default table suffix is the benchmark 
                            version with periods replaced with underscores

--db_user                   If the --db argument is set, this argument 
                            specifies the database server username. Not 
                            applicable to Google BigQuery. HTTP AUTH user for 
                            --db callbacks, user name for Librato Metrics. For 
                            MySQL user needs create table, drop table, and 
                            load data infile permissions. For PostgreSQL, the 
                            permissions are the same except that the user needs 
                            copy permissions in place of MySQL load data infile
                            
--iteration                 Explicit iteration number for test results - 
                            otherwise 1 will be assumed unless results are in 
                            numbered sub-directories
                            
--nostore_html              Do not store HTML UnixBench reports

--nostore_log               Do not store UnixBench log

--nostore_rrd               Do not store collectd RRD files

--nostore_text              Do not store text UnixBench reports

--output                    The output directory to use for writing CSV files.
                            If not specified, the current working directory 
                            will be used
                            
--remove                    One or more columns to remove from the saved output
                            (CSV files or tables). This argument may be 
                            repeated for multiple columns. To define multiple 
                            values in ~/.ch_benchmark, use one line and comma
                            separated values. Wildcards are supported
                            
--store                     Save result artifacts to object storage. The 
                            following argument values are supported:
                              azure     => save artifacts to an Azure Blob
                                           Storage container
                              google    => save artifacts to a Google Cloud 
                                           Storage bucket
                              s3        => save artifacts to an S3 
                                           compatible bucket
                            When used, URLs to the corresponding result 
                            artifacts will be included in the CSV/db 
                            results
                                           
--store_container           If the --store argument is set, this argument 
                            specifies the name of the container/bucket 
                            where results should be stored. This argument is 
                            REQUIRED when --store is set
                            
--store_endpoint            Overrides default API endpoints for storage 
                            platforms. If specified, the endpoint should be 
                            compatible with the designated --store API

--store_insecure            Use an insecure endpoint (http) instead of secure 
                            (https)
                            
--store_key                 If the --store argument is set, this argument 
                            specifies the API key or user for the corresponding
                            endpoint. This argument is REQUIRED when --store is 
                            set
                            
--store_prefix              If the --store argument is set, this argument 
                            specifies a container directory prefix (to avoid 
                            overwriting other results). The following dynamic 
                            values may be included:
                              {date[_format]} => a date string (optionally 
                                                 formatted per [format] - see
                                                 http://php.net/manual/en/function.date.php
                                                 for valid format options - 
                                                 default format is Y-m-d)
                              {benchmark}     => benchmark name (unixbench)
                                                 (meta-id value in benchmark.ini)
                              {version}       => benchmark version (e.g. 5_1_3)
                                                 (meta-version value in benchmark.ini)
                              {iteration}     => iteration number
                              {hostname}      => the compute instance hostname
                              {meta_*}        => any of the meta_* runtime 
                                                 parameters. If a meta_* value
                                                 is designated but was not set, 
                                                 at runtime, it will be removed 
                                                 from the prefix (including a 
                                                 trailing /). Spaces are 
                                                 replaced with _
                              {rand}          => a random number. Random numbers 
                                                 are the same for each unique
                                                 combination of other prefix 
                                                 values

                            Multiple dynamic values may be specified, each 
                            separated by a | character (e.g. {meta_compute_service_id|rand})
                            in which case the first dynamic value present will 
                            be used. All substitions are lowercase
                            
                            The default prefix is: 
                            {benchmark}_{version}/{meta_compute_service_id|meta_provider_id}/{meta_instance_id}/{meta_storage_config}/{meta_region}/{date|meta_test_id}/{meta_resource_id|hostname}/{meta_run_id|rand}-{iteration}
                            
--store_public              If the --store argument is set, this argument 
                            will result in stored artifact URLs being publicly 
                            readable. If --store=azure, this parameter is 
                            ignored because access rights are set at the 
                            container level
                            
--store_region              If the --store argument is set, this argument 
                            optionally specifies the service region. When an 
                            explicit --store_endpoint argument is specified, 
                            this argument is ignored. Otherwise, it is used to
                            determine the correct endpoint based on the --store
                            value specified. Valid regions for each --store 
                            value are:
                              azure     => not used (region is tied to the 
                                           account credentials)
                              google    => not used (region is designated at time 
                                           of bucket creation)
                              s3        => required if --store_container is not 
                                           in the 'us-east-1' region 
                                           regin identifiers documented here:
                                           http://docs.aws.amazon.com/general/latest/gr/rande.html#s3_region
                            
--store_secret              If the --store argument is set, this argument 
                            specifies the API secret or password for the 
                            corresponding endpoint. This argument is REQUIRED 
                            when --store is set
                            
--verbose/-v                Show verbose output - warning: this may produce a 
                            lot of output
                            
                            
DEPENDENCIES
Saving artifacts using the --db and --store flags has the following 
dependencies:

  --db bigquery  'bq'    => part of Google Cloud SDK see 
                            https://developers.google.com/cloud/sdk/ for 
                            detailed install instructions. 'bq' should be
                            pre-authenticated for the desired project where 
                            the dataset exists and tables should be created
                            
  --db callback  'curl'  => included with 'curl' package
  
  --db mysql     'mysql' => included with 'mysql' package
  
  --db postgresl 'psql'  => included with 'postgresql' package
  
  --save         'curl'  => included with 'curl' package


USAGE
# save results to CSV files
./save.sh

# save results from 5 iterations text example above
./save.sh ~/unixbench-testing

# save results to a PostgreSQL database
./save --db postgresql --db_user dbuser --db_pswd dbpass --db_host db.mydomain.com --db_name benchmarks

# save results to BigQuery and artifact (TRIAD gnuplot PNG image) to S3
./save --db bigquery --db_name benchmark_dataset --store s3 --store_key THISIH5TPISAEZIJFAKE --store_secret thisNoat1VCITCGggisOaJl3pxKmGu2HMKxxfake --store_container benchmarks1234

# save results to Librato Metrics using the median metric and custom name/source
./save.sh --db librato --db_user [user] --db_pswd [API key] -v --db_librato_aggregate --db_librato_value metric

# save results to Librato Metrics using count + sum and custom name/source and other attributes
./save.sh --db librato --db_user [user] --db_pswd [API key] -v --db_librato_aggregate --db_librato_count samples --db_librato_display_units_short ms --db_librato_max metric_max --db_librato_min metric_min --db_librato_measure_time test_stopped --db_librato_name "{benchmark}-{test}" --db_librato_period 300 --db_librato_source "{meta_geo_region}" --db_librato_sum metric_sum --db_librato_sum_squares metric_sum_squares


EXIT CODES:
  0 saving of results successful
  1 saving of results failed

EOF
  exit
elif [ -f "/usr/bin/php" ]; then
  $( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/lib/save.php $@
  exit $?
else
  echo "Error: missing dependency php-cli (/usr/bin/php)"
  exit 1
fi
