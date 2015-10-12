TPC-H PostgreSQL benchmark
==========================
This repository contains a simple implementation that runs a TPC-H-like
benchmark with a PostgreSQL database. It builds on the official TPC-H
benchmark available at http://tpc.org/tpch/default.asp (uses just the
dbgen a qgen parts).


Preparing dbgen and qgen
------------------------
The first thing you need to do is to prepare the tool that generates
data and queries. This step is more thoroughly explained at my blog at 

    http://www.fuzzy.cz/en/articles/dss-tpc-h-benchmark-with-postgresql/

but let's briefly repeat what needs to be done.

First, download the TPC-H benchmark from http://tpc.org/tpch/default.asp
and extract it to a directory

    $ wget http://tpc.org/tpch/spec/tpch_2_14_3.tgz
    $ mkdir tpch
    $ tar -xzf tpch_2_14_3.tgz -C tpch

and then prepare the Makefile - create a copy from makefile.suite

    $ cd tpch/dbgen
    $ cp makefile.suite Makefile
    $ nano Makefile

and modify it so that it contains this (around line 110)

    CC=gcc
    DATABASE=ORACLE
    MACHINE=LINUX
    WORKLOAD=TPCH

and compile it using `make` as usual. Now you should have `dbgen` and
`qgen` tools that generate data and queries.


Generating data
---------------
Right, so let's generate the data using the `dbgen` tool - there's one
important parameter 'scale' that influences the amount of data. It's
roughly equal to number of GB of raw data, so to generate 10GB of data
just do

    $ ./dbgen -s 10

which creates a bunch of .tbl files in Oracle-like CSV format

    $ ls *.tbl

and to convert them to a CSV format compatible with PostgreSQL, do this

    $ for i in `ls *.tbl`; do sed 's/|$//' $i > ${i/tbl/csv}; echo $i; done;

Finally, move these data to the 'dss/data' directory or somewhere else,
and create a symlink to /tmp/dss-data (that's where tpch-load.sql is
looking for for the data from).

It's a good idea to place this directory on a ramdrive so that it does not
influence the benchmark (e.g. it's a very bad idea to place the data on the
same drive as PostgreSQL data directory).


Generating queries
------------------
Now we have to generate queries from templates specified in TPC-H benchmark.
The templates provided at tpch.org are not suitable for PostgreSQL. So
I have provided slightly modified queries in the 'dss/templates' directory
and you should place the queries in 'dss/queries' dir.

    for q in `seq 1 22`
    do
        DSS_QUERY=dss/templates ./qgen $q >> dss/queries/$q.sql
        sed 's/^select/explain select/' dss/queries/$q.sql > dss/queries/$q.explain.sql
        cat dss/queries/$q.sql >> dss/queries/$q.explain.sql;
    done

Now you should have 44 files in the dss/queries directory. 22 of them will
actually run the queries and the other 22 will generate EXPLAIN plan of
the query (without actually running it).


Running the benchmark
---------------------
The actual benchmark is implemented in the 'tpch.sh' script. It expects
an already prepared database and four parameters - directory where to place
the results, database and user name. So to run it, do this:

    $ ./tpch.sh ./results tpch-db tpch-user

and wait until the benchmark.


Processing the results
----------------------
All the results are written into the output directory (first parameter). To get
useful results (timing of each query, various statistics), you can use script
process.php. It expects two parameters - input dir (with data collected by the
tpch.sh script) and output file (in CSV format). For example like this:

    $ php process.php ./results output.csv

This should give you nicely formatted CSV file.
