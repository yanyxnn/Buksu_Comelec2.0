#!/usr/bin/env bash
# Phase 1B schema integrity tests (pure SQL against the migrated MySQL schema).
# Usage:  DB=buksu_p1c_throwaway_test ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES \
#         MYSQL="mysql -uroot -h127.0.0.1 -P3307" bash database/verification/phase1b_integrity_tests.sh
# SAFETY (Phase 01C, D3-D4): this script INSERTS fixtures and finally TRUNCATEs the fixture tables (with
#   FOREIGN_KEY_CHECKS=0, for cleanup only). It therefore runs ONLY against the exact disposable database
#   'buksu_p1c_throwaway_test'. There is no default database. It refuses unless: DB is set; DB is exactly the
#   allowed name (and is not a known application / Phase 04 database); ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES;
#   SELECT DATABASE() on the live connection equals DB; and every fixture table is empty. It prints the server
#   version, port and hostname and labels the server GATE / NON-GATE (gate = MariaDB 10.4.32 on port 3307).
#   Opt-in strict gate: REQUIRE_GATE_SERVER=YES refuses unless the server is MariaDB 10.4.32 on port 3307
#   (MariaDB 10.11 remains usable as exploratory testing when it is not set).
# Each negative test asserts WHICH constraint rejected the row, so a test cannot
# pass by failing for the wrong reason.
#
# ENUM domain integrity (23 approved ENUM columns) is proven on two independent layers:
#   1. BEHAVIOUR (enum_guard, one per ENUM column): invalid value -> database rejects it -> row NOT
#      persisted, under four sql_mode variants; every actual ENUM member is accepted. No error text.
#   2. STRUCTURE (meta-A/B/C, portable MySQL 8 + MariaDB): every ENUM has a CHECK named
#      chk_<table>_<column> whose member SET equals the ENUM member SET; nothing else is a CHECK, except the
#      explicitly approved Phase 02 extensions (PHASE2_ENUMS / PHASE2_EXTRA_CHECKS), which are verified
#      separately so the Phase 01B baseline (23 ENUMs) stays provably intact.
# Portability rule: metadata is read ONLY from columns present in both engines
#   (information_schema.COLUMNS; information_schema.CHECK_CONSTRAINTS.CONSTRAINT_SCHEMA /
#    CONSTRAINT_NAME / CHECK_CLAUSE). CHECK clauses are never compared as raw strings: quoted members
#   are extracted and compared as unordered sets (MySQL renders _utf8mb4'X', MariaDB renders 'X').
EXPECTED_ENUM_COUNT=23   # approved Phase 01B BASELINE; election_incidents.severity is deliberately NOT an ENUM
# Approved Phase 02 schema EXTENSIONS: intentional additions made after Phase 01B was completed. They are NOT
# part of the 23-ENUM baseline and are reported separately (meta-A2/B2/C2); they are never counted as
# Phase 01B regressions, and nothing outside baseline + this list is tolerated. Each must stay present.
PHASE2_ENUMS=(change_requests.status)
PHASE2_EXTRA_CHECKS=(chk_admin_users_role chk_change_requests_decision_state chk_change_requests_no_self_decision)
# Approved Phase 01C (D3) schema EXTENSIONS: lowercase-UUIDv4 shape CHECKs on the four Domain B primary keys,
# added by 2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4. Also not part of the 23-ENUM baseline;
# verified separately (meta-C3 and the D3 section) and never counted as Phase 01B regressions.
PHASE1C_D3_EXTRA_CHECKS=(chk_ballots_id_uuidv4 chk_ballot_contest_responses_id_uuidv4 chk_ballot_events_id_uuidv4 chk_ballot_dispositions_id_uuidv4)
DB="${DB:-}"   # deliberately NO default: this script is destructive to its fixture tables
MYSQL="${MYSQL:-mysql -uroot}"
PASS=0; FAIL=0
q()  { $MYSQL -D "$DB" -N -e "$1" 2>&1 | tr -d '\r'; }
ok() { # name sql
  out=$(q "$2"); if [ -z "$out" ] || ! echo "$out" | grep -q "^ERROR"; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FAIL  $1  -> unexpected: $out"; FAIL=$((FAIL+1)); fi; }
no() { # name sql expected-substring
  out=$(q "$2"); if echo "$out" | grep -q "^ERROR" && echo "$out" | grep -q "$3"; then echo "PASS  $1  [rejected by: $3]"; PASS=$((PASS+1)); else echo "FAIL  $1  (expected rejection by '$3') got: ${out:-<accepted>}"; FAIL=$((FAIL+1)); fi; }
empty() { out=$(q "$2"); if [ -z "$out" ]; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FAIL  $1 -> $out"; FAIL=$((FAIL+1)); fi; }

# ---- Domain-value (ENUM) closure: behavioural layer -------------------------------------------
# Invariant proven for every ENUM column:   INVALID VALUE -> DATABASE REJECTS IT -> ROW NOT PERSISTED.
# Asserts BEHAVIOUR only: never error text, never CHECK metadata.
#
# enum_guard TABLE COLUMN BAD INSERT_SQL COUNT_SQL VALUE_SQL DELETE_SQL
#   Templates use @K@ (unique 3-char string key), @N@ (unique numeric key: 900-903 attempts, 909
#   control) and @V@ (the enum value under test).
#   Negative: BAD is attempted under four sql_mode variants. The three ordinary variants (server
#     default / '' / STRICT_ALL_TABLES) must ERROR and leave 0 rows. Under INSERT IGNORE the engines
#     legitimately differ (MariaDB errors; MySQL 8 downgrades a CHECK violation to a warning and
#     skips the row), so only the invariant itself is asserted there: 0 rows persisted.
#   Positive: EVERY member of the ENUM, read at runtime from information_schema.COLUMNS, must be
#     accepted and read back exactly (catches a CHECK narrower than its ENUM).
GUARDED=()
enum_values() { q "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$1' AND COLUMN_NAME='$2' AND DATA_TYPE='enum'" | grep -o "'[^']*'" | tr -d "'"; }
enum_guard() {
  local table="$1" col="$2" bad="$3" ins="$4" cnt="$5" val="$6" del="$7"
  local name="$table.$col" why="" i k num u sql out n v cnt2 val2 del2 vals nvals=0
  GUARDED+=("$name")
  local -a pre=("" "SET SESSION sql_mode='';" "SET SESSION sql_mode='STRICT_ALL_TABLES';" "SET SESSION sql_mode='';")
  local -a lab=("server-default" "permissive" "strict" "permissive+IGNORE")
  for i in 0 1 2 3; do
    k="B0$i"; num=$((900+i)); u=$(printf '%012x' "$num"); sql="${ins//@K@/$k}"; sql="${sql//@N@/$num}"; sql="${sql//@U@/$u}"; sql="${sql//@V@/$bad}"
    [ "$i" = 3 ] && sql="${sql/INSERT INTO/INSERT IGNORE INTO}"
    cnt2="${cnt//@K@/$k}"; cnt2="${cnt2//@N@/$num}"; cnt2="${cnt2//@U@/$u}"; del2="${del//@K@/$k}"; del2="${del2//@N@/$num}"; del2="${del2//@U@/$u}"
    out=$(q "${pre[$i]} $sql"); n=$(q "$cnt2")
    if [ "$i" != 3 ]; then echo "$out" | grep -q "^ERROR" || why="$why [${lab[$i]}: statement not rejected]"; fi
    [ "$n" = "0" ] || why="$why [${lab[$i]}: row persisted, count=$n]"
    q "$del2" >/dev/null
  done
  vals=$(enum_values "$table" "$col")
  [ -n "$vals" ] || why="$why [could not read ENUM values from the catalog]"
  echo "$vals" | grep -qxF "$bad" && why="$why [test error: BAD value '$bad' is a member of the ENUM]"
  k="G00"; num=909
  for v in $vals; do
    nvals=$((nvals+1))
    u=$(printf '%012x' "$num"); sql="${ins//@K@/$k}"; sql="${sql//@N@/$num}"; sql="${sql//@U@/$u}"; sql="${sql//@V@/$v}"
    cnt2="${cnt//@K@/$k}"; cnt2="${cnt2//@N@/$num}"; cnt2="${cnt2//@U@/$u}"; val2="${val//@K@/$k}"; val2="${val2//@N@/$num}"; val2="${val2//@U@/$u}"; del2="${del//@K@/$k}"; del2="${del2//@N@/$num}"; del2="${del2//@U@/$u}"
    out=$(q "$sql"); n=$(q "$cnt2"); got=$(q "$val2")
    if echo "$out" | grep -q "^ERROR" || [ "$n" != "1" ] || [ "$got" != "$v" ]; then why="$why [valid '$v' not stored exactly: out='$out' count=$n value='$got']"; fi
    q "$del2" >/dev/null
  done
  if [ -z "$why" ]; then echo "PASS  $name  [invalid rejected, 0 rows persisted in 4 modes; all $nvals valid values accepted]"; PASS=$((PASS+1))
  else echo "FAIL  $name ->$why"; FAIL=$((FAIL+1)); fi
}

# ---- Safety gate (Phase 01C, D3-D4) -- runs before ANY statement is sent to the server beyond identity checks ----
ALLOWED_DB="buksu_p1c_throwaway_test"
DENIED_DBS="buksu_comelec2.0 buksu_comelec_v2 comelec_phase04c_104_test"
gate_refuse() { echo "REFUSING TO RUN: $1" >&2; exit 2; }
[ -n "$DB" ] || gate_refuse "DB is not set. This script has no default database; set DB=$ALLOWED_DB."
for d in $DENIED_DBS; do [ "$DB" != "$d" ] || gate_refuse "'$DB' is an application or Phase 04 database and is explicitly denied."; done
[ "$DB" = "$ALLOWED_DB" ] || gate_refuse "'$DB' is not the allowed disposable database '$ALLOWED_DB'."
[ "${ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP:-}" = "YES" ] || gate_refuse "ALLOW_DESTRUCTIVE_FIXTURE_CLEANUP=YES is required (fixture tables are TRUNCATEd at the end)."
ident=$($MYSQL -D "$DB" -N -e "SELECT DATABASE()" 2>&1 | tr -d '\r')
[ "$ident" = "$DB" ] || gate_refuse "SELECT DATABASE() returned '${ident}', expected '$DB' (database missing, or connection not on the expected database)."
srv=$($MYSQL -D "$DB" -N -e "SELECT VERSION(), @@port, @@hostname" 2>&1 | tr -d '\r')
srv_ver=$(printf '%s' "$srv" | cut -f1); srv_port=$(printf '%s' "$srv" | cut -f2); srv_host=$(printf '%s' "$srv" | cut -f3)
case "$srv_ver" in 10.4.32-MariaDB*) [ "$srv_port" = "3307" ] && gate_label="GATE SERVER (MariaDB 10.4.32, port 3307)" || gate_label="NON-GATE SERVER (10.4.32 but port $srv_port; the required gate uses port 3307)";;
  *) gate_label="NON-GATE SERVER (exploratory only; the required gate is MariaDB 10.4.32 on port 3307)";; esac
