<?php

namespace Runthings\WcBookingsAvailabilityCache\Integrations;

use Exception;
use WC_Booking;
use WC_Bookings_Controller;
use WC_Product;
use WC_Product_Booking;

/**
 * @package  WCBookingsCacheController
 */

class WCBookingsAvailabilityCacheController extends WC_Bookings_Controller
{
    /**
     * Finds days which are partially booked & fully booked already.
     *
     * This function will get a general min/max Booking date, which initially is [today, today + 1 year]
     * Based on the Bookings retrieved from that date, it will shrink the range to the [Bookings_min, Bookings_max]
     * For the newly generated range, it will determine availability of dates by calling `wc_bookings_get_time_slots` on it.
     *
     * Depending on the data returned from it we set:
     * Fully booked days     - for those dates that there are no more slot available
     * Partially booked days - for those dates that there are some slots available
     *
     * @param WC_Product_Booking|int $bookable_product    Bookable product.
     * @param int                    $min_date            Min date to check for bookings.
     * @param int                    $max_date            Max date to check for bookings.
     * @param string                 $default_date_format Date format to use for the returned array.
     * @param int                    $timezone_offset     Timezone offset in hours.
     * @param array                  $resource_ids        Array of resource ids.
     *
     * @return void|array( 'partially_booked_days', 'fully_booked_days' ) Array of booked days.
     * @throws Exception When the product is not a bookable product.
     */
    public static function find_booked_day_blocks( $bookable_product, $min_date = 0, $max_date = 0, $default_date_format = 'Y-n-j', $timezone_offset = 0, $resource_ids = array() ) {
        $booked_day_blocks = array(
            'partially_booked_days' => array(),
            'fully_booked_days'     => array(),
            'unavailable_days'      => array(),
        );

        $timezone_offset = $timezone_offset * HOUR_IN_SECONDS;

        if ( is_int( $bookable_product ) ) {
            $bookable_product = wc_get_product( $bookable_product );
        }

        if ( ! is_a( $bookable_product, 'WC_Product_Booking' ) ) {
            return $booked_day_blocks;
        }

        // Check if the first and the last days are available or not.
        // The first day may have some minimum bookable time in the future,
        // and the time is passed enough, leaving no more blocks available
        // for that day, In that case, already considered available first
        // day should be marked as unavailable. Same applies to the last day.
        $first_day_end   = strtotime( 'tomorrow', $min_date ) - 1;
        $last_day_starts = strtotime( 'midnight', $max_date );

        // For products without resources, pass 0 in $resource_ids to check product-level availability.
        $resource_ids_loop = count( $resource_ids ) ? $resource_ids : array( 0 );
        foreach ( $resource_ids_loop as $resource_id ) {
            // Get the blocks available after checking existing rules.
            $first_day_blocks = $bookable_product->get_blocks_in_range( $min_date, $first_day_end, array(), $resource_id );
            // Get the blocks available after checking existing bookings.
            $first_day_blocks = wc_bookings_get_time_slots( $bookable_product, $first_day_blocks, array(), $resource_id, $min_date, $first_day_end );

            // If no blocks available for the minimum bookable (first) day, mark it unavailable.
            if ( 0 === count( $first_day_blocks ) ) {
                // phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
                $min_date_format = date( $default_date_format, $min_date );

                $booked_day_blocks['unavailable_days'][ $min_date_format ][0] = 1;
                if ( $bookable_product->has_resources() ) {
                    foreach ( $bookable_product->get_resources() as $resource ) {
                        $booked_day_blocks['unavailable_days'][ $min_date_format ][ $resource->ID ] = 1;
                    }
                }
            }

            // If $max_date and $last_day_starts are same, it means the $max_date
            // is reset and coming from the Calendar instead of the setting.
            // See WC_Bookings_WC_Ajax::find_booked_day_blocks for more information.
            // In this case, no need to check the last day availability.
            if ( $max_date === $last_day_starts ) {
                continue;
            }

            // Get the blocks available after checking existing rules.
            $last_day_blocks = $bookable_product->get_blocks_in_range( $last_day_starts, $max_date, array(), $resource_id );
            // Get the blocks available after checking existing bookings.
            $last_day_blocks = wc_bookings_get_time_slots( $bookable_product, $last_day_blocks, array(), $resource_id, $last_day_starts, $max_date );

            // If no blocks available for the maximum bookable (last) day, mark it unavailable.
            if ( 0 === count( $last_day_blocks ) ) {
                // phpcs:disable WordPress.DateTime.RestrictedFunctions.date_date
                $max_date_format = date( $default_date_format, $max_date );

                $booked_day_blocks['unavailable_days'][ $max_date_format ][0] = 1;
                if ( $bookable_product->has_resources() ) {
                    foreach ( $bookable_product->get_resources() as $resource ) {
                        $booked_day_blocks['unavailable_days'][ $max_date_format ][ $resource->ID ] = 1;
                    }
                }
            }
        }

        // Get existing bookings and go through them to set partial/fully booked days.
        $existing_bookings = WC_Booking_Data_Store::get_all_existing_bookings( $bookable_product, $min_date, $max_date );

        if ( empty( $existing_bookings ) ) {
            return $booked_day_blocks;
        }

        // phpcs:disable WordPress.DateTime.CurrentTimeTimestamp.Requested
        $min_booking_date = strtotime( '+100 years', current_time( 'timestamp' ) );
        // phpcs:disable WordPress.DateTime.CurrentTimeTimestamp.Requested
        $max_booking_date = strtotime( '-100 years', current_time( 'timestamp' ) );
        $bookings         = array();
        $day_format       = 1 === (int) $bookable_product->get_qty() ? 'unavailable_days' : 'partially_booked_days';

        // Find the minimum and maximum booking dates and store the booking data in an array for further processing.
        foreach ( $existing_bookings as $existing_booking ) {
            if ( ! is_a( $existing_booking, 'WC_Booking' ) ) {
                continue;
            }
            $check_date    = strtotime( 'midnight', $existing_booking->get_start() + $timezone_offset );
            $check_date_to = strtotime( 'midnight', $existing_booking->get_end() + $timezone_offset );
            $resource_id   = $existing_booking->get_resource_id();

            if ( ! empty( $resource_ids ) && ! in_array( $resource_id, $resource_ids, true ) ) {
                continue;
            }

            // If it's a booking on the same day, move it before the end of the current day.
            if ( $check_date_to === $check_date ) {
                $check_date_to = strtotime( '+1 day', $check_date ) - 1;
            }

            $min_booking_date = min( $min_booking_date, $check_date );
            $max_booking_date = max( $max_booking_date, $check_date_to );

            // If the booking duration is day, make sure we add the (duration) days to unavailable days.
            // This will mark them as white on the calendar, since they are not fully booked, but rather
            // unavailable. The difference is that a booking extending to those days is allowed.
            if ( 1 < $bookable_product->get_duration() && 'day' === $bookable_product->get_duration_unit() ) {

                $amount_of_buffer_days = $bookable_product->get_buffer_period();

                if ( $bookable_product->get_apply_adjacent_buffer() ) {
                    $amount_of_buffer_days *= 2;
                }

                $duration_with_buffer = $bookable_product->get_duration() + $amount_of_buffer_days;

                // This buffer only gets applied from the left hand side, the buffer on the right hand side will get processed elsewhere.
                $check_new_date = strtotime( '-' . ( $duration_with_buffer - 1 ) . ' days', $check_date );

                // Mark the days between the fake booking and the actual booking as unavailable.
                while ( $check_new_date < $check_date ) {
                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $date_format = date( $default_date_format, $check_new_date );
                    $booked_day_blocks[ $day_format ][ $date_format ][ $resource_id ] = 1;
                    $check_new_date = strtotime( '+1 day', $check_new_date );
                }
            }

            $bookings[] = array(
                'start' => $check_date,
                'end'   => $check_date_to,
                'res'   => $resource_id,
            );
        }

        $max_booking_date = strtotime( '+1 day', $max_booking_date );

        /*
         * Changing the booking timestamps according to the local timezone.
         * Fix for https://github.com/woocommerce/woocommerce-bookings/issues/3069
         */
        $min_booking_date = strtotime( 'midnight', $min_booking_date - $timezone_offset );
        $max_booking_date = strtotime( 'midnight', $max_booking_date - $timezone_offset );

        // phpcs:ignore PHPCompatibility.FunctionUse.ArgumentFunctionsReportCurrentValue.Changed -- Reason: value is unchanged.
        $arg_list = func_get_args();
        $source   = $arg_list[6] ?? 'user';

        // If the seventh argument is `action-scheduler-helper`, just schedule an event and return void.
        if ( 'action-scheduler-helper' === $source ) {
            $product_id = $bookable_product->get_id();

            // Create action scheduler event only if it's not already created
            // OR it's not running right now (i.e. 'cache_update_started' meta does not exist).
            $clear_cache_event = as_has_scheduled_action( 'wc-booking-scheduled-update-availability', array( $product_id, $min_booking_date, $max_booking_date, $timezone_offset ) );

            // Append unique transient name in metas to support multiple event creations.
            $transient_name       = 'book_ts_' . md5( http_build_query( array( $product_id, 0, $min_booking_date, $max_booking_date, false ) ) );
            $cache_update_started = get_post_meta( $product_id, 'cache_update_started-' . $transient_name, true );

            if ( ! $clear_cache_event && ! $cache_update_started ) {

                // Schedule event.
                as_schedule_single_action( time(), 'wc-booking-scheduled-update-availability', array( $product_id, $min_booking_date, $max_booking_date, $timezone_offset ) );

                // Preserve previous availability to serve to users until new one is generated.
                $available_slots = WC_Bookings_Cache::get( $transient_name );
                if ( $available_slots ) {
                    WC_Bookings_Cache::set( 'prev-availability-' . $transient_name, $available_slots, 5 * MINUTE_IN_SECONDS );
                }

                // Track that the event is scheduled via a meta, in order to show users the old availability until it runs.
                update_post_meta( $product_id, 'cache_update_scheduled-' . $transient_name, true );
            }

            return;
        }

        // Call these for the whole chunk range for the bookings since they're expensive.
        $blocks = $bookable_product->get_blocks_in_range( $min_booking_date, $max_booking_date );

        // The following loop is needed when:
        // - The product is not available by default.
        // - The product has no availability and the availability is provided by the resources.
        // We need to loop trough the resources to get the blocs in range that would be missing from the product.
        // We are limiting it to products with customer selected resources because it is expensive and there are no requests for automatically selected resources.
        if ( ! $bookable_product->get_default_availability() && $bookable_product->has_resources() && ! $bookable_product->is_resource_assignment_type( 'automatic' ) ) {
            foreach ( $bookable_product->get_resources() as $resource ) {
                $resource_id     = $resource->get_id();
                $resource_blocks = $bookable_product->get_blocks_in_range( $min_booking_date, $max_booking_date, array(), $resource_id );

                $blocks = array_unique( array_merge( $blocks, $resource_blocks ) );
                sort( $blocks );
            }
        }

        // Passing seventh and eight arguments to know whether an event is scheduled or not.
        $available_blocks = wc_bookings_get_time_slots( $bookable_product, $blocks, array(), 0, $min_booking_date, $max_booking_date, false, $timezone_offset, $source );

        $booked_day_blocks['old_availability'] = isset( $available_blocks['old_availability'] ) && true === $available_blocks['old_availability'];
        unset( $available_blocks['old_availability'] );

        $available_slots = array();

        // Update existing available blocks availability according to the customer's local timezone.
        $available_blocks = self::update_booking_availability_for_customers_timezone( $available_blocks, $timezone_offset );

        foreach ( $available_blocks as $block => $quantity ) {
            foreach ( $quantity['resources'] as $resource_id => $availability ) {
                if ( ! empty( $resource_ids ) && ! in_array( $resource_id, $resource_ids, true ) ) {
                    continue;
                }

                if ( $availability > 0 ) {
                    // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
                    $available_slots[ $resource_id ][] = date( $default_date_format, $block );
                }
            }
        }
        // Go through [start, end] of each of the bookings by chunking it in days: [start, start + 1d, start + 2d, ..., end]
        // For each of the chunk check the available slots. If there are no slots, it is fully booked, otherwise partially booked.
        $booking_type = '';
        foreach ( $bookings as $booking ) {
            $check_date = $booking['start'];
            while ( $check_date <= $booking['end'] ) {
                if ( self::is_booking_past_midnight_and_before_start_time( $booking, $bookable_product, $check_date ) ) {
                    $check_date = strtotime( '+1 day', $check_date );
                    continue;
                }

                $date_format  = date( $default_date_format, $check_date );
                $booking_type = isset( $available_slots[ $booking['res'] ] ) && in_array( $date_format, $available_slots[ $booking['res'] ], true ) ? 'partially_booked_days' : 'fully_booked_days';

                $booked_day_blocks[ $booking_type ][ $date_format ][ $booking['res'] ] = 1;

                $check_date = strtotime( '+1 day', $check_date );
            }
        }

        /**
         * Filter the booked day blocks calculated per project.
         *
         * @since 1.9.13
         *
         * @param array $booked_day_blocks {
         *  @type array $partially_booked_days
         *  @type array $fully_booked_days
         * }
         * @param WC_Product $bookable_product
         */
        return apply_filters( 'woocommerce_bookings_booked_day_blocks', $booked_day_blocks, $bookable_product );
    }

    /**
     * For hour bookings types check that the booking is past midnight and before start time.
     * This only works for the very next day after booking start.
     *
     * @since 1.10.7
     *
     * @param WC_Booking         $booking    Booking object.
     * @param WC_Product_Booking $product    Bookable product object.
     * @param string             $check_date Date to check.
     *
     * @return boolean True if booking is past midnight and before start time.
     */
    private static function is_booking_past_midnight_and_before_start_time( $booking, $product, $check_date ) {
        // This handles bookings overlapping midnight when slots only start
        // from a specific hour.
        $start_time = $product->get_first_block_time();

        return (
            'hour' === $product->get_duration_unit()
            && ! empty( $start_time )
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            && date( 'md', $booking['end'] ) === ( date( 'md', $check_date ) )
            // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
            && (int) str_replace( ':', '', $start_time ) > (int) date( 'Hi', $booking['end'] )
        );
    }


}