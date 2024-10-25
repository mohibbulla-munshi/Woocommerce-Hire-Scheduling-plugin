<?php
/**
 * Plugin Name: WooCommerce Hire Scheduling
 * Description: Adds a hire scheduling system to WooCommerce products with dynamic pricing based on the hire duration.
 * Version: 1.0.0
 * Author: Mohibbulla Munshi
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Enqueue necessary scripts for the date picker and AJAX.
add_action('wp_enqueue_scripts', 'whs_enqueue_scripts');
function whs_enqueue_scripts() {
    if (is_product()) {
        // Enqueue jQuery UI for date pickers
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        
        // Enqueue custom script for hire scheduling
        wp_enqueue_script('whs-hire-scheduler', plugins_url('/assets/js/hire-scheduler.js', __FILE__), array('jquery', 'jquery-ui-datepicker'), null, true);
        
        // Pass product ID and AJAX URL to the script
        global $product;
        wp_localize_script('whs-hire-scheduler', 'whs_data', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'product_id' => $product->get_id(),
        ));
    }
}

// Add custom hire fields to the product page (hire start date, hire end date)
add_action('woocommerce_before_add_to_cart_button', 'whs_add_hire_fields');
function whs_add_hire_fields() {
    echo '<div class="hire-scheduler">';
    echo '<h4>You Can Hire this Product</h4>';
    echo '<label for="hire_start_date">Hire Start Date: </label>';
    echo '<input type="text" id="hire_start_date" name="hire_start_date" required><br>';
    echo '<label for="hire_end_date">Hire End Date: </label>';
    echo '<input type="text" id="hire_end_date" name="hire_end_date" required><br>';
    echo '<div><strong>New Price: </strong><span id="dynamic-price"></span></div>'; // Updated here
    echo '</div>';
    echo '<br>';
}

// Save hire dates to the cart when the product is added.
add_filter('woocommerce_add_cart_item_data', 'whs_add_cart_item_data', 10, 2);
function whs_add_cart_item_data($cart_item_data, $product_id) {
    if (isset($_POST['hire_start_date']) && isset($_POST['hire_end_date'])) {
        $cart_item_data['hire_start_date'] = sanitize_text_field($_POST['hire_start_date']);
        $cart_item_data['hire_end_date'] = sanitize_text_field($_POST['hire_end_date']);
        $cart_item_data['unique_key'] = md5(microtime().rand()); // Ensure unique cart item.
    }
    return $cart_item_data;
}

// Display hire dates in the cart and checkout pages.
add_filter('woocommerce_get_item_data', 'whs_display_hire_dates_in_cart', 10, 2);
function whs_display_hire_dates_in_cart($item_data, $cart_item) {
    if (isset($cart_item['hire_start_date'])) {
        $item_data[] = array(
            'name' => 'Hire Start Date',
            'value' => $cart_item['hire_start_date'],
        );
    }
    if (isset($cart_item['hire_end_date'])) {
        $item_data[] = array(
            'name' => 'Hire End Date',
            'value' => $cart_item['hire_end_date'],
        );
    }
    return $item_data;
}

// Apply dynamic pricing based on hire duration during cart calculation.
add_action('woocommerce_before_calculate_totals', 'whs_apply_dynamic_pricing');
function whs_apply_dynamic_pricing($cart) {
    // Avoid applying in admin or during AJAX requests
    if (is_admin() && !defined('DOING_AJAX')) return;

    foreach ($cart->get_cart() as $cart_item) {
        if (isset($cart_item['hire_start_date']) && isset($cart_item['hire_end_date'])) {
            $start_date = strtotime($cart_item['hire_start_date']);
            $end_date = strtotime($cart_item['hire_end_date']);
            $days_hired = ($end_date - $start_date) / (60 * 60 * 24); // Calculate number of days

            // Debug log for understanding pricing
            error_log("Product ID: {$cart_item['product_id']}, Start Date: {$cart_item['hire_start_date']}, End Date: {$cart_item['hire_end_date']}, Days Hired: $days_hired");

            // Adjust price only if valid days hired
            if ($days_hired > 0) {
                $base_price = $cart_item['data']->get_price();
                $new_price = $base_price * $days_hired; // Calculate new price
                
                // Set the new price
                $cart_item['data']->set_price($new_price);
                error_log("New Price for Product ID: {$cart_item['product_id']} is $new_price");
            } else {
                // Reset price to base if invalid duration
                $cart_item['data']->set_price($cart_item['data']->get_regular_price());
                error_log("Resetting price for Product ID: {$cart_item['product_id']} to base price.");
            }
        }
    }
}

// Ensure the final price is used in the order
add_action('woocommerce_checkout_create_order_line_item', 'whs_checkout_order_line_item', 10, 4);
function whs_checkout_order_line_item($item, $cart_item_key, $values, $order) {
    // This ensures the order item total reflects the calculated price
    if (isset($values['hire_start_date']) && isset($values['hire_end_date'])) {
        $item->set_total($values['data']->get_price());
        $item->set_subtotal($values['data']->get_price());
    }
}

// Handle the AJAX request to calculate the dynamic price.
add_action('wp_ajax_whs_calculate_price', 'whs_calculate_price');
add_action('wp_ajax_nopriv_whs_calculate_price', 'whs_calculate_price');
function whs_calculate_price() {
    if (isset($_POST['start_date'], $_POST['end_date'], $_POST['product_id'])) {
        $product_id = intval($_POST['product_id']);
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);

        // Validate date formats
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);

        // Ensure the hire period is valid
        if ($start_timestamp && $end_timestamp && $end_timestamp > $start_timestamp) {
            // Calculate duration in days
            $days_hired = ($end_timestamp - $start_timestamp) / (60 * 60 * 24);

            // Get product and base price
            $product = wc_get_product($product_id);
            $base_price = $product->get_price();

            // Calculate new price based on hire duration
            $new_price = $base_price * $days_hired;

            // Format price according to WooCommerce settings
            $formatted_price = wc_price($new_price); // Use wc_price() to format price

            // Return the formatted price
            wp_send_json_success(array('new_price' => $formatted_price));
        } else {
            wp_send_json_error('Invalid hire duration or dates.');
        }
    } else {
        wp_send_json_error('Invalid data received.');
    }
}
