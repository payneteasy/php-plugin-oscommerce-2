<?php

use PaynetEasy\PaynetEasyApi\PaynetDatabaseAggregate;
use PaynetEasy\PaynetEasyApi\PaynetPaymentAggregate;

use order as OsCommerceOrder;

/**
 * Payment plugin for PaynetEasy
 */
class payneteasyform
{
    /**
     * Payment method system code
     *
     * @var string
     */
    public $code;

    /**
     * Payment method module name (for admin interface)
     *
     * @var string
     */
    public $title;

    /**
     * Payment merhod description
     *
     * @var string
     */
    public $description;

    /**
     * Payment method name (for checkout form)
     *
     * @var string
     */
    public $public_title;

    /**
     * Payment method sort order
     *
     * @var string
     */
    public $sort_order;

    /**
     * Is payment method enabled
     *
     * @var bool
     */
    public $enabled;

    /**
     * Paynet library config
     *
     * @var array
     */
    protected $_config = array();

    /**
     * Aggregate for different database operations for order
     * For lazy load use payneteasyform::get_database_aggregate()
     *
     * @see payneteasyform::get_database_aggregate()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaynetDatabaseAggregate
     */
    protected $_database_aggregate;

    /**
     * Aggregate for payment by Paynet
     * For lazy load use payneteasyform::get_payment_aggregate()
     *
     * @see payneteasyform::get_payment_aggregate()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaynetPaymentAggregate
     */
    protected $_payment_aggregate;

    /**
     * Logger instance
     *
     * @var \logger
     */
    protected $_logger;

    /**
     * Set object public config fields.
     * Set paynet library config to object property.
     */
    public function __construct()
    {
        $this->code         = 'payneteasyform';
        $this->title        = MODULE_PAYMENT_PAYNETEASYFORM_TITLE;
        $this->public_title = MODULE_PAYMENT_PAYNETEASYFORM_PUBLIC_TITLE;
        $this->description  = MODULE_PAYMENT_PAYNETEASYFORM_DESCRIPTION;
        $this->sort_order   = MODULE_PAYMENT_PAYNETEASYFORM_SORT_ORDER;
        $this->enabled      = MODULE_PAYMENT_PAYNETEASYFORM_STATUS == 'Yes';
    }

    /**
     * Return payment method id and title for checkout form
     *
     * @return array
     */
    public function selection()
    {
        return array
        (
            'id'        => $this->code,
            'module'    => $this->public_title
        );
    }

    /**
     * Return javascript code for front end order validation
     *
     * @return string
     */
    public function javascript_validation()
    {
    }

    /**
     * Check order data after payment method has been selected
     */
    public function pre_confirmation_check()
    {
    }

    /**
     * Check or process order data before proceeding to payment confirmation
     */
    public function confirmation()
    {
    }

    /**
     * Return the html form hidden elements sent as POST data to the payment gateway
     */
    public function process_button()
    {
    }

    /**
     * Check order data before order finalising
     */
    public function before_process()
    {
    }

    /**
     * Method starts order processing in Paynet
     * and redirect customer to Paynet gateway.
     *
     * :KLUDGE:         Imenem          09.07.13
     *
     * Method breaks original OsCommerce order processing flow.
     */
    public function after_process()
    {
        $order      = $this->get_order();

        $this
            ->get_database_aggregate()
            ->save_all_order_data($order)
        ;

        try
        {
            $response = $this
                ->get_payment_aggregate()
                ->start_sale($order, $this->get_return_url($order));
            ;
        }
        catch (Exception $e)
        {
            $this->log_exception($e);
            $this->cancel_order($order);
            $this->error_redirect();
        }

        tep_redirect($response->getRedirectUrl());
    }

    /**
     * Return array with error message
     * Error message array format:
     * ['title' => string, 'error' => string]
     *
     * @return      array
     */
    public function get_error()
    {
        return array
        (
            'title' => MODULE_PAYMENT_PAYNETEASYFORM_NOT_PASSED,
            'error' => urldecode($_REQUEST['error'])
        );
    }

    /**
     * Return true, if module already installed
     *
     * @return  bool
     */
    public function check()
    {
        $is_module_installed = tep_db_query("SELECT `configuration_value` from " . TABLE_CONFIGURATION .
                                            " WHERE `configuration_key` = 'MODULE_PAYMENT_PAYNETEASYFORM_STATUS'");
        return (bool) tep_db_num_rows($is_module_installed);
    }

