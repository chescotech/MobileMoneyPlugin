<?php
/*
Plugin Name: Chesco Pay for Woocommerce
Description: Make Payment easy with mobile payments MTN, AIRTEL and ZAMTEL 
Version: 1.0.0
Author: Melvin Chipimo
Author URI: https://chipimo.com
text-domain: chesco-pay-woo
*/
require_once ABSPATH . 'wp-admin/includes/plugin.php';
// if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;
// Check if WooCommerce is active, if not then deactivate and show error message
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    deactivate_plugins(plugin_basename(__FILE__));
    wp_die("<strong>Mobile Money Pay</strong> requires <strong>WooCommerce</strong> plugin to work normally. Please activate it or install it from <a href=\"http://wordpress.org/plugins/woocommerce/\" target=\"_blank\">here</a>.<br /><br />Back to the WordPress <a href='" . get_admin_url(null, 'plugins.php') . "'>Plugins page</a>.");
}


add_action('plugins_loaded', 'chesco_payment_init', 11);

function chesco_payment_init()
{
    if (class_exists('WC_Payment_Gateway')) {
        class WC_Chesco_pay_Gateway extends WC_Payment_Gateway
        {

            public function __construct()
            {
                $this->id                 = 'chesco_payment';
                $this->icon               = apply_filters('woocommerce_chesco_icon', plugins_url('/assets/MobileMoneyLogo.png', __FILE__));
                $this->has_fields         = false;
                $this->method_title       = __('Chesco Pay', 'chesco-pay-woo');
                $this->method_description = __('Mobile money local content payment systems.', 'chesco-pay-woo');

                $this->successful_status  = $this->get_option('successful_status');
                $this->title              = $this->get_option('title');
                $this->description        = $this->get_option('description');
                $this->instructions       = $this->get_option('instructions', $this->description);

                $this->init_form_fields();
                $this->init_settings();

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_thankyou_' . $this->id, array($this, 'check_mobile_response'));
            }

            public function init_form_fields()
            {
                $this->form_fields = apply_filters('woo_chesco_pay_fields', array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'chesco-pay-woo'),
                        'type' => 'checkbox',
                        'label' => __('Enable or Disable Mobile Money Payments', 'chesco-pay-woo'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => __('Mobile Money Payment', 'chesco-pay-woo'),
                        'type' => 'text',
                        'default' => __('Mobile Money Payment', 'chesco-pay-woo'),
                        'desc_tip' => true,
                        'description' => __('Add a new title for the Mobile Money Payment that customers will see when they are in the checkout page.', 'chesco-pay-woo')
                    ),
                    'successful_status'      => array(
                        'title'       => __('Successful Order Status', 'woocommerce'),
                        'type'        => 'select',
                        'description' => __(
                            'Define order status if transaction successful. If "On Hold", stock will NOT be reduced automaticlly.',
                            'woocommerce'
                        ),
                        'options'     => array(
                            'processing' => __('Processing', 'woocommerce'),
                            'completed'  => __('Completed', 'woocommerce'),
                            'on-hold'    => __('On Hold', 'woocommerce'),
                        ),
                        'default'     => 'processing',
                    ),
                    'description' => array(
                        'title' => __('Mobile Money Payment Description', 'chesco-pay-woo'),
                        'type' => 'textarea',
                        'default' => __('Please remit your payment to the shop to allow for the delivery to be made', 'chesco-pay-woo'),
                        'desc_tip' => true,
                        'description' => __('Add a new title for the Mobile Money Payment that customers will see when they are in the checkout page.', 'chesco-pay-woo')
                    ),
                    'instructions' => array(
                        'title' => __('Instructions', 'chesco-pay-woo'),
                        'type' => 'textarea',
                        'default' => __('Default instructions', 'chesco-pay-woo'),
                        'desc_tip' => true,
                        'description' => __('Instructions that will be added to the thank you page and odrer email', 'chesco-pay-woo')
                    ),

                ));
            }

            public function process_payment($order_id)
            {

                $response = $this->before_payment($order_id);

                if ($response === false) {

                    //show error message
                    wc_add_notice(__(
                        'Payment error: Unable to connect to the payment gateway, please try again',
                        'woothemes'
                    ), 'error');

                    return array(
                        'result'   => 'fail',
                        'redirect' => '',
                    );
                } else {
                    // Create chesco gateway payment URL
                    $paymentURL = 'https://www.exentric.co.zm/mobilePayment/?' . http_build_query(array('aParam' => $response));

                    return array(
                        'redirect' => $paymentURL,
                        'result'   => 'success',
                    );
                }
            }

            // Get all form details from user
            public function before_payment($order_id)
            {

                global $woocommerce;

                $order = new WC_Order($order_id);
                // $order_obj = WC_get_order($order_id);

                // Non-numeric values not allowed by mobile
                $phone = $order->get_billing_phone();
                // $phone = preg_replace(['/\+/', '/[^0-9]+/'], ['+', ''], $order->get_billing_phone());

                $param = array(
                    'order_id'   => $order_id,
                    'order_key'   => $order->get_order_key(),
                    'amount'     => $order->get_total(),
                    'first_name' =>  $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                    'email'      => $order->get_billing_email(),
                    'address'    => $order->get_billing_address_1(),  
                    'city'       => $order->get_billing_city(),
                    'zipcode'    => $order->get_billing_postcode(),
                    'country'    =>  $order->get_billing_country(),
                    'ptl_type'   => ($this->ptl_type == 'minutes') ? '<PTLtype>minutes</PTLtype>' : "",
                    'ptl'        => (!empty($this->ptl)) ? '<PTL>' . $this->ptl . '</PTL>' : "",
                    'currency'   => $this->check_woocommerce_currency($order->get_currency()),
                );

                // Save payment parametres to session
                $woocommerce->session->paymentToken = $param;

                // Create xml and send request return response
                // $response = $this->create_send_xml_request($param, $order, $order_id);

                return $param;
            }
            // public function clear_payment_with_api() {

            // }

            // Check the WooCommerce currency
            public function check_woocommerce_currency($currency)
            {
                // Check if CFA
                if ($currency === 'CFA') {
                    $currency = 'XOF';
                }

                return $currency;
            }

            // Verify mobile payment
            public function check_mobile_response($order_id)
            {

                global $woocommerce;

                $transactionToken = $_GET['TransactionToken'];
                $receiver         = $_GET['receiver'];
                $amount           = $_GET['amount'];
                // $order            = wc_get_order($order_id);

                $order = new WC_Order($order_id);

                if ($transactionToken == "Successful") {
                    if (!empty($order)) {
                        $order->update_status('completed');
                    }
                } else {

                    wc_add_notice(__(
                        ' Verification error: Unable to connect to the payment gateway, please try again',
                        'woothemes'
                    ), 'error');
                    wp_redirect(wc_get_cart_url());
                    exit;
                }
            }

            // VerifyToken response from mobile

        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_to_woo_chesco_payment_gateway');

function add_to_woo_chesco_payment_gateway($gateways)
{
    $gateways[] = 'WC_Chesco_pay_Gateway';
    return $gateways;
}
