-- $ID$
-- TPC-H/TPC-R Potential Part Promotion Query (Q20)
-- Function Query Definition
-- Approved February 1998
:x
:o
select
	s_name,
	s_address
from
	supplier,
	nation
where
	s_suppkey in (
		select
			ps_suppkey
		from
			partsupp,
			(
				select
					l_partkey agg_partkey,
					l_suppkey agg_suppkey,
					0.5 * sum(l_quantity) AS agg_quantity
				from
					lineitem
				where
					l_shipdate >= date ':2'
					and l_shipdate < date ':2' + interval '1' year
				group by
					l_partkey,
					l_suppkey
			) agg_lineitem
		where
			agg_partkey = ps_partkey
			and agg_suppkey = ps_suppkey
			and ps_partkey in (
				select
					p_partkey
				from
					part
				where
					p_name like ':1%'
			)
			and ps_availqty > agg_quantity
	)
	and s_nationkey = n_nationkey
	and n_name = ':3'
order by
	s_name
LIMIT 1;
