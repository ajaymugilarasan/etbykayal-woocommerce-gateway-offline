<?php

/**
 * Plugin Name: WooCommerce Offline Gateway
 * Plugin URI: https://www.skyverge.com/?p=3343
 * Description: Clones the "Cheque" gateway to create another manual / offline payment method; can be used for testing as well.
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com/
 * Version: 1.0.2
 * Text Domain: wc-gateway-offline
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2015-2016 SkyVerge, Inc. (info@skyverge.com) and WooCommerce
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Gateway-Offline
 * @author    SkyVerge
 * @category  Admin
 * @copyright Copyright (c) 2015-2016, SkyVerge, Inc. and WooCommerce
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 * This offline gateway forks the WooCommerce core "Cheque" payment gateway to create another offline payment method.
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
  return;
}


/**
 * Add the gateway to WC Available Gateways
 * 
 * @since 1.0.0
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + offline gateway
 */
function wc_offline_add_to_gateways($gateways)
{
  $gateways[] = 'WC_Gateway_Offline';
  return $gateways;
}
add_filter('woocommerce_payment_gateways', 'wc_offline_add_to_gateways');


/**
 * Adds plugin page links
 * 
 * @since 1.0.0
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 */
function wc_offline_gateway_plugin_links($links)
{

  $plugin_links = array(
    '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=offline_gateway') . '">' . __('Configure', 'wc-gateway-offline') . '</a>'
  );

  return array_merge($plugin_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_offline_gateway_plugin_links');


/**
 * Offline Payment Gateway
 *
 * Provides an Offline Payment Gateway; mainly for testing purposes.
 * We load it later to ensure WC is loaded first since we're extending it.
 *
 * @class 		WC_Gateway_Offline
 * @extends		WC_Payment_Gateway
 * @version		1.0.0
 * @package		WooCommerce/Classes/Payment
 * @author 		SkyVerge
 */
add_action('plugins_loaded', 'wc_offline_gateway_init', 11);

function wc_offline_gateway_init()
{

  class WC_Gateway_Offline extends WC_Payment_Gateway
  {

    /**
     * Constructor for the gateway.
     */
    public function __construct()
    {

      $this->id                 = 'offline_gateway';
      $this->icon               = apply_filters('woocommerce_offline_icon', '');
      $this->has_fields         = false;
      $this->method_title       = __('Offline PG for International Orders', 'wc-gateway-offline');
      $this->method_description = __('Allows offline payments. Very handy if you use your cheque gateway for another payment method, and can help with testing. Orders are marked as "on-hold" when received.', 'wc-gateway-offline');

      // Load the settings.
      $this->init_form_fields();
      $this->init_settings();

      // Define user set variables
      $this->title        = $this->get_option('title');
      $this->description  = $this->get_option('description');
      $this->instructions = $this->get_option('instructions', $this->description);

      // Actions
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

      // Customer Emails
      add_action('woocommerce_email_before_order_table', array($this, 'email_instructions'), 10, 3);
    }


    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields()
    {

      $this->form_fields = apply_filters('wc_offline_form_fields', array(

        'enabled' => array(
          'title'   => __('Enable/Disable', 'wc-gateway-offline'),
          'type'    => 'checkbox',
          'label'   => __('Enable Offline Payment', 'wc-gateway-offline'),
          'default' => 'yes'
        ),

        'title' => array(
          'title'       => __('Title', 'wc-gateway-offline'),
          'type'        => 'text',
          'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-offline'),
          'default'     => __('Offline Payment', 'wc-gateway-offline'),
          'desc_tip'    => true,
        ),

        'description' => array(
          'title'       => __('Description', 'wc-gateway-offline'),
          'type'        => 'textarea',
          'description' => __('Payment method description that the customer will see on your checkout.', 'wc-gateway-offline'),
          'default'     => __('Please remit payment to Store Name upon pickup or delivery.', 'wc-gateway-offline'),
          'desc_tip'    => true,
        ),

        'instructions' => array(
          'title'       => __('Instructions', 'wc-gateway-offline'),
          'type'        => 'textarea',
          'description' => __('Instructions that will be added to the thank you page and emails.', 'wc-gateway-offline'),
          'default'     => '',
          'desc_tip'    => true,
        ),
      ));
    }


    /**
     * Output for the order received page.
     */
    public function thankyou_page()
    {
      if ($this->instructions) {
        echo wpautop(wptexturize($this->instructions));
      }
    }


    /**
     * Add content to the WC emails.
     *
     * @access public
     * @param WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     */
    public function email_instructions($order, $sent_to_admin, $plain_text = false)
    {

      if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('on-hold')) {
        echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
      }
    }


    /**
     * Process the payment and return the result
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id)
    {

      $order = wc_get_order($order_id);

      // Mark as on-hold (we're awaiting the payment)
      $order->update_status('pending-payment-i', __('Order received (intl)', 'wc-gateway-offline'));

      // Reduce stock levels
      // $order->reduce_order_stock();

      // Remove cart
      WC()->cart->empty_cart();

      // Return thankyou redirect
      return array(
        'result'   => 'success',
        'redirect'  => $this->get_return_url($order)
      );
    }
  } // end \WC_Gateway_Offline class
}

add_filter('woocommerce_available_payment_gateways', 'disable_payment_gateway_for_international_shipping');

function disable_payment_gateway_for_international_shipping($available_gateways)
{
  global $woocommerce;
  if ($woocommerce->customer->get_shipping_country() == "IN") {
    unset($available_gateways['offline_gateway']);
  }

  if ($woocommerce->customer->get_shipping_country() != "IN") {
    unset($available_gateways['cod']);
    unset($available_gateways['paytm']);
  }

  return $available_gateways;
}



/* remove shipping column from emails */
add_filter('woocommerce_get_order_item_totals', 'customize_email_order_line_totals', 1000, 3);
function customize_email_order_line_totals($total_rows, $order, $tax_display)
{
  global $woocommerce;
  if (!is_wc_endpoint_url() || !is_admin() && $woocommerce->customer->get_shipping_country() != "IN") {
    unset($total_rows['shipping']);
  }
  return $total_rows;
}


add_action('restrict_manage_posts', 'display_admin_shop_order_language_filter');
function display_admin_shop_order_language_filter()
{
  global $pagenow, $post_type;

  if ('shop_order' === $post_type && 'edit.php' === $pagenow) {
    $domain    = 'woocommerce';
    $languages = ['International', 'COD'];
    $current   = isset($_GET['shipping_type']) ? $_GET['shipping_type'] : '';

    echo '<select name="shipping_type">
        <option value="">' . __('Custom Filter', $domain) . '</option>';

    foreach ($languages as $value) {
      printf(
        '<option value="%s"%s>%s</option>',
        $value,
        $value === $current ? '" selected="selected"' : '',
        $value
      );
    }
    echo '</select>';
  }
}

// add_action('pre_get_posts', 'process_admin_shop_order_language_filter');
function process_admin_shop_order_language_filter($query)
{
  global $pagenow;

  if (
    $query->is_admin && $pagenow == 'edit.php' && isset($_GET['shipping_type'])
    && $_GET['shipping_type'] != '' && $_GET['post_type'] == 'shop_order'
  ) {

    $meta_query = [];
    $meta_query[] = $query->get('meta_query'); // Get the current "meta query"

    // var_dump($meta_query);
    if ($_GET['shipping_type'] == 'International') {
      $meta_query[] = array( // Add to "meta query"
        'meta_key' => '_payment_method',
        'value'    => 'offline_gateway',
        'compare' => '='
      );
    } elseif ($_GET['shipping_type'] == 'COD') {
      $meta_query[] = array( // Add to "meta query"
        'meta_key' => '_payment_method',
        'value'    => 'cod',
        'compare' => '='
      );
    }
    $query->set('meta_query', $meta_query); // Set the new "meta query"

    // $query->set('paged', (get_query_var('paged') ? get_query_var('paged') : 1)); 
  }
}





add_action('woocommerce_thankyou', 'add_international_meta_key', 10, 1);
function add_international_meta_key($order_id)
{
  if (!$order_id)
    return;

  // Allow code execution only once 
  if (!get_post_meta($order_id, '_is_international_order', true)) {

    // Get an instance of the WC_Order object
    $order = wc_get_order($order_id);

    // Get the order key
    $order_key = $order->get_order_key();

    // Get the order number
    $order_key = $order->get_order_number();

    if ($order->get_shipping_country() != 'IN') {
      $order->update_meta_data('_is_international_order', true);
      $order->save();
    }

    // Flag the action as done (to avoid repetitions on reload for example)

  }
}



function filter_manage_edit_shop_order_columns($columns)
{
  // Add new column after order status (4) column
  return array_slice($columns, 0, 4, true)
    + array('order_payment_method' => __('Payment method', 'woocommerce'))
    + array_slice($columns, 4, NULL, true);
}
add_filter('manage_edit-shop_order_columns', 'filter_manage_edit_shop_order_columns', 10, 1);

// Display details after order status column, on order admin list (populate the column)
function action_manage_shop_order_posts_custom_column($column, $post_id)
{
  // Compare
  if ($column == 'order_payment_method') {
    // Get order
    $order = wc_get_order($post_id);

    // Get the payment method
    $payment_method = $order->get_payment_method();

    // NOT empty
    if (!empty($payment_method)) {
      echo ($payment_method);
    } else {
      echo __('N/A', 'woocommerce');
    }
  }
}
add_action('manage_shop_order_posts_custom_column', 'action_manage_shop_order_posts_custom_column', 10, 2);

function filter_manage_edit_shop_order_columns_shipping_type($columns)
{
  // Add new column after order status (4) column
  return array_slice($columns, 0, 4, true)
    + array('shipping_type' => __('Shipping Type', 'woocommerce'))
    + array_slice($columns, 4, NULL, true);
}
add_filter('manage_edit-shop_order_columns', 'filter_manage_edit_shop_order_columns_shipping_type', 10, 1);

// Display details after order status column, on order admin list (populate the column)
function action_manage_shop_order_posts_custom_column_shipping_type($column, $post_id)
{
  // Compare
  if ($column == 'shipping_type') {
    // Get order
    $order = wc_get_order($post_id);

    if ($order->get_shipping_country() != 'IN') {
      echo "<span style='border: 1px solid var(--wp-admin-theme-color); color: var(--wp-admin-theme-color); padding: 2px; border-radius: 3px;'>International - " . WC()->countries->countries[$order->get_shipping_country()] . '</span>';
    } else {
      echo "Domestic";
    }
  }
}
add_action('manage_shop_order_posts_custom_column', 'action_manage_shop_order_posts_custom_column_shipping_type', 10, 2);


// Filter request
function filter_request($vars)
{
  global $pagenow, $typenow;

  // Filter ID
  $filter_id = 'filter-by-payment';

  // Only on WooCommerce admin orders list
  if ($pagenow == 'edit.php' && 'shop_order' === $typenow && isset($_GET['shipping_type']) && !empty($_GET['shipping_type']) && $_GET['shipping_type'] == 'International') {
    $vars['meta_key']   = '_is_international_order';
    $vars['meta_value'] = true;
  }

  return $vars;
}
add_filter('request', 'filter_request', 10, 1);



add_action('woocommerce_checkout_process', 'wc_minimum_order_amount');
add_action('woocommerce_before_cart', 'wc_minimum_order_amount');

function wc_minimum_order_amount()
{
  // Set this variable to specify a minimum order value
  global $woocommerce;
  $minimum = 3000;

  if ($woocommerce->customer->get_shipping_country() != "IN") {
    if (WC()->cart->total < 3000) {

      if (is_cart()) {

        wc_print_notice(
          sprintf(
            'Your current order total is %s — you must have an order with a minimum of %s to place your order ',
            wc_price(WC()->cart->total),
            wc_price(3000)
          ),
          'error'
        );
      } else {

        wc_add_notice(
          sprintf(
            'Your current order total is %s — you must have an order with a minimum of %s to place your order',
            wc_price(WC()->cart->total),
            wc_price(3000)
          ),
          'error'
        );
      }
    }
  } else {
    if (WC()->cart->total < 200) {

      if (is_cart()) {

        wc_print_notice(
          sprintf(
            'Your current order total is %s — you must have an order with a minimum of %s to place your order ',
            wc_price(WC()->cart->total),
            wc_price(200)
          ),
          'error'
        );
      } else {

        wc_add_notice(
          sprintf(
            'Your current order total is %s — you must have an order with a minimum of %s to place your order',
            wc_price(WC()->cart->total),
            wc_price(200)
          ),
          'error'
        );
      }
    }
  }
}
