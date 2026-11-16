# Valida un reporte `mariadb --batch --raw`: cada marker `TABLE\tnombre`
# debe ser único y corresponder 1:1 con exactamente una fila CHECK TABLE
# `base.nombre\tcheck\tstatus\tOK`. Rechaza faltantes, duplicados y tablas
# desconocidas aunque el conteo agregado de OK coincida.

BEGIN {
	if ( expected !~ /^[1-9][0-9]*$/ ) {
		print "expected inválido para CHECK TABLE" > "/dev/stderr"
		exit 2
	}
	if ( database !~ /^[A-Za-z0-9_]+$/ ) {
		print "database inválida para CHECK TABLE" > "/dev/stderr"
		exit 2
	}
}

$1 == "TABLE" {
	marker_rows++
	name = $2
	if ( name !~ /^wp_akb_search_[A-Za-z0-9_]+$/ ) {
		bad++
	}
	markers[name]++
}

$2 == "check" {
	checks++
	raw_name = $1
	name = raw_name
	sub(/^.*\./, "", name)
	if ( raw_name != database "." name ) {
		bad++
	}
	checked[name]++
	if ( $3 == "status" && $4 == "OK" ) {
		ok++
	} else {
		bad++
	}
}

END {
	for ( name in markers ) {
		unique_markers++
		if ( markers[name] != 1 || checked[name] != 1 ) {
			bad++
		}
	}
	for ( name in checked ) {
		if ( !(name in markers) || checked[name] != 1 ) {
			bad++
		}
	}
	if ( marker_rows != expected || unique_markers != expected || checks != expected || ok != expected || bad != 0 ) {
		printf "CHECK TABLE no es 1:1/status-OK: esperadas=%d markers=%d unique=%d checks=%d ok=%d bad=%d\n", expected, marker_rows, unique_markers, checks, ok, bad > "/dev/stderr"
		exit 1
	}
}
