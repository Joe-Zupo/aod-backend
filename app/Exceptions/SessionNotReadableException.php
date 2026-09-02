<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A session read endpoint (show, captions, timeline, timeline-summary) was hit
 * while the session is still `processing`: the analysis pipeline is mid-run and
 * there is no coherent view to return yet. Thrown by
 * SessionController::assertReadable() and rendered as a 409 in bootstrap/app.php,
 * so every read action stays a single clean return path.
 */
class SessionNotReadableException extends RuntimeException {}
