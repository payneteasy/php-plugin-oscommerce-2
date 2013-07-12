<?php

namespace PaynetEasy\PaynetEasyApi;

use order as OsCommerceOrder;

/**
 * Aggregate for mail sending
 */
class PaynetMailerAggregate
{
    /**
     * Method send email about order update to customer
     *
     * @param       order       $order      Order
     */
    public function send_order_update_email(OsCommerceOrder $order)
    {
        if (SEND_EMAILS != 'true')
        {
            return;
        }

        $email_text = $this->get_email_text($order);

        tep_mail($order->customer['firstname'] . ' ' . $order->customer['lastname'],
                 $order->customer['email_address'],
                 EMAIL_TEXT_SUBJECT,
                 $email_text,
                 STORE_OWNER,
                 STORE_OWNER_EMAIL_ADDRESS);

        if (strlen(SEND_EXTRA_ORDER_EMAILS_TO) > 0)
        {
            tep_mail('',
                     SEND_EXTRA_ORDER_EMAILS_TO,
                     EMAIL_TEXT_SUBJECT,
                     $email_text,
                     STORE_OWNER,
                     STORE_OWNER_EMAIL_ADDRESS);
        }
    }

    /**
     * Method returns text for email about order update
     *
     * @param       order       $order      Order
     */
    protected function get_email_text(OsCommerceOrder $order)
    {
        $email_text  = $this->get_store_description();
        $email_text .= $this->get_common_description($order);
        $email_text .= $this->get_products_description($order);
        $email_text .= $this->get_addresses_description($order);
        $email_text .= $this->get_payment_description();

        return $email_text;
    }

    /**
     * Get store description
     *
     * @return      string
     */
    protected function get_store_description()
    {
        return STORE_NAME . "\n" . EMAIL_SEPARATOR . "\n";
    }

    /**
     * Get common order description:
     * - order id
     * - invoice url
     * - date
     * - comments
     *
     * @param       order       $order      Order
     *
     * @return      string                  Common order description
     */
    protected function get_common_description(OsCommerceOrder $order)
    {
        $invoice_url  = tep_href_link(FILENAME_ACCOUNT_HISTORY_INFO, 'order_id=' . $order->info['order_id'], 'SSL', false);

        $common_info  = EMAIL_TEXT_ORDER_NUMBER . ': ' . $order->info['order_id'] . "\n" .
        $common_info .= EMAIL_TEXT_INVOICE_URL  . ': ' . $invoice_url . "\n";
        $common_info .= EMAIL_TEXT_DATE_ORDERED . ': ' . strftime(DATE_FORMAT_LONG) . "\n";

        if ($order->info['comments'])
        {
            $common_info .= EMAIL_TEXT_COMMENTS . ': ' . tep_db_output($order->info['comments']) . "\n";
        }

        return $common_info;
    }

    /**
     * Method returns order products description
     *
     * @param       order       $order      Order
     *
     * @return      string                  Order products description
     */
    protected function get_products_description(OsCommerceOrder $order)
    {
        $es = EMAIL_SEPARATOR;
        $products_info  = '';
        $currency       = $GLOBALS['currencies'];

        foreach ($order->products as $product)
        {
            $price          = $currency->display_price($product['final_price'], $product['tax'], $product['qty']);

            $products_info .= "{$product['qty']} x {$product['name']} ({$product['model']}) = {$price}";
            $products_info .= "{$this->get_product_attributes_description($product)}\n";
        }

        foreach ($order->totals as $order_total)
        {
            $products_info .= strip_tags($order_total['title']) . ' ' . strip_tags($order_total['text']) . "\n";
        }

        return "\n" . EMAIL_TEXT_PRODUCTS . "\n{$es}\n{$products_info}{$es}\n";
    }

    /**
     * Method returns order product attributes description
     *
     * @param       array       $product        Order product
     *
     * @return      string                      Order product attributes description
     */
    protected function get_product_attributes_description(array $product)
    {
        $attributes_info = '';

        if (empty($product['attributes']))
        {
            return $attributes_info;
        }

        foreach ($product['attributes'] as $attribute)
        {
            $attributes_info .= "\n\t" . $attribute['products_options_name'] . ' ' . $attribute['products_options_values_name'];
        }

        return $attributes_info;
    }

    /**
     * Get order addresses description^
     * - shiping address
     * - billing address
     *
     * @param       order       $order      Order
     *
     * @return      string                  Order addresses description
     */
    protected function get_addresses_description(OsCommerceOrder $order)
    {
        $es = EMAIL_SEPARATOR;
        $addresses_description = '';

        if ($order->content_type != 'virtual')
        {
            $addresses_description .= "\n" . EMAIL_TEXT_DELIVERY_ADDRESS . "\n{$es}\n" .
                            tep_address_format($order->delivery['format_id'], $order->delivery, false, '', "\n") . "\n{$es}\n";
        }

        $addresses_description .= "\n" . EMAIL_TEXT_BILLING_ADDRESS . "\n{$es}\n" .
                        tep_address_format($order->billing['format_id'], $order->billing, false, '', "\n") . "\n{$es}\n";

        return $addresses_description;
    }

    /**
     * Get order payment method description
     *
     * @return      strign
     */
    protected function get_payment_description()
    {
        return "\n" . EMAIL_TEXT_PAYMENT_METHOD . ': ' . MODULE_PAYMENT_PAYNETEASYFORM_PUBLIC_TITLE . "\n\n";
    }
}