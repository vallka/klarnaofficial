<?php
/**
 * 2015 Prestaworks AB.
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to info@prestaworks.se so we can send you a copy immediately.
 *
 *  @author    Prestaworks AB <info@prestaworks.se>
 *  @copyright 2015 Prestaworks AB
 *  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 *  International Registered Trademark & Property of Prestaworks AB
 */
 
class KlarnaOfficialPushKcoModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;
    public $display_column_right = false;
    public $ssl = true;

    public function postProcess()
    {
        if (!Tools::getIsset('klarna_order_id')) {
            Logger::addLog('KCO V3: bad push by:'.Tools::getRemoteAddr(), 1, null, null, null, true);
            die('missing parameters');
        }
        //$url = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
        //Logger::addLog($url, 1, null, null, null, true);

        require_once dirname(__FILE__).'/../../libraries/KCOUK/autoload.php';
        //Klarna uses iso 3166-1 alpha 3, prestashop uses different iso so we need to convert this.
        $country_iso_codes = array(
        'SWE' => 'SE',
        'NOR' => 'NO',
        'FIN' => 'FI',
        'DNK' => 'DK',
        'DEU' => 'DE',
        'NLD' => 'NL',
        'se' => 'SE',
        'no' => 'NO',
        'fi' => 'FI',
        'dk' => 'DK',
        'de' => 'DE',
        'nl' => 'NL',
        'gb' => 'GB',
        'us' => 'US',
        );

        try {
            $sid = Tools::getValue('sid');
            // if ($sid == 'gb') {
                // $sharedSecret = Configuration::get('KCO_UK_SECRET');
                // $merchantId = Configuration::get('KCO_UK_EID');
            // } elseif ($sid == 'us') {
                // $sharedSecret = Configuration::get('KCO_US_SECRET');
                // $merchantId = Configuration::get('KCO_US_EID');
            // } elseif ($sid == 'nl') {
                // $sharedSecret = Configuration::get('KCO_NL_SECRET');
                // $merchantId = Configuration::get('KCO_NL_EID');
            // } elseif ($sid == 'se') {
                // $sharedSecret = Configuration::get('KCOV3_SWEDEN_SECRET');
                // $merchantId = Configuration::get('KCOV3_SWEDEN_EID');
            // } elseif ($sid == 'no') {
                // $sharedSecret = Configuration::get('KCOV3_NORWAY_SECRET');
                // $merchantId = Configuration::get('KCOV3_NORWAY_EID');
            // } elseif ($sid == 'fi') {
                // $sharedSecret = Configuration::get('KCOV3_FINLAND_SECRET');
                // $merchantId = Configuration::get('KCOV3_FINLAND_EID');
            // } elseif ($sid == 'de') {
                // $sharedSecret = Configuration::get('KCOV3_GERMANY_SECRET');
                // $merchantId = Configuration::get('KCOV3_GERMANY_EID');
            // } elseif ($sid == 'at') {
                // $sharedSecret = Configuration::get('KCOV3_AUSTRIA_SECRET');
                // $merchantId = Configuration::get('KCOV3_AUSTRIA_EID');
            // }
            $merchantId = Configuration::get('KCOV3_MID');
            $sharedSecret = Configuration::get('KCOV3_SECRET');
            if ((int) (Configuration::get('KCO_TESTMODE')) == 1) {
                $connector = \Klarna\Rest\Transport\Connector::create(
                    $merchantId,
                    $sharedSecret,
                    \Klarna\Rest\Transport\ConnectorInterface::EU_TEST_BASE_URL
                );

                $orderId = Tools::getValue('klarna_order_id');

                $checkout = new \Klarna\Rest\Checkout\Order($connector, $orderId);
                $checkout->fetch();
            } else {
                $connector = \Klarna\Rest\Transport\Connector::create(
                    $merchantId,
                    $sharedSecret,
                    \Klarna\Rest\Transport\ConnectorInterface::EU_BASE_URL
                );

                $orderId = Tools::getValue('klarna_order_id');

                $checkout = new \Klarna\Rest\Checkout\Order($connector, $orderId);
                $checkout->fetch();
            }
            //var_dump($checkout);
            if ($checkout['status'] == 'checkout_complete') {
                $id_cart = $checkout['merchant_reference2'];
                $cart = new Cart((int) ($id_cart));

                Context::getContext()->currency = new Currency((int) $cart->id_currency);

                $reference = Tools::getValue('klarna_order_id');
                if ($cart->OrderExists()) {
                    $klarna_reservation = Tools::getValue('klarna_order_id');
                    
                    $sql = 'SELECT m.transaction_id, o.id_order FROM `'._DB_PREFIX_.
                    'order_payment` m LEFT JOIN `'._DB_PREFIX_.
                    'orders` o ON m.order_reference=o.reference WHERE o.id_cart='.(int) ($id_cart);
                    
                    $messages = Db::getInstance()->ExecuteS($sql);
                    foreach ($messages as $message) {
                        //Check if reference matches
                        if ($message['transaction_id']==$klarna_reservation) {
                            //Already created, send create
                            $update = new Klarna\Rest\OrderManagement\Order($connector, $orderId);
                            $update->updateMerchantReferences(array(
                                'merchant_reference1' => ''.$message['id_order'],
                                'merchant_reference2' => ''.$id_cart,
                            ));
                            $update->acknowledge();
                            Logger::addLog(
                                'KCO: created sent: '.$id_cart.' res:'.$klarna_reservation,
                                1,
                                null,
                                null,
                                null,
                                true
                            );
                            die;
                        }
                    }
                    //Duplicate reservation, cancel reservation.
                    Logger::addLog(
                        'KCO: cancel cart: '.$id_cart.' res:'.$klarna_reservation,
                        1,
                        null,
                        null,
                        null,
                        true
                    );
                    
                    $checkout->cancel();
                } else {
                    //Create the order
                    $klarna_reservation = Tools::getValue('klarna_order_id');
                    $shipping = $checkout['shipping_address'];
                    $billing = $checkout['billing_address'];

                    if (!Validate::isEmail($shipping['email'])) {
                        $shipping['email'] = 'ingen_mejl_'.$id_cart.'@ingendoman.cc';
                    }
                    
                    $newsletter = 0;
                    $newsletter_setting = (int)Configuration::get('KCO_ADD_NEWSLETTERBOX', null, $cart->id_shop);
                    if ($newsletter_setting == 0 || $newsletter_setting == 1) {
                        if (isset($checkout['merchant_requested']) &&
                            isset($checkout['merchant_requested']['additional_checkbox']) &&
                            $checkout['merchant_requested']['additional_checkbox'] == true
                        ) {
                            $newsletter = 1;
                        }
                    } elseif ($newsletter_setting == 2) {
                        $newsletter = 1;
                    }

                    if (0 == (int)$cart->id_customer) {
                        $id_customer = (int) (Customer::customerExists($shipping['email'], true, true));
                    } else {
                        $id_customer = (int)$cart->id_customer;
                    }
                    if ($id_customer > 0) {
                        $customer = new Customer($id_customer);
                        if ($newsletter == 1) {
                            $sql_update_customer = "UPDATE "._DB_PREFIX_."customer SET newsletter=1".
                            " WHERE id_customer=$id_customer;";
                            Db::getInstance()->execute(pSQL($sql_update_customer));
                        }
                    } else {
                        //add customer
                        $id_gender = 9;
                        $date_of_birth = "";
                        $customer = $this->module->createNewCustomer(
                            $shipping['given_name'],
                            $shipping['family_name'],
                            $shipping['email'],
                            $newsletter,
                            $id_gender,
                            $date_of_birth,
                            $cart
                        );
                    }

                    $this->module->changeAddressOnKCOCart($shipping, $billing, $country_iso_codes, $customer, $cart);
                    
                    $amount = (int) ($checkout['order_amount']);
                    $amount = (float) ($amount / 100);

                    $cart->id_customer = $customer->id;
                    $cart->secure_key = $customer->secure_key;
                    $cart->update();

                    $update_sql = 'UPDATE '._DB_PREFIX_.
                    'cart SET id_customer='.
                    (int) $customer->id.
                    ', secure_key=\''.
                    pSQL($customer->secure_key).
                    '\' WHERE id_cart='.
                    (int) $cart->id;
                    
                    Db::getInstance()->execute($update_sql);

                    if (Configuration::get('KCO_ROUNDOFF') == 1) {
                        $total_cart_price_before_round = $cart->getOrderTotal(true, Cart::BOTH);
                        $total_cart_price_after_round = round($total_cart_price_before_round);
                        $diff = abs($total_cart_price_after_round - $total_cart_price_before_round);
                        if ($diff > 0) {
                            $amount = $total_cart_price_before_round;
                        }
                    }

                    $reference = pSQL($reference);
                    $merchantId = pSQL($merchantId);
                    
                    $extra = array();
                    $extra['transaction_id'] = $reference;

                    $id_shop = (int) $cart->id_shop;
                    
                    $sql = 'INSERT INTO `'._DB_PREFIX_.
                        "klarna_orders`(eid, id_order, id_cart, id_shop, ssn, invoicenumber,risk_status ,reservation) ".
                        "VALUES('$merchantId', 0, ".
                        (int) $cart->id.", $id_shop, '', '', '','$reference');";
                    Db::getInstance()->execute($sql);
                    
                    $this->module->validateOrder(
                        $cart->id,
                        Configuration::get('PS_OS_PAYMENT'),
                        number_format($amount, 2, '.', ''),
                        $this->module->displayName,
                        '',
                        $extra,
                        $cart->id_currency,
                        false,
                        $customer->secure_key
                    );

                    $order_reference = $this->module->currentOrder;
                    if (Configuration::get('KCO_ORDERID') == 1) {
                        $order = new Order($this->module->currentOrder);
                        $order_reference = $order->reference;
                    }
                    $update = new Klarna\Rest\OrderManagement\Order($connector, $reference);
                    $update->updateMerchantReferences(array(
                                'merchant_reference1' => ''.$order_reference,
                                'merchant_reference2' => ''.$cart->id,
                            ));
                    $update->acknowledge();

                    $sql = 'UPDATE `'._DB_PREFIX_.
                        "klarna_orders` SET id_order=".
                        (int) $this->module->currentOrder.
                        " WHERE id_order=0 AND id_cart=".
                        (int) $cart->id;

                    Db::getInstance()->execute($sql);
                }
            }
        } catch (Exception $e) {
            Logger::addLog('Klarna Checkout: '.htmlspecialchars($e->getMessage()), 1, null, null, null, true);
        }
    }
}