    /**
     * Install module config fields to DB
     */
    public function install()
    {
        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_STATUS',
                                'Module enabled?',
                                'Enable PaynetEasy payment form?',
                                'No',
                                "tep_cfg_select_option(array('Yes', 'No'), ");

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_SORT_ORDER',
                                'Sort order',
                                'Sort order of display. Lowest is displayed first.');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_END_POINT',
                                'End point',
                                'End point for PaynetEasy gateway');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_LOGIN',
                                'Merchant login',
                                'Merchant login for PaynetEasy');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_CONTROL',
                                'Merchant control key',
                                'Merchant control key for gateway queries sign');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_GATEWAY',
                                'Sandbox gateway URL');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_PRODUCTION_GATEWAY',
                                'Production gateway URL');

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_ENABLED',
                                'Sandbox enabled',
                                'Disable sandbox mode for real order processing',
                                'Yes',
                                "tep_cfg_select_option(array('Yes', 'No'), ");

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_SUCCESS_PAYMENT_ORDER_STATUS',
                                'Order status after success payment',
                                '',
                                '',
                                "tep_cfg_pull_down_order_statuses(",
                                "tep_get_order_status_name");

        $this->add_config_field('MODULE_PAYMENT_PAYNETEASYFORM_ERROR_PAYMENT_ORDER_STATUS',
                                'Order status after payment with error',
                                '',
                                '',
                                "tep_cfg_pull_down_order_statuses(",
                                "tep_get_order_status_name");
    }

    /**
     * Remove module config fields from DB
     */
    public function remove()
    {
        tep_db_query("DELETE FROM " . TABLE_CONFIGURATION .
                     " WHERE `configuration_key` in ('" . implode("', '", $this->keys()) . "')");
    }

    /**
     * Get module config fields list
     *
     * @return array
     */
    public function keys()
    {
        return array
        (
            'MODULE_PAYMENT_PAYNETEASYFORM_STATUS',
            'MODULE_PAYMENT_PAYNETEASYFORM_SORT_ORDER',
            'MODULE_PAYMENT_PAYNETEASYFORM_END_POINT',
            'MODULE_PAYMENT_PAYNETEASYFORM_LOGIN',
            'MODULE_PAYMENT_PAYNETEASYFORM_CONTROL',
            'MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_GATEWAY',
            'MODULE_PAYMENT_PAYNETEASYFORM_PRODUCTION_GATEWAY',
            'MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_ENABLED',
            'MODULE_PAYMENT_PAYNETEASYFORM_SUCCESS_PAYMENT_ORDER_STATUS',
            'MODULE_PAYMENT_PAYNETEASYFORM_ERROR_PAYMENT_ORDER_STATUS'
        );
    }

    /**
     * Add module config field to DB
     *
     * @staticvar       int         $sort_order         Field sort order, increments on each method call
     *
     * @param           string      $key                Config field key
     * @param           string      $title              Config field title
     * @param           string      $description        Config field description
     * @param           string      $default_value      Config field default value
     * @param           string      $set_function           Function for config field display
     */
    protected function add_config_field($key, $title, $description = '', $value = '', $set_function = '', $use_function = '')
    {
        static $sort_order = 1;

        tep_db_perform(TABLE_CONFIGURATION, array
        (
            'configuration_title'       => $title,
            'configuration_key'         => $key,
            'configuration_value'       => $value,
            'configuration_description' => $description,
            'configuration_group_id'    => 6,
            'sort_order'                => $sort_order,
            'set_function'              => $set_function,
            'use_function'              => $use_function,
            'date_added'                => 'now()'
        ));

        ++$sort_order;
    }

    /**
     * Get order
     *
     * @return      order
     */
    protected function get_order()
    {
        $order                          = $GLOBALS['order'];
        $order->totals                  = $GLOBALS['order_totals'];
        $order->customer['customer_id'] = $GLOBALS['customer_id'];
        $order->info['language_id']     = $GLOBALS['languages_id'];

        return $order;
    }

    /**
     * Get URL for final order processing
     *
     * @param       OsCommerceOrder         $order      Order
     *
     * @return      string
     */
    protected function get_return_url(OsCommerceOrder $order)
    {
        return tep_href_link('ext/modules/payment/payneteasyform/sale_finisher.php',
                             "order_id={$order->info['order_id']}",
                             'SSL');
    }

    /**
     * Log exception message
     *
     * @param       Exception       $error      Exception to log
     */
    protected function log_exception(Exception $error)
    {
        if (!isset($this->_logger))
        {
            $this->_logger = new logger;
        }

        $this->_logger->write($error->getMessage(), 'ERROR');
    }

    /**
     * Cancel order
     *
     * @param       OsCommerceOrder         $order              Order
     */
    protected function cancel_order(OsCommerceOrder $order)
    {
        $order->info['order_status']    = MODULE_PAYMENT_PAYNETEASYFORM_ERROR_PAYMENT_ORDER_STATUS;
        $order->info['comments']        = MODULE_PAYMENT_PAYNETEASYFORM_TECHNICAL_ERROR;

        $this
            ->get_database_aggregate()
            ->update_order_status($order)
        ;
    }

    /**
     * Redirect customer to payment result page with error message
     *
     * @param       string      $error_message      Error message
     */
    protected function error_redirect($message = MODULE_PAYMENT_PAYNETEASYFORM_TECHNICAL_ERROR)
    {
        $params  = 'payment_error=' . $this->code . '&error=' . urlencode($message);
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, $params, 'SSL'));
    }

    /**
     * Get aggregate for different database operations for order
     *
     * @return      \PaynetEasy\PaynetEasyApi\PaynetDatabaseAggregate
     */
    protected function get_database_aggregate()
    {
        if (!$this->_database_aggregate)
        {
            require_once __DIR__ . '/payneteasyform/paynet_database_aggregate.php';
            $this->_database_aggregate = new PaynetDatabaseAggregate;
        }

        return $this->_database_aggregate;
    }

    /**
     * Get aggregate for payment by Paynet
     *
     * @return      \PaynetEasy\PaynetEasyApi\PaynetPaymentAggregate
     */
    protected function get_payment_aggregate()
    {
        if (!$this->_payment_aggregate)
        {
            require_once __DIR__ . '/payneteasyform/paynet_payment_aggregate.php';
            $this->_payment_aggregate = new PaynetPaymentAggregate;
        }

        return $this->_payment_aggregate;
    }
}