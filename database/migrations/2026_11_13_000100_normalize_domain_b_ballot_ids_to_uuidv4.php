<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 01C / D3 — Domain B identifier normalisation (ULID -> UUIDv4-shaped CHAR(36)).
 *
 * FORWARD-ONLY, EMPTY-TABLE-ONLY, FAIL-CLOSED (approved decisions D3-D1, D3-D2).
 *
 * Converts four Domain B primary keys and their five dependent columns from CHAR(26) to CHAR(36):
 *   PKs:        ballots.id, ballot_contest_responses.id, ballot_events.id, ballot_dispositions.id
 *   dependents: ballot_contest_responses.ballot_id, ballot_candidate_selections.ballot_contest_response_id,
 *               ballot_reporting_contexts.ballot_id, ballot_events.ballot_id, ballot_dispositions.ballot_id
 * and adds a lowercase-UUIDv4 CHECK on each of the four primary keys.
 *
 * voter_participations.id and submission_attempts.id (Domain A) deliberately stay ULID.
 *
 * WHAT THIS MIGRATION DOES NOT DO
 *   - It does not generate identifiers. CHAR(36) + CHECK only constrain the SHAPE of stored values; there is
 *     no application-side ballot writer or UUID generator in this code base yet.
 *   - It never deletes, truncates, overwrites or converts any existing record, and never disables
 *     foreign_key_checks. If ANY of the six Domain B tables holds a row (and the schema would have to change),
 *     it stops, reports every non-empty table with its row count (counts only, never identifiers or content),
 *     and leaves the database exactly as it found it.
 *   - The six historical create_ballot*_table migrations are intentionally NOT edited.
 *
 * LOCKING AND CONNECTION. A named advisory lock (GET_LOCK) is taken FIRST, on one connection; every read and
 * every schema statement of this migration runs on that same connection, which is re-verified (CONNECTION_ID and
 * IS_USED_LOCK) before each stage; the lock is released in `finally` on success, refusal and any exception.
 * An advisory lock coordinates migration runs only. It does NOT stop an ordinary application session from
 * inserting a ballot, so run this before any ballot writer exists, or in maintenance mode.
 *
 * NOT ATOMIC. MariaDB DDL commits implicitly, so this migration cannot be wrapped in a transaction. A failure in
 * the middle of the DDL phase (possible only on empty tables) leaves a partial but resumable schema: the next
 * run re-takes the lock, re-checks emptiness and completes the remaining steps.
 *
 * MariaDB/MySQL only. On any other driver (the SQLite test database) this is a documented no-op.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const LOCK_TIMEOUT_SECONDS = 5;

    /** The six Domain B tables whose emptiness gates every schema change. */
    private const TABLES = [
        'ballots',
        'ballot_contest_responses',
        'ballot_candidate_selections',
        'ballot_reporting_contexts',
        'ballot_events',
        'ballot_dispositions',
    ];

    /** The nine columns that change type, as [table, column]. The first four are primary keys. */
    private const COLUMNS = [
        ['ballots', 'id'],
        ['ballot_contest_responses', 'id'],
        ['ballot_events', 'id'],
        ['ballot_dispositions', 'id'],
        ['ballot_contest_responses', 'ballot_id'],
        ['ballot_candidate_selections', 'ballot_contest_response_id'],
        ['ballot_reporting_contexts', 'ballot_id'],
        ['ballot_events', 'ballot_id'],
        ['ballot_dispositions', 'ballot_id'],
    ];

    /** Primary keys that receive the lowercase-UUIDv4 CHECK: table => constraint name. */
    private const UUID_CHECKS = [
        'ballots' => 'chk_ballots_id_uuidv4',
        'ballot_contest_responses' => 'chk_ballot_contest_responses_id_uuidv4',
        'ballot_events' => 'chk_ballot_events_id_uuidv4',
        'ballot_dispositions' => 'chk_ballot_dispositions_id_uuidv4',
    ];

    /** Lowercase RFC 4122 UUIDv4 shape: version nibble 4, variant 8/9/a/b. BINARY makes the match case-sensitive. */
    private const UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$';

    /**
     * Foreign keys that are dropped and re-created, per table, IN THEIR ORIGINAL CREATION ORDER: the FK that
     * references a converted column plus every FK created after it on the same table. Re-creating them in this
     * order restores the order in which the historical migrations created them (InnoDB reports the first violated
     * FK; the Phase 01B harness check A2 depends on it). Each entry: [name, columns, referenced table, referenced columns].
     */
    private const RECREATED_FKS = [
        'ballot_contest_responses' => [
            ['bcr_ballot_same_election_fk', ['ballot_id', 'election_id'], 'ballots', ['id', 'election_id']],
            ['bcr_contest_same_election_fk', ['contest_id', 'election_id'], 'contests', ['id', 'election_id']],
        ],
        'ballot_candidate_selections' => [
            ['bcs_response_contest_match_foreign', ['ballot_contest_response_id', 'contest_id'], 'ballot_contest_responses', ['id', 'contest_id']],
            ['bcs_candidacy_contest_membership_foreign', ['candidacy_id', 'contest_id'], 'candidacies', ['id', 'contest_id']],
        ],
        'ballot_reporting_contexts' => [
            ['brc_ballot_same_election_fk', ['ballot_id', 'election_id'], 'ballots', ['id', 'election_id']],
        ],
        'ballot_events' => [
            ['ballot_events_ballot_id_foreign', ['ballot_id'], 'ballots', ['id']],
            ['ballot_events_related_incident_id_foreign', ['related_incident_id'], 'election_incidents', ['id']],
            ['ballot_events_related_result_calculation_run_id_foreign', ['related_result_calculation_run_id'], 'result_calculation_runs', ['id']],
            ['ballot_events_actor_id_foreign', ['actor_id'], 'admin_users', ['id']],
        ],
        'ballot_dispositions' => [
            ['ballot_dispositions_ballot_id_foreign', ['ballot_id'], 'ballots', ['id']],
            ['ballot_dispositions_related_incident_id_foreign', ['related_incident_id'], 'election_incidents', ['id']],
            ['ballot_dispositions_decided_by_foreign', ['decided_by'], 'admin_users', ['id']],
        ],
    ];

    public function up(): void
    {
        $this->apply('up');
    }

    public function down(): void
    {
        $this->apply('down');
    }

    // ------------------------------------------------------------------------------------------------------
    // Orchestration
    // ------------------------------------------------------------------------------------------------------

    private function apply(string $direction): void
    {
        $conn = DB::connection($this->getConnection());

        if (! in_array($conn->getDriverName(), ['mysql', 'mariadb'], true)) {
            return; // SQLite test schema: out of scope for D3 (documented no-op).
        }

        // Step 1 — lock FIRST, before any read of the Domain B tables and before any schema change.
        [$lockName, $connectionId] = $this->acquireLock($conn);
        $failure = null;

        try {
            $this->assertSameConnection($conn, $lockName, $connectionId);

            // Step 2 — read-only inventory. Nothing below changes anything until every refusal check has passed.
            $shape = $this->inspectShape($conn);
            $counts = $this->countRows($conn);
            $target = $direction === 'up' ? 'CHAR(36)' : 'CHAR(26)';

            if ($this->isAlreadyAtTarget($direction, $shape)) {
                return; // Nothing to do. Never refuses on a populated database that is already converted.
            }

            // Step 3 — fail closed: any record in any of the six tables stops the run, unchanged.
            $nonEmpty = array_filter($counts, static fn (int $n): bool => $n > 0);
            if ($nonEmpty !== []) {
                throw new RuntimeException($this->refusalMessage($direction, $nonEmpty, $shape));
            }

            if ($shape['problems'] !== []) {
                throw new RuntimeException(
                    'Phase 01C D3 stopped before changing anything: the Domain B schema is not in a recognised shape ('
                    .implode('; ', $shape['problems']).'). The six tables are empty and no change was made.'
                );
            }

            // Step 4 — all six tables are empty and the shape is recognised: convert (idempotent / resumable).
            $before = $this->structureSignature($conn, false);
            $beforeFull = $this->allRecreatedForeignKeysPresent($shape) ? $this->structureSignature($conn, true) : null;
            $this->assertSameConnection($conn, $lockName, $connectionId);
            $this->dropRecreatedForeignKeys($conn);

            $this->assertSameConnection($conn, $lockName, $connectionId);
            if ($direction === 'down') {
                $this->dropUuidChecks($conn);
            }
            $this->convertColumns($conn, $direction === 'up' ? 36 : 26);

            $this->assertSameConnection($conn, $lockName, $connectionId);
            $this->addRecreatedForeignKeys($conn);
            if ($direction === 'up') {
                $this->addUuidChecks($conn);
            }

            // Step 5 — post-verification, still under the lock; refuse to call a wrong result a success.
            $this->assertSameConnection($conn, $lockName, $connectionId);
            $after = $this->inspectShape($conn);
            if (! $this->isAlreadyAtTarget($direction, $after)) {
                throw new RuntimeException(
                    "Phase 01C D3 post-verification failed: the schema is not fully at {$target} with the expected foreign keys"
                    .($direction === 'up' ? ' and UUIDv4 CHECKs' : '').' ('.implode('; ', $after['problems'] ?: ['see inventory']).'). '
                    .'Tables were empty; re-run the migration to resume.'
                );
            }
            if ($this->structureSignature($conn, false) !== $before
                || ($beforeFull !== null && $this->structureSignature($conn, true) !== $beforeFull)) {
                throw new RuntimeException(
                    'Phase 01C D3 post-verification failed: an index or foreign key outside the intended change differs from its pre-migration definition.'
                );
            }
            $leftover = array_filter($this->countRows($conn), static fn (int $n): bool => $n > 0);
            if ($leftover !== []) {
                throw new RuntimeException('Phase 01C D3 post-verification failed: Domain B tables were expected to remain empty but report rows: '.$this->formatCounts($leftover).'.');
            }
        } catch (Throwable $e) {
            $failure = $e;
            throw $e;
        } finally {
            $released = $this->releaseLock($conn, $lockName, $connectionId);
            if ($failure === null && ! $released) {
                throw new RuntimeException("Phase 01C D3 completed but could not confirm release of advisory lock {$lockName}.");
            }
        }
    }

    // ------------------------------------------------------------------------------------------------------
    // Locking (one connection for GET_LOCK, every schema operation, and RELEASE_LOCK)
    // ------------------------------------------------------------------------------------------------------

    /** @return array{0: string, 1: int} [lock name, connection id] */
    private function acquireLock(Connection $conn): array
    {
        $db = (string) $conn->selectOne('SELECT DATABASE() AS d', [], false)->d;
        $name = 'buksu_comelec:phase01c_d3:'.substr(md5($db), 0, 16);
        $row = $conn->selectOne('SELECT GET_LOCK(?, ?) AS got, CONNECTION_ID() AS cid', [$name, self::LOCK_TIMEOUT_SECONDS], false);

        if ((int) ($row->got ?? 0) !== 1) {
            throw new RuntimeException(
                "Phase 01C D3 could not acquire the migration lock '{$name}' within ".self::LOCK_TIMEOUT_SECONDS
                .' seconds (another migration or maintenance session may be running). No schema change was attempted.'
            );
        }

        return [$name, (int) $row->cid];
    }

    private function assertSameConnection(Connection $conn, string $name, int $connectionId): void
    {
        $row = $conn->selectOne('SELECT CONNECTION_ID() AS cid, IS_USED_LOCK(?) AS holder', [$name], false);
        if ((int) $row->cid !== $connectionId || (int) ($row->holder ?? 0) !== $connectionId) {
            throw new RuntimeException(
                'Phase 01C D3 stopped: the database connection changed or the advisory lock is no longer held by it '
                ."(lock '{$name}', expected connection {$connectionId}). No further schema statement was issued."
            );
        }
    }

    private function releaseLock(Connection $conn, string $name, int $connectionId): bool
    {
        try {
            $row = $conn->selectOne('SELECT RELEASE_LOCK(?) AS r, CONNECTION_ID() AS cid', [$name], false);

            return (int) ($row->r ?? 0) === 1 && (int) $row->cid === $connectionId;
        } catch (Throwable) {
            return false; // connection gone: the server releases the lock when the session ends
        }
    }

    // ------------------------------------------------------------------------------------------------------
    // Read-only inspection
    // ------------------------------------------------------------------------------------------------------

    /** @return array<string, int> table => row count (all six, counts only) */
    private function countRows(Connection $conn): array
    {
        $counts = [];
        foreach (self::TABLES as $table) {
            $counts[$table] = (int) $conn->selectOne("SELECT COUNT(*) AS c FROM `{$table}`", [], false)->c;
        }

        return $counts;
    }

    /**
     * @return array{
     *   cols: array<string, string>, fks: array<string, array<string, array<string, mixed>>>,
     *   checks: array<string, bool>, problems: list<string>
     * }
     */
    private function inspectShape(Connection $conn): array
    {
        $problems = [];
        $cols = [];
        foreach (self::COLUMNS as [$table, $column]) {
            $r = $conn->selectOne(
                'SELECT LOWER(DATA_TYPE) AS t, CHARACTER_MAXIMUM_LENGTH AS l, IS_NULLABLE AS n FROM information_schema.COLUMNS '
                .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
                false
            );
            if ($r === null) {
                $problems[] = "{$table}.{$column} does not exist";
                $cols["{$table}.{$column}"] = 'missing';

                continue;
            }
            $kind = ((string) $r->t === 'char' && (int) $r->l === 26 && $r->n === 'NO') ? 'CHAR(26)'
                  : (((string) $r->t === 'char' && (int) $r->l === 36 && $r->n === 'NO') ? 'CHAR(36)' : 'other');
            if ($kind === 'other') {
                $problems[] = "{$table}.{$column} is {$r->t}({$r->l}) nullable={$r->n}";
            }
            $cols["{$table}.{$column}"] = $kind;
        }

        $fks = [];
        foreach (self::RECREATED_FKS as $table => $defs) {
            foreach ($defs as [$name, $fkCols, $refTable, $refCols]) {
                $rows = $conn->select(
                    'SELECT k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS dr '
                    .'FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
                    .'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
                    .'WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = ? ORDER BY k.ORDINAL_POSITION',
                    [$table, $name],
                    false
                );
                if ($rows === []) {
                    $fks[$table][$name] = ['present' => false];

                    continue;
                }
                $exact = array_map(static fn ($x) => $x->c, $rows) === $fkCols
                    && array_map(static fn ($x) => $x->rc, $rows) === $refCols
                    && $rows[0]->rt === $refTable
                    && $rows[0]->dr === 'RESTRICT';
                $fks[$table][$name] = ['present' => true, 'exact' => $exact];
                if (! $exact) {
                    $problems[] = "foreign key {$table}.{$name} exists with an unexpected definition";
                }
            }
        }

        $checks = [];
        foreach (self::UUID_CHECKS as $table => $name) {
            $checks[$table] = $conn->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                [$name],
                false
            )->c > 0;
        }

        return ['cols' => $cols, 'fks' => $fks, 'checks' => $checks, 'problems' => $problems];
    }

    private function isAlreadyAtTarget(string $direction, array $shape): bool
    {
        if ($shape['problems'] !== []) {
            return false;
        }
        $want = $direction === 'up' ? 'CHAR(36)' : 'CHAR(26)';
        foreach ($shape['cols'] as $kind) {
            if ($kind !== $want) {
                return false;
            }
        }
        foreach ($shape['fks'] as $byName) {
            foreach ($byName as $fk) {
                if (! $fk['present'] || ! $fk['exact']) {
                    return false;
                }
            }
        }
        foreach ($shape['checks'] as $present) {
            if ($present !== ($direction === 'up')) {
                return false;
            }
        }

        return true;
    }

    private function allRecreatedForeignKeysPresent(array $shape): bool
    {
        foreach ($shape['fks'] as $byName) {
            foreach ($byName as $fk) {
                if (! $fk['present']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Index and foreign-key signature of the six tables. FK drop/re-add and column MODIFY must leave every index
     * and every FK definition identical, so the signature before and after must match. Foreign keys that this
     * migration drops and re-creates are included only when $includeRecreated is true (that comparison is made
     * only when all of them were present before, i.e. not when resuming an interrupted run; their exact
     * definition is verified separately by inspectShape()). The creation ORDER of foreign keys is not exposed by
     * the catalogue; it is guaranteed by construction and checked behaviourally by Phase 01B harness test A2.
     *
     * @return list<string>
     */
    private function structureSignature(Connection $conn, bool $includeRecreated): array
    {
        $recreated = [];
        foreach (self::RECREATED_FKS as $defs) {
            foreach ($defs as [$name]) {
                $recreated[$name] = true;
            }
        }
        $in = implode(',', array_fill(0, count(self::TABLES), '?'));
        $sig = [];
        $rows = $conn->select(
            'SELECT TABLE_NAME AS t, INDEX_NAME AS i, NON_UNIQUE AS u, SEQ_IN_INDEX AS s, COLUMN_NAME AS c FROM information_schema.STATISTICS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            self::TABLES,
            false
        );
        foreach ($rows as $r) {
            $sig[] = "idx|{$r->t}|{$r->i}|{$r->u}|{$r->s}|{$r->c}";
        }
        $rows = $conn->select(
            'SELECT k.TABLE_NAME AS t, k.CONSTRAINT_NAME AS n, k.ORDINAL_POSITION AS o, k.COLUMN_NAME AS c, k.REFERENCED_TABLE_NAME AS rt, '
            .'k.REFERENCED_COLUMN_NAME AS rc, r.DELETE_RULE AS dr, r.UPDATE_RULE AS ur '
            .'FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r '
            .'ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME '
            ."WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME IN ({$in}) AND k.REFERENCED_TABLE_NAME IS NOT NULL "
            .'ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            self::TABLES,
            false
        );
        foreach ($rows as $r) {
            if (! $includeRecreated && isset($recreated[$r->n])) {
                continue;
            }
            $sig[] = "fk|{$r->t}|{$r->n}|{$r->o}|{$r->c}|{$r->rt}|{$r->rc}|{$r->dr}|{$r->ur}";
        }

        return $sig;
    }

    // ------------------------------------------------------------------------------------------------------
    // Messages (counts only: never identifiers or row content)
    // ------------------------------------------------------------------------------------------------------

    /** @param array<string, int> $counts */
    private function formatCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $table => $n) {
            $parts[] = "{$table}: {$n} ".($n === 1 ? 'row' : 'rows');
        }

        return implode('; ', $parts);
    }

    /** @param array<string, int> $nonEmpty */
    private function refusalMessage(string $direction, array $nonEmpty, array $shape): string
    {
        $what = $direction === 'up'
            ? 'cannot convert the Domain B identifier columns to CHAR(36) UUIDv4'
            : 'cannot roll back the Domain B identifier conversion (ballot records exist that were created after it)';
        $note = $shape['problems'] === [] ? '' : ' (The schema also deviates from the expected shape: '.implode('; ', $shape['problems']).'.)';

        return 'Phase 01C D3 REFUSED: '.$what.' because these tables contain records: '.$this->formatCounts($nonEmpty).'. '
            .'No record was deleted, truncated, overwritten or converted, the schema was not changed, and this migration run '
            .'is not recorded. D3 runs only on EMPTY Domain B tables (fail-closed, decision D3-D2). '
            .'Resolve this deliberately (for example on a disposable database, or through an approved separate procedure) and re-run.'.$note;
    }

    // ------------------------------------------------------------------------------------------------------
    // Schema changes (reached only with the lock held, all six tables empty, and a recognised shape)
    // ------------------------------------------------------------------------------------------------------

    private function dropRecreatedForeignKeys(Connection $conn): void
    {
        foreach (self::RECREATED_FKS as $table => $defs) {
            foreach (array_reverse($defs) as [$name]) {
                if ($this->foreignKeyExists($conn, $table, $name)) {
                    $conn->statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
                }
            }
        }
    }

    private function foreignKeyExists(Connection $conn, string $table, string $name): bool
    {
        return (int) $conn->selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? '
            ."AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            [$table, $name],
            false
        )->c > 0;
    }

    private function convertColumns(Connection $conn, int $length): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            $r = $conn->selectOne(
                'SELECT CHARACTER_MAXIMUM_LENGTH AS l FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
                false
            );
            if ((int) $r->l !== $length) {
                $conn->statement("ALTER TABLE `{$table}` MODIFY `{$column}` CHAR({$length}) NOT NULL");
            }
        }
    }

    private function addRecreatedForeignKeys(Connection $conn): void
    {
        foreach (self::RECREATED_FKS as $table => $defs) {
            foreach ($defs as [$name, $cols, $refTable, $refCols]) {
                if (! $this->foreignKeyExists($conn, $table, $name)) {
                    $conn->statement(
                        "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`".implode('`, `', $cols).'`) '
                        ."REFERENCES `{$refTable}` (`".implode('`, `', $refCols).'`) ON DELETE RESTRICT'
                    );
                }
            }
        }
    }

    private function addUuidChecks(Connection $conn): void
    {
        foreach (self::UUID_CHECKS as $table => $name) {
            $exists = (int) $conn->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                [$name],
                false
            )->c > 0;
            if (! $exists) {
                $conn->statement(
                    "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK (BINARY `id` REGEXP '".self::UUID_PATTERN."')"
                );
            }
        }
    }

    private function dropUuidChecks(Connection $conn): void
    {
        foreach (self::UUID_CHECKS as $table => $name) {
            $exists = (int) $conn->selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
                [$name],
                false
            )->c > 0;
            if ($exists) {
                $conn->statement("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
            }
        }
    }
};
