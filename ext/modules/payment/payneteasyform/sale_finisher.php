<?php

namespace PaynetEasy\PaynetEasyApi;

/*
 * The gateway returns the customer with a cross-site POST, which carries no
 * SameSite=Lax shop cookie: the shop would start a new session and the customer
 * would lose the login and the cart. Re-issue the request as a GET to this URL
 * before the shop starts a session. Only the fields that are checked go to the
 * query: the other gateway fields (card and customer data) are not needed.
 */
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST))
{
    $request = array_merge($_GET, $_POST);
    $query   = array();

    foreach (array('order_id', 'status', 'orderid', 'paynet-order-id', 'client_orderid', 'merchant_order',
                   'merchant-order-id', 'control', 'merchant_control', 'amount',
                   'error_message', 'error-message', 'error_code', 'error-code') as $field)
    {
        if (isset($request[$field]) && is_scalar($request[$field]))
        {
            $query[$field] = (string) $request[$field];
        }
    }

    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?' . http_build_query($query), true, 303);
    exit;
}

chdir('../../../../');

require_once 'includes/application_top.php';
require_once DIR_WS_CLASSES . 'order.php';
require_once DIR_WS_LANGUAGES . $GLOBALS['language'] . '/' . FILENAME_CHECKOUT_PROCESS;
require_once DIR_WS_LANGUAGES . $GLOBALS['language'] . '/modules/payment/payneteasyform.php';

use order as OsCommerceOrder;

class SaleFinisher
{
    /**
     * Aggregate for different database operations for order
     * For lazy load use SaleFinisher::get_database_aggregate()
     *
     * @see SaleFinisher::get_database_aggregate()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaynetDatabaseAggregate
     */
    protected $_database_aggregate;

    /**
     * Aggregate for payment by Paynet
     * For lazy load use SaleFinisher::get_payment_aggregate()
     *
     * @see SaleFinisher::get_payment_aggregate()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaynetPaymentAggregate
     */
    protected $_payment_aggregate;

    /**
     * Aggregate for customer notifying after order changing
     * For lazy load use SaleFinisher::get_mailer_aggregate()
     *
     * @see SaleFinisher::get_mailer_aggregate()
     *
     * @var \PaynetEasy\PaynetEasyApi\PaynetMailerAggregate
     */
    protected $_mailer_aggregate;

    /**
     * Name of the database lock held for the order, null if not held
     *
     * @var string|null
     */
    protected $_lock_name;

    /**
     * Finish sale and redirect customer to payment result page
     *
     * @param       integer     $order_id           Order id to start sale
     */
    public function finish_sale($order_id, array $callback_data)
    {
        $order_id = (int) $order_id;

        // The customer return and the gateway callback may come at the same time:
        // the order is checked and updated by one request at a time.
        // The lock is released before every redirect.
        if (!$this->lock_order($order_id))
        {
            error_log('PaynetEasy: order ' . $order_id . ' is being processed by another request');
            $this->error_redirect();
        }

        $status   = $this->get_order_status_id($order_id);

        // Only an order waiting for payment changes its state
        if (!$this->is_awaiting_payment($status))
        {
            if ($status === (int) MODULE_PAYMENT_PAYNETEASYFORM_SUCCESS_PAYMENT_ORDER_STATUS)
            {
                $this->success_redirect();
            }

            $this->error_redirect();
        }

        $order = $this->get_order($order_id);

        try
        {
            // Only the payment started for this order by this module can finish it
            if (empty($order->info['paynet_order_id']))
            {
                throw new \RuntimeException('No payment was started for order ' . $order_id);
            }

            $response = $this
                ->get_payment_aggregate()
                ->finish_sale($order, $callback_data);
            ;
        }
        catch (\Exception $e)
        {
            // Invalid data: the order is not changed
            $this->log_exception($e);
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
            $this->error_redirect('declined');
        }
    }

    /**
     * Take the database lock of the order, wait for it up to 15 seconds
     *
     * @param       integer     $order_id       Order id
     *
     * @return      boolean                     False, if another request holds the lock
     */
    protected function lock_order($order_id)
    {
        $name = substr(DB_DATABASE, 0, 30) . '.payneteasyform_order_' . (int) $order_id;

        $row = tep_db_fetch_array(tep_db_query(
            "select get_lock('" . tep_db_input($name) . "', 15) as locked"
        ));

        if (!$row || (int) $row['locked'] !== 1)
        {
            return false;
        }

        $this->_lock_name = $name;

        return true;
    }

