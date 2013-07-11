<?php

namespace PaynetEasy\Paynet;

chdir('../../../../');

require_once 'includes/application_top.php';
require_once DIR_WS_CLASSES . 'order.php';
require_once DIR_WS_LANGUAGES . $GLOBALS['language'] . '/' . FILENAME_CHECKOUT_PROCESS;
require_once DIR_WS_LANGUAGES . $GLOBALS['language'] . '/modules/payment/payneteasyform.php';

use order as OsCommerceOrder;

class SaleFinisher
{
    /**
     * Logger instance
     *
     * @var \logger
     */
    protected $_logger;

    /**
     * Aggregate for different database operations for order
     * For lazy load use SaleFinisher::get_database_aggregate()
     *
     * @see SaleFinisher::get_database_aggregate()
     *
     * @var \PaynetEasy\Paynet\PaynetDatabaseAggregate
     */
    protected $_database_aggregate;

    /**
     * Aggregate for payment by Paynet
     * For lazy load use SaleFinisher::get_payment_aggregate()
     *
     * @see SaleFinisher::get_payment_aggregate()
     *
     * @var \PaynetEasy\Paynet\PaynetPaymentAggregate
     */
    protected $_payment_aggregate;

    /**
     * Aggregate for customer notifying after order changing
     * For lazy load use SaleFinisher::get_mailer_aggregate()
     *
     * @see SaleFinisher::get_mailer_aggregate()
     *
     * @var \PaynetEasy\Paynet\PaynetMailerAggregate
     */
    protected $_mailer_aggregate;

    /**
     * Finish sale and redirect customer to payment result page
     *
     * @param       integer     $order_id           Order id to start sale
     */
    public function finish_sale($order_id, array $callback_data)
    {
        $order = $this->get_order($order_id, $callback_data);

        try
        {
            $response = $this
                ->get_payment_aggregate()
                ->finish_sale($order, $callback_data);
            ;
        }
        catch (Exception $e)
        {
            $this->log_exception($e);
            $this->cancel_order($order);
            $this->error_redirect();
        }

        if ($response->isApproved())
        {
            $this->approve_order($order);
            $this->success_redirect();
        }
        else
        {
            $message = MODULE_PAYMENT_PAYNETEASYFORM_DECLINED;

            $this->cancel_order($order, $message);
            $this->error_redirect($message);
        }
    }

    /**
     * Get order by order ID
     *
     * @param       integer     $order_id       Order id
     *
     * @return      order                       Order object
     */
    protected function get_order($order_id, array $callback_data)
    {
        $order = new OsCommerceOrder((int) $order_id);
        $order->info['order_id'] = $order_id;

        if (!empty($callback_data['orderid']))
        {
            $order->info['paynet_order_id'] = $callback_data['orderid'];
        }

        return $order;
    }

    /**
     * Cancel order
     *
     * @param       order       $order              Order
     * @param       string      $message            Error message
     */
    protected function cancel_order(OsCommerceOrder $order,
                                    $message = MODULE_PAYMENT_PAYNETEASYFORM_TECHNICAL_ERROR)
    {
        $order->info['order_status']    = MODULE_PAYMENT_PAYNETEASYFORM_ERROR_PAYMENT_ORDER_STATUS;
        $order->info['comments']        = $message;

        $this
            ->get_database_aggregate()
            ->update_order_status($order)
        ;

        $this
            ->get_mailer_aggregate()
            ->send_order_update_email($order)
        ;
    }

    /**
     * Approves order
     *
     * @param       order       $order              Order
     */
    protected function approve_order(OsCommerceOrder $order)
    {
        $order->info['order_status']    = MODULE_PAYMENT_PAYNETEASYFORM_SUCCESS_PAYMENT_ORDER_STATUS;
        $order->info['comments']        = MODULE_PAYMENT_PAYNETEASYFORM_APPROVED;

        $this
            ->get_database_aggregate()
            ->update_order_status($order)
        ;

        $this
            ->get_mailer_aggregate()
            ->send_order_update_email($order)
        ;

        $this->clear_cart();
    }

    /**
     * Redirect customer to payment result page with error message
     *
     * @param       string      $error_message      Error message
     */
    protected function error_redirect($message = MODULE_PAYMENT_PAYNETEASYFORM_TECHNICAL_ERROR)
    {
        $params  = 'payment_error=payneteasyform&error=' . urlencode($message);
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, $params, 'SSL'));
    }

    /**
     * Redirect customer to payment result page with success message
     */
    protected function success_redirect()
    {
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
    }

    /**
     * Method clears shopping cart
     */
    protected function clear_cart()
    {
        $GLOBALS['cart']->reset(true);

        tep_session_unregister('sendto');
        tep_session_unregister('billto');
        tep_session_unregister('shipping');
        tep_session_unregister('payment');
        tep_session_unregister('comments');
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
     * Get aggregate for different database operations for order
     *
     * @return      \PaynetEasy\Paynet\PaynetDatabaseAggregate
     */
    protected function get_database_aggregate()
    {
        if (!$this->_database_aggregate)
        {
            require_once DIR_FS_CATALOG . 'includes/modules/payment/payneteasyform/paynet_database_aggregate.php';
            $this->_database_aggregate = new PaynetDatabaseAggregate;
        }

        return $this->_database_aggregate;
    }

    /**
     * Get aggregate for payment by Paynet
     *
     * @return      \PaynetEasy\Paynet\PaynetPaymentAggregate
     */
    protected function get_payment_aggregate()
    {
        if (!$this->_payment_aggregate)
        {
            require_once DIR_FS_CATALOG . 'includes/modules/payment/payneteasyform/paynet_payment_aggregate.php';
            $this->_payment_aggregate = new PaynetPaymentAggregate;
        }

        return $this->_payment_aggregate;
    }

    /**
     * Get aggregate for customer notifying after order changing
     *
     * @return      \PaynetEasy\Paynet\PaynetMailerAggregate
     */
    protected function get_mailer_aggregate()
    {
        if (!$this->_mailer_aggregate)
        {
            require_once DIR_FS_CATALOG . 'includes/modules/payment/payneteasyform/paynet_mailer_aggregate.php';
            $this->_mailer_aggregate = new PaynetMailerAggregate;
        }

        return $this->_mailer_aggregate;
    }
}

$sale_finisher = new SaleFinisher;
$sale_finisher->finish_sale($_GET['order_id'], $_REQUEST);