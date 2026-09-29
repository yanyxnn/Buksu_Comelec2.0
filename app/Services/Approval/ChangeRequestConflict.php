<?php

namespace App\Services\Approval;

use RuntimeException;

/** The request is no longer PENDING (someone else decided first) or cannot be decided by this actor. */
class ChangeRequestConflict extends RuntimeException {}
