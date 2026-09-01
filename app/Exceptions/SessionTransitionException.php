<?php

namespace App\Exceptions;

use DomainException;

/**
 * A Session lifecycle transition (start, cancel, ...) was refused because one
 * of its guards was not met. Carries the client-facing message; the
 * SessionController catches this specific type and renders it as a 422, so an
 * unrelated failure raised in the same call path still surfaces as a real
 * error rather than being flattened into a validation-style response.
 */
class SessionTransitionException extends DomainException {}