echo "SERVER: version=$srv_ver port=$srv_port host=$srv_host database=$ident :: $gate_label"
if [ "${REQUIRE_GATE_SERVER:-}" = "YES" ]; then case "$gate_label" in "GATE SERVER"*) ;; *) gate_refuse "REQUIRE_GATE_SERVER=YES but this is not MariaDB 10.4.32 on port 3307 (version=$srv_ver port=$srv_port).";; esac; fi
FIXTURE_TABLES="result_snapshots result_aggregates result_calculation_runs ballot_reporting_contexts ballot_candidate_selections ballot_contest_responses ballots ballot_events ballot_dispositions voter_participations submission_attempts ballot_structure_snapshots candidacies candidate_roster_snapshots election_eligibility_snapshots candidates contests parties representation_groups election_config_versions elections student_enrollments import_batches students admin_users election_incidents"
nonempty=""
for t in $FIXTURE_TABLES; do n=$(q "SELECT COUNT(*) FROM \`$t\`"); [ "$n" = "0" ] || nonempty="$nonempty $t($n)"; done
[ -z "$nonempty" ] || gate_refuse "fixture table(s) are not empty:$nonempty. Nothing was changed. Use a freshly migrated, empty disposable database."
echo "ENGINE: $(q 'SELECT VERSION()') | sql_mode: $(q 'SELECT @@GLOBAL.sql_mode')"
T="NOW(),NOW()"
BALLOT1=b0000000-0000-4000-8000-000000000001   # valid lowercase UUIDv4 fixture ids (D3): Domain B ids are CHAR(36) UUIDv4-shaped
q "INSERT INTO admin_users(id,authorized_email,google_subject,display_name,created_at,updated_at) VALUES (1,'fixture.admin@example.test','g1','Admin',$T);
INSERT INTO students(id,institutional_id,institutional_email,first_name,last_name,current_college,current_course,current_year_level,status,created_at,updated_at) VALUES
 (1,'2020-0001','a@x.edu','A','One','CCS','BSIT','1','ACTIVE',$T),(2,'2020-0002','b@x.edu','B','Two','CCS','BSIT','1','ACTIVE',$T),(3,'2020-0003','c@x.edu','C','Three','CCS','BSIT','1','ACTIVE',$T);
INSERT INTO elections(id,name,type,created_by,created_at,updated_at) VALUES (1,'E1','T',1,$T),(2,'E2','T',1,$T);
INSERT INTO election_config_versions(id,election_id,version_number,proposed_by,created_at,updated_at) VALUES (1,1,1,1,$T),(2,2,1,1,$T);
INSERT INTO representation_groups(id,election_id,name,scope_definition_json,created_at,updated_at) VALUES (1,1,'rg1','{}',$T),(2,2,'rg2','{}',$T);
INSERT INTO parties(id,election_id,name,created_at,updated_at) VALUES (1,1,'p1',$T),(2,2,'p2',$T);
INSERT INTO contests(id,election_id,election_config_version_id,name,position_label,created_at,updated_at) VALUES (1,1,1,'A','P',$T),(2,1,1,'B','P',$T),(3,2,2,'X','P',$T);
INSERT INTO candidates(id,student_id,created_at,updated_at) VALUES (1,1,$T),(2,2,$T),(3,3,$T);
INSERT INTO candidate_roster_snapshots(id,election_id,election_config_version_id,version_number,created_at,updated_at) VALUES (1,1,1,1,$T),(2,2,2,1,$T);
INSERT INTO election_eligibility_snapshots(id,election_id,election_config_version_id,rule_definition_json,created_at,updated_at) VALUES (1,1,1,'{}',$T),(2,2,2,'{}',$T);
INSERT INTO candidacies(id,candidate_id,election_id,contest_id,display_name,created_at,updated_at) VALUES (1,1,1,1,'cA',$T),(2,2,1,2,'cB',$T),(3,3,2,3,'cX',$T);
INSERT INTO ballot_structure_snapshots(id,election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,created_at,updated_at) VALUES (1,1,1,1,1,1,$T),(2,2,2,2,2,1,$T);
INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('$BALLOT1',1,1,NOW());
INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('c0000000-0000-4000-8000-00000000000a','$BALLOT1',1,1,'VOTE');
INSERT INTO result_calculation_runs(id,election_id,algorithm_version,created_at,updated_at) VALUES (1,1,'v1',$T),(2,2,'v1',$T);
INSERT INTO import_batches(id,source_filename,academic_year,semester,uploaded_by,created_at,updated_at) VALUES (1,'f1.csv','2026-2027','1st',1,$T),(2,'f2.csv','2026-2027','1st',1,$T);" >/dev/null

