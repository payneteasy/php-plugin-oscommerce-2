<?php

namespace PaynetEasy\PaynetEasyApi;

require_once DIR_FS_CATALOG . 'vendor/autoload.php';

use order                                   as OsCommerceOrder;

use PaynetEasy\PaynetEasyApi\PaymentData\PaymentTransaction;
use PaynetEasy\PaynetEasyApi\PaymentData\Payment;
use PaynetEasy\PaynetEasyApi\PaymentData\Customer;
use PaynetEasy\PaynetEasyApi\PaymentData\BillingAddress;

use PaynetEasy\PaynetEasyApi\Utils\Validator;
use PaynetEasy\PaynetEasyApi\PaymentData\QueryConfig;
use PaynetEasy\PaynetEasyApi\Transport\CallbackResponse;

use PaynetEasy\PaynetEasyApi\PaymentProcessor;

/**
 * Aggregate for payment by Paynet
 */
class PaynetPaymentAggregate
{
    /**
     * Order processor instance.
     * For lazy loading use PaynetEasyFormAggregate::get_order_processor()
     *
     * @see PaynetEasyFormAggregate::get_payment_processor()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaymentProcessor
     */
    protected $_payment_procesor;

    /**
     * Starts order processing.
     * Method executes query to paynet gateway and returns response from gateway.
     * After that user must be redirected to the Response::getRedirectUrl()
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     * @param       string          $return_url                         Url for order processing after payment
     *
     * @return      \PaynetEasy\PaynetEasyApi\Transport\Response               Gateway response object
     */
    public function start_sale(OsCommerceOrder $oscommerce_order, $return_url)
    {
        $paynet_transaction = $this->get_paynet_transaction($oscommerce_order, $return_url);

        return $this
            ->get_payment_processor()
            ->executeQuery('sale-form', $paynet_transaction);
    }

    /**
     *
     * @param       order           $oscommerce_order                   OsCommerce order
     * @param       array           $callback_data                      Callback data from paynet
     *
     * @return      \PaynetEasy\PaynetEasyApi\Transport\CallbackResponse       Callback object
     */
    public function finish_sale(OsCommerceOrder $oscommerce_order, array $callback_data)
    {
        $paynet_transaction = $this->get_paynet_transaction($oscommerce_order);
        $paynet_transaction->setStatus(PaymentTransaction::STATUS_PROCESSING);

        return $this
            ->get_payment_processor()
            ->processCustomerReturn(new CallbackResponse($callback_data), $paynet_transaction);
    }

    /**
     * Transform OsCommerce order to Paynet order
     *
     * @param       order           $oscommerce_order       OsCommerce order
     * @param       string          $redirect_url           Url for final payment processing
     *
     * @return      PaymentTransaction                      Paynet transaction
     */
    protected function get_paynet_transaction(OsCommerceOrder $oscommerce_order, $redirect_url = null)
    {
        $oscommerce_customer    = $oscommerce_order->customer;

        $paynet_transaction     = new PaymentTransaction;
        $paynet_address         = new BillingAddress;
        $paynet_payment         = new Payment;
        $paynet_customer        = new Customer;
        $query_config           = new QueryConfig;

        $state_code = tep_get_zone_code($oscommerce_customer['country']['id'],
                                        $oscommerce_customer['zone_id'],
                                        $oscommerce_customer['state']);

        $paynet_address
            ->setCountry($oscommerce_customer['country']['iso_code_2'])
            ->setState($state_code)
            ->setCity($oscommerce_customer['city'])
            ->setFirstLine($oscommerce_customer['street_address'])
            ->setZipCode($oscommerce_customer['postcode'])
            ->setPhone($oscommerce_customer['telephone'])
        ;

        $paynet_customer
            ->setEmail($oscommerce_customer['email_address'])
            ->setFirstName($oscommerce_customer['firstname'])
            ->setLastName($oscommerce_customer['lastname'])
            ->setIpAddress(tep_get_ip_address())
        ;

        $paynet_payment
            ->setClientId($oscommerce_order->info['order_id'])
            ->setDescription($this->get_paynet_order_description($oscommerce_order))
            ->setAmount($oscommerce_order->info['total'])
            ->setCurrency($oscommerce_order->info['currency'])
            ->setCustomer($paynet_customer)
            ->setBillingAddress($paynet_address)
        ;

        if (isset ($oscommerce_order->info['paynet_order_id']))
        {
            $paynet_payment->setPaynetId($oscommerce_order->info['paynet_order_id']);
        }

        $query_config
            ->setEndPoint((int) MODULE_PAYMENT_PAYNETEASYFORM_END_POINT)
            ->setLogin(MODULE_PAYMENT_PAYNETEASYFORM_LOGIN)
            ->setSigningKey(MODULE_PAYMENT_PAYNETEASYFORM_SIGNING_KEY)
            ->setGatewayMode(MODULE_PAYMENT_PAYNETEASYFORM_GATEWAY_MODE)
            ->setGatewayUrlSandbox(MODULE_PAYMENT_PAYNETEASYFORM_SANDBOX_GATEWAY)
            ->setGatewayUrlProduction(MODULE_PAYMENT_PAYNETEASYFORM_PRODUCTION_GATEWAY)
        ;

        if (Validator::validateByRule($redirect_url, Validator::URL, false))
        {
            $query_config
                ->setRedirectUrl($redirect_url)
                ->setCallbackUrl($redirect_url)
            ;
        }

        $paynet_transaction
            ->setPayment($paynet_payment)
            ->setQueryConfig($query_config)
        ;

        return $paynet_transaction;
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
     * @return      \PaynetEasy\PaynetEasyApi\PaymentProcessor
     */
    protected function get_payment_processor()
    {
        if (!$this->_order_procesor)
        {
            $this->_order_procesor = new PaymentProcessor;
        }

        return $this->_order_procesor;
    }
}