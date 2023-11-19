<?php

use Runthings\WcBookingsAvailabilityCache\Init;
use Runthings\WcBookingsAvailabilityCache\WooBokingsAvailabilityMiddleware;
use Runthings\WcBookingsAvailabilityCache\Base\RuthingsDevWCBookingsCacheActivate;
use Runthings\WcBookingsAvailabilityCache\Base\RuthingsDevWCBookingsCacheDeactivate;

/**
 * @package WcBookingsAvailabilityCache
 */

/*
 * Plugin Name: WooCommerce Bookings Cache Availability
 * Plugin URI: https://runthings.dev
 * Description: Caches bookings availability and improves calendar performance.
 * Version: 1.0.0
 * Author: Joshua Adebayo
 * Author URI: https://runthings.dev/
 * License: GPLv3 or later
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/
/*
Copyright 2023 Joshua Adebayo

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 3, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/
defined('ABSPATH') || die();

if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

if (!defined('RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_PLUGIN')) {
    define('RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_PLUGIN', plugin_dir_path(__FILE__));
}

if (!defined('RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_ABS_PATH')) {
    define('RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_ABS_PATH', __FILE__);
}

/**
 * Execute upon plugin activation
 */
RuthingsDevWCBookingsCacheActivate::activate(__FILE__);

/**
 * Execute upon plugin deactivation
 */
function deactivate_ruthings_wc_bookings_cache_plugin()
{
    RuthingsDevWCBookingsCacheDeactivate::deactivate();
}
register_deactivation_hook(__FILE__, 'deactivate_ruthings_wc_bookings_cache_plugin');

/**
 * Initialize all the core classes of the plugin
 */
//if (class_exists('Runthings\\WcBookingsAvailabilityCache\\WooBokingsAvailabilityMiddleware')) {
    new WooBokingsAvailabilityMiddleware();
//}
