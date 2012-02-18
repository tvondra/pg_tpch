#!/bin/sh

RESULTS=$1
DBNAME=$2
USER=$3

# delay between stats collections (iostat, vmstat, ...)
DELAY=15

# DSS queries timeout (5 minutes or something like that)
DSS_TIMEOUT=300 # 5 minutes in seconds

# log
LOGFILE=bench.log

function benchmark_run() {

	mkdir -p $RESULTS

	# store the settings
	psql -h localhost postgres -c "select name,setting from pg_settings" > $RESULTS/settings.log 2> $RESULTS/settings.err

	print_log "preparing TPC-H database"

	# create database, populate it with data and set up foreign keys
	# psql -h localhost tpch < dss/tpch-create.sql > $RESULTS/create.log 2> $RESULTS/create.err

	print_log "  loading data"

	psql -h localhost -U $USER $DBNAME < dss/tpch-load.sql > $RESULTS/load.log 2> $RESULTS/load.err

	print_log "  creating primary keys"

	psql -h localhost -U $USER $DBNAME < dss/tpch-pkeys.sql > $RESULTS/pkeys.log 2> $RESULTS/pkeys.err

	print_log "  creating foreign keys"

	psql -h localhost -U $USER $DBNAME < dss/tpch-alter.sql > $RESULTS/alter.log 2> $RESULTS/alter.err

	print_log "  creating indexes"

	psql -h localhost -U $USER $DBNAME < dss/tpch-index.sql > $RESULTS/index.log 2> $RESULTS/index.err

	print_log "  analyzing"

	psql -h localhost -U $USER $DBNAME -c "analyze" > $RESULTS/analyze.log 2> $RESULTS/analyze.err

	print_log "running TPC-H benchmark"

	benchmark_dss $RESULTS

	print_log "finished TPC-H benchmark"

}

function benchmark_dss() {

	mkdir -p $RESULTS

	mkdir $RESULTS/vmstat-s $RESULTS/vmstat-d $RESULTS/explain $RESULTS/results $RESULTS/errors

	# get bgwriter stats
	psql postgres -c "SELECT * FROM pg_stat_bgwriter" > $RESULTS/stats-before.log 2>> $RESULTS/stats-before.err
	psql postgres -c "SELECT * FROM pg_stat_database WHERE datname = '$DBNAME'" >> $RESULTS/stats-before.log 2>> $RESULTS/stats-before.err

	vmstat -s > $RESULTS/vmstat-s-before.log 2>&1
	vmstat -d > $RESULTS/vmstat-d-before.log 2>&1

	print_log "running queries defined in TPC-H benchmark"

	for n in `seq 1 22`
	do

		q="dss/queries/$n.sql"
		qe="dss/queries/$n.explain.sql"

		if [ -f "$q" ]; then

			print_log "  running query $n"

			echo "======= query $n =======" >> $RESULTS/data.log 2>&1;

			# run explain
			psql -h localhost -U $USER $DBNAME < $qe > $RESULTS/explain/$n 2>> $RESULTS/explain.err

			vmstat -s > $RESULTS/vmstat-s/before-$n.log 2>&1
			vmstat -d > $RESULTS/vmstat-d/before-$n.log 2>&1

			# run the query on background
			/usr/bin/time -a -f "$n = %e" -o $RESULTS/results.log psql -h localhost -U $USER $DBNAME < $q > $RESULTS/results/$n 2> $RESULTS/errors/$n &

			# wait up to the given number of seconds, then terminate the query if still running (don't wait for too long)
			for i in `seq 0 $DSS_TIMEOUT`
			do

				# the query is still running - check the time
				if [ -d "/proc/$!" ]; then

					# the time is over, kill it with fire!
					if [ $i -eq $DSS_TIMEOUT ]; then

						print_log "    killing query $n (timeout)"

						# echo "$q : timeout" >> $RESULTS/results.log
						psql -h localhost postgres -c "SELECT pg_terminate_backend(procpid) FROM pg_stat_activity WHERE datname = 'tpch'" >> $RESULTS/queries.err 2>&1;

						# time to do a cleanup
						sleep 10;

						# just check how many backends are there (should be 0)
						psql -h localhost postgres -c "SELECT COUNT(*) AS tpch_backends FROM pg_stat_activity WHERE datname = 'tpch'" >> $RESULTS/queries.err 2>&1;

					else
						# the query is still running and we have time left, sleep another second
						sleep 1;
					fi;

				else

					# the query finished in time, do not wait anymore
					print_log "    query $n finished OK ($i seconds)"
					break;

				fi;

			done;

			vmstat -s > $RESULTS/vmstat-s/after-$n.log 2>&1
			vmstat -d > $RESULTS/vmstat-d/after-$n.log 2>&1

		fi;

	done;

	# collect stats again
	psql postgres -c "SELECT * FROM pg_stat_bgwriter" > $RESULTS/stats-after.log 2>> $RESULTS/stats-after.err
	psql postgres -c "SELECT * FROM pg_stat_database WHERE datname = '$DBNAME'" >> $RESULTS/stats-after.log 2>> $RESULTS/stats-after.err

	vmstat -s > $RESULTS/vmstat-s-after.log 2>&1
	vmstat -d > $RESULTS/vmstat-d-after.log 2>&1

}

function stat_collection_start()
{

	local RESULTS=$1

	# run some basic monitoring tools (iotop, iostat, vmstat)
	for dev in $DEVICES
	do
		iostat -t -x /dev/$dev $DELAY >> $RESULTS/iostat.$dev.log &
	done;

	vmstat $DELAY >> $RESULTS/vmstat.log &

}

function stat_collection_stop()
{

	# wait to get a complete log from iostat etc. and then kill them
	sleep $DELAY

	for p in `jobs -p`; do
		kill $p;
	done;

}

function print_log() {

	local message=$1

	echo `date +"%Y-%m-%d %H:%M:%S"` "["`date +%s`"] : $message" >> $RESULTS/$LOGFILE;

}

mkdir $RESULTS;

# start statistics collection
stat_collection_start $RESULTS

# run the benchmark
benchmark_run $RESULTS $DBNAME $USER

# stop statistics collection
stat_collection_stop
