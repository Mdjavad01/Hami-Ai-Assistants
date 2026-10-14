#!/usr/bin/env bash
# G1 — SSRF matrix for Haa_API::is_url_safe(), run against the real WordPress
# (the stub harness fakes Haa_API away, so this cannot be a stub test).
#
# Two fresh processes, one per allow_localhost state; every host is checked
# against an expected verdict. "refuse" means is_url_safe() returns a WP_Error.
# The IPv6-bracket, IPv4-mapped and decimal/hex/octal rows are the actual hole:
# before the fix they come back ALLOWED with allow_localhost=0.
#
# haai-test only. The provider base URL is never used by these calls.
set -u

PHP="C:/xampp/php/php.exe"
WPCLI="C:/xampp/wp-cli/wp-cli.phar"
BENCH="C:/xampp/htdocs/haai-test"
WP="$PHP $WPCLI --path=$BENCH"
fail=0

probe() { # $1 = allow_localhost, $2 = host, $3 = expect (allow|refuse)
	local flag="$1" host="$2" want="$3" got
	got=$($WP eval "
	\$s = Haa_Settings::get();
	\$s['provider']['allow_localhost'] = $flag;
	update_option( HAAI_OPTION, \$s, 'no' );
	Haa_Settings::flush_cache();
	\$r = Haa_API::is_url_safe( 'http://$host/v1' );
	echo is_wp_error( \$r ) ? 'refuse' : 'allow';
	" 2>/dev/null | tail -1)
	if [ "$got" = "$want" ]; then
		echo "  ok   [flag=$flag] $host -> $got"
	else
		echo "  FAIL [flag=$flag] $host -> $got (expected $want)"
		fail=$((fail+1))
	fi
}

echo "== allow_localhost = 0 : nothing private may be reached =="
F=0
probe $F "127.0.0.1"            refuse
probe $F "localhost"             refuse
probe $F "[::1]"                 refuse
probe $F "[::ffff:127.0.0.1]"    refuse
probe $F "[fe80::1]"             refuse
probe $F "[fc00::1]"             refuse
probe $F "169.254.169.254"       refuse
probe $F "0.0.0.0"               refuse
probe $F "10.0.0.5"              refuse
probe $F "192.168.1.1"           refuse
probe $F "172.16.0.1"            refuse
probe $F "2130706433"            refuse
probe $F "0x7f000001"            refuse
probe $F "0177.0.0.1"            refuse
probe $F "8.8.8.8"               allow
probe $F "93.184.216.34"         allow

echo "== allow_localhost = 1 : loopback/LAN allowed, metadata never =="
F=1
probe $F "127.0.0.1"            allow
probe $F "localhost"             allow
probe $F "[::1]"                 allow
probe $F "10.0.0.5"              allow
probe $F "192.168.1.1"           allow
probe $F "169.254.169.254"       refuse
probe $F "0.0.0.0"               refuse
probe $F "[fe80::1]"             refuse
probe $F "[::ffff:169.254.169.254]" refuse
probe $F "8.8.8.8"               allow

echo "== restore the flag the run expects =="
$WP eval '$s=Haa_Settings::get(); $s["provider"]["allow_localhost"]=1; update_option(HAAI_OPTION,$s,"no"); Haa_Settings::flush_cache(); echo "allow_localhost=1\n";' 2>&1 | tail -1

echo "SUMMARY failures=$fail"
exit $fail