RA=c0000000-0000-4000-8000-00000000000a
echo "=== A/B/C: response - selection - candidacy contest agreement ==="
no  "A  response=ContestA, selection=ContestB (candidacy B)"               "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$RA',2,2)" bcs_response_contest_match_foreign
no  "A2 response=ContestA, selection=ContestB, candidacy belongs to A"     "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$RA',2,1)" bcs_response_contest_match_foreign
no  "B  response=A, selection declares A, candidacy belongs to B"          "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$RA',1,2)" bcs_candidacy_contest_membership_foreign
ok  "C  response=A, selection=A, candidacy=A"                              "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$RA',1,1)"
no  "   duplicate candidacy in same response"                              "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$RA',1,1)" bcs_response_candidacy_unique

echo "=== Cross-election: configuration ==="
no  "contest election=1 with config version of election 2"                 "INSERT INTO contests(election_id,election_config_version_id,name,position_label,created_at,updated_at) VALUES (1,2,'bad','P',$T)" contests_cv_same_election_fk
no  "contest with representation group of another election"                "INSERT INTO contests(election_id,election_config_version_id,name,position_label,representation_group_id,created_at,updated_at) VALUES (1,1,'bad','P',2,$T)" contests_rg_same_election_fk
ok  "contest with same-election representation group (control)"            "INSERT INTO contests(election_id,election_config_version_id,name,position_label,representation_group_id,created_at,updated_at) VALUES (1,1,'ok','P',1,$T)"
no  "elections.current_config_version_id -> other election's version"      "UPDATE elections SET current_config_version_id=2 WHERE id=1" elections_current_cv_own_election_fk
no  "elections.locked_config_version_id  -> other election's version"      "UPDATE elections SET locked_config_version_id=2 WHERE id=1" elections_locked_cv_own_election_fk
ok  "elections.current_config_version_id -> own version (control)"         "UPDATE elections SET current_config_version_id=1 WHERE id=1"
echo "=== Cross-election: candidates / snapshots ==="
no  "candidacy election=2 pointing at contest of election 1"               "INSERT INTO candidacies(candidate_id,election_id,contest_id,display_name,created_at,updated_at) VALUES (3,2,1,'bad',$T)" candidacies_contest_same_election_fk
no  "candidacy with party of another election"                             "INSERT INTO candidacies(candidate_id,election_id,contest_id,party_id,display_name,created_at,updated_at) VALUES (3,1,1,2,'bad',$T)" candidacies_party_same_election_fk
no  "candidacy with roster snapshot of another election"                   "INSERT INTO candidacies(candidate_id,election_id,contest_id,roster_snapshot_id,display_name,created_at,updated_at) VALUES (3,1,1,2,'bad',$T)" candidacies_roster_same_election_fk
ok  "candidacy with same-election party + roster (control)"                "INSERT INTO candidacies(candidate_id,election_id,contest_id,party_id,roster_snapshot_id,display_name,created_at,updated_at) VALUES (3,1,1,1,1,'ok',$T)"
no  "roster snapshot: election 1 with config version of election 2"        "INSERT INTO candidate_roster_snapshots(election_id,election_config_version_id,version_number,created_at,updated_at) VALUES (1,2,9,$T)" crs_cv_same_election_fk
no  "eligibility snapshot: election 1 with config version of election 2"   "INSERT INTO election_eligibility_snapshots(election_id,election_config_version_id,rule_definition_json,created_at,updated_at) VALUES (1,2,'{}',$T)" ees_cv_same_election_fk
no  "structure snapshot with other election's config version"              "INSERT INTO ballot_structure_snapshots(election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,created_at,updated_at) VALUES (1,2,1,1,7,$T)" bss_cv_same_election_fk
no  "structure snapshot with other election's roster snapshot"             "INSERT INTO ballot_structure_snapshots(election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,created_at,updated_at) VALUES (1,1,2,1,7,$T)" bss_roster_same_election_fk
no  "structure snapshot with other election's eligibility snapshot"        "INSERT INTO ballot_structure_snapshots(election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,created_at,updated_at) VALUES (1,1,1,2,7,$T)" bss_eligibility_same_election_fk
echo "=== Cross-election: ballot tree ==="
no  "ballot election=1 on structure snapshot of election 2"                "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('b0000000-0000-4000-8000-000000000009',1,2,NOW())" ballots_bss_same_election_fk
no  "response: contest of election 2 on election-1 ballot (election_id=1)" "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('c0000000-0000-4000-8000-0000000000e1','$BALLOT1',3,1,'ABSTAIN')" bcr_contest_same_election_fk
no  "response: election-1 ballot stamped election_id=2"                     "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('c0000000-0000-4000-8000-0000000000e2','$BALLOT1',3,2,'ABSTAIN')" bcr_ballot_same_election_fk
no  "reporting context: election-1 ballot stamped election_id=2"           "INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('$BALLOT1',2,'CCS')" brc_ballot_same_election_fk
ok  "reporting context: same election (control)"                           "INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('$BALLOT1',1,'CCS')"
echo "=== Cross-election: results ==="
no  "aggregate: run of election 1 stamped election_id=2"                   "INSERT INTO result_aggregates(result_calculation_run_id,election_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,2,'OVERALL_CAST_COUNT','OVERALL',1,$T)" ra_run_same_election_fk
no  "aggregate: contest of election 2 on election-1 run"                   "INSERT INTO result_aggregates(result_calculation_run_id,election_id,contest_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,3,'CONTEST_ABSTAIN_COUNT','OVERALL',1,$T)" ra_contest_same_election_fk
no  "aggregate: candidacy of election 2 on election-1 run"                 "INSERT INTO result_aggregates(result_calculation_run_id,election_id,candidacy_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,3,'CANDIDATE_VOTE_COUNT','OVERALL',1,$T)" ra_candidacy_same_election_fk
no  "aggregate: candidacy A stated under contest B (same election)"        "INSERT INTO result_aggregates(result_calculation_run_id,election_id,contest_id,candidacy_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,2,1,'CANDIDATE_VOTE_COUNT','OVERALL',1,$T)" ra_candidacy_contest_match_fk
ok  "aggregate: valid candidate slice (control)"                           "INSERT INTO result_aggregates(result_calculation_run_id,election_id,contest_id,candidacy_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,1,1,'CANDIDATE_VOTE_COUNT','OVERALL',1,$T)"
ok  "aggregate: election-wide slice, NULL contest/candidacy (control)"     "INSERT INTO result_aggregates(result_calculation_run_id,election_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,'OVERALL_CAST_COUNT','OVERALL',1,$T)"
no  "aggregate: duplicate NULL-dimension slice"                            "INSERT INTO result_aggregates(result_calculation_run_id,election_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (1,1,'OVERALL_CAST_COUNT','OVERALL',5,$T)" result_aggregates_slice_unique
no  "result snapshot: run of election 1 stamped election 2"                "INSERT INTO result_snapshots(election_id,result_calculation_run_id,snapshot_json,created_at,updated_at) VALUES (2,1,'{}',$T)" rs_run_same_election_fk
ok  "result snapshot: same election (control)"                             "INSERT INTO result_snapshots(election_id,result_calculation_run_id,snapshot_json,created_at,updated_at) VALUES (1,1,'{}',$T)"
echo "=== Regression: earlier approved constraints ==="
no  "duplicate (ballot, contest) response"                                 "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('c0000000-0000-4000-8000-0000000000d1','$BALLOT1',1,1,'ABSTAIN')" ballot_contest_responses_ballot_id_contest_id_unique
enum_guard ballot_contest_responses response_type SKIP \
  "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('c0000000-0000-4000-8000-@U@','$BALLOT1',2,1,'@V@')" \
  "SELECT COUNT(*) FROM ballot_contest_responses WHERE id='c0000000-0000-4000-8000-@U@'" \
  "SELECT response_type FROM ballot_contest_responses WHERE id='c0000000-0000-4000-8000-@U@'" \
  "DELETE FROM ballot_contest_responses WHERE id='c0000000-0000-4000-8000-@U@'"
