<?php

namespace App\Exceptions;

use RuntimeException;

class VenueSearchUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The venue search service is unavailable.');
    }
}
