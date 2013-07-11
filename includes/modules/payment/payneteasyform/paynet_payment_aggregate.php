<?php

namespace PaynetEasy\Paynet;

require_once DIR_FS_CATALOG . 'vendor/autoload.php';

use order                                   as OsCommerceOrder;

use PaynetEasy\Paynet\OrderData\Order       as PaynetOrder;
use PaynetEasy\Paynet\OrderData\Customer    as PaynetCustomer;

use PaynetEasy\Paynet\OrderProcessor;

/**
 * Aggregate for payment by Paynet
 */
class PaynetPaymentAggregate
{
    /**
     * Order processor instance.
     * For lazy loading use PaynetEasyFormAggregate::get_order_processor()
     *
     * @see PaynetEasyFormAggregate::get_order_processor()
     *
     * @var \PaynetEasy\Paynet\OrderProcessor
     */
    protected $_order_procesor;

    /**
     * Starts order processing.
     * Method executes query to paynet gateway and returns response from gateway.
     * After that user must be redirected to the Response::getRedirectUrl()
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     * @param       string          $return_url                         Url for order processing after payment
     *
     * @return      \PaynetEasy\Paynet\Transport\Response               Gateway response object
     */
    public function start_sale(OsCommerceOrder $oscommerce_order, $return_url)
    {
        $paynet_order = $this->get_paynet_order($oscommerce_order);

        return $this
            ->get_order_processor()
            ->executeQuery('sale-form', $this->get_config($return_url), $paynet_order);
    }

    /**
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     * @param       array           $callback_data                      Callback data from paynet
     *
     * @return      \PaynetEasy\Paynet\Transport\CallbackResponse       Callback object
     */
    public function finish_sale(OsCommerceOrder $oscommerce_order, array $callback_data)
    {
        $paynet_order = $this->get_paynet_order($oscommerce_order);

        return $this
            ->get_order_processor()
            ->executeCallback($callback_data, $this->get_config(), $paynet_order);
    }

    /**
     * Transform OsCommerce order to Paynet order
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     *
     * @return      \PaynetEasy\Paynet\OrderData\Order                  Paynet order
     */
    protected function get_paynet_order(OsCommerceOrder $oscommerce_order)
    {
        $oscommerce_customer    = $oscommerce_order->customer;
        $paynet_order           = new PaynetOrder;
        $paynet_customer        = new PaynetCustomer;

        $state_code = tep_get_zone_code($oscommerce_customer['country']['id'],
                                        $oscommerce_customer['zone_id'],
                                        $oscommerce_customer['state']);

        $paynet_customer
            ->setCountry($oscommerce_customer['country']['iso_code_2'])
            ->setState($state_code)
            ->setCity($oscommerce_customer['city'])
            ->setAddress($oscommerce_customer['street_address'])
            ->setZipCode($oscommerce_customer['postcode'])
            ->setPhone($oscommerce_customer['telephone'])
            ->setEmail($oscommerce_customer['email_address'])
            ->setFirstName($oscommerce_customer['firstname'])
            ->setLastName($oscommerce_customer['lastname'])
        ;

        $paynet_order
            ->setClientOrderId($oscommerce_order->info['order_id'])
            ->setDescription($this->get_paynet_order_description($oscommerce_order))
            ->setAmount($oscommerce_order->info['total'])
            ->setCurrency($oscommerce_order->info['currency'])
            ->setIpAddress(tep_get_ip_address())
            ->setCustomer($paynet_customer)
        ;

        if (isset ($oscommerce_order->info['paynet_order_id']))
        {
            $paynet_order->setPaynetOrderId($oscommerce_order->info['paynet_order_id']);
        }

        return $paynet_order;
    }

    /**
     * Get Paynet order description
     *
     * @param           order           $oscommerce_order       OsCommerce order
     *
     * @return          string
     */
    protected function get_paynet_order_description(OsCommerceOrder $oscommerce_order)
    {
        return MODULE_PAYMENT_PAYNETEASYFORM_SHOPPING_IN . ': ' . STORE_NAME . '; ' .
               MODULE_PAYMENT_PAYNETEASYFORM_CLIENT_ORDER_ID . ': ' . $oscommerce_order->info['order_id'];
    }

    /**
     * Get service for order processing
     *
     * @return      \PaynetEasy\Paynet\OrderProcessor
     */
    protected function get_order_processor()
    {
        if (!$this->_order_procesor)
        {
            $config = $this->get_config();

            if ($config['sandbox_enabled'])
            {
                $gateway_url = $config['sandbox_gateway'];
            }
            else
            {
                $gateway_url = $config['production_gateway'];
            }

            $this->_order_procesor = new OrderProcessor($gateway_url);
        }

        return $this->_order_procesor;
    }

    protected function get_config($return_url = '')
    {
        $config = array
        (
            'end_point'             => (int) MODULE_PAYMENT_PAYNETEASYFORM_END_POINT,
            'login'                 => MODULE_PAYMENT_PAYNETEASYFORM_LOGIN,
            'control'               => MODULE_PAYMENT_PAYNETEASYFORM_CONTROL,
            'sandbox_gateway'       => MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_GATEWAY,
            'production_gateway'    => MODULE_PAYMENT_PAYNETEASYFORM_PRODUCTION_GATEWAY,
            'sandbox_enabled'       => MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_ENABLED === 'True',
            'redirect_url'          => tep_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL'),
            'server_callback_url'   => tep_href_link(FILENAME_CHECKOUT_PROCESS, '', 'SSL'),
        );

        if (!empty($return_url))
        {
            $config['redirect_url']         = $return_url;
            $config['server_callback_url']  = $return_url;
        }

        return $config;
    }
}