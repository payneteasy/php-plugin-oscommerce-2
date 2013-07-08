<?php

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

    public function __construct()
    {
        $this->set_config();
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
    }

    /**
     * Remove module config fields from DB
     */
    public function remove()
    {
        tep_db_query("DELETE FROM " . TABLE_CONFIGURATION . " WHERE `configuration_key` in ('" . implode("', '", $this->keys()) . "')");
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
            'MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_ENABLED'
        );
    }

    /**
     * Set object public config fields.
     * Set paynet library config to object property.
     */
    protected function set_config()
    {
        $this->code         = 'payneteasyform';
        $this->title        = MODULE_PAYMENT_PAYNETEASYFORM_TITLE;
        $this->public_title = MODULE_PAYMENT_PAYNETEASYFORM_PUBLIC_TITLE;
        $this->description  = MODULE_PAYMENT_PAYNETEASYFORM_DESCRIPTION;
        $this->sort_order   = MODULE_PAYMENT_PAYNETEASYFORM_SORT_ORDER;
        $this->enabled      = MODULE_PAYMENT_PAYNETEASYFORM_STATUS == 'Yes';

        $this->_config = array
        (
            'end_point'             => (int) MODULE_PAYMENT_PAYNET_END_POINT,
            'login'                 => MODULE_PAYMENT_PAYNET_LOGIN,
            'control'               => MODULE_PAYMENT_PAYNET_CONTROL,
            'sandbox_gateway'       => MODULE_PAYMENT_PAYNET_SANDBOX_GATEWAY,
            'production_gateway'    => MODULE_PAYMENT_PAYNET_PRODUCTION_GATEWAY,
            'sandbox_enabled'       => MODULE_PAYMENT_PAYNET_SANDBOX_ENABLED === 'True'
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
     * @param           string      $function           Function for config field display
     */
    protected function add_config_field($key, $title, $description = '', $value = '', $function = '')
    {
        static $sort_order = 1;

        tep_db_perform(TABLE_CONFIGURATION, array
        (
            'configuration_title'       => $title,
            'configuration_key'         => $key,
            'configuration_value'       => $value,
            'configuration_description' => $description,
            'configuration_group_id'    =>  6,
            'sort_order'                => $sort_order,
            'set_function'              => $function,
            'date_added'                => 'now()'
        ));

        ++$sort_order;
    }
}