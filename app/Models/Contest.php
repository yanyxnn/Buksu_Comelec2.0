<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An election contest / position (`contests`), bound to ONE election and ONE election configuration version.
 *
 * Phase 04C manages only the generic fields: name, position label, an optional representation group of the same
 * election, and the voting rule (ContestRule). The `scope` column is left untouched (no approved meaning yet), and
 * nothing here calculates eligibility. Nothing is mass-assignable: rows are written only by ElectionContestService.
 */
class Contest extends Model
{
    protected $table = 'contests';

    protected $guarded = ['*'];

    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    public function configVersion(): BelongsTo
    {
        return $this->belongsTo(ElectionConfigVersion::class, 'election_config_version_id');
    }

    public function representationGroup(): BelongsTo
    {
        return $this->belongsTo(RepresentationGroup::class);
    }

    public function rule(): HasOne
    {
        return $this->hasOne(ContestRule::class);
    }
}
