<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Minimal "I cannot get in" note for admins. Never grants access, never touches students. */
class AccessIssueReport extends Model
{
    protected $table = 'access_issue_reports';

    protected $fillable = ['google_email', 'problem_type', 'subject_fingerprint', 'reported_student_id', 'description', 'denial_reason'];
}
