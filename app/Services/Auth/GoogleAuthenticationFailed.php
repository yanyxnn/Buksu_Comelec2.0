<?php

namespace App\Services\Auth;

use RuntimeException;

/** The Google leg failed for any reason (state mismatch, network, malformed payload…). Always fail closed. */
class GoogleAuthenticationFailed extends RuntimeException {}
