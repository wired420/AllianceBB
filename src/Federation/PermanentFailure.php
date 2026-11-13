<?php

declare(strict_types=1);

namespace AllianceBB\Federation;

use RuntimeException;

/** Thrown by a job that should not be retried, e.g. the other site rejected it. */
final class PermanentFailure extends RuntimeException
{
}
