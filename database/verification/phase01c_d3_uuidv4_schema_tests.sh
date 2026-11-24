#!/usr/bin/env bash
# Phase 01C / D3 schema checks (READ-ONLY: SELECT / SHOW against information_schema only; writes nothing).
# Usage:  DB=buksu_p1c_throwaway_test MYSQL="mysql -uroot -h127.0.0.1 -P3307" bash database/verification/phase01c_d3_uuidv4_schema_tests.sh
# There is no default database. The script prints the server version, port, hostname and SELECT DATABASE() and labels
# the server GATE (MariaDB 10.4.32 on port 3307) or NON-GATE (exploratory). Set REQUIRE_GATE_SERVER=YES to refuse
# to run on anything but the gate server. It proves schema SHAPE only; it makes no claim about identifier generation.
DB="${DB:-}"
MYSQL="${MYSQL:-mysql -uroot}"
PASS=0; FAIL=0
q() { $MYSQL -D "$DB" -N -e "$1" 2>&1 | tr -d '\r'; }
pass() { echo "PASS  $1"; PASS=$((PASS+1)); }
fail() { echo "FAIL  $1"; FAIL=$((FAIL+1)); }
refuse() { echo "REFUSING TO RUN: $1" >&2; exit 2; }
[ -n "$DB" ] || refuse "DB is not set. This script has no default database."
ident=$($MYSQL -D "$DB" -N -e "SELECT DATABASE()" 2>&1 | tr -d '\r')
[ "$ident" = "$DB" ] || refuse "SELECT DATABASE() returned '${ident}', expected '$DB'."
srv=$($MYSQL -D "$DB" -N -e "SELECT VERSION(), @@port, @@hostname" 2>&1 | tr -d '\r')
ver=$(printf '%s' "$srv" | cut -f1); port=$(printf '%s' "$srv" | cut -f2); host=$(printf '%s' "$srv" | cut -f3)
case "$ver" in 10.4.32-MariaDB*) [ "$port" = "3307" ] && label="GATE SERVER (MariaDB 10.4.32, port 3307)" || label="NON-GATE SERVER (10.4.32, port $port)";; *) label="NON-GATE SERVER (exploratory only)";; esac
echo "SERVER: version=$ver port=$port host=$host database=$ident :: $label"
if [ "${REQUIRE_GATE_SERVER:-}" = "YES" ]; then case "$label" in GATE*) ;; *) refuse "REQUIRE_GATE_SERVER=YES but this is not the gate server.";; esac; fi

COLS="ballots.id ballot_contest_responses.id ballot_events.id ballot_dispositions.id ballot_contest_responses.ballot_id ballot_candidate_selections.ballot_contest_response_id ballot_reporting_contexts.ballot_id ballot_events.ballot_id ballot_dispositions.ballot_id"

echo "=== D3-01 column types ==="
bad=""
for ref in $COLS; do
  got=$(q "SELECT LOWER(DATA_TYPE), CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='${ref%%.*}' AND COLUMN_NAME='${ref#*.}'" | tr '\t' ' ')
  [ "$got" = "char 36 NO" ] || bad="$bad [$ref: '$got']"
done
[ -z "$bad" ] && pass "D3-01  the 4 Domain B primary keys and 5 dependent columns are CHAR(36) NOT NULL" || fail "D3-01 ->$bad"

echo "=== D3-02 Domain A unchanged ==="
bad=""
for ref in voter_participations.id submission_attempts.id; do
  got=$(q "SELECT LOWER(DATA_TYPE), CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='${ref%%.*}' AND COLUMN_NAME='${ref#*.}'" | tr '\t' ' ')
  [ "$got" = "char 26" ] || bad="$bad [$ref: '$got']"
done
[ -z "$bad" ] && pass "D3-02  voter_participations.id and submission_attempts.id remain CHAR(26) ULID" || fail "D3-02 ->$bad"

echo "=== D3-03 foreign keys (5 reference the converted columns; every one preserved with identical columns and RESTRICT) ==="
# name|table|columns|referenced table|referenced columns
FKS="bcr_ballot_same_election_fk|ballot_contest_responses|ballot_id,election_id|ballots|id,election_id
bcs_response_contest_match_foreign|ballot_candidate_selections|ballot_contest_response_id,contest_id|ballot_contest_responses|id,contest_id
brc_ballot_same_election_fk|ballot_reporting_contexts|ballot_id,election_id|ballots|id,election_id
ballot_events_ballot_id_foreign|ballot_events|ballot_id|ballots|id
ballot_dispositions_ballot_id_foreign|ballot_dispositions|ballot_id|ballots|id
bcr_contest_same_election_fk|ballot_contest_responses|contest_id,election_id|contests|id,election_id
bcs_candidacy_contest_membership_foreign|ballot_candidate_selections|candidacy_id,contest_id|candidacies|id,contest_id
ballot_events_related_incident_id_foreign|ballot_events|related_incident_id|election_incidents|id
ballot_events_related_result_calculation_run_id_foreign|ballot_events|related_result_calculation_run_id|result_calculation_runs|id
ballot_events_actor_id_foreign|ballot_events|actor_id|admin_users|id
ballot_dispositions_related_incident_id_foreign|ballot_dispositions|related_incident_id|election_incidents|id
ballot_dispositions_decided_by_foreign|ballot_dispositions|decided_by|admin_users|id"
bad=""; n=0
while IFS='|' read -r name tbl cols rt rcols; do
  [ -n "$name" ] || continue; n=$((n+1))
  got=$(q "SELECT GROUP_CONCAT(k.COLUMN_NAME ORDER BY k.ORDINAL_POSITION), k.REFERENCED_TABLE_NAME, GROUP_CONCAT(k.REFERENCED_COLUMN_NAME ORDER BY k.ORDINAL_POSITION), r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.TABLE_NAME=k.TABLE_NAME AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA='$DB' AND k.TABLE_NAME='$tbl' AND k.CONSTRAINT_NAME='$name' GROUP BY k.REFERENCED_TABLE_NAME, r.DELETE_RULE" | tr '\t' '|')
  [ "$got" = "$cols|$rt|$rcols|RESTRICT" ] || bad="$bad [$tbl.$name: '$got']"
