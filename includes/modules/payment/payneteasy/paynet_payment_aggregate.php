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
     * Paynet payment query config
     *
     * @var array
     */
    protected $_query_config;

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
     * @param       array       $query_config       Paynet payment query config
     */
    public function __construct(array $query_config)
    {
        $this->_query_config = $query_config;
    }

    /**
     * Starts order processing.
     * Method executes query to paynet gateway and returns response from gateway.
     * After that user must be redirected to the Response::getRedirectUrl()
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     *
     * @return      \PaynetEasy\Paynet\Transport\Response               Gateway response object
     */
    public function start_sale(OsCommerceOrder $oscommerce_order)
    {
        $paynet_order = $this->get_paynet_order($oscommerce_order);

        return $this
            ->get_order_processor()
            ->executeQuery('sale-form', $this->_query_config, $paynet_order);
    }

    public function finish_sale()
    {
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
            /**
             * @todo Change to real order data
             */
            ->setPaynetOrderId(uniqid())
            ->setDescription($this->get_paynet_order_description($oscommerce_order))
            ->setAmount($oscommerce_order->info['total'])
            ->setCurrency($oscommerce_order->info['currency'])
            ->setIpAddress(tep_get_ip_address())
            ->setCustomer($paynet_customer)
        ;

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
            if ($this->_query_config['sandbox_enabled'])
            {
                $gateway_url = $this->_query_config['sandbox_gateway'];
            }
            else
            {
                $gateway_url = $this->_query_config['production_gateway'];
            }

            $this->_order_procesor = new OrderProcessor($gateway_url);
        }

        return $this->_order_procesor;
    }
}