enum_guard ballots status VOIDED \
  "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at,status) VALUES ('b0000000-0000-4000-8000-@U@',1,1,NOW(),'@V@')" \
  "SELECT COUNT(*) FROM ballots WHERE id='b0000000-0000-4000-8000-@U@'" \
  "SELECT status FROM ballots WHERE id='b0000000-0000-4000-8000-@U@'" \
  "DELETE FROM ballots WHERE id='b0000000-0000-4000-8000-@U@'"
enum_guard students status IRREGULAR \
  "INSERT INTO students(institutional_id,institutional_email,first_name,last_name,current_college,current_course,current_year_level,status,created_at,updated_at) VALUES ('enum-@K@','enum-@K@@x','x','x','c','c','1','@V@',NOW(),NOW())" \
  "SELECT COUNT(*) FROM students WHERE institutional_id='enum-@K@'" \
  "SELECT status FROM students WHERE institutional_id='enum-@K@'" \
  "DELETE FROM students WHERE institutional_id='enum-@K@'"
no  "duplicate institutional_id"                                           "INSERT INTO students(institutional_id,institutional_email,first_name,last_name,current_college,current_course,current_year_level,status,created_at,updated_at) VALUES ('2020-0001','z@x','x','x','c','c','1','ACTIVE',$T)" students_institutional_id_unique
ok  "first participation (control)"                                        "INSERT INTO voter_participations(id,election_id,student_id,submission_uuid,participated_at,receipt_code,created_at,updated_at) VALUES ('01P00000000000000000000001',1,1,'sub-1',NOW(),'rc-1',$T)"
no  "second participation, same election+student"                          "INSERT INTO voter_participations(id,election_id,student_id,submission_uuid,participated_at,receipt_code,created_at,updated_at) VALUES ('01P00000000000000000000002',1,1,'sub-2',NOW(),'rc-2',$T)" voter_participations_election_id_student_id_unique
no  "duplicate submission_uuid in participations"                          "INSERT INTO voter_participations(id,election_id,student_id,submission_uuid,participated_at,receipt_code,created_at,updated_at) VALUES ('01P00000000000000000000003',1,2,'sub-1',NOW(),'rc-3',$T)" voter_participations_submission_uuid_unique
ok  "submission attempt (control)"                                         "INSERT INTO submission_attempts(id,submission_uuid,election_id,student_id,created_at,updated_at) VALUES ('01S00000000000000000000001','s-1',1,1,$T)"
no  "duplicate submission_uuid in submission_attempts"                     "INSERT INTO submission_attempts(id,submission_uuid,election_id,student_id,created_at,updated_at) VALUES ('01S00000000000000000000002','s-1',1,2,$T)" submission_attempts_submission_uuid_unique
echo "=== Data Center import history ==="
ok  "enrollment: student 1 in batch 1 (control)"                          "INSERT INTO student_enrollments(student_id,import_batch_id,college,course,year_level,status,effective_from,created_at,updated_at) VALUES (1,1,'CCS','BSIT','1','ACTIVE',NOW(),$T)"
no  "enrollment: same student twice in the same batch"                     "INSERT INTO student_enrollments(student_id,import_batch_id,college,course,year_level,status,effective_from,created_at,updated_at) VALUES (1,1,'CCS','BSIT','2','ACTIVE',NOW(),$T)" student_enrollments_import_batch_id_student_id_unique
ok  "enrollment: same student in a LATER batch (history preserved)"        "INSERT INTO student_enrollments(student_id,import_batch_id,college,course,year_level,status,effective_from,created_at,updated_at) VALUES (1,2,'CCS','BSIT','2','ACTIVE',NOW(),$T)"
ok  "enrollment: different student in the same batch"                      "INSERT INTO student_enrollments(student_id,import_batch_id,college,course,year_level,status,effective_from,created_at,updated_at) VALUES (2,1,'CCS','BSIT','1','ACTIVE',NOW(),$T)"
echo "=== Domain-value closure: remaining ENUM columns (invalid -> rejected -> not persisted) ==="
enum_guard import_batches status CANCELLED \
  "INSERT INTO import_batches(id,source_filename,academic_year,semester,uploaded_by,status,created_at,updated_at) VALUES (@N@,'enum-@K@','2026-2027','1st',1,'@V@',$T)" \
  "SELECT COUNT(*) FROM import_batches WHERE id=@N@" \
  "SELECT status FROM import_batches WHERE id=@N@" \
  "DELETE FROM import_batches WHERE id=@N@"
enum_guard elections state ARCHIVED \
  "INSERT INTO elections(id,name,type,state,created_by,created_at,updated_at) VALUES (@N@,'enum-@K@','T','@V@',1,$T)" \
  "SELECT COUNT(*) FROM elections WHERE id=@N@" \
  "SELECT state FROM elections WHERE id=@N@" \
  "DELETE FROM elections WHERE id=@N@"
enum_guard student_enrollments status IRREGULAR \
  "INSERT INTO student_enrollments(id,student_id,import_batch_id,college,course,year_level,status,effective_from,created_at,updated_at) VALUES (@N@,3,1,'CCS','BSIT','1','@V@',NOW(),$T)" \
  "SELECT COUNT(*) FROM student_enrollments WHERE id=@N@" \
  "SELECT status FROM student_enrollments WHERE id=@N@" \
  "DELETE FROM student_enrollments WHERE id=@N@"
enum_guard import_batch_rows classification SKIPPED \
  "INSERT INTO import_batch_rows(id,import_batch_id,institutional_id,classification,raw_row_json,created_at,updated_at) VALUES (@N@,1,'enum-@K@','@V@','{}',$T)" \
  "SELECT COUNT(*) FROM import_batch_rows WHERE id=@N@" \
  "SELECT classification FROM import_batch_rows WHERE id=@N@" \
  "DELETE FROM import_batch_rows WHERE id=@N@"
enum_guard election_config_versions status REJECTED \
  "INSERT INTO election_config_versions(id,election_id,version_number,status,proposed_by,created_at,updated_at) VALUES (@N@,1,@N@,'@V@',1,$T)" \
  "SELECT COUNT(*) FROM election_config_versions WHERE id=@N@" \
  "SELECT status FROM election_config_versions WHERE id=@N@" \
  "DELETE FROM election_config_versions WHERE id=@N@"
enum_guard contest_rules voting_method RANKED_CHOICE \
  "INSERT INTO contest_rules(id,contest_id,seat_count,selection_limit,voting_method,created_at,updated_at) VALUES (@N@,3,1,1,'@V@',$T)" \
  "SELECT COUNT(*) FROM contest_rules WHERE id=@N@" \
  "SELECT voting_method FROM contest_rules WHERE id=@N@" \
  "DELETE FROM contest_rules WHERE id=@N@"
enum_guard election_eligibility_snapshots status OPEN \
  "INSERT INTO election_eligibility_snapshots(id,election_id,election_config_version_id,status,rule_definition_json,created_at,updated_at) VALUES (@N@,1,1,'@V@','{}',$T)" \
  "SELECT COUNT(*) FROM election_eligibility_snapshots WHERE id=@N@" \
  "SELECT status FROM election_eligibility_snapshots WHERE id=@N@" \
  "DELETE FROM election_eligibility_snapshots WHERE id=@N@"
enum_guard election_eligible_voters status IRREGULAR \
  "INSERT INTO election_eligible_voters(id,eligibility_snapshot_id,student_id,college,course,year_level,status,source,created_at,updated_at) VALUES (@N@,1,3,'CCS','BSIT','1','@V@','enum-@K@',$T)" \
  "SELECT COUNT(*) FROM election_eligible_voters WHERE id=@N@" \
  "SELECT status FROM election_eligible_voters WHERE id=@N@" \
  "DELETE FROM election_eligible_voters WHERE id=@N@"
enum_guard candidate_roster_snapshots status PUBLISHED \
  "INSERT INTO candidate_roster_snapshots(id,election_id,election_config_version_id,version_number,status,created_at,updated_at) VALUES (@N@,1,1,@N@,'@V@',$T)" \
  "SELECT COUNT(*) FROM candidate_roster_snapshots WHERE id=@N@" \
  "SELECT status FROM candidate_roster_snapshots WHERE id=@N@" \
  "DELETE FROM candidate_roster_snapshots WHERE id=@N@"
