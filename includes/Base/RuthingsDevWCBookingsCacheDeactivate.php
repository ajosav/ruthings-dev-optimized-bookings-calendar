<?php

namespace Runthings\WcBookingsAvailabilityCache\Base;

/**
 * @package  WcBookingsAvailabilityCache
 */

class RuthingsDevWCBookingsCacheDeactivate
{
    public static function deactivate()
    {
        flush_rewrite_rules();
    }
}
