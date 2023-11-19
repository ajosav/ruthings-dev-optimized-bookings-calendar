<?php

namespace Runthings\WcBookingsAvailabilityCache\Base;

/**
 * @package  WcBookingsAvailabilityCache
 */

class RuthingsDevWCBookingsCacheActivate
{
    public static function activate()
    {
        register_activation_hook(RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_ABS_PATH, array(static::class, 'check_dependency'));
        add_action( 'plugins_loaded', array(static::class, 'check_dependency'));

        if (!self::check_dependency()) {
            return;
        }

        // Flush Permalinks.
        flush_rewrite_rules();
    }

    public static function check_dependency()
    {
        if (!self::is_bookings_installed())
        {
            // Deactivate bookings cache
            if ( function_exists( 'deactivate_plugins' ) ) {
                deactivate_plugins(plugin_basename(RUNTHINGS_WC_BOOKINGS_CACHE_AVAILABILITY_ABS_PATH));
            }

            // Notify admin
            add_action( 'admin_notices', array(static::class, 'woocommerce_bookings_not_installed_wc_notice' ) );
            return false;
        }

        return true;
    }

    public static function woocommerce_bookings_not_installed_wc_notice()
    {
        $error_message = __( 'Woocommerce Booking Availability Cache requires Woocommerce Bookings plugin activated.', 'runthings-wc-bookings-cache-availability' );
        echo wp_kses_post( sprintf( '<div class="error">%s %s</div>', wpautop( $error_message ), wpautop( 'Plugin <strong>deactivated</strong>.' ) ) );
    }

    /**
     * Returns true if bookings is installed/active and false if not
     *
     * @return boolean
     */
    public static function is_bookings_installed()
    {
        $active_plugins = (array) get_option('active_plugins', []);
        // Notice the typo on plugin's file for Bookings <= 1.9.10.
        $old_booking_file = 'woocommerce-bookings/woocommmerce-bookings.php';
        $booking_file     = 'woocommerce-bookings/woocommerce-bookings.php';

        return (
            in_array($booking_file, $active_plugins, true)
            ||
            array_key_exists($booking_file, $active_plugins)
            ||
            class_exists('WC_Bookings')
            ||
            in_array($old_booking_file, $active_plugins, true)
            ||
            array_key_exists($old_booking_file, $active_plugins)
        );
    }
}