enum_guard candidacies status ARCHIVED \
  "INSERT INTO candidacies(id,candidate_id,election_id,contest_id,display_name,status,created_at,updated_at) VALUES (@N@,2,1,1,'enum-@K@','@V@',$T)" \
  "SELECT COUNT(*) FROM candidacies WHERE id=@N@" \
  "SELECT status FROM candidacies WHERE id=@N@" \
  "DELETE FROM candidacies WHERE id=@N@"
enum_guard ballot_structure_snapshots status ACTIVE \
  "INSERT INTO ballot_structure_snapshots(id,election_id,election_config_version_id,candidate_roster_snapshot_id,eligibility_snapshot_id,version_number,status,created_at,updated_at) VALUES (@N@,1,1,1,1,@N@,'@V@',$T)" \
  "SELECT COUNT(*) FROM ballot_structure_snapshots WHERE id=@N@" \
  "SELECT status FROM ballot_structure_snapshots WHERE id=@N@" \
  "DELETE FROM ballot_structure_snapshots WHERE id=@N@"
enum_guard submission_attempts status CANCELLED \
  "INSERT INTO submission_attempts(id,submission_uuid,election_id,student_id,status,created_at,updated_at) VALUES ('01S00000000000000000000@K@','enum-@K@',1,3,'@V@',$T)" \
  "SELECT COUNT(*) FROM submission_attempts WHERE id='01S00000000000000000000@K@'" \
  "SELECT status FROM submission_attempts WHERE id='01S00000000000000000000@K@'" \
  "DELETE FROM submission_attempts WHERE id='01S00000000000000000000@K@'"
enum_guard outbox_events status SENT \
  "INSERT INTO outbox_events(id,event_type,payload_json,status,occurred_at,created_at,updated_at) VALUES (@N@,'enum-test','{}','@V@',NOW(),$T)" \
  "SELECT COUNT(*) FROM outbox_events WHERE id=@N@" \
  "SELECT status FROM outbox_events WHERE id=@N@" \
  "DELETE FROM outbox_events WHERE id=@N@"
enum_guard result_calculation_runs status CANCELLED \
  "INSERT INTO result_calculation_runs(id,election_id,algorithm_version,status,created_at,updated_at) VALUES (@N@,1,'v1','@V@',$T)" \
  "SELECT COUNT(*) FROM result_calculation_runs WHERE id=@N@" \
  "SELECT status FROM result_calculation_runs WHERE id=@N@" \
  "DELETE FROM result_calculation_runs WHERE id=@N@"
enum_guard result_aggregates metric_type TURNOUT \
  "INSERT INTO result_aggregates(id,result_calculation_run_id,election_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (@N@,2,2,'@V@','OVERALL',1,$T)" \
  "SELECT COUNT(*) FROM result_aggregates WHERE id=@N@" \
  "SELECT metric_type FROM result_aggregates WHERE id=@N@" \
  "DELETE FROM result_aggregates WHERE id=@N@"
enum_guard result_aggregates dimension_type PROGRAM \
  "INSERT INTO result_aggregates(id,result_calculation_run_id,election_id,metric_type,dimension_type,count,created_at,updated_at) VALUES (@N@,1,1,'OVERALL_PARTICIPATION_COUNT','@V@',1,$T)" \
  "SELECT COUNT(*) FROM result_aggregates WHERE id=@N@" \
  "SELECT dimension_type FROM result_aggregates WHERE id=@N@" \
  "DELETE FROM result_aggregates WHERE id=@N@"
enum_guard result_aggregates denominator_type ELIGIBLE_ALL \
  "INSERT INTO result_aggregates(id,result_calculation_run_id,election_id,metric_type,dimension_type,count,denominator_type,created_at,updated_at) VALUES (@N@,1,1,'OVERALL_PARTICIPATION_COUNT','OVERALL',1,'@V@',$T)" \
  "SELECT COUNT(*) FROM result_aggregates WHERE id=@N@" \
  "SELECT denominator_type FROM result_aggregates WHERE id=@N@" \
  "DELETE FROM result_aggregates WHERE id=@N@"
enum_guard result_snapshots status DRAFT \
  "INSERT INTO result_snapshots(id,election_id,result_calculation_run_id,status,snapshot_json,created_at,updated_at) VALUES (@N@,1,1,'@V@','{}',$T)" \
  "SELECT COUNT(*) FROM result_snapshots WHERE id=@N@" \
  "SELECT status FROM result_snapshots WHERE id=@N@" \
  "DELETE FROM result_snapshots WHERE id=@N@"
enum_guard election_incidents status REOPENED \
  "INSERT INTO election_incidents(id,election_id,title,description,status,created_at,updated_at) VALUES (@N@,1,'t','d','@V@',$T)" \
  "SELECT COUNT(*) FROM election_incidents WHERE id=@N@" \
  "SELECT status FROM election_incidents WHERE id=@N@" \
  "DELETE FROM election_incidents WHERE id=@N@"
enum_guard ballot_dispositions disposition DELETED \
  "INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('d0000000-0000-4000-8000-@U@','$BALLOT1','@V@',1,NOW())" \
  "SELECT COUNT(*) FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-@U@'" \
  "SELECT disposition FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-@U@'" \
  "DELETE FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-@U@'"
echo "=== Domain-value closure: structural layer (portable MySQL 8 / MariaDB) ==="
# Quoted-member extraction, shared by ENUM and CHECK sides: prints the sorted unique quoted members.
members() { grep -o "'[^']*'" | tr -d "'" | LC_ALL=C sort -u; }
inv_all=$(q "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND DATA_TYPE='enum'" | LC_ALL=C sort)
p2e=$(printf '%s\n' "${PHASE2_ENUMS[@]}" | LC_ALL=C sort)
inv=$(comm -23 <(echo "$inv_all") <(echo "$p2e"))      # ENUM columns that are NOT approved Phase 02 extensions = Phase 01B baseline set
inv_p2=$(comm -12 <(echo "$inv_all") <(echo "$p2e"))   # approved Phase 02 ENUM extensions actually present
ninv=$(printf '%s' "$inv" | grep -c .); nP2=$(printf '%s' "$inv_p2" | grep -c .)

# meta-A (Phase 01B BASELINE): ENUM inventory (vendor-neutral discovery) == guarded set, and == the approved count.
grd=$(printf '%s\n' "${GUARDED[@]}" | LC_ALL=C sort)
unguarded=$(comm -23 <(echo "$inv") <(echo "$grd") | tr '\n' ' '); stale=$(comm -13 <(echo "$inv") <(echo "$grd") | tr '\n' ' ')
if [ "$ninv" -eq "$EXPECTED_ENUM_COUNT" ] && [ -z "$unguarded" ] && [ -z "$stale" ]; then
  echo "PASS  meta-A  [Phase 01B baseline]: ENUM inventory == guarded set == approved count ($ninv/$EXPECTED_ENUM_COUNT columns)"; PASS=$((PASS+1))
else echo "FAIL  meta-A  [Phase 01B baseline]: ENUM inventory != guarded set / approved count. found=$ninv expected=$EXPECTED_ENUM_COUNT unguarded (unapproved ENUM): [${unguarded:-none}] stale: [${stale:-none}]"; FAIL=$((FAIL+1)); fi

# meta-A2 (approved Phase 02 extensions): every approved extension ENUM is present (cannot be silently dropped).
missP2e=$(comm -13 <(echo "$inv_all") <(echo "$p2e") | tr '\n' ' ')
if [ -z "$missP2e" ] && [ "$nP2" -eq "${#PHASE2_ENUMS[@]}" ]; then
  echo "PASS  meta-A2 [Phase 02 extension]: approved ENUM extension(s) present and recognised as intentional additions ($nP2: $(printf '%s ' $inv_p2)) - not counted against the 23-ENUM baseline"; PASS=$((PASS+1))
