<?php

/**
 * @package       VM Payment - 9psb 
 * @author        9Psb
 * @copyright     Copyright (C) 2024 9Payment Ltd. All rights reserved.
 * @version       1.0.0, September 2024
 * @license       GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die('Direct access to ' . basename(__FILE__) . ' is not allowed.');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DIRECTORY_SEPARATOR . 'vmpsplugin.php');

class plgVmPaymen9psb extends vmPSPlugin
{
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable  = true;
        $this->_tablepkey = 'id';
        $this->_tableId   = 'id';

        $this->tableFields = array_keys($this->getTableSQLFields());

        $varsToPush = array(
            'test_mode' => array(
                1,
                'int'
            ),
            'live_private_key' => array(
                '',
                'char'
            ),
            'live_public_key' => array(
                '',
                'char'
            ), 
            'status_pending' => array(
                '',
                'char'
            ),
            'status_success' => array(
                '',
                'char'
            ),
            'status_canceled' => array(
                '',
                'char'
            ),

            'min_amount' => array(
                0,
                'int'
            ),
            'max_amount' => array(
                0,
                'int'
            ),
            'cost_per_transaction' => array(
                0,
                'int'
            ),
            'cost_percent_total' => array(
                0,
                'int'
            ),
            'tax_id' => array(
                0,
                'int'
            )
        );

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment 9psb Table');
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(11) unsigned NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(11) UNSIGNED DEFAULT NULL',
            'order_number' => 'char(32) DEFAULT NULL',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED DEFAULT NULL',
            'payment_name' => 'char(255) NOT NULL DEFAULT \'\' ',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\' ',
            'payment_currency' => 'char(3) ',
            'cost_per_transaction' => ' decimal(10,2) DEFAULT NULL ',
            'cost_percent_total' => ' decimal(10,2) DEFAULT NULL ',
            'tax_id' => 'smallint(11) DEFAULT NULL',
            '9psb_transaction_reference' => 'char(32) DEFAULT NULL'
        );

        return $SQLfields;
    }

    function get9psbSettings($payment_method_id)
    {
        $psb_settings = $this->getPluginMethod($payment_method_id);

        if ($psb_settings->test_mode) {
            $private_key = $psb_settings->test_private_key;
            $public_key = $psb_settings->test_public_key;
        } else {
            $private_key = $psb_settings->live_private_key;
            $public_key = $psb_settings->live_public_key;
        }

        $private_key = str_replace(' ', '', $private_key);
        $public_key = str_replace(' ', '', $public_key);

        return array(
            'secret_key' => $private_key,
            'public_key' => $public_key
        );
    }

    function plgVmConfirmedOrder($cart, $order)
{
    if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id))) {
        return null;
    }
    if (!$this->selectedThisElement($method->payment_element)) {
        return false;
    }

    if (!class_exists('VirtueMartModelOrders'))
        require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');

    if (!class_exists('VirtueMartModelCurrency'))
        require(JPATH_VM_ADMINISTRATOR . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'currency.php');

    $order_info = $order['details']['BT'];
    $country_code = ShopFunctions::getCountryByID($order_info->virtuemart_country_id, 'country_3_code');

    // Get payment currency
    $this->getPaymentCurrency($method);
    $db = JFactory::getDbo();
    $query = $db->getQuery(true);
    $query
        ->select('currency_code_3')
        ->from($db->quoteName('#__virtuemart_currencies'))
        ->where($db->quoteName('virtuemart_currency_id') . ' = ' . $db->quote($method->payment_currency));
    $db->setQuery($query);
    $currency_code = $db->loadResult();

    // Get total amount for the current payment currency
    $totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total, $method->payment_currency);

    // Prepare data to be stored in the database
    $dbValues['order_number'] = $order['details']['BT']->order_number;
    $dbValues['payment_name'] = $this->renderPluginName($method, $order);
    $dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
    $dbValues['cost_per_transaction'] = $method->cost_per_transaction;
    $dbValues['cost_percent_total'] = $method->cost_percent_total;
    $dbValues['payment_currency'] = $method->payment_currency;
    $dbValues['payment_order_total'] = $totalInPaymentCurrency;
    $dbValues['tax_id'] = $method->tax_id;
    $dbValues['merchant_reference'] = $dbValues['order_number'] . '-' . date('YmdHis');

    $this->storePSPluginInternalData($dbValues);

    // Authenticate with 9PSB to get access token
    $authUrl = 'https://bank9jacollectapi.9psb.com.ng/gateway-api/v1/authenticate';
    $authData = json_encode([
        'publicKey' => 'PB_X1y3zwpxrAghtMnznDwiUHD7PYtnEuxkowVdBCbgNOvr8br',
        'privateKey' => 'PV_NV9qCfuvV8rdhYlFqbY8rEmbFiq8sCfXmui5BDqzS8Sk2sr'
    ]);

    $options = [
        'http' => [
            'header' => "Content-Type: application/json\r\n" .
                        "Accept: application/json\r\n",
            'method' => 'POST',
            'content' => $authData,
            'timeout' => 30
        ]
    ];

    $context = stream_context_create($options);
    $auth_response = @file_get_contents($authUrl, false, $context);

    if ($auth_response === false) {
        $error_details = error_get_last();
        throw new Exception('Failed to authenticate with 9PSB: ' . ($error_details['message'] ?? 'Unknown error'));
    }

    $auth_body = json_decode($auth_response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode authentication response: ' . json_last_error_msg());
    }

    $access_token = $auth_body['accessToken'];

    // Log access token for debugging
    error_log('Access Token: ' . $access_token);

    // Initiate payment
    $callback_url = JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&on=' . $order['details']['BT']->order_number . '&pm=' . $order['details']['BT']->virtuemart_paymentmethod_id;

    $initiateUrl = 'https://bank9jacollectapi.9psb.com.ng/gateway-api/v1/initiate-payment';

    $initiateData = json_encode([
        'amount' => rand(1000, 5000), // Random total payment amount
        'callbackUrl' => $callback_url,
        'customer' => [
            'name' => 'John Doe', 
            'email' => 'johndoe' . rand(1, 100) . '@example.com', 
            'phoneNo' => '+234' . rand(7000000000, 7999999999),
        ],
        'merchantReference' => 'ORD-' . rand(1000, 9999) . '-' . date('YmdHis'),
        'narration' => 'Test payment for order #' . rand(1000, 9999),
        'amountType' => 'ANY',
        'metaData' => [
            ['key' => 'order_id', 'value' => (string)rand(10000, 99999)],
            ['key' => 'customer_id', 'value' => (string)rand(1000, 9999)]
        ],
        'businessCode' => 'TESTBUSINESSCODE',
    ]);


    error_log('Initiate Payment Request Data: ' . print_r($initiateData, true));

    $initiateOptions = [
        'http' => [
            'header' => "Authorization: Bearer $access_token\r\n" .
                        "Content-Type: application/json\r\n" .
                        "Accept: application/json\r\n",
            'method' => 'POST',
            'content' => $initiateData,
            'timeout' => 30
        ]
    ];

    $initiateContext = stream_context_create($initiateOptions);
    $initiate_response = @file_get_contents($initiateUrl, false, $initiateContext);

    // Handle 202 Accepted response
    if (http_response_code() == 202) {
        // Log that the request was accepted but not processed immediately
        error_log('Payment initiation accepted (HTTP 202), awaiting further processing.');
        return array('result' => 'pending', 'message' => 'Payment initiation accepted, please check back later.');
    }

    if ($initiate_response === false) {
        $error_details = error_get_last();
        throw new Exception('Failed to initiate payment: ' . ($error_details['message'] ?? 'Unknown error'));
    }

    $initiate_body = json_decode($initiate_response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Failed to decode payment initiation response: ' . json_last_error_msg());
    }

    // Log the entire payment initiation response
    error_log('Payment initiation response: ' . print_r($initiate_body, true));

    if (isset($initiate_body['data']['link'])) {
        return array(
            'result' => 'success',
            'redirect' => $initiate_body['data']['link']
        );
    } else {
        $error_message = isset($initiate_body['message']) ? $initiate_body['message'] : 'Unknown error occurred during payment initiation';
        throw new Exception('Payment initiation failed: ' . $error_message);
    }
}

    
    
    
    
    
    

    function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('VirtueMartCart')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'cart.php');
        }
        if (!class_exists('shopFunctionsF')) {
            require(VMPATH_SITE . DIRECTORY_SEPARATOR . 'helpers' . DIRECTORY_SEPARATOR . 'shopfunctionsf.php');
        }
        if (!class_exists('VirtueMartModelOrders')) {
            require(VMPATH_ADMIN . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'orders.php');
        }

        VmConfig::loadJLang('com_virtuemart_orders', TRUE);
        $post_data = vRequest::getPost();

        // The payment itself should send the parameter needed.
        $virtuemart_paymentmethod_id = vRequest::getInt('pm', 0);
        $order_number                = vRequest::getString('on', 0);
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id))) {
            return NULL;
        }

        if (!$this->selectedThisElement($method->payment_element)) {
            return NULL;
        }

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number))) {
            return NULL;
        }

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id))) {
            return '';
        }

        VmConfig::loadJLang('com_virtuemart');
        $orderModel = VmModel::getModel('orders');
        $order      = $orderModel->getOrder($virtuemart_order_id);

        $payment_name = $this->renderPluginName($method);
        $html = '<table>' . "\n";
        $html .= $this->getHtmlRow('Payment Name', $payment_name);
        $html .= $this->getHtmlRow('Order Number', $order_number);

        $transData = $this->verify9psbTransaction($post_data['token'], $post_data['payment_method_id']);
        if (!property_exists($transData, 'error') && property_exists($transData, 'status') && ($transData->status === 'success') && (strpos($transData->reference, $order_number . "-") === 0)) {
            // Update order status - From pending to complete
            $order['order_status']      = 'C';
            $order['customer_notified'] = 1;
            $orderModel->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);

            $html .= $this->getHtmlRow('Total Amount', number_format($transData->amount/100, 2));
            $html .= $this->getHtmlRow('Status', $transData->status);
            $html .= '</table>' . "\n";
            // add order url
            $url=JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number='.$order_number,FALSE);
            $html.='<a href="'.JRoute::_('index.php?option=com_virtuemart&view=orders&layout=details&order_number='.$order_number,FALSE).'" class="vm-button-correct">'.vmText::_('COM_VIRTUEMART_ORDER_VIEW_ORDER').'</a>';

            // Empty cart
            $cart = VirtueMartCart::getCart();
            $cart->emptyCart();

            return True;
        } else if (property_exists($transData, 'error')) {
            die($transData->error);
        } else {
            $html .= $this->getHtmlRow('Total Amount', number_format($transData->amount/100, 2));
            $html .= $this->getHtmlRow('Status', $transData->status);
            $html .= '</table>' . "\n";
            $html.='<a href="'.JRoute::_('index.php?option=com_virtuemart&view=cart',false).'" class="vm-button-correct">'.vmText::_('CART_PAGE').'</a>';

            // Update order status - From pending to canceled
            $order['order_status']      = 'X';
            $order['customer_notified'] = 1;
            $orderModel->updateStatusForOneOrder($order['details']['BT']->virtuemart_order_id, $order, TRUE);
        }

        return False;
    }

    function plgVmOnUserPaymentCancel()
    {
        return true;
    }

    /**
     * Required functions by Joomla or VirtueMart. Removed code comments due to 'file length'.
     * All copyrights are (c) respective year of author or copyright holder, and/or the author.
     */
    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        if (preg_match('/%$/', $method->cost_percent_total)) {
            $cost_percent_total = substr($method->cost_percent_total, 0, -1);
        } else {
            $cost_percent_total = $method->cost_percent_total;
        }
        return ($method->cost_per_transaction + ($cart_prices['salesPrice'] * $cost_percent_total * 0.01));
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        $this->convert_condition_amount($method);
        $address     = (($cart->ST == 0) ? $cart->BT : $cart->ST);
        $amount      = $this->getCartAmount($cart_prices);
        $amount_cond = ($amount >= $method->min_amount AND $amount <= $method->max_amount OR ($method->min_amount <= $amount AND ($method->max_amount == 0)));
        $countries   = array();
        if (!empty($method->countries)) {
            if (!is_array($method->countries)) {
                $countries[0] = $method->countries;
            } else {
                $countries = $method->countries;
            }
        }
        if (!is_array($address)) {
            $address                          = array();
            $address['virtuemart_country_id'] = 0;
        }
        if (!isset($address['virtuemart_country_id'])) {
            $address['virtuemart_country_id'] = 0;
        }
        if (in_array($address['virtuemart_country_id'], $countries) || count($countries) == 0) {
            if ($amount_cond) {
                return TRUE;
            }
        }
        return FALSE;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmOnSelectCheckPayment(VirtueMartCart $cart)
    {
        return $this->OnSelectCheck($cart);
    }

    public function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

}
