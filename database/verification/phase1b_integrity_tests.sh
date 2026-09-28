#!/usr/bin/env bash
# Phase 1B schema integrity tests (pure SQL against the migrated MySQL schema).
# Usage:  DB=buksu_comelec2.0 MYSQL="mysql -uroot" ./phase1b_integrity_tests.sh
# SAFETY: refuses to run unless the election tables are empty; truncates its own fixtures at the end.
# Each negative test asserts WHICH constraint rejected the row, so a test cannot
# pass by failing for the wrong reason.
DB="${DB:-buksu_comelec2.0}"
MYSQL="${MYSQL:-mysql -uroot}"
PASS=0; FAIL=0
q()  { $MYSQL -D "$DB" -N -e "$1" 2>&1; }
ok() { # name sql
  out=$(q "$2"); if [ -z "$out" ] || ! echo "$out" | grep -q "^ERROR"; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FAIL  $1  -> unexpected: $out"; FAIL=$((FAIL+1)); fi; }
no() { # name sql expected-substring
  out=$(q "$2"); if echo "$out" | grep -q "^ERROR" && echo "$out" | grep -q "$3"; then echo "PASS  $1  [rejected by: $3]"; PASS=$((PASS+1)); else echo "FAIL  $1  (expected rejection by '$3') got: ${out:-<accepted>}"; FAIL=$((FAIL+1)); fi; }
empty() { out=$(q "$2"); if [ -z "$out" ]; then echo "PASS  $1"; PASS=$((PASS+1)); else echo "FAIL  $1 -> $out"; FAIL=$((FAIL+1)); fi; }

[ "$(q 'SELECT COUNT(*) FROM elections')" = "0" ] || { echo "Refusing: elections table not empty."; exit 2; }
T="NOW(),NOW()"
q "INSERT INTO admin_users(id,google_subject,display_name,created_at,updated_at) VALUES (1,'g1','Admin',$T);
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
INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('01B00000000000000000000001',1,1,NOW());
INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('01R0000000000000000000000A','01B00000000000000000000001',1,1,'VOTE');
INSERT INTO result_calculation_runs(id,election_id,algorithm_version,created_at,updated_at) VALUES (1,1,'v1',$T),(2,2,'v1',$T);
INSERT INTO import_batches(id,source_filename,academic_year,semester,uploaded_by,created_at,updated_at) VALUES (1,'f1.csv','2026-2027','1st',1,$T),(2,'f2.csv','2026-2027','1st',1,$T);" >/dev/null

RA=01R0000000000000000000000A
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
no  "ballot election=1 on structure snapshot of election 2"                "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at) VALUES ('01B00000000000000000000009',1,2,NOW())" ballots_bss_same_election_fk
no  "response: contest of election 2 on election-1 ballot (election_id=1)" "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('01R00000000000000000000X01','01B00000000000000000000001',3,1,'ABSTAIN')" bcr_contest_same_election_fk
no  "response: election-1 ballot stamped election_id=2"                     "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('01R00000000000000000000X02','01B00000000000000000000001',3,2,'ABSTAIN')" bcr_ballot_same_election_fk
no  "reporting context: election-1 ballot stamped election_id=2"           "INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('01B00000000000000000000001',2,'CCS')" brc_ballot_same_election_fk
ok  "reporting context: same election (control)"                           "INSERT INTO ballot_reporting_contexts(ballot_id,election_id,frozen_college) VALUES ('01B00000000000000000000001',1,'CCS')"
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
no  "duplicate (ballot, contest) response"                                 "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('01R00000000000000000000D01','01B00000000000000000000001',1,1,'ABSTAIN')" ballot_contest_responses_ballot_id_contest_id_unique
no  "response_type SKIP"                                                   "INSERT INTO ballot_contest_responses(id,ballot_id,contest_id,election_id,response_type) VALUES ('01R00000000000000000000D02','01B00000000000000000000001',2,1,'SKIP')" "Data truncated"
no  "ballot status VOIDED"                                                 "INSERT INTO ballots(id,election_id,ballot_structure_snapshot_id,cast_at,status) VALUES ('01B00000000000000000000008',1,1,NOW(),'VOIDED')" "Data truncated"
no  "student status IRREGULAR"                                              "INSERT INTO students(institutional_id,institutional_email,first_name,last_name,current_college,current_course,current_year_level,status,created_at,updated_at) VALUES ('s9','s9@x','x','x','c','c','1','IRREGULAR',$T)" "Data truncated"
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
echo "=== Ballot secrecy (structural) ==="
empty "no voter-identity column in Domain B tables" "SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME IN ('ballots','ballot_contest_responses','ballot_candidate_selections','ballot_reporting_contexts','ballot_events','ballot_dispositions') AND (COLUMN_NAME LIKE '%student%' OR COLUMN_NAME LIKE '%institutional%' OR COLUMN_NAME LIKE '%participation%' OR COLUMN_NAME LIKE '%submission%' OR COLUMN_NAME LIKE '%receipt%' OR COLUMN_NAME LIKE '%google%' OR COLUMN_NAME LIKE '%email%')"
empty "no FK from Domain A tables to Domain B tables, or the reverse" "SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='$DB' AND REFERENCED_TABLE_NAME IS NOT NULL AND ((TABLE_NAME IN ('voter_participations','submission_attempts') AND REFERENCED_TABLE_NAME LIKE 'ballot%') OR (TABLE_NAME LIKE 'ballot%' AND REFERENCED_TABLE_NAME IN ('students','voter_participations','submission_attempts','election_eligible_voters')))"

q "SET FOREIGN_KEY_CHECKS=0; TRUNCATE result_snapshots; TRUNCATE result_aggregates; TRUNCATE result_calculation_runs; TRUNCATE ballot_reporting_contexts; TRUNCATE ballot_candidate_selections; TRUNCATE ballot_contest_responses; TRUNCATE ballots; TRUNCATE voter_participations; TRUNCATE submission_attempts; TRUNCATE ballot_structure_snapshots; TRUNCATE candidacies; TRUNCATE candidate_roster_snapshots; TRUNCATE election_eligibility_snapshots; TRUNCATE candidates; TRUNCATE contests; TRUNCATE parties; TRUNCATE representation_groups; TRUNCATE election_config_versions; TRUNCATE elections; TRUNCATE student_enrollments; TRUNCATE import_batches; TRUNCATE students; TRUNCATE admin_users; SET FOREIGN_KEY_CHECKS=1;" >/dev/null
echo; echo "RESULT: $PASS passed, $FAIL failed"; [ "$FAIL" -eq 0 ]
