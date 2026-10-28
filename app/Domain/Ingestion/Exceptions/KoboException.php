<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

/**
 * Anything that stopped a request to KoboToolbox (see SourceException: the same kinds as ODK's,
 * never the API token in the message).
 */
abstract class KoboException extends SourceException {}
