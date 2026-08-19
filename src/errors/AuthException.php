<?php

declare(strict_types=1);

namespace justinholtweb\sanka\errors;

/**
 * Raised when credentials are missing, malformed, or rejected by the engine.
 *
 * Distinct from {@see EngineException} because the fix is different: an auth failure means “go fix
 * the settings”, an engine failure means “the engine said no to this URL”.
 */
class AuthException extends SankaException
{
}
