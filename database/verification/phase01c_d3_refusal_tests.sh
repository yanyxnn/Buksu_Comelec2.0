#!/usr/bin/env bash
# Phase 01C / D3 refusal, rollback-protection, lock and resume tests.   *** DESTRUCTIVE TO ITS OWN FIXTURES ***
#
# Usage (the script cd's to the repository root itself):
#   DB=buksu_p1c_throwaway_test ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES \
#   DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=root DB_PASSWORD= \
#   MYSQL="mysql -uroot -h127.0.0.1 -P3307" bash database/verification/phase01c_d3_refusal_tests.sh
#   (optional, strict gate: add REQUIRE_GATE_SERVER=YES to refuse anything but MariaDB 10.4.32 on port 3307)
#
# THE TARGET DATABASE MUST BE NEW AND EMPTY (zero tables and views). This script NEVER drops, wipes or truncates
# existing tables: if the database already contains any table or view it refuses immediately and changes nothing.
# Create a fresh database for each run (for example DROP DATABASE IF EXISTS + CREATE DATABASE, done by you, outside
# this script). The script migrates that empty database itself; a second run therefore needs a new empty database.
#
# What it proves (on the disposable database ONLY):
#   R1  the migration REFUSES when any of the six Domain B tables holds a row - each table alone (minimum valid
#       dependency chain, 1 and 2 chains) and all six together - listing EVERY non-empty table with its exact row
#       count, naming no identifier, leaving schema + indexes + FKs + CHECKs + row counts byte-identical, not recording
#       the migration, and releasing the advisory lock;
#   R2  ROLLBACK refuses the same way when post-migration ballot records exist;
#   R3  lock contention fails fast with no change; R4 a converted populated database is a no-op, not a refusal;
#   R5  an interrupted conversion resumes; with a row it refuses and changes nothing; R6 an unrecognised shape stops
#       with no change. In every case the lock is verified free IN THE SAME PHP PROCESS after the run (a process exit would
#       hide a missing RELEASE_LOCK), and the connection id is verified unchanged.
# Fixtures: valid parent rows are inserted with foreign_key_checks ON; they are never inserted or removed with
# foreign-key checks disabled. Legacy-state fixtures use 26-char ULID-shaped ids, converted-state fixtures use lowercase UUIDv4.
# Fixture rows are removed child -> parent with plain DELETE (no TRUNCATE). This is safe only because the safety gate
# proves the target is the disposable database, that it held ZERO tables before the script created anything, and that
# the Laravel connection and the mysql client resolve to the same server and database. At the end (R9) every fixture
# table of the Phase 01B harness is verified to hold zero rows, and R9 fails otherwise.
#
# Safety gate (D3-D4): no default DB; DB must equal the exact allowlisted name and not be a denied application / Phase 04
# database; ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES; DB_HOST, DB_PORT, DB_USERNAME must be given explicitly (and DB_PASSWORD
# defined) so artisan can never fall back to the application .env; SELECT DATABASE() must equal DB through BOTH the mysql
# client and the Laravel connection; the server version/port/hostname are printed and labelled GATE / NON-GATE.
# REQUIRE_GATE_SERVER=YES (opt-in) refuses unless the server is MariaDB 10.4.32 on port 3307; MariaDB 10.11 stays usable
# as exploratory testing when it is not set.
set -u
cd "$(dirname "$0")/../.." || exit 2
ROOT="$(pwd)"
DB="${DB:-}"; MYSQL="${MYSQL:-}"
ALLOWED_DB="buksu_p1c_throwaway_test"
DENIED_DBS="buksu_comelec2.0 buksu_comelec_v2 comelec_phase04c_104_test"
MIG="database/migrations/2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4.php"
PASS=0; FAIL=0
refuse() { echo "REFUSING TO RUN: $1" >&2; exit 2; }
[ -n "$DB" ] || refuse "DB is not set. There is no default database; set DB=$ALLOWED_DB."
for d in $DENIED_DBS; do [ "$DB" != "$d" ] || refuse "'$DB' is an application or Phase 04 database and is explicitly denied."; done
[ "$DB" = "$ALLOWED_DB" ] || refuse "'$DB' is not the allowed disposable database '$ALLOWED_DB'."
[ "${ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP:-}" = "YES" ] || refuse "ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES is required."
[ -n "$MYSQL" ] || refuse "MYSQL (client command including host/port/user) is not set."
[ -n "${DB_HOST:-}" ] && [ -n "${DB_PORT:-}" ] && [ -n "${DB_USERNAME:-}" ] && [ "${DB_PASSWORD+set}" = "set" ] || refuse "DB_HOST, DB_PORT, DB_USERNAME and DB_PASSWORD (may be empty) must be exported explicitly so artisan cannot use the application .env."
[ -f "$MIG" ] || refuse "migration file $MIG not found (run from a tree that contains the D3 patch)."
export DB_CONNECTION="${DB_CONNECTION:-mysql}" DB_DATABASE="$DB" DB_URL="" APP_ENV=testing
sql() { $MYSQL -D "$DB" -N -e "$1" 2>&1 | tr -d '\r'; }
ident=$($MYSQL -D "$DB" -N -e "SELECT DATABASE()" 2>&1 | tr -d '\r')
[ "$ident" = "$DB" ] || refuse "SELECT DATABASE() returned '${ident}', expected '$DB'."
srv=$($MYSQL -D "$DB" -N -e "SELECT VERSION(), @@port, @@hostname" 2>&1 | tr -d '\r')
sv=$(printf '%s' "$srv" | cut -f1); sp=$(printf '%s' "$srv" | cut -f2); sh=$(printf '%s' "$srv" | cut -f3)
sdd=$($MYSQL -D "$DB" -N -e "SELECT @@datadir" 2>&1 | tr -d '\r')
case "$sv" in 10.4.32-MariaDB*) [ "$sp" = "3307" ] && glabel="GATE SERVER (MariaDB 10.4.32, port 3307)" || glabel="NON-GATE SERVER (10.4.32, port $sp)";; *) glabel="NON-GATE SERVER (exploratory only; the required gate is MariaDB 10.4.32 on port 3307)";; esac
echo "SERVER: version=$sv port=$sp host=$sh database=$ident :: $glabel"
if [ "${REQUIRE_GATE_SERVER:-}" = "YES" ]; then case "$glabel" in "GATE SERVER"*) ;; *) refuse "REQUIRE_GATE_SERVER=YES but this is not MariaDB 10.4.32 on port 3307 (version=$sv port=$sp).";; esac; fi
# The database must be brand new: zero tables and zero views. Nothing is dropped, wiped or truncated by this script.
ntab=$($MYSQL -D "$DB" -N -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB'" 2>&1 | tr -d '\r')
[ "$ntab" = "0" ] || refuse "database '$DB' is not empty (information_schema reports: '$ntab' table/view count). This script never drops or wipes anything. Nothing was changed. Create a new empty database and re-run."
LOCK=$(sql "SELECT CONCAT('buksu_comelec:phase01c_d3:', SUBSTR(MD5(DATABASE()),1,16))")

# --- PHP driver: runs up()/down() of the migration directly in ONE process, on the Laravel connection --------------------
read -r -d '' PHP_DRIVER <<'PHPEOF'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$name = (string) \Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS d')->d;
echo "DATABASE=$name\n";
if ($name !== getenv('D3_EXPECT_DB')) { echo "RESULT=wrong-database\n"; exit(3); }
if (getenv('D3_DIR') === 'identity') {
    $r = \Illuminate\Support\Facades\DB::selectOne('SELECT VERSION() AS v, @@port AS p, @@hostname AS h, @@datadir AS d, (SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()) AS t');
    echo "IDENT=".$r->v.'|'.$r->p.'|'.$r->h.'|'.$r->d.'|'.$r->t."\n";
    echo "RESULT=identity-ok\n"; exit(0);
}
$lock = getenv('D3_LOCK');
$m = require getenv('D3_FILE');
$cid0 = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS c')->c;
$res = 'ok'; $msg = '';
try { getenv('D3_DIR') === 'down' ? $m->down() : $m->up(); }
catch (Throwable $e) { $res = 'exception'; $msg = str_replace(["\r","\n"], ' ', $e->getMessage()); }
$row = \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS c, IS_FREE_LOCK(?) AS f', [$lock]);
echo "RESULT=$res\nCONN_SAME=".(((int) $row->c === $cid0) ? 1 : 0)."\nLOCK_FREE_AFTER=".(int) $row->f."\nMESSAGE=$msg\n";
PHPEOF
drive() { # up|down
  DR_OUT=$(D3_EXPECT_DB="$DB" D3_LOCK="$LOCK" D3_FILE="$ROOT/$MIG" D3_DIR="$1" php -d display_errors=0 -r "$PHP_DRIVER" 2>&1); DR_RC=$?
  DR_RES=$(printf '%s\n' "$DR_OUT" | sed -n 's/^RESULT=//p'); DR_MSG=$(printf '%s\n' "$DR_OUT" | sed -n 's/^MESSAGE=//p')
  DR_FREE=$(printf '%s\n' "$DR_OUT" | sed -n 's/^LOCK_FREE_AFTER=//p'); DR_SAME=$(printf '%s\n' "$DR_OUT" | sed -n 's/^CONN_SAME=//p')
}
art() { php artisan "$@" 2>&1; }

# --- helpers --------------------------------------------------------------------------------------------------------
SIX="ballots ballot_contest_responses ballot_candidate_selections ballot_reporting_contexts ballot_events ballot_dispositions"
ok()   { echo "PASS  $1"; PASS=$((PASS+1)); }
bad()  { echo "FAIL  $1"; FAIL=$((FAIL+1)); }
signature() { # schema + index + FK + CHECK + row counts of the six tables (stable text)
  sql "SELECT CONCAT('col|',TABLE_NAME,'|',COLUMN_NAME,'|',COLUMN_TYPE,'|',IS_NULLABLE,'|',IFNULL(COLLATION_NAME,'')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions')
 UNION ALL SELECT CONCAT('idx|',TABLE_NAME,'|',INDEX_NAME,'|',NON_UNIQUE,'|',SEQ_IN_INDEX,'|',COLUMN_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions')
 UNION ALL SELECT CONCAT('fk|',k.TABLE_NAME,'|',k.CONSTRAINT_NAME,'|',k.ORDINAL_POSITION,'|',k.COLUMN_NAME,'|',k.REFERENCED_TABLE_NAME,'|',k.REFERENCED_COLUMN_NAME,'|',r.DELETE_RULE,'|',r.UPDATE_RULE) FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions') AND k.REFERENCED_TABLE_NAME IS NOT NULL
 UNION ALL SELECT CONCAT('chk|',CONSTRAINT_NAME,'|',CHECK_CLAUSE) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME LIKE '%ballot%'
 UNION ALL SELECT CONCAT('cnt|ballots|',COUNT(*)) FROM ballots UNION ALL SELECT CONCAT('cnt|ballot_contest_responses|',COUNT(*)) FROM ballot_contest_responses
 UNION ALL SELECT CONCAT('cnt|ballot_candidate_selections|',COUNT(*)) FROM ballot_candidate_selections UNION ALL SELECT CONCAT('cnt|ballot_reporting_contexts|',COUNT(*)) FROM ballot_reporting_contexts
 UNION ALL SELECT CONCAT('cnt|ballot_events|',COUNT(*)) FROM ballot_events UNION ALL SELECT CONCAT('cnt|ballot_dispositions|',COUNT(*)) FROM ballot_dispositions ORDER BY 1" | md5sum | cut -c1-32
}
col36() { sql "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE='char' AND CHARACTER_MAXIMUM_LENGTH=36 AND CONCAT(TABLE_NAME,'.',COLUMN_NAME) IN ('ballots.id','ballot_contest_responses.id','ballot_events.id','ballot_dispositions.id','ballot_contest_responses.ballot_id','ballot_candidate_selections.ballot_contest_response_id','ballot_reporting_contexts.ballot_id','ballot_events.ballot_id','ballot_dispositions.ballot_id')"; }
nchk() { sql "SELECT COUNT(*) FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME LIKE 'chk_%_id_uuidv4'"; }
recorded() { sql "SELECT COUNT(*) FROM migrations WHERE migration='2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4'"; }
state() { local c k; c=$(col36); k=$(nchk); if [ "$c" = 9 ] && [ "$k" = 4 ]; then echo final; elif [ "$c" = 0 ] && [ "$k" = 0 ]; then echo legacy; else echo "mixed($c cols CHAR(36), $k checks)"; fi; }
lock_free_sql() { sql "SELECT IS_FREE_LOCK('$LOCK')"; }
counts_str() { local t out="" n; for t in $SIX; do n=$(sql "SELECT COUNT(*) FROM \`$t\`"); out="$out $t=$n"; done; echo "${out# }"; }
clean_fixtures() { sql "DELETE FROM ballot_dispositions; DELETE FROM ballot_events; DELETE FROM ballot_candidate_selections; DELETE FROM ballot_reporting_contexts; DELETE FROM ballot_contest_responses; DELETE FROM ballots;" >/dev/null; }

# fixture ids: legacy (26-char ULID-shaped) or final (lowercase UUIDv4)
MODE=legacy
id26() { printf '01D3%s%021d' "$1" "$2"; }
idu()  { printf '%s0000000-0000-4000-8000-%012d' "$1" "$2"; }
bid() { [ "$MODE" = legacy ] && id26 B "$1" || idu b "$1"; }
rid() { [ "$MODE" = legacy ] && id26 R "$1" || idu c "$1"; }
eid() { [ "$MODE" = legacy ] && id26 E "$1" || idu e "$1"; }
did() { [ "$MODE" = legacy ] && id26 D "$1" || idu d "$1"; }
ALLIDS_RE='01D3[BRED]|[bcde]0000000-0000-4000-8000-'
# minimum valid dependency chain per target table, replica n (every statement valid with foreign_key_checks ON)
chain_sql() { # target n
  local t="$1" n="$2" b r
  b="INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('$(bid $n)',1,1,NOW());"
  r="INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('$(rid $n)','$(bid $n)',1,1,'VOTE');"
  case "$t" in
    ballots) echo "$b";;
    ballot_contest_responses) echo "$b $r";;
    ballot_candidate_selections) echo "$b $r INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$(rid $n)',1,1);";;
    ballot_reporting_contexts) echo "$b INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('$(bid $n)',1,'CCS');";;
    ballot_events) echo "$b INSERT INTO ballot_events(id,ballot_id,event_type,occurred_at) VALUES ('$(eid $n)','$(bid $n)','FIXTURE',NOW());";;
    ballot_dispositions) echo "$b INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('$(did $n)','$(bid $n)','REINSTATED',1,NOW());";;
  esac
}
chain_counts() { # target -> per-chain counts "table=n ..." (parents included)
  case "$1" in
    ballots) echo "ballots=1";;
    ballot_contest_responses) echo "ballots=1 ballot_contest_responses=1";;
    ballot_candidate_selections) echo "ballots=1 ballot_contest_responses=1 ballot_candidate_selections=1";;
    ballot_reporting_contexts) echo "ballots=1 ballot_reporting_contexts=1";;
    ballot_events) echo "ballots=1 ballot_events=1";;
    ballot_dispositions) echo "ballots=1 ballot_dispositions=1";;
  esac
}
scale_counts() { local k="$1" it out=""; shift; for it in $1; do out="$out ${it%%=*}=$(( ${it#*=} * k ))"; done; echo "${out# }"; }
# all six tables together, distinct counts: ballots 3, responses 2, selections 1, contexts 3, events 4, dispositions 5
FULL_COUNTS="ballots=3 ballot_contest_responses=2 ballot_candidate_selections=1 ballot_reporting_contexts=3 ballot_events=4 ballot_dispositions=5"
full_sql() {
  local s="" n
  for n in 1 2 3; do s="$s INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('$(bid $n)',1,1,NOW());"; done
  for n in 1 2; do s="$s INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('$(rid $n)','$(bid $n)',1,1,'VOTE');"; done
  s="$s INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$(rid 1)',1,1);"
  for n in 1 2 3; do s="$s INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('$(bid $n)',1,'CCS');"; done
  for n in 1 2 3 4; do s="$s INSERT INTO ballot_events(id,ballot_id,event_type,occurred_at) VALUES ('$(eid $n)','$(bid $(( (n-1) % 3 + 1 )))','FIXTURE',NOW());"; done
  for n in 1 2 3 4 5; do s="$s INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('$(did $n)','$(bid 2)','REINSTATED',1,NOW());"; done
  echo "$s"
}
# parse "tables contain records: a: N rows; b: 1 row. No record ..." into "a=N b=1" (sorted) from a message
parse_listed() { printf '%s' "$1" | sed -n 's/.*tables contain records: \(.*\)\. No record was deleted.*/\1/p' | tr ';' '\n' | sed -E 's/^ *([a-z_]+): ([0-9]+) rows?.*/\1=\2/' | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//'; }
sorted() { printf '%s\n' $1 | LC_ALL=C sort | tr '\n' ' ' | sed 's/ $//'; }

expect_refusal() { # label direction expected-counts (sets are "t=n ...")  -- runs the driver, asserts everything, uses current fixtures
  local label="$1" dir="$2" exp="$3" before after listed want ids why=""
  before=$(signature); local cnt_before; cnt_before=$(counts_str)
  drive "$dir"
  after=$(signature)
  [ "$DR_RES" = "exception" ] || why="$why [no refusal: RESULT=$DR_RES]"
  printf '%s' "$DR_MSG" | grep -q "REFUSED" || why="$why [message lacks REFUSED]"
  listed=$(parse_listed "$DR_MSG"); want=$(sorted "$exp")
  [ "$listed" = "$want" ] || why="$why [listed '$listed' != expected '$want']"
  printf '%s' "$DR_MSG" | grep -qE "$ALLIDS_RE" && why="$why [message contains an identifier]"
  printf '%s' "$DR_MSG" | grep -qi "no record was deleted, truncated, overwritten or converted" || why="$why [message lacks the no-change statement]"
  [ "$before" = "$after" ] || why="$why [schema/index/FK/CHECK/row-count signature CHANGED]"
  [ "$(counts_str)" = "$cnt_before" ] || why="$why [row counts changed: $cnt_before -> $(counts_str)]"
  [ "$DR_FREE" = "1" ] || why="$why [advisory lock still held by the migrating connection after the run]"
  [ "$DR_SAME" = "1" ] || why="$why [connection id changed during the run]"
  [ "$(lock_free_sql)" = "1" ] || why="$why [lock not free after process exit]"
  if [ -z "$why" ]; then ok "$label  [refused; listed exactly: $want; nothing changed; lock released; same connection]"; else bad "$label ->$why  :: $DR_MSG"; fi
}

# --- Phase 0: build a known state and the parent rows -----------------------------------------------------------------
echo "=== R0 build state: migrate the NEW EMPTY disposable database (nothing is wiped), insert parents, roll D3 back to the legacy schema ==="
drive identity
[ "$DR_RES" = "identity-ok" ] || refuse "the Laravel connection does not resolve to '$DB' (got: $(printf '%s' "$DR_OUT" | tr '\n' ' ')). Nothing was changed."
lar=$(printf '%s\n' "$DR_OUT" | sed -n 's/^IDENT=//p')
cli="$sv|$sp|$sh|$sdd|0"
[ "$lar" = "$cli" ] || refuse "the Laravel connection and the mysql client do not resolve to the same server/database (Laravel: version|port|host|datadir|tables = '$lar'; mysql client: '$cli'). Nothing was changed."
ok "R0-id  Laravel connection and mysql client agree: database=$DB version=$sv port=$sp host=$sh; the database holds 0 tables through both"
out=$(art migrate --force); [ $? -eq 0 ] || { echo "$out" | tail -5; refuse "migrate failed on the disposable database"; }
[ "$(state)" = final ] && ok "R0a  fresh database migrated through D3 (all migrations): schema is final" || bad "R0a  state is $(state)"
for t in $SIX; do n=$(sql "SELECT COUNT(*) FROM \`$t\`"); [ "$n" = 0 ] || refuse "Domain B table $t is not empty after the initial migration"; done
T="NOW(),NOW()"
pr=$(sql "INSERT INTO admin_users(id,authorized_email,google_subject,display_name,created_at,updated_at) VALUES (1,'fixture.admin@example.test','g1','Admin',$T);
INSERT INTO students(id,institutional_id,institutional_email,first_name,last_name,current_college,current_course,current_year_level,status,created_at,updated_at) VALUES (1,'2020-0001','a@x.edu','A','One','CCS','BSIT','1','ACTIVE',$T);
INSERT INTO elections(id,name,type,created_by,created_at,updated_at) VALUES (1,'E1','T',1,$T);
INSERT INTO election_config_versions(id,election_id,version_number,proposed_by,created_at,updated_at) VALUES (1,1,1,1,$T);
INSERT INTO contests(id,election_id,election_config_version_id,name,position_label,created_at,updated_at) VALUES (1,1,1,'A','P',$T);
INSERT INTO candidates(id,student_id,created_at,updated_at) VALUES (1,1,$T);
INSERT INTO candidate_roster_snapshots(id,election_id,election_config_version_id,version_number,created_at,updated_at) VALUES (1,1,1,1,$T);
INSERT INTO election_eligibility_snapshots(id,election_id,election_config_version_id,rule_definition_json,created_at,updated_at) VALUES (1,1,1,'{}',$T);
INSERT INTO candidacies(id,candidate_id,election_id,contest_id,display_name,created_at,updated_at) VALUES (1,1,1,1,'cA',$T);
INSERT INTO ballot_structure_snapshots(id,election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,created_at,updated_at) VALUES (1,1,1,1,1,1,$T);")
if printf '%s' "$pr" | grep -q "^ERROR"; then bad "R0b  parent rows could not be inserted with foreign_key_checks ON: $pr"; else ok "R0b  valid parent rows inserted with foreign_key_checks ON (admin, student, election, config version, contest, candidate, snapshots, candidacy, structure snapshot)"; fi
out=$(art migrate:rollback --path="$MIG" --force); [ $? -eq 0 ] || echo "$out" | tail -3
[ "$(state)" = legacy ] && ok "R0c  D3 rolled back on the empty schema: legacy CHAR(26) schema, no UUID CHECKs" || bad "R0c  state is $(state)"
[ "$(recorded)" = 0 ] && ok "R0d  migration not recorded as run" || bad "R0d  migration still recorded"
S_LEGACY=$(signature)
drive down  # already legacy and empty: must be a no-op
{ [ "$DR_RES" = ok ] && [ "$(signature)" = "$S_LEGACY" ] && [ "$DR_FREE" = 1 ]; } && ok "R0e  down() on an already-legacy empty schema is a no-op (signature identical, lock released)" || bad "R0e  down() on legacy: RES=$DR_RES :: $DR_MSG"

# --- R1: refusal matrix on the LEGACY schema --------------------------------------------------------------------------
echo "=== R1 refusal matrix (legacy CHAR(26) schema, fixture ULIDs, minimum valid dependency chains) ==="
MODE=legacy
for t in $SIX; do
  for k in 1 2; do
    clean_fixtures
    sqlout=""; for n in $(seq 1 $k); do sqlout="$sqlout $(chain_sql $t $n)"; done
    res=$(sql "$sqlout"); if printf '%s' "$res" | grep -q "^ERROR"; then bad "R1  fixture for $t x$k could not be built with foreign_key_checks ON: $res"; continue; fi
    exp=$(scale_counts $k "$(chain_counts $t)")
    got=$(counts_str); want_all=""; for x in $SIX; do c=0; for it in $exp; do [ "${it%%=*}" = "$x" ] && c=${it#*=}; done; want_all="$want_all $x=$c"; done
    [ "$got" = "${want_all# }" ] || { bad "R1  fixture row counts for $t x$k are '$got', expected '${want_all# }'"; continue; }
    expect_refusal "R1 up refuses: $t x$k chain(s)" up "$exp"
    [ "$(recorded)" = 0 ] || bad "R1  $t x$k: migration recorded despite refusal"
  done
done
clean_fixtures; res=$(sql "$(full_sql)")
if printf '%s' "$res" | grep -q "^ERROR"; then bad "R1  full tree fixture failed: $res"; else expect_refusal "R1 up refuses: all six tables together" up "$FULL_COUNTS"; fi
# through the real artisan entry point: non-zero exit, nothing recorded, nothing changed
b=$(signature); out=$(art migrate --path="$MIG" --force); rc=$?
a=$(signature)
{ [ $rc -ne 0 ] && printf '%s' "$out" | grep -q "REFUSED" && [ "$b" = "$a" ] && [ "$(recorded)" = 0 ] && [ "$(lock_free_sql)" = 1 ]; } && ok "R1 artisan migrate (real entry point) exits non-zero with the refusal, records nothing, changes nothing" || bad "R1 artisan migrate refusal: rc=$rc recorded=$(recorded) same=$([ "$b" = "$a" ] && echo y || echo n)"
clean_fixtures

# --- R3: lock contention ------------------------------------------------------------------------------------------------
echo "=== R3 lock contention ==="
$MYSQL -D "$DB" -N -e "SELECT GET_LOCK('$LOCK', 0); SELECT SLEEP(40);" >/dev/null 2>&1 &
HOLDER=$!
for i in $(seq 1 20); do [ "$(lock_free_sql)" = "0" ] && break; sleep 0.5; done
if [ "$(lock_free_sql)" != "0" ]; then bad "R3  could not establish a competing lock holder"; else
  b=$(signature); t0=$(date +%s); drive up; t1=$(date +%s); a=$(signature)
  w=""; [ "$DR_RES" = exception ] || w="$w [no failure]"; printf '%s' "$DR_MSG" | grep -q "could not acquire the migration lock" || w="$w [wrong message: $DR_MSG]"
  printf '%s' "$DR_MSG" | grep -q "No schema change was attempted" || w="$w [missing no-change statement]"
  [ "$b" = "$a" ] || w="$w [schema changed]"; [ $((t1-t0)) -le 15 ] || w="$w [did not fail fast: $((t1-t0))s]"
  [ "$(state)" = legacy ] || w="$w [state $(state)]"
  holder_id=$(sql "SELECT IS_USED_LOCK('$LOCK')"); [ -n "$holder_id" ] && [ "$holder_id" != "NULL" ] && sql "KILL CONNECTION $holder_id" >/dev/null
  [ -z "$w" ] && ok "R3  contention: migration fails fast ($((t1-t0))s) with the lock message; nothing changed" || bad "R3 ->$w"
  kill $HOLDER 2>/dev/null; wait $HOLDER 2>/dev/null
  for i in $(seq 1 20); do [ "$(lock_free_sql)" = "1" ] && break; sleep 0.5; done
  [ "$(lock_free_sql)" = "1" ] && ok "R3b competing holder gone; lock free" || bad "R3b lock still held after killing the holder"
fi

# --- R6: unrecognised shape stops, unchanged, lock released ---------------------------------------------------------
echo "=== R6 unrecognised shape (exception path) ==="
sql "ALTER TABLE ballot_events DROP FOREIGN KEY ballot_events_ballot_id_foreign; ALTER TABLE ballot_events MODIFY ballot_id VARCHAR(40) NOT NULL;" >/dev/null
b=$(signature); drive up; a=$(signature)
{ [ "$DR_RES" = exception ] && printf '%s' "$DR_MSG" | grep -q "not in a recognised shape" && [ "$b" = "$a" ] && [ "$DR_FREE" = 1 ] && [ "$DR_SAME" = 1 ]; } && ok "R6  unrecognised shape: stops with a clear message, no change, lock released after the exception" || bad "R6  RES=$DR_RES free=$DR_FREE :: $DR_MSG"
sql "ALTER TABLE ballot_events MODIFY ballot_id CHAR(26) NOT NULL; ALTER TABLE ballot_events ADD CONSTRAINT ballot_events_ballot_id_foreign FOREIGN KEY (ballot_id) REFERENCES ballots (id) ON DELETE RESTRICT;" >/dev/null

# --- R5: interrupted conversion on an empty schema ------------------------------------------------------------------------
echo "=== R5 interrupted conversion (partial schema) ==="
partial() { # emulate a crash after the FK drops and two column conversions
  sql "ALTER TABLE ballot_contest_responses DROP FOREIGN KEY bcr_contest_same_election_fk; ALTER TABLE ballot_contest_responses DROP FOREIGN KEY bcr_ballot_same_election_fk; ALTER TABLE ballot_candidate_selections DROP FOREIGN KEY bcs_candidacy_contest_membership_foreign; ALTER TABLE ballot_candidate_selections DROP FOREIGN KEY bcs_response_contest_match_foreign; ALTER TABLE ballot_reporting_contexts DROP FOREIGN KEY brc_ballot_same_election_fk; ALTER TABLE ballot_events DROP FOREIGN KEY ballot_events_actor_id_foreign; ALTER TABLE ballot_events DROP FOREIGN KEY ballot_events_related_result_calculation_run_id_foreign; ALTER TABLE ballot_events DROP FOREIGN KEY ballot_events_related_incident_id_foreign; ALTER TABLE ballot_events DROP FOREIGN KEY ballot_events_ballot_id_foreign; ALTER TABLE ballot_dispositions DROP FOREIGN KEY ballot_dispositions_decided_by_foreign; ALTER TABLE ballot_dispositions DROP FOREIGN KEY ballot_dispositions_related_incident_id_foreign; ALTER TABLE ballot_dispositions DROP FOREIGN KEY ballot_dispositions_ballot_id_foreign; ALTER TABLE ballots MODIFY id CHAR(36) NOT NULL; ALTER TABLE ballot_contest_responses MODIFY id CHAR(36) NOT NULL;" | grep "^ERROR"
}
pe=$(partial); [ -z "$pe" ] && ok "R5a  partial (interrupted) schema emulated: FKs dropped, 2 of 9 columns converted" || bad "R5a  could not emulate partial schema: $pe"
# R5b: partial + a fixture row -> refuse with counts, change nothing
MODE=final; sql "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('$(bid 1)',1,1,NOW());" | grep "^ERROR"
b=$(signature); drive up; a=$(signature)
{ [ "$DR_RES" = exception ] && printf '%s' "$DR_MSG" | grep -q "REFUSED" && [ "$(parse_listed "$DR_MSG")" = "ballots=1" ] && [ "$b" = "$a" ] && [ "$DR_FREE" = 1 ]; } && ok "R5b  partial schema + 1 ballot row: refuses with 'ballots: 1 row'; the partial schema is left exactly as found" || bad "R5b  RES=$DR_RES :: $DR_MSG"
clean_fixtures
# R5c: partial + empty -> resumes to the final schema
drive up
[ "$DR_RES" = ok ] && [ "$(state)" = final ] && [ "$DR_FREE" = 1 ] && ok "R5c  interrupted conversion resumed to the final schema (9 CHAR(36), 4 CHECKs, FKs restored); lock released" || bad "R5c  RES=$DR_RES state=$(state) :: $DR_MSG"
[ "$(lock_free_sql)" = 1 ] || bad "R5c  lock not free"

# --- Schema checks on the converted schema + A2 ordering evidence ---------------------------------------------------------
echo "=== R7 converted schema checked by the read-only schema script ==="
out=$(DB="$DB" MYSQL="$MYSQL" bash database/verification/phase01c_d3_uuidv4_schema_tests.sh 2>&1); echo "$out" | tail -1
echo "$out" | grep -q "RESULT: 8 passed, 0 failed" && ok "R7  phase01c_d3_uuidv4_schema_tests.sh: 8 passed, 0 failed on the converted schema" || bad "R7  schema script: $(echo "$out" | grep -E '^FAIL|RESULT')"
S_FINAL=$(signature)

# --- R2: rollback refusal (final schema, post-migration records) --------------------------------------------------------------
echo "=== R2 rollback refusal (converted schema, UUIDv4 fixtures created after the migration) ==="
MODE=final
sql "DELETE FROM migrations WHERE migration='2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4'; INSERT INTO migrations(migration,batch) VALUES ('2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4',(SELECT IFNULL(MAX(batch),0)+1 FROM (SELECT batch FROM migrations) m));" >/dev/null
for t in $SIX; do
  for k in 1 2; do
    clean_fixtures
    sqlout=""; for n in $(seq 1 $k); do sqlout="$sqlout $(chain_sql $t $n)"; done
    res=$(sql "$sqlout"); if printf '%s' "$res" | grep -q "^ERROR"; then bad "R2  fixture for $t x$k failed with foreign_key_checks ON: $res"; continue; fi
    exp=$(scale_counts $k "$(chain_counts $t)")
    expect_refusal "R2 down refuses: $t x$k chain(s)" down "$exp"
    [ "$(state)" = final ] && [ "$(recorded)" = 1 ] || bad "R2  $t x$k: schema/record state changed ($(state), recorded=$(recorded))"
  done
done
clean_fixtures; res=$(sql "$(full_sql)")
if printf '%s' "$res" | grep -q "^ERROR"; then bad "R2  full tree fixture failed: $res"; else expect_refusal "R2 down refuses: all six tables together" down "$FULL_COUNTS"; fi
# real artisan entry point
b=$(signature); out=$(art migrate:rollback --path="$MIG" --force); rc=$?; a=$(signature)
{ [ $rc -ne 0 ] && printf '%s' "$out" | grep -q "REFUSED" && [ "$b" = "$a" ] && [ "$(state)" = final ] && [ "$(recorded)" = 1 ]; } && ok "R2 artisan migrate:rollback (real entry point) exits non-zero, schema stays CHAR(36), migration stays recorded" || bad "R2 artisan rollback refusal: rc=$rc state=$(state) recorded=$(recorded)"

# --- R4: converted + populated + re-run is a no-op (refusal applies only when the run would change the schema) ----------------
echo "=== R4 converted populated database: migrate is a no-op, never a refusal ==="
sql "DELETE FROM migrations WHERE migration='2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4'" >/dev/null
b=$(signature); out=$(art migrate --path="$MIG" --force); rc=$?; a=$(signature)
{ [ $rc -eq 0 ] && [ "$b" = "$a" ] && [ "$(recorded)" = 1 ] && [ "$(lock_free_sql)" = 1 ]; } && ok "R4  populated + already converted: migration completes as a no-op (no refusal, no change), then is recorded" || bad "R4  rc=$rc recorded=$(recorded) same=$([ "$b" = "$a" ] && echo y || echo n) :: $(echo "$out" | tail -3)"
drive up; { [ "$DR_RES" = ok ] && [ "$(signature)" = "$a" ]; } && ok "R4b second direct run is also a no-op (lock released, same connection)" || bad "R4b RES=$DR_RES :: $DR_MSG"

# --- Recovery: with the fixtures removed, rollback and re-migrate both work ----------------------------------------------------
echo "=== R8 recovery after removing the fixtures ==="
clean_fixtures
out=$(art migrate:rollback --path="$MIG" --force); rc=$?
{ [ $rc -eq 0 ] && [ "$(state)" = legacy ] && [ "$(signature)" = "$S_LEGACY" ]; } && ok "R8a  rollback on the now-empty schema succeeds and restores the legacy schema exactly (signature identical to the pre-D3 state)" || bad "R8a  rc=$rc state=$(state) :: $(echo "$out" | tail -2)"
out=$(art migrate --path="$MIG" --force); rc=$?
{ [ $rc -eq 0 ] && [ "$(state)" = final ] && [ "$(signature)" = "$S_FINAL" ] && [ "$(lock_free_sql)" = 1 ]; } && ok "R8b  re-migrate succeeds and reproduces the final schema exactly (signature identical)" || bad "R8b  rc=$rc state=$(state) :: $(echo "$out" | tail -2)"

clean_fixtures
# remove the parent rows this script inserted (child -> parent, foreign_key_checks ON) so the database is again free of fixtures
pc=$(sql "DELETE FROM ballot_structure_snapshots WHERE id=1; DELETE FROM candidacies WHERE id=1; DELETE FROM election_eligibility_snapshots WHERE id=1; DELETE FROM candidate_roster_snapshots WHERE id=1; DELETE FROM candidates WHERE id=1; DELETE FROM contests WHERE id=1; DELETE FROM election_config_versions WHERE id=1; DELETE FROM elections WHERE id=1; DELETE FROM students WHERE id=1; DELETE FROM admin_users WHERE id=1;")
if printf '%s' "$pc" | grep -q "^ERROR"; then bad "R9  parent fixtures could not be removed: $pc"; else ok "R9  parent fixtures removed child -> parent with foreign_key_checks ON; database left free of fixtures (schema: $(state))"; fi
# R9b: every fixture table of the Phase 01B harness must be empty (the harness refuses to start otherwise). FAIL if any is not.
HARNESS_FT=$(sed -n 's/^FIXTURE_TABLES="\(.*\)"$/\1/p' database/verification/phase1b_integrity_tests.sh)
FIXTURE_TABLES="result_snapshots result_aggregates result_calculation_runs ballot_reporting_contexts ballot_candidate_selections ballot_contest_responses ballots ballot_events ballot_dispositions voter_participations submission_attempts ballot_structure_snapshots candidacies candidate_roster_snapshots election_eligibility_snapshots candidates contests parties representation_groups election_config_versions elections student_enrollments import_batches students admin_users election_incidents"
if [ "$HARNESS_FT" != "$FIXTURE_TABLES" ]; then bad "R9b  fixture-table list differs from the Phase 01B harness FIXTURE_TABLES (harness: '$HARNESS_FT')"; fi
resid=""; nft=0
for t in $HARNESS_FT; do nft=$((nft+1)); n=$(sql "SELECT COUNT(*) FROM \`$t\`"); [ "$n" = "0" ] || resid="$resid $t($n)"; done
if [ -z "$resid" ] && [ "$nft" -gt 0 ]; then ok "R9b  all $nft Phase 01B FIXTURE_TABLES hold zero rows; the Phase 01B harness can start"; else bad "R9b  residual fixture rows (the Phase 01B harness would refuse to start):${resid:- list could not be read}"; fi
echo; echo "Final state: $(state); Domain B row counts: $(counts_str)"
echo "RESULT: $PASS passed, $FAIL failed  [$glabel]"; [ "$FAIL" -eq 0 ]
