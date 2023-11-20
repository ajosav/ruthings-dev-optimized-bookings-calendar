<?php

namespace Runthings\WcBookingsAvailabilityCache;

use Exception;
use WC_Bookings_Controller;
use WC_Bookings_WC_Ajax;


/**
 * @package  WcBookingsAvailabilityCache
 */

class WooBokingsAvailabilityMiddleware
{
    public function __construct()
    {
        add_action('init', [$this, 'remove_wc_bookings_find_booked_day_blocks']);
        add_action('wc_ajax_wc_bookings_find_booked_day_blocks', [$this, 'wc_cache_find_booked_day_blocks']);
    }

    public function wc_cache_find_booked_day_blocks()
    {
        check_ajax_referer('find-booked-day-blocks', 'security');
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
        $product_id  = !empty($_GET['product_id']) ? absint($_GET['product_id']) : null;
        $resource_id = !empty($_GET['resource_id']) ? absint($_GET['resource_id']) : null;

        if (empty($product_id)) {
            wp_send_json_error('Missing product ID');
            exit;
        }

        try {

            $args                          = array();

            // cache product if it's already checked
            $product                       = $this->get_product_by_id($product_id);
            $args['availability_rules']    = array();
            $args['availability_rules'][0] = $product->get_availability_rules();

            $get_min_date = $product->get_min_date();
            $get_max_date = $product->get_max_date();

            // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
            $min_date_bookable = strtotime("+{$get_min_date['value']} {$get_min_date['unit']}", current_time('timestamp'));
            // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
            $max_date_bookable = strtotime("+{$get_max_date['value']} {$get_max_date['unit']}", current_time('timestamp'));

            // If a buffer is used, subtract it from the min date bookable in the
            // future to cover and display the bookings made during that time.
            // Fix for https://github.com/woocommerce/woocommerce-bookings/issues/3509.
            $interval_in_minutes   = $product->get_time_interval_in_minutes();
            $amount_of_buffer_days = $product->get_buffer_period();
            $buffer_in_seconds     = $interval_in_minutes * $amount_of_buffer_days * 60;
            $min_date_bookable     = $min_date_bookable - $buffer_in_seconds;

            // If the date is provided, use it only if it is a valid Unix timestamp, and it is after/before the min/max bookable time.
            // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
            $min_date = $args['min_date'] = isset($_GET['min_date'])
                && false !== strtotime(sanitize_text_field(wp_unslash($_GET['min_date'])))
                && strtotime(sanitize_text_field(wp_unslash($_GET['min_date']))) > $min_date_bookable ? strtotime(sanitize_text_field(wp_unslash($_GET['min_date']))) : $min_date_bookable;

            // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
            $max_date = $args['max_date'] = isset($_GET['max_date'])
                && false !== strtotime(sanitize_text_field(wp_unslash($_GET['max_date'])))
                && strtotime(sanitize_text_field(wp_unslash($_GET['max_date']))) < $max_date_bookable ? strtotime(sanitize_text_field(wp_unslash($_GET['max_date']))) : $max_date_bookable;

            $timezone_offset = isset($_GET['timezone_offset']) ? sanitize_text_field(wp_unslash($_GET['timezone_offset'])) : 0;

            if ($product->has_resources()) {
                foreach ($product->get_resources() as $resource) {
                    $args['availability_rules'][$resource->ID] = $product->get_availability_rules($resource->ID);
                }
            }
            // Cache booked day blocks
            $booked = WC_Bookings_Controller::find_booked_day_blocks(
                $product_id,
                $min_date,
                $max_date,
                'Y-n-j',
                $timezone_offset,
                $resource_id ? array($resource_id) : array()
            );

            $args['partially_booked_days'] = $booked['partially_booked_days'];
            $args['fully_booked_days']     = $booked['fully_booked_days'];
            $args['unavailable_days']      = $booked['unavailable_days'];
            $args['restricted_days']       = $product->has_restricted_days() ? $product->get_restricted_days() : false;
            $args['old_availability']      = isset($booked['old_availability']) && true === $booked['old_availability'];

            $buffer_days = array();
            if (!in_array($product->get_duration_unit(), array('minute', 'hour'), true)) {
                $buffer_days = WC_Bookings_Controller::get_buffer_day_blocks_for_booked_days($product, $args['fully_booked_days']);
            }

            $args['buffer_days'] = $buffer_days;

            /**
             * Filter the find booked day blocks results.
             *
             * @since 1.15.79
             *
             * @param array              $args        Result.
             * @param array              $booked      Booked blocks.
             * @param WC_Product_Booking $product     Product.
             * @param int                $resource_id Resource ID.
             */
            $args = apply_filters('woocommerce_bookings_find_booked_day_blocks', $args, $booked, $product, $resource_id);

            wp_send_json($args);
        } catch (Exception $e) {

            wp_die($e->getMessage());
        }
    }

    public function remove_wc_bookings_find_booked_day_blocks()
    {
        $wc_bookings_ajax_class = WC_Bookings_WC_Ajax::class;
        //        remove_all_actions('wc_ajax_wc_bookings_find_booked_day_blocks');
        remove_action('wc_ajax_wc_bookings_find_booked_day_blocks', [$wc_bookings_ajax_class, 'find_booked_day_blocks']);
    }

    private function get_product_by_id($product_id)
    {
        $transient_name = 'wc_cache_product_post_' . $product_id;
        if ($product = get_transient($transient_name)) {
            return $product;
        }

        $product = get_wc_product_booking($product_id);
        return set_transient($transient_name, $product, DAY_IN_SECONDS);
    }
}
