<?php

/*

   Parses data collected during the benchmark and produces an SQL script with
   the data and a bunch of images (latency and tps of the pgbench part).

   Expected cmdline parameters are

      $ php collect.php INPUT_DIRECTORY OUTPUT_IMAGES_DIRECTORY

   so if the benchmark data are in 'bench-results' directory, and you want to
   get images into the 'bench-images' directory, do this

      $ php collect.php ./bench-results ./bench-images

   The processing may take a lot of time (and CPU/memory), because it has to
   parse all the pgbench results and build all the images.

   This requires a working gnuplot installation (to build the images).

*/

	date_default_timezone_set('Europe/Prague');

	if (count($argv) < 3) {
		echo "ERROR: not enough parameters\n";
		exit();
	}

	$input  = $argv[1];
	$output = $argv[2];

	if (! file_exists($input)) {
		echo "ERROR: input directory '$input' does not exist\n";
		exit();
	} else if (! is_dir($input)) {
		echo "ERROR: is not a directory: '$input'\n";
		exit();
	} else if (file_exists($output)) {
		echo "ERROR: output file '$output' already exists\n";
		exit();
	}

	/* query timeout limit */
	define('QUERY_TIMEOUT', 300);

	echo "input directory: $input\n";
	echo "output file: $output\n";

	// load the results from directory
	$data = load_tpch($input);

	// add information from the shell log
	parse_log($data, "$input/bench.log");

	// write the results into CSV
	print_tpch_csv($data, "$output");

	/* FUNCTIONS */

	/* loads postgresql stats from the directory (expects stats-before/stats-after log files) */
	/* param $dir - directory with benchmark results */
	function load_stats($dir) {

		$diff = array();

		$before = file("$dir/stats-before.log");
		$after = file("$dir/stats-after.log");

		// pg_stat_bgwriter

		$matches_before = array();
		preg_match('/^\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)/', $before[2], $matches_before);

		$matches_after = array();
		preg_match('/^\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)/', $after[2], $matches_after);

		$diff['checkpoints_timed'] = $matches_after[1] - $matches_before[1];
		$diff['checkpoints_req'] = $matches_after[2] - $matches_before[2];
		$diff['buffers_checkpoint'] = $matches_after[3] - $matches_before[3];
		$diff['buffers_clean'] = $matches_after[4] - $matches_before[4];
		$diff['maxwritten_clean'] = $matches_after[5] - $matches_before[5];
		$diff['buffers_backend'] = $matches_after[6] - $matches_before[6];
		$diff['buffers_alloc'] = $matches_after[7] - $matches_before[7];

		// pg_stat_database

		$matches_before = array();
		preg_match('/^\s+([0-9]+)\s\|\s+([a-zA-Z_\-]+)\s+\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)/', $before[7], $matches_before);

		$matches_after = array();
		preg_match('/^\s+([0-9]+)\s\|\s+([a-zA-Z_\-]+)\s+\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)\s\|\s+([0-9]+)/', $after[7], $matches_after);

		$diff['xact_commit'] = $matches_after[4] - $matches_before[4];
		$diff['xact_rollback'] = $matches_after[5] - $matches_before[5];
		$diff['blks_read'] = $matches_after[6] - $matches_before[6];
		$diff['blks_hit'] = $matches_after[7] - $matches_before[7];
		$diff['tup_returned'] = $matches_after[8] - $matches_before[8];
		$diff['tup_fetched'] = $matches_after[9] - $matches_before[9];
		$diff['tup_inserted'] = $matches_after[10] - $matches_before[10];
		$diff['tup_updated'] = $matches_after[11] - $matches_before[11];
		$diff['tup_deleted'] = $matches_after[12] - $matches_before[12];

		$diff['hit_ratio'] = round(floatval(100*$diff['blks_hit']) / ($diff['blks_hit'] + $diff['blks_read']),1);

		return $diff;

	}

	/* loads query stats from the directory (expects results.log file) */
	/* param $dir - directory with benchmark results */
	function load_queries($dir) {

		$queries = array();
		$results = file("$dir/results.log");

		foreach ($results AS $line) {

			if (substr_count($line, '=') > 0) {

				$tmp = explode('=', $line);
				$qn = intval($tmp[0]); /* query id */

				$queries[$qn]['duration'] = floatval($tmp[1]);
				$queries[$qn]['hash'] = get_plan_hash("$dir/explain/$qn");

			}
		}

		return $queries;

	}

	/* loads tpc-h results from the directory */
	/* param $dir - directory with benchmark results */
	function load_tpch($dir) {
		$out = array();
		$out['stats'] = load_stats($dir);
		$out['queries'] = load_queries($dir);
		return $out;
	}

	function score_eval($current, $min, $max = QUERY_TIMEOUT) {

		// cancelled queries should get 0
		if ($current >= $max) {
			return 0;
		}

		// otherwise use the inverse (always "current > min")
		return $min/$current;

	}

	function time_eval($current, $max = QUERY_TIMEOUT) {

		return min(floatval($current), $max);

	}

	/* loads bench.log and appends it to the results */
	/* param $data - benchmark data */
	/* param $logfile - logfile to read data from */
	function parse_log(&$data, $logfile) {

		$log = file($logfile);

		$t = 0;

		$ddir = '';
		$idir = '';

		for ($i = 0; $i < count($log); $i++) {
			$matches = array();
			if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] : preparing TPC-H database/', $log[$i], $matches)) {
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] :   loading data/', $log[$i], $matches)) {
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] :   creating primary keys/', $log[$i], $matches)) {
				$data['stats']['load'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] :   creating foreign keys/', $log[$i], $matches)) {
				$data['stats']['pkeys'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] :   creating indexes/', $log[$i], $matches)) {
				$data['stats']['fkeys'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] :   analyzing/', $log[$i], $matches)) {
				$data['stats']['indexes'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] : running TPC-H benchmark/', $log[$i], $matches)) {
				$data['stats']['analyze'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			} else if (preg_match('/[0-9][0-9]:[0-9][0-9]:[0-9][0-9] \[([0-9]+)\] : finished TPC-H benchmark/', $log[$i], $matches)) {
				$data['stats']['benchmark'] = intval($matches[1]) - $t;
				$t = intval($matches[1]);
			}

		}

	}

	/* writes benchmark results to the CSV file */
	/* param $data - benchmark data */
	/* param $logfile - logfile to read data from */
	function print_tpch_csv($data, $outfile) {

		$fd = fopen($outfile, "a");

		fwrite($fd, 'tpch_load;tpch_pkeys;tpch_fkeys;tpch_indexes;tpch_analyze;tpch_total;' .
					'query_1;query_2;query_3;query_4;query_5;query_6;query_7;query_8;query_9;query_10;query_11;query_12;query_13;' .
					'query_14;query_15;query_16;query_17;query_18;query_19;query_20;query_21;query_22;'.
					'query_1_hash;query_2_hash;query_3_hash;query_4_hash;query_5_hash;query_6_hash;query_7_hash;query_8_hash;query_9_hash;' .
					'query_10_hash;query_11_hash;query_12_hash;query_13_hash;query_14_hash;query_15_hash;query_16_hash;query_17_hash;' .
					'query_18_hash;query_19_hash;query_20_hash;query_21_hash;query_22_hash;db_cache_hit_ratio' . "\n");

		$line = '%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;' .
					'%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;' .
					'%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;%.2f;'.
					'%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%s;%.2f';

		fwrite($fd, sprintf($line,

					// tpc-h
					$data['stats']['load'],
					$data['stats']['pkeys'],
					$data['stats']['fkeys'],
					$data['stats']['indexes'],
					$data['stats']['analyze'],
					$data['stats']['benchmark'],

					($data['queries'][1]['duration'] < QUERY_TIMEOUT) ? $data['queries'][1]['duration'] : null,
					($data['queries'][2]['duration'] < QUERY_TIMEOUT) ? $data['queries'][2]['duration'] : null,
					($data['queries'][3]['duration'] < QUERY_TIMEOUT) ? $data['queries'][3]['duration'] : null,
					($data['queries'][4]['duration'] < QUERY_TIMEOUT) ? $data['queries'][4]['duration'] : null,
					($data['queries'][5]['duration'] < QUERY_TIMEOUT) ? $data['queries'][5]['duration'] : null,
					($data['queries'][6]['duration'] < QUERY_TIMEOUT) ? $data['queries'][6]['duration'] : null,
					($data['queries'][7]['duration'] < QUERY_TIMEOUT) ? $data['queries'][7]['duration'] : null,
					($data['queries'][8]['duration'] < QUERY_TIMEOUT) ? $data['queries'][8]['duration'] : null,
					($data['queries'][9]['duration'] < QUERY_TIMEOUT) ? $data['queries'][9]['duration'] : null,
					($data['queries'][10]['duration'] < QUERY_TIMEOUT) ? $data['queries'][10]['duration'] : null,
					($data['queries'][11]['duration'] < QUERY_TIMEOUT) ? $data['queries'][11]['duration'] : null,
					($data['queries'][12]['duration'] < QUERY_TIMEOUT) ? $data['queries'][12]['duration'] : null,
					($data['queries'][13]['duration'] < QUERY_TIMEOUT) ? $data['queries'][13]['duration'] : null,
					($data['queries'][14]['duration'] < QUERY_TIMEOUT) ? $data['queries'][14]['duration'] : null,
					($data['queries'][15]['duration'] < QUERY_TIMEOUT) ? $data['queries'][15]['duration'] : null,
					($data['queries'][16]['duration'] < QUERY_TIMEOUT) ? $data['queries'][16]['duration'] : null,
					($data['queries'][17]['duration'] < QUERY_TIMEOUT) ? $data['queries'][17]['duration'] : null,
					($data['queries'][18]['duration'] < QUERY_TIMEOUT) ? $data['queries'][18]['duration'] : null,
					($data['queries'][19]['duration'] < QUERY_TIMEOUT) ? $data['queries'][19]['duration'] : null,
					($data['queries'][20]['duration'] < QUERY_TIMEOUT) ? $data['queries'][20]['duration'] : null,
					($data['queries'][21]['duration'] < QUERY_TIMEOUT) ? $data['queries'][21]['duration'] : null,
					($data['queries'][22]['duration'] < QUERY_TIMEOUT) ? $data['queries'][22]['duration'] : null,

					$data['queries'][1]['hash'],
					$data['queries'][2]['hash'],
					$data['queries'][3]['hash'],
					$data['queries'][4]['hash'],
					$data['queries'][5]['hash'],
					$data['queries'][6]['hash'],
					$data['queries'][7]['hash'],
					$data['queries'][8]['hash'],
					$data['queries'][9]['hash'],
					$data['queries'][10]['hash'],
					$data['queries'][11]['hash'],
					$data['queries'][12]['hash'],
					$data['queries'][13]['hash'],
					$data['queries'][14]['hash'],
					$data['queries'][15]['hash'],
					$data['queries'][16]['hash'],
					$data['queries'][17]['hash'],
					$data['queries'][18]['hash'],
					$data['queries'][19]['hash'],
					$data['queries'][20]['hash'],
					$data['queries'][21]['hash'],
					$data['queries'][22]['hash'],
					$data['stats']['hit_ratio']

				) . ";\n");

		fclose($fd);

	}

	function load_checkpoints($logfile) {

		$log = file($logfile);
		$checkpoints = array();

		foreach ($log AS $row) {
			$row = trim($row);

			if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2}).([0-9]{3}) [A-Z]+ [0-9]+ :[a-z0-9]+\.[a-z0-9]+   LOG:  checkpoint starting: (.*)$/', $row, $matches)) {
				$checkpoints[] = array('start' => mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]) + TIME_DIFF, 'cause' => $matches[8]);
			} else if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2}) ([0-9]{2}):([0-9]{2}):([0-9]{2}).([0-9]{3}) [A-Z]+ [0-9]+ :[a-z0-9]+\.[a-z0-9]+   LOG:  checkpoint complete: wrote ([0-9]+) buffers/', $row, $matches)) {
				$checkpoints[count($checkpoints)-1]['end'] = mktime($matches[4], $matches[5], $matches[6], $matches[2], $matches[3], $matches[1]) + TIME_DIFF;
				$checkpoints[count($checkpoints)-1]['buffers'] = $matches[8];
			}
		}

		return $checkpoints;

	}

	/* returns a hash (used to compare multiple plans) */
	function get_plan_hash($file) {

		$plan = file($file);

		$tmp = '';
		foreach ($plan AS $line) {
			$line = preg_replace('/[0-9]/', '', $line);
			$line = preg_replace('/^\s+/', '', $line);
			$line = preg_replace('/\s+$/', '', $line);
			$tmp .= $line;
		}

		return md5($tmp);

	}

?>