else echo "FAIL  meta-A2 [Phase 02 extension]: approved ENUM extension missing: [${missP2e:-none}]"; FAIL=$((FAIL+1)); fi

# meta-B / meta-B2: for EVERY ENUM column, the constraint named chk_<table>_<column> (located by schema + name)
# exists exactly once, references the column, and its member SET equals the ENUM member SET.
enum_check_problems() { # $1 = newline list of table.column refs; prints the problems found (empty when none)
  local bad="" ref t c em rows nrows cm extra miss
  while IFS= read -r ref; do
    [ -n "$ref" ] || continue
    t="${ref%%.*}"; c="${ref#*.}"
    em=$(q "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$t' AND COLUMN_NAME='$c'" | members)
    # Backslashes are stripped server-side (portable SQL): MySQL 8 stores the clause with escaped quotes
    # (_utf8mb4\'X\'), MariaDB with plain quotes ('X'). Safe only because members are [A-Za-z0-9_] (checked).
    rows=$(q "SELECT REPLACE(CHECK_CLAUSE, CHAR(92), '') FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND CONSTRAINT_NAME='chk_${t}_${c}'")
    if printf '%s\n' "$em" | grep -qvE '^[A-Za-z0-9_]+$'; then bad="$bad [$ref: ENUM member outside [A-Za-z0-9_] (or empty); extractor must be extended before this comparison is trustworthy]"; continue; fi
    nrows=$(printf '%s' "$rows" | grep -c .)
    if [ "$nrows" -ne 1 ]; then bad="$bad [$ref: expected exactly 1 constraint chk_${t}_${c}, found $nrows]"; continue; fi
    printf '%s' "$rows" | grep -qF "\`$c\`" || { bad="$bad [$ref: chk_${t}_${c} does not reference column $c]"; continue; }
    cm=$(printf '%s' "$rows" | members)
    if [ -z "$em" ] || [ "$em" != "$cm" ]; then
      extra=$(comm -13 <(echo "$em") <(echo "$cm") | tr '\n' ','); miss=$(comm -23 <(echo "$em") <(echo "$cm") | tr '\n' ',')
      bad="$bad [$ref: CHECK members != ENUM members; extra-in-CHECK={${extra}} missing-from-CHECK={${miss}}]"
    fi
  done <<< "$1"
  printf '%s' "$bad"
}
bad=$(enum_check_problems "$inv")
if [ -z "$bad" ] && [ "$ninv" -gt 0 ]; then echo "PASS  meta-B  [Phase 01B baseline]: ENUM member set == CHECK member set for all $ninv baseline ENUM columns (unordered, quoted-member extraction)"; PASS=$((PASS+1))
else echo "FAIL  meta-B  [Phase 01B baseline]: ENUM vs CHECK member sets ->${bad:- [empty ENUM inventory]}"; FAIL=$((FAIL+1)); fi
badP2=$(enum_check_problems "$inv_p2")
if [ -z "$badP2" ] && [ "$nP2" -gt 0 ]; then echo "PASS  meta-B2 [Phase 02 extension]: ENUM member set == CHECK member set for the $nP2 approved Phase 02 ENUM extension(s)"; PASS=$((PASS+1))
else echo "FAIL  meta-B2 [Phase 02 extension]: ENUM vs CHECK member sets ->${badP2:- [no approved extension found]}"; FAIL=$((FAIL+1)); fi

# meta-C (Phase 01B BASELINE): the CHECK inventory contains {chk_<table>_<column> for each baseline ENUM column}:
# none missing, and NO CHECK anywhere that is neither baseline nor an approved Phase 02 extension
# (JSON-validity checks that MariaDB synthesises for JSON columns are excluded).
want=$(while IFS= read -r ref; do [ -n "$ref" ] && echo "chk_${ref%%.*}_${ref#*.}"; done <<< "$inv" | LC_ALL=C sort)
want_p2=$( { while IFS= read -r ref; do [ -n "$ref" ] && echo "chk_${ref%%.*}_${ref#*.}"; done <<< "$inv_p2"; printf '%s\n' "${PHASE2_EXTRA_CHECKS[@]}"; } | LC_ALL=C sort -u)
approved=$( { echo "$want"; echo "$want_p2"; printf '%s\n' "${PHASE1C_D3_EXTRA_CHECKS[@]}"; } | grep . | LC_ALL=C sort -u)
have=$(q "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND LOWER(CHECK_CLAUSE) NOT LIKE '%json_valid(%'" | LC_ALL=C sort)
missing=$(comm -23 <(echo "$want") <(echo "$have") | tr '\n' ' '); unexpected=$(comm -13 <(echo "$approved") <(echo "$have") | tr '\n' ' ')
if [ -z "$missing" ] && [ -z "$unexpected" ] && [ "$ninv" -gt 0 ]; then echo "PASS  meta-C  [Phase 01B baseline]: CHECK inventory == {chk_<table>_<column>} for the $ninv baseline ENUM columns (none missing); no unapproved CHECK anywhere"; PASS=$((PASS+1))
else echo "FAIL  meta-C  [Phase 01B baseline]: CHECK inventory mismatch. missing baseline CHECK: [${missing:-none}] unapproved CHECK (neither baseline nor approved Phase 02): [${unexpected:-none}]"; FAIL=$((FAIL+1)); fi

# meta-C2 (approved Phase 02 extensions): every approved Phase 02 CHECK is present: they must not be weakened or dropped.
nwp2=$(printf '%s' "$want_p2" | grep -c .)
missP2c=$(comm -23 <(echo "$want_p2") <(echo "$have") | tr '\n' ' ')
if [ -z "$missP2c" ]; then echo "PASS  meta-C2 [Phase 02 extension]: all $nwp2 approved Phase 02 CHECK constraints present ($(printf '%s ' $want_p2)) - recognised as intentional additions, not Phase 01B regressions"; PASS=$((PASS+1))
else echo "FAIL  meta-C2 [Phase 02 extension]: approved Phase 02 CHECK constraint(s) missing/dropped: [$missP2c]"; FAIL=$((FAIL+1)); fi

# meta-C3 (approved Phase 01C D3 extensions): the four lowercase-UUIDv4 CHECKs are present and each is attached to its own table's id column.
missD3=""
for t in ballots ballot_contest_responses ballot_events ballot_dispositions; do
  nm="chk_${t}_id_uuidv4"
  cl=$(q "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND CONSTRAINT_NAME='$nm'")
  [ "$(printf '%s' "$cl" | grep -c .)" = "1" ] && printf '%s' "$cl" | grep -qF '`id`' || missD3="$missD3 $nm"
done
if [ -z "$missD3" ]; then echo "PASS  meta-C3 [Phase 01C D3 extension]: all ${#PHASE1C_D3_EXTRA_CHECKS[@]} approved UUIDv4 CHECK constraints present and attached to \`id\` ($(printf '%s ' "${PHASE1C_D3_EXTRA_CHECKS[@]}")) - recognised as intentional additions"; PASS=$((PASS+1))
else echo "FAIL  meta-C3 [Phase 01C D3 extension]: approved UUIDv4 CHECK missing or not on \`id\`: [$missD3]"; FAIL=$((FAIL+1)); fi

echo "=== election_incidents.severity: free-form nullable VARCHAR(255), no default, no CHECK ==="
sev=$(q "SELECT LOWER(COLUMN_TYPE),IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='election_incidents' AND COLUMN_NAME='severity'" | tr '\t' ' ')
if [ "$sev" = "varchar(255) YES" ]; then echo "PASS  severity is nullable VARCHAR(255), not an ENUM"; PASS=$((PASS+1)); else echo "FAIL  severity type: expected 'varchar(255) YES', got '$sev'"; FAIL=$((FAIL+1)); fi
sw=""
sev_ins() { # MODE ID [SEVERITY_TEXT]  -- omit the text argument to insert without naming the column
  if [ $# -ge 3 ]; then q "$1 INSERT INTO election_incidents(id,election_id,title,description,severity,created_at,updated_at) VALUES ($2,1,'t','d','$3',NOW(),NOW())"
  else q "$1 INSERT INTO election_incidents(id,election_id,title,description,created_at,updated_at) VALUES ($2,1,'t','d',NOW(),NOW())"; fi
}
long255=$(printf 'x%.0s' $(seq 1 255))
for mode in "" "SET SESSION sql_mode='';"; do
  lab="${mode:+permissive}"; lab="${lab:-server-default}"
  q "DELETE FROM election_incidents WHERE id IN (960,961)" >/dev/null
  sev_ins "$mode" 960 >/dev/null
  [ "$(q "SELECT COUNT(*), SUM(severity IS NULL) FROM election_incidents WHERE id=960" | tr '\t' ' ')" = "1 1" ] || sw="$sw [$lab: omitted severity is not stored as NULL]"
  q "DELETE FROM election_incidents WHERE id=960" >/dev/null
  for txt in "SEVERE" "urgent - escalate to COMELEC chair" "$long255"; do
    sev_ins "$mode" 961 "$txt" >/dev/null
    [ "$(q "SELECT severity = '$txt' FROM election_incidents WHERE id=961")" = "1" ] || sw="$sw [$lab: free text (${#txt} chars) not stored exactly]"
    q "DELETE FROM election_incidents WHERE id=961" >/dev/null
  done
done
refs=$(q "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA='$DB' AND LOWER(CHECK_CLAUSE) LIKE '%severity%'" | tr '\n' ' ')
[ -z "$refs" ] || sw="$sw [a CHECK references severity: $refs]"
if [ -z "$sw" ]; then echo "PASS  severity: NULL when omitted, arbitrary text up to 255 chars stored exactly (2 sql_modes), no CHECK references it"; PASS=$((PASS+1)); else echo "FAIL  severity behaviour ->$sw"; FAIL=$((FAIL+1)); fi

echo "=== VOIDED belongs to ballot_dispositions.disposition, never to ballots.status ==="
q "DELETE FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-0000000000f0'; DELETE FROM ballots WHERE id='b0000000-0000-4000-8000-0000000000f0'" >/dev/null
dv=$(q "INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('d0000000-0000-4000-8000-0000000000f0','$BALLOT1','VOIDED',1,NOW()); SELECT disposition FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-0000000000f0'")
q "DELETE FROM ballot_dispositions WHERE id='d0000000-0000-4000-8000-0000000000f0'" >/dev/null
q "SET SESSION sql_mode=''; INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at,status) VALUES ('b0000000-0000-4000-8000-0000000000f0',1,1,NOW(),'VOIDED')" >/dev/null 2>&1
bv=$(q "SELECT COUNT(*) FROM ballots WHERE id='b0000000-0000-4000-8000-0000000000f0'")
q "DELETE FROM ballots WHERE id='b0000000-0000-4000-8000-0000000000f0'" >/dev/null
if [ "$dv" = "VOIDED" ] && [ "$bv" = "0" ]; then echo "PASS  VOIDED accepted as a disposition (stored exactly) and rejected as ballots.status (0 rows persisted)"; PASS=$((PASS+1))
else echo "FAIL  VOIDED placement: as disposition='$dv' (want VOIDED); rows persisted as ballots.status=$bv (want 0)"; FAIL=$((FAIL+1)); fi

echo "=== Phase 01C D3: Domain B identifier shape (CHAR(36) + lowercase-UUIDv4 CHECK) ==="
# Honest scope: a CHECK constraint is a database-level guard that holds only while check evaluation is enabled.
# MariaDB lets a privileged session switch it off (check_constraint_checks=0); that limitation is demonstrated below.
D3_COLS="ballots.id ballot_contest_responses.id ballot_events.id ballot_dispositions.id ballot_contest_responses.ballot_id ballot_candidate_selections.ballot_contest_response_id ballot_reporting_contexts.ballot_id ballot_events.ballot_id ballot_dispositions.ballot_id"
bad=""
for ref in $D3_COLS; do
  t="${ref%%.*}"; c="${ref#*.}"
  got=$(q "SELECT LOWER(DATA_TYPE), CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$t' AND COLUMN_NAME='$c'" | tr '\t' ' ')
  [ "$got" = "char 36 NO" ] || bad="$bad [$ref: '$got']"
done
if [ -z "$bad" ]; then echo "PASS  D3-shape: the 4 Domain B primary keys and 5 dependent columns are all CHAR(36) NOT NULL"; PASS=$((PASS+1)); else echo "FAIL  D3-shape: expected 'char 36 NO' for every listed column ->$bad"; FAIL=$((FAIL+1)); fi
bad=""
for ref in voter_participations.id submission_attempts.id; do
  t="${ref%%.*}"; c="${ref#*.}"
  got=$(q "SELECT LOWER(DATA_TYPE), CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$t' AND COLUMN_NAME='$c'" | tr '\t' ' ')
  [ "$got" = "char 26" ] || bad="$bad [$ref: '$got']"
done
if [ -z "$bad" ]; then echo "PASS  D3-domainA: voter_participations.id and submission_attempts.id remain ULID (CHAR(26)); Domain A untouched"; PASS=$((PASS+1)); else echo "FAIL  D3-domainA ->$bad"; FAIL=$((FAIL+1)); fi

ccc=$(q "SELECT @@SESSION.check_constraint_checks, @@GLOBAL.check_constraint_checks" | tr '\t' ' ')
case "$ccc" in
  "1 1") echo "PASS  D3-ccc: check_constraint_checks is enabled (session=1, global=1)"; PASS=$((PASS+1)); CCC_VAR=yes;;
  ERROR*) case "$srv_ver" in *MariaDB*) echo "FAIL  D3-ccc: MariaDB server but check_constraint_checks could not be read: $ccc"; FAIL=$((FAIL+1)); CCC_VAR=no;;
            *) echo "PASS  D3-ccc: server has no check_constraint_checks variable (non-MariaDB engine: CHECK is enforced unless declared NOT ENFORCED); enforcement is proven behaviourally below"; PASS=$((PASS+1)); CCC_VAR=no;; esac;;
  *) echo "FAIL  D3-ccc: check_constraint_checks is not enabled (session/global = '$ccc'); the UUIDv4 CHECKs would NOT be evaluated"; FAIL=$((FAIL+1)); CCC_VAR=no;;
esac

