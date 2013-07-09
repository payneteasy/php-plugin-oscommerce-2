<?php

namespace PaynetEasy\Paynet;

use order as OsCommerceOrder;

/**
 * Aggregate for different database operations for order
 */
class PaynetDatabaseAggregate
{
    /**
     * Save next OsCommerce order data to DB:
     * - order
     * - order totals
     * - order status history
     * - order products and their attributes
     * Also method updates products statistics:
     * - products quantity
     * - products ordered count
     *
     * @param       order       $order          Order to save
     */
    public function save_all_order_data(OsCommerceOrder $order)
    {
        $this->insert_order($order);
        $this->insert_order_totals($order);
        $this->insert_order_status_history($order);
        $this->insert_order_products($order);
        $this->update_products_statistics($order);
    }

    /**
     * Insert new order to DB.
     * After insert method saves
     * order id to order::$info['order_id'] and
     * customer id to order::$customer['customer_id']
     *
     * @param       order       $order          Order to save
     */
    public function insert_order(OsCommerceOrder $order)
    {
        tep_db_perform(TABLE_ORDERS, array
        (
            'customers_id'                => $order->customer['customer_id'],
            'customers_name'              => $order->customer['firstname'] . ' ' . $order->customer['lastname'],
            'customers_company'           => $order->customer['company'],
            'customers_street_address'    => $order->customer['street_address'],
            'customers_suburb'            => $order->customer['suburb'],
            'customers_city'              => $order->customer['city'],
            'customers_postcode'          => $order->customer['postcode'],
            'customers_state'             => $order->customer['state'],
            'customers_country'           => $order->customer['country']['title'],
            'customers_telephone'         => $order->customer['telephone'],
            'customers_email_address'     => $order->customer['email_address'],
            'customers_address_format_id' => $order->customer['format_id'],
            'delivery_name'               => trim($order->delivery['firstname'] . ' ' . $order->delivery['lastname']),
            'delivery_company'            => $order->delivery['company'],
            'delivery_street_address'     => $order->delivery['street_address'],
            'delivery_suburb'             => $order->delivery['suburb'],
            'delivery_city'               => $order->delivery['city'],
            'delivery_postcode'           => $order->delivery['postcode'],
            'delivery_state'              => $order->delivery['state'],
            'delivery_country'            => $order->delivery['country']['title'],
            'delivery_address_format_id'  => $order->delivery['format_id'],
            'billing_name'                => $order->billing['firstname'] . ' ' . $order->billing['lastname'],
            'billing_company'             => $order->billing['company'],
            'billing_street_address'      => $order->billing['street_address'],
            'billing_suburb'              => $order->billing['suburb'],
            'billing_city'                => $order->billing['city'],
            'billing_postcode'            => $order->billing['postcode'],
            'billing_state'               => $order->billing['state'],
            'billing_country'             => $order->billing['country']['title'],
            'billing_address_format_id'   => $order->billing['format_id'],
            'payment_method'              => $order->info['payment_method'],
            'cc_type'                     => $order->info['cc_type'],
            'cc_owner'                    => $order->info['cc_owner'],
            'cc_number'                   => $order->info['cc_number'],
            'cc_expires'                  => $order->info['cc_expires'],
            'date_purchased'              => 'now()',
            'orders_status'               => $order->info['order_status'],
            'currency'                    => $order->info['currency'],
            'currency_value'              => $order->info['currency_value']
        ));

        $order->customer['customer_id'] = $GLOBALS['customer_id'];
        $order->info['order_id']        = tep_db_insert_id();
    }

    /**
     * Method insert order totals to DB
     *
     * @param       order       $order          Order
     */
    public function insert_order_totals(OsCommerceOrder $order)
    {
        foreach ($GLOBALS['order_totals'] as $order_total)
        {
            tep_db_perform(TABLE_ORDERS_TOTAL, array
            (
                'orders_id'  => $order->info['order_id'],
                'title'      => $order_total['title'],
                'text'       => $order_total['text'],
                'value'      => $order_total['value'],
                'class'      => $order_total['code'],
                'sort_order' => $order_total['sort_order']
            ));
        }
    }

    /**
     * Method insert order status to status history
     *
     * @param       order       $order          Order
     */
    public function insert_order_status_history(OsCommerceOrder $order)
    {
        tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, array
        (
            'orders_id'         => $order->info['order_id'],
            'orders_status_id'  => $order->info['order_status'],
            'date_added'        => 'now()',
            'customer_notified' => SEND_EMAILS == 'true',
            'comments'          => $order->info['comments']
        ));
    }

    /**
     * Method updates products statistics:
     * - products quantity
     * - products ordered count
     *
     * @param       order       $order          Order
     */
    public function update_products_statistics(OsCommerceOrder $order)
    {
        if (STOCK_LIMITED != 'true')
        {
            return;
        }

        foreach ($order->products as $product)
        {
            $this->update_product_quantity($product);
            $this->update_product_ordered($product);
        }
    }

    /**
     * Method updates product quantity
     *
     * @param       array       $product        Product
     */
    public function update_product_quantity(array $product)
    {
        $quantity_data = $this->select_product_quantity($product);

        if (empty($quantity_data))
        {
            return;
        }

        // do not decrement quantities if products_attributes_filename exists
        if (DOWNLOAD_ENABLED != 'true' || empty($quantity_data['products_attributes_filename']))
        {
            $stock_left = $quantity_data['products_quantity'] - $product['qty'];
        }
        else
        {
            $stock_left = $quantity_data['products_quantity'];
        }

        tep_db_query("update " . TABLE_PRODUCTS . " set products_quantity = '" . (int) $stock_left .
                     "' where products_id = '" . tep_get_prid($product['id']) . "'");

        if ($stock_left < 1 && STOCK_ALLOW_CHECKOUT == 'false')
        {
            tep_db_query("update " . TABLE_PRODUCTS . " set products_status = '0' " .
                         " where products_id = '" . tep_get_prid($product['id']) . "'");
        }
    }

    /**
     * Method selects product quantity data from DB.
     * Quantity data format:
     * ['products_quantity' => integer, 'products_attributes_filename' => string] if download enabled
     * ['products_quantity' => integer] if download disabled
     *
     * @param       array       $product        Product
     *
     * @return      array                       Product quantity data
     */
    public function select_product_quantity(array $product)
    {
        if (DOWNLOAD_ENABLED == 'true')
        {
            $quantity_query =
                "SELECT products_quantity, pad.products_attributes_filename
                 FROM " . TABLE_PRODUCTS . " p
                 LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES . " pa
                    ON p.products_id = pa.products_id
                 LEFT JOIN " . TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD . " pad
                    ON pa.products_attributes_id = pad.products_attributes_id
                 WHERE p.products_id = '" . tep_get_prid($product['id']) . "'";

            // Will work with only one option for downloadable products
            // otherwise, we have to build the query dynamically with a loop
            if (!empty($product['attributes']))
            {
                $quantity_query .= " AND pa.options_id = '" .        (int) $product['attributes'][0]['option_id'] .
                                  "' AND pa.options_values_id = '" . (int) $product['attributes'][0]['value_id'] . "'";
            }

        }
        else
        {
            $quantity_query = "SELECT products_quantity FROM " . TABLE_PRODUCTS .
                              " WHERE products_id = '" . tep_get_prid($product['id']) . "'";
        }

        return tep_db_fetch_array(tep_db_query($quantity_query)) ?: array();
    }

    /**
     * Method updates product ordered count
     *
     * @param       array       $product        Product
     */
    public function update_product_ordered(array $product)
    {
        // Update products_ordered (for bestsellers list)
        tep_db_query("update " . TABLE_PRODUCTS .
                     " set products_ordered = products_ordered + " . (int) $product['qty'] .
                     " where products_id = '" . tep_get_prid($product['id']) . "'");
    }

    /**
     * Method inserts order products and their attributes data to DB:
     *
     * @param       order       $order          Order
     */
    public function insert_order_products(OsCommerceOrder $order)
    {
        foreach ($order->products as $product)
        {
            $this->insert_order_product($order, $product);
            $this->insert_product_attributes($order, $product);
        }
    }

    /**
     * Method inserts order product data to DB
     *
     * @param       order       $order          Order
     * @param       array       $product        Product
     */
    public function insert_order_product(OsCommerceOrder $order, array $product)
    {
        tep_db_perform(TABLE_ORDERS_PRODUCTS, array
        (
            'orders_id'         => $order->info['order_id'],
            'products_id'       => tep_get_prid($product['id']),
            'products_model'    => $product['model'],
            'products_name'     => $product['name'],
            'products_price'    => $product['price'],
            'final_price'       => $product['final_price'],
            'products_tax'      => $product['tax'],
            'products_quantity' => $product['qty']
        ));

        $product['order_product_id'] = tep_db_insert_id();
    }

    /**
     * Method inserts all order product attributes data to DB
     *
     * @param       order       $order          Order
     * @param       array       $product        Product
     */
    public function insert_product_attributes(OsCommerceOrder $order, array $product)
    {
        if (empty($product['attributes']))
        {
            return;
        }

        foreach ($product['attributes'] as $attribute)
        {
            $this->insert_product_attribute($order, $product, $attribute);
        }
    }

    /**
     * Method inserts order product attribute to DB
     *
     * @param       order       $order          Order
     * @param       array       $product        Product
     * @param       array       $attribute      Attribute
     */
    public function insert_product_attribute(OsCommerceOrder $order, array $product, array $attribute)
    {
        $attribute_data = $this->select_attribute_data($product, $attribute);

        if (empty($attribute_data))
        {
            return;
        }

        tep_db_perform(TABLE_ORDERS_PRODUCTS_ATTRIBUTES, array
        (
            'orders_id'               => $order->info['order_id'],
            'orders_products_id'      => $product['order_product_id'],
            'products_options'        => $attribute_data['products_options_name'],
            'products_options_values' => $attribute_data['products_options_values_name'],
            'options_values_price'    => $attribute_data['options_values_price'],
            'price_prefix'            => $attribute_data['price_prefix']
        ));

        if (    DOWNLOAD_ENABLED == 'true'
            &&  isset($attribute_data['products_attributes_filename'])
            &&  tep_not_null($attribute_data['products_attributes_filename']))
        {
            tep_db_perform(TABLE_ORDERS_PRODUCTS_DOWNLOAD, array
            (
                'orders_id'                => $order->info['order_id'],
                'orders_products_id'       => $product['order_product_id'],
                'orders_products_filename' => $attribute_data['products_attributes_filename'],
                'download_maxdays'         => $attribute_data['products_attributes_maxdays'],
                'download_count'           => $attribute_data['products_attributes_maxcount']
            ));
        }
    }

    /**
     * Method selects product attribute from DB.
     * Attribute data format:
     *
     * - if download enabled
     * [
     *     'products_options_name'              => string,
     *     'products_options_values_name'       => string,
     *     'options_values_price'               => float,
     *     'price_prefix'                       => string,
     *     'products_attributes_maxdays'        => integer,
     *     'products_attributes_maxcount'       => integer,
     *     'pad.products_attributes_filename'   => string
     * ]
     *- if download disabled
     * [
     *     'products_options_name'              => string,
     *     'products_options_values_name'       => string
     *     'options_values_price'               => float
     *     'price_prefix'                       => string
     * ]
     *
     * @param       array       $product        Product
     * @param       array       $attribute      Attribute
     *
     * @return      array                       Attribute data
     */
    public function select_attribute_data(array $product, array $attribute)
    {
        $product_id     = (int) $product['id'];
        $option_id      = (int) $attribute['option_id'];
        $value_id       = (int) $attribute['value_id'];
        $language_id    = (int) $GLOBALS['languages_id'];

        if (DOWNLOAD_ENABLED == 'true')
        {
            $attributes_query =
               "SELECT  popt.products_options_name,
                        poval.products_options_values_name,
                        pa.options_values_price,
                        pa.price_prefix,
                        pad.products_attributes_maxdays,
                        pad.products_attributes_maxcount,
                        pad.products_attributes_filename
                FROM " .        TABLE_PRODUCTS_OPTIONS .                " popt, " .
                                TABLE_PRODUCTS_OPTIONS_VALUES .         " poval, " .
                                TABLE_PRODUCTS_ATTRIBUTES .             " pa
                LEFT JOIN " .   TABLE_PRODUCTS_ATTRIBUTES_DOWNLOAD .    " pad
                    ON  pa.products_attributes_id   = pad.products_attributes_id
                WHERE   pa.products_id              = {$product_id}
                    AND pa.options_id               = {$option_id}
                    AND pa.options_id               = popt.products_options_id
                    AND pa.options_values_id        = {$value_id}
                    AND pa.options_values_id        = poval.products_options_values_id
                    AND popt.language_id            = {$language_id}
                    AND poval.language_id           = {$language_id}";
        }
        else
        {
            $attributes_query =
                "SELECT popt.products_options_name,
                        poval.products_options_values_name,
                        pa.options_values_price,
                        pa.price_prefix
                 FROM " .   TABLE_PRODUCTS_OPTIONS .                    " popt, " .
                            TABLE_PRODUCTS_OPTIONS_VALUES .             " poval, " .
                            TABLE_PRODUCTS_ATTRIBUTES .                 " pa
                 WHERE   pa.products_id             = {$product_id}
                     AND pa.options_id              = {$option_id}
                     AND pa.options_id              = popt.products_options_id
                     AND pa.options_values_id       = {$value_id}
                     AND pa.options_values_id       = poval.products_options_values_id
                     AND popt.language_id           = {$language_id}
                     AND poval.language_id          = {$language_id}";
        }

        return tep_db_fetch_array(tep_db_query($attributes_query)) ?: array();
    }
}