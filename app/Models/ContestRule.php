<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The voting rule of one contest (`contest_rules`, one row per contest).
 *
 * `seat_count` (how many seats the contest fills) and `selection_limit` (the most candidates one voter may select)
 * are separate settings; no rule relating them has been approved, so none is applied
 * (docs/DATABASE.md: a multi-seat contest may allow fewer than the maximum selections and that is still a VOTE).
 * Nothing is mass-assignable: rows are written only by ElectionContestService.
 */
class ContestRule extends Model
{
    public const SINGLE_CHOICE = 'SINGLE_CHOICE';

    public const MULTI_CHOICE = 'MULTI_CHOICE';

    /** Mirrors the `contest_rules.voting_method` enum. */
    public const VOTING_METHODS = [self::SINGLE_CHOICE, self::MULTI_CHOICE];

    protected $table = 'contest_rules';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'seat_count' => 'integer',
            'selection_limit' => 'integer',
            'allow_abstain' => 'boolean',
        ];
    }

    public function contest(): BelongsTo
    {
        return $this->belongsTo(Contest::class);
    }
}
