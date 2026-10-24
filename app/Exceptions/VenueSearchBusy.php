<?php

namespace App\Exceptions;

use RuntimeException;

class VenueSearchBusy extends RuntimeException
{
    public function __construct(public readonly int $retryAfter = 1)
    {
        parent::__construct('The venue search service is busy.');
    }
}
