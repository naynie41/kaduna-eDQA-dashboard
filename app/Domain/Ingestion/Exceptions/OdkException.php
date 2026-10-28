<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/**
 * Anything that stopped a request to ODK Central (see SourceException: the same kinds on every
 * source, never a password or token in the message).
 */
abstract class OdkException extends SourceException {}