# uuid_guard TABLE INSERT_TEMPLATE(@I@ = id under test) DELETE_TEMPLATE(@I@)
#   Valid lowercase UUIDv4 is accepted and read back exactly. Each invalid shape is rejected BY chk_<table>_id_uuidv4
#   (constraint named in the error) in two sql_modes, and persists no row.
D3_VALID='e0000000-0000-4000-8000-0000000000a1'
D3_INVALID=(
 "ulid|01ARZ3NDEKTSV4RRFFQ69G5FAV"
 "ulid-in-uuid-layout|01arz3nd-ektv-4rrf-fq69-g5fav0000000"
 "version-1|e0000000-0000-1000-8000-0000000000a1"
 "version-5|e0000000-0000-5000-8000-0000000000a1"
 "version-0|e0000000-0000-0000-8000-0000000000a1"
 "variant-c|e0000000-0000-4000-c000-0000000000a1"
 "variant-7|e0000000-0000-4000-7000-0000000000a1"
 "uppercase-hex|E0000000-0000-4000-8000-0000000000A1"
 "mixed-case|e0000000-0000-4000-8000-0000000000A1"
 "no-hyphens|e0000000000040008000000000000a1x"
 "non-hex|g0000000-0000-4000-8000-0000000000a1"
 "wrong-separator|e0000000_0000-4000-8000-0000000000a1"
 "too-short|e0000000-0000-4000-8000-0000000000a"
)
uuid_guard() {
  local table="$1" ins="$2" del="$3" name="chk_${1}_id_uuidv4" why="" ent label id sql out n got mode ninv=0
  local -a pre=("" "SET SESSION sql_mode='';")
  for ent in "${D3_INVALID[@]}"; do
    label="${ent%%|*}"; id="${ent#*|}"; ninv=$((ninv+1))
    for mode in 0 1; do
      sql="${ins//@I@/$id}"; out=$(q "${pre[$mode]} $sql"); n=$(q "SELECT COUNT(*) FROM \`$table\` WHERE id='$id'")
      echo "$out" | grep -q "^ERROR" || why="$why [$label/mode$mode: not rejected]"
      echo "$out" | grep -qF "$name" || why="$why [$label/mode$mode: not rejected by $name: ${out:-<accepted>}]"
      [ "$n" = "0" ] || { why="$why [$label/mode$mode: row persisted]"; q "${del//@I@/$id}" >/dev/null; }
    done
  done
  sql="${ins//@I@/$D3_VALID}"; out=$(q "$sql"); got=$(q "SELECT id FROM \`$table\` WHERE id='$D3_VALID'")
  { echo "$out" | grep -q "^ERROR" || [ "$got" != "$D3_VALID" ]; } && why="$why [valid lowercase UUIDv4 not stored exactly: out='$out' got='$got']"
  q "${del//@I@/$D3_VALID}" >/dev/null
  if [ -z "$why" ]; then echo "PASS  D3-check $table.id  [valid lowercase UUIDv4 stored exactly; $ninv invalid shapes (ULID, bad version, bad variant, uppercase, malformed) rejected by $name in 2 sql_modes, 0 rows persisted]"; PASS=$((PASS+1))
  else echo "FAIL  D3-check $table.id ->$why"; FAIL=$((FAIL+1)); fi
}
uuid_guard ballots \
  "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('@I@',1,1,NOW())" \
  "DELETE FROM ballots WHERE id='@I@'"
uuid_guard ballot_contest_responses \
  "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('@I@','$BALLOT1',2,1,'ABSTAIN')" \
  "DELETE FROM ballot_contest_responses WHERE id='@I@'"
uuid_guard ballot_events \
  "INSERT INTO ballot_events(id,ballot_id,event_type,occurred_at) VALUES ('@I@','$BALLOT1','FIXTURE',NOW())" \
  "DELETE FROM ballot_events WHERE id='@I@'"
uuid_guard ballot_dispositions \
  "INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('@I@','$BALLOT1','REINSTATED',1,NOW())" \
  "DELETE FROM ballot_dispositions WHERE id='@I@'"

# Dependent columns are bound by their foreign keys: an orphan (valid-shaped but non-existent parent) and a ULID-shaped value are both refused by the FK.
ORPHAN='f0000000-0000-4000-8000-0000000000b1'; ULID26='01ARZ3NDEKTSV4RRFFQ69G5FAV'
bad=""
fkcase() { # label sql expected-constraint
  local out n; out=$(q "$2"); echo "$out" | grep -q "^ERROR" && echo "$out" | grep -qF "$3" || bad="$bad [$1: expected rejection by $3, got: ${out:-<accepted>}]"; }
for v in "$ORPHAN" "$ULID26"; do
  fkcase "response.ballot_id=$v"   "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('e0000000-0000-4000-8000-0000000000c1','$v',2,1,'ABSTAIN')" bcr_ballot_same_election_fk
  fkcase "selection.response=$v"   "INSERT INTO ballot_candidate_selections(ballot_contest_response_id,contest_id,candidacy_id) VALUES ('$v',1,1)" bcs_response_contest_match_foreign
  fkcase "context.ballot_id=$v"    "INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('$v',1,'CCS')" brc_ballot_same_election_fk
  fkcase "event.ballot_id=$v"      "INSERT INTO ballot_events(id,ballot_id,event_type,occurred_at) VALUES ('e0000000-0000-4000-8000-0000000000c2','$v','FIXTURE',NOW())" ballot_events_ballot_id_foreign
  fkcase "disposition.ballot_id=$v" "INSERT INTO ballot_dispositions(id,ballot_id,disposition,decided_by,decided_at) VALUES ('e0000000-0000-4000-8000-0000000000c3','$v','REINSTATED',1,NOW())" ballot_dispositions_ballot_id_foreign
done
left=$(q "SELECT (SELECT COUNT(*) FROM ballot_events)+(SELECT COUNT(*) FROM ballot_dispositions)+(SELECT COUNT(*) FROM ballot_contest_responses WHERE id LIKE 'e0000000%')")
[ "$left" = "0" ] || bad="$bad [orphan tests left $left rows]"
if [ -z "$bad" ]; then echo "PASS  D3-fk  [all 5 dependent columns refuse an orphan UUID and a ULID-shaped value, each by its own named foreign key; 0 rows persisted]"; PASS=$((PASS+1)); else echo "FAIL  D3-fk ->$bad"; FAIL=$((FAIL+1)); fi

# Limitation, demonstrated (informational but counted): with check evaluation switched off for ONE session an invalid id is accepted.
# This is why the CHECK is described as a database-level guard, not an unconditional security boundary.
if [ "$CCC_VAR" = "yes" ]; then
  q "DELETE FROM ballots WHERE id='$ULID26'" >/dev/null
  q "SET SESSION check_constraint_checks=0; INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('$ULID26',1,1,NOW())" >/dev/null
  got=$(q "SELECT COUNT(*) FROM ballots WHERE id='$ULID26'"); q "DELETE FROM ballots WHERE id='$ULID26'" >/dev/null
  if [ "$got" = "1" ]; then echo "PASS  D3-limit  [documented limitation observed: a session with check_constraint_checks=0 can bypass the UUIDv4 CHECK; the row was removed again. The CHECK is NOT an unconditional security boundary]"; PASS=$((PASS+1))
  else echo "FAIL  D3-limit  expected the session-level bypass to be possible (rows=$got); documentation of the limitation must be re-evaluated"; FAIL=$((FAIL+1)); fi
fi

echo "=== Ballot secrecy (structural) ==="
empty "no voter-identity column in Domain B tables" "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions') AND (COLUMN_NAME LIKE '%student%' OR COLUMN_NAME LIKE '%institutional%' OR COLUMN_NAME LIKE '%participation%' OR COLUMN_NAME LIKE '%submission%' OR COLUMN_NAME LIKE '%receipt%' OR COLUMN_NAME LIKE '%google%' OR COLUMN_NAME LIKE '%email%')"
empty "no FK from Domain A tables to Domain B tables, or the reverse" "SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$DB' AND REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_NAME IN ('voter_participations','submission_attempts') AND REFERENCED_TABLE_NAME LIKE 'ballot%') OR (TABLE_NAME LIKE 'ballot%' AND REFERENCED_TABLE_NAME IN ('students','voter_participations','submission_attempts','election_eligible_voters')))"

q "SET FOREIGN_KEY_CHECKS=0; TRUNCATE result_snapshots; TRUNCATE result_aggregates; TRUNCATE result_calculation_runs; TRUNCATE ballot_reporting_contexts; TRUNCATE ballot_candidate_selections; TRUNCATE ballot_contest_responses; TRUNCATE ballots; TRUNCATE voter_participations; TRUNCATE submission_attempts; TRUNCATE ballot_structure_snapshots; TRUNCATE candidacies; TRUNCATE candidate_roster_snapshots; TRUNCATE election_eligibility_snapshots; TRUNCATE candidates; TRUNCATE contests; TRUNCATE parties; TRUNCATE representation_groups; TRUNCATE election_config_versions; TRUNCATE elections; TRUNCATE student_enrollments; TRUNCATE import_batches; TRUNCATE students; TRUNCATE admin_users; SET FOREIGN_KEY_CHECKS=1;" >/dev/null
echo; echo "SUMMARY  Phase 01B baseline: $ninv/$EXPECTED_ENUM_COUNT ENUM columns guarded by CHECKs (meta-A/B/C).  Approved Phase 02 extensions: $nP2 ENUM + $nwp2 CHECK recognised separately (meta-A2/B2/C2)."
echo "RESULT: $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]