done <<< "$FKS"
[ -z "$bad" ] && pass "D3-03  all $n foreign keys present with expected columns, referenced columns and ON DELETE RESTRICT" || fail "D3-03 ->$bad"

echo "=== D3-04 indexes that the conversion must preserve ==="
IDX="ballots|ballots_id_election_unique|id,election_id
ballot_contest_responses|ballot_contest_responses_ballot_id_contest_id_unique|ballot_id,contest_id
ballot_contest_responses|bcr_id_contest_unique|id,contest_id
ballot_candidate_selections|bcs_response_candidacy_unique|ballot_contest_response_id,candidacy_id
ballot_reporting_contexts|ballot_reporting_contexts_ballot_id_unique|ballot_id
ballot_events|ballot_events_ballot_id_index|ballot_id
ballot_dispositions|ballot_dispositions_ballot_id_index|ballot_id"
bad=""
while IFS='|' read -r tbl idx cols; do
  [ -n "$tbl" ] || continue
  got=$(q "SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$tbl' AND INDEX_NAME='$idx'")
  [ "$got" = "$cols" ] || bad="$bad [$tbl.$idx: '$got' != '$cols']"
done <<< "$IDX"
[ -z "$bad" ] && pass "D3-04  preserved unique / composite / lookup indexes present with expected columns" || fail "D3-04 ->$bad"

echo "=== D3-05 parent/child column compatibility (FK prerequisite) ==="
bad=""
while IFS='|' read -r pt pc ct cc; do
  a=$(q "SELECT CONCAT(COLUMN_TYPE,'/',IFNULL(CHARACTER_SET_NAME,''),'/',IFNULL(COLLATION_NAME,'')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$pt' AND COLUMN_NAME='$pc'")
  b=$(q "SELECT CONCAT(COLUMN_TYPE,'/',IFNULL(CHARACTER_SET_NAME,''),'/',IFNULL(COLLATION_NAME,'')) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$ct' AND COLUMN_NAME='$cc'")
  [ "$a" = "$b" ] && [ -n "$a" ] || bad="$bad [$pt.$pc ($a) vs $ct.$cc ($b)]"
done <<< "ballots|id|ballot_contest_responses|ballot_id
ballots|id|ballot_reporting_contexts|ballot_id
ballots|id|ballot_events|ballot_id
ballots|id|ballot_dispositions|ballot_id
ballot_contest_responses|id|ballot_candidate_selections|ballot_contest_response_id"
[ -z "$bad" ] && pass "D3-05  each referencing column has the same type, character set and collation as the key it references" || fail "D3-05 ->$bad"

echo "=== D3-06 lowercase-UUIDv4 CHECK constraints (catalogue) ==="
bad=""
for t in ballots ballot_contest_responses ballot_events ballot_dispositions; do
  cl=$(q "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND CONSTRAINT_NAME='chk_${t}_id_uuidv4'")
  if [ -z "$cl" ]; then bad="$bad [chk_${t}_id_uuidv4 missing]"; continue; fi
  for frag in '[0-9a-f]{8}' '4[0-9a-f]{3}' '[89ab]' '{12}'; do printf '%s' "$cl" | grep -qF -- "$frag" || bad="$bad [chk_${t}_id_uuidv4 lacks $frag]"; done
done
[ -z "$bad" ] && pass "D3-06  4 UUIDv4 CHECKs present (8-4-4-4-12 hex, version nibble 4, variant 8/9/a/b)" || fail "D3-06 ->$bad"

echo "=== D3-07 check evaluation enabled ==="
ccc=$(q "SELECT @@SESSION.check_constraint_checks, @@GLOBAL.check_constraint_checks" | tr '\t' ' ')
case "$ccc" in
  "1 1") pass "D3-07  check_constraint_checks session=1 global=1 (the CHECKs are evaluated; a session can still switch this off, so the CHECK is a database-level guard, not an unconditional security boundary)";;
  ERROR*) case "$ver" in *MariaDB*) fail "D3-07  could not read check_constraint_checks on MariaDB: $ccc";; *) pass "D3-07  no check_constraint_checks variable on this engine (CHECK enforced unless NOT ENFORCED)";; esac;;
  *) fail "D3-07  check_constraint_checks is not enabled (session/global='$ccc')";;
esac

echo "=== D3-08 InnoDB ==="
bad=$(q "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions') AND ENGINE<>'InnoDB'" | tr '\n' ' ')
[ -z "$bad" ] && pass "D3-08  all six Domain B tables are InnoDB" || fail "D3-08 not InnoDB: $bad"

echo; echo "RESULT: $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]
