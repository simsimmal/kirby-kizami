<?php

namespace Kizami;

/** An error the report and snapshot routes answer with as JSON, with its HTTP status. */
final class ReportError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
