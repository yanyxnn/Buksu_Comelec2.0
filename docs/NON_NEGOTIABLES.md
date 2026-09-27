# NON-NEGOTIABLES

1. MySQL is the authoritative election data source.
2. No election-specific rule that can reasonably vary may be hard-coded.
3. One institutional/student ID represents one permanent student identity.
4. Student status is only ACTIVE or INACTIVE.
5. Current official Data Center data is authoritative for current placement.
6. Course change sets new-course year level to 1st Year.
7. Approved election eligibility is snapshotted and locked for the election.
8. Approved candidate roster is snapshotted and locked for the election.
9. Finalized elections are immutable.
10. Cast ballots cannot be edited/deleted through normal admin functions.
11. Participation is separated from ballot selections.
12. No normal data path may directly expose student → candidate selection.
13. Receipts prove participation only and never expose selections.
14. Vote submission is atomic.
15. Vote submission is idempotent.
16. DB constraints protect critical uniqueness/integrity rules.
17. Unknown submission outcome must be resolved by submission-status lookup, never blind retry.
18. Result aggregates are derived data, not authoritative votes.
19. Realtime events occur only after DB commit.
20. Student realtime data contains only overall cast votes and anonymous candidate leaderboards.
21. Integrity anomalies are investigated and reconciled, not silently overwritten.
22. Ties are detected and handed to the COMELEC President/authorized heads; the system does not decide the winner.
23. All sensitive admin actions are audited and identify the actual admin.
24. An admin cannot approve their own change request.
25. All three admins are notified of pending approvals.
26. Backups are encrypted/verified and restore-tested.
27. Restore is not an uncontrolled direct overwrite of production.
28. Secondary realtime/queue/reporting failures must not corrupt committed votes.
29. Database/service secrets must not be committed to source control.
30. Documentation and change log must stay aligned with the implemented system.