    /**
     * Release the database lock of the order, if it is held
     */
    protected function unlock_order()
    {
        if ($this->_lock_name !== null)
        {
            tep_db_query("select release_lock('" . tep_db_input($this->_lock_name) . "')");
            $this->_lock_name = null;
        }
    }

    /**
     * Get current order status id, null if the order does not exist
     *
     * @param       integer     $order_id       Order id
     *
     * @return      integer|null
     */
    protected function get_order_status_id($order_id)
    {
        $row = tep_db_fetch_array(tep_db_query(
            "select orders_status from " . TABLE_ORDERS . " where orders_id = '" . (int) $order_id . "'"
        ));

        return $row ? (int) $row['orders_status'] : null;
    }

    /**
     * True, if the order has the status it was created with
     * and has not got a payment result yet
     *
     * @param       integer|null    $status     Order status id
     *
     * @return      boolean
     */
    protected function is_awaiting_payment($status)
    {
        return     $status !== null
                && $status === (int) DEFAULT_ORDERS_STATUS_ID
                && $status !== (int) MODULE_PAYMENT_PAYNETEASYFORM_SUCCESS_PAYMENT_ORDER_STATUS
                && $status !== (int) MODULE_PAYMENT_PAYNETEASYFORM_ERROR_PAYMENT_ORDER_STATUS;
    }

    /**
     * Get order by order ID
     *
     * @param       integer     $order_id       Order id
     *
     * @return      order                       Order object
     */
    protected function get_order($order_id)
    {
        $order = new OsCommerceOrder((int) $order_id);
        $order->info['order_id'] = $order_id;

        // The payment id and the amount are taken from the database, not from
        // the request: the request is compared with them
        $payment = $this
            ->get_database_aggregate()
            ->select_payment($order_id)
        ;

        if ($payment)
        {
            $order->info['paynet_order_id'] = $payment['paynet_order_id'];
            $order->info['paynet_amount']   = $payment['amount'];
            $order->info['currency']        = $payment['currency'];
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

        $this->clear_cart($order);
    }

    /**
     * Redirect customer to payment result page with error message
     *
     * @param       string      $error_code         Error code: 'technical' or 'declined'
     */
    protected function error_redirect($error_code = 'technical')
    {
        $this->unlock_order();

        $params  = 'payment_error=payneteasyform&error=' . urlencode($error_code);
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, $params, 'SSL'));
    }

    /**
     * Redirect customer to payment result page with success message
     */
    protected function success_redirect()
    {
        $this->unlock_order();

        tep_redirect(tep_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL'));
    }

    /**
     * Method clears shopping cart of the order customer
     *
     * The gateway returns the customer with a cross-site POST,
     * which may come without the shop session: the saved cart is cleared too.
     *
     * @param       order       $order              Order
     */
    protected function clear_cart(OsCommerceOrder $order)
    {
        $row = tep_db_fetch_array(tep_db_query(
            "select customers_id from " . TABLE_ORDERS . " where orders_id = '" . (int) $order->info['order_id'] . "'"
        ));

        if (!empty($row['customers_id']))
        {
            tep_db_query("delete from " . TABLE_CUSTOMERS_BASKET . " where customers_id = '" . (int) $row['customers_id'] . "'");
            tep_db_query("delete from " . TABLE_CUSTOMERS_BASKET_ATTRIBUTES . " where customers_id = '" . (int) $row['customers_id'] . "'");
        }

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
    protected function log_exception(\Exception $error)
    {
        // The catalog has no logger class: write to the PHP error log
        error_log('PaynetEasy: ' . $error->getMessage());
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
            require_once DIR_FS_CATALOG . 'includes/modules/payment/payneteasyform/paynet_database_aggregate.php';
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
            require_once DIR_FS_CATALOG . 'includes/modules/payment/payneteasyform/paynet_payment_aggregate.php';
            $this->_payment_aggregate = new PaynetPaymentAggregate;
        }

        return $this->_payment_aggregate;
    }

    /**
     * Get aggregate for customer notifying after order changing
     *
     * @return      \PaynetEasy\PaynetEasyApi\PaynetMailerAggregate
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