# VOTING FLOW

## High-level
Student login → verify eligibility → election-level abstain or start → one contest at a time → final review → final submission → atomic server transaction → private confirmation → choice-free proof-of-participation receipt.

## Submission requirements
Server re-checks election state, server time, eligibility snapshot, ballot version/structure, configured selection limits, abstention rules, and participation state.

## Idempotency
Every final submission has an opaque `submission_uuid`. Retried requests with the same UUID return the existing processing outcome rather than creating another vote.

## Unknown outcome
If the browser times out, the client checks submission status before retrying. The user is never instructed to blindly resubmit.

## Transaction
Participation record, ballot, ballot items, and required authoritative technical events are committed atomically. Any failure rolls the transaction back.

## Immutability
After CAST/COMMITTED, normal admin APIs cannot edit or delete a ballot.

## Network failure
A committed vote remains valid even if the response is lost. A non-committed request can be retried safely through idempotency/status logic.

## Pause/close
If election state becomes PAUSED or CLOSED before final commit, new submission is rejected by the server. A successfully committed vote remains valid.
