<?php
    defined('_JEXEC') or die('Restricted access');
    
    class plgHikashoppaymentecpay extends hikashopPaymentPlugin {
        public $accepted_currencies = array('TWD');
        
        public $multiple = true;
        public $name = 'ecpay';
        public $pluginConfig = array(
            'ecpay_merchant_id' => array('商店代號', 'input'),
            'ecpay_hash_key' => array('HashKey', 'input'),
            'ecpay_hash_iv' => array('HashIV', 'input'),
            'ecpay_created_status' => array('訂單建立狀態', 'orderstatus'),
            'ecpay_succeed_status' => array('付款成功狀態', 'orderstatus'),
            'ecpay_failed_status' => array('付款失敗狀態', 'orderstatus'),
            'ecpay_payment_methods' => array('綠界科技付款方式', 'checkbox', array(
                'Credit'    => '信用卡(一次付清)',
                'Credit_3'  => '信用卡(3期)',
                'Credit_6'  => '信用卡(6期)',
                'Credit_12' => '信用卡(12期)',
                'Credit_18' => '信用卡(18期)',
                'Credit_24' => '信用卡(24期)',
                'WebATM'    => '網路ATM',
                'ATM'       => 'ATM',
                'CVS'       => '超商代碼',
                'BARCODE'   => '超商條碼',
            )),
        );

        function getPaymentDefaultValues(&$element) {
            $element->payment_name = '綠界科技';
            $element->payment_description = '您可透過此金流服務進行付款';
            $element->payment_params->ecpay_merchant_id = '2000132';
            $element->payment_params->ecpay_hash_key = '5294y06JbISpM5x9';
            $element->payment_params->ecpay_hash_iv = 'v77hoKGq4kWxNNIS';
            $element->payment_params->ecpay_created_status = 'created';
            $element->payment_params->ecpay_succeed_status = 'confirmed';
            $element->payment_params->ecpay_failed_status = 'cancelled';
            $element->payment_params->ecpay_payment_methods = ['Credit', 'Credit_3', 'Credit_6', 'Credit_12', 'Credit_18', 'Credit_24', 'WebATM', 'ATM', 'CVS', 'BARCODE'];
        }

        function getECPayPaymentMethods() {
            return $this->pluginConfig['ecpay_payment_methods'][2];
        }

        function needCC(&$method) {
            $ecpayPaymentMethods = $method->payment_params->ecpay_payment_methods;
            $ecpayPayment = $this->getECPayPaymentMethods();
            if (is_array($ecpayPaymentMethods) && sizeof($ecpayPaymentMethods) > 0) {
                $paymentMethods = '';
                foreach ($ecpayPaymentMethods as $key => $ecpayPaymentMethod) {
                    $paymentMethods .= '<option value="' . $ecpayPaymentMethod . '">' . $ecpayPayment[$ecpayPaymentMethod] . '</option>';
                }
                $method->custom_html = '
                    <span>
                        付款方式: 
                        <select name="ecpay_choose_payment">
                          ' . $paymentMethods . '
                        </select>
                    </span>
                    <script>
                        window.onload = function() {
                            if (typeof document.getElementById(\'hikashop_checkout_payment_1_3\') !== \'undefined\' && document.getElementById(\'hikashop_checkout_payment_1_3\') !== null) {
                                window.setInterval(function() {
                                    document.querySelector(\'.hikabtn_checkout_payment_submit\').style = \'display: none\';
                                }, 500);
                            }
                        }
                    </script>
                ';
                return true;
            }
            return false;
        }

        function onPaymentConfiguration(&$element) {
            parent::onPaymentConfiguration($element);
            $app = JFactory::getApplication();
            
            if (empty($element->payment_params->ecpay_merchant_id)) {
                $app->enqueueMessage('商店代號不可為空值!', 'error');
                return false;
            }
            
            if (empty($element->payment_params->ecpay_hash_key)) {
                $app->enqueueMessage('HashKey不可為空值!', 'error');
                return false;
            }
            
            if (empty($element->payment_params->ecpay_hash_iv)) {
                $app->enqueueMessage('HashIV不可為空值!', 'error');
                return false;
            }
        }

        function onPaymentNotification(&$statuses) {
            $this->invokeExt(JPATH_PLUGINS . '/hikashoppayment/' . $this->name . '/');
            $this->writeToLog(LogMsg::RESP_DES);
            $this->writeToLog('_POST:' . "\n" . print_r($_POST, true));
            
            $filter = JFilterInput::getInstance();
            $ignore_params = array('hikashop_payment_notification_plugin');
            foreach ($_POST as $key => $value) {
                if (in_array($key, $ignore_params)) {
                    unset($_POST[$key]);
                } else {
                    $key = $filter->clean($key);
                    $value = JRequest::getString($key);
                    $_POST[$key] = $value;
                }
            }
            
            $history = new stdClass();
            $history->notified = 1;
            $history->amount = 0;
            $history->data = '';
            $res_msg = '1|OK';
            $cart_order_id = '';
            $update_status = null;
            try {
                $ecpay_params_str = $this->loadECPayPaymentParams();
                $ecpay_params = @unserialize($ecpay_params_str);
                $merchant_id = $ecpay_params->ecpay_merchant_id;
                $hash_key = $ecpay_params->ecpay_hash_key;
                $hash_iv = $ecpay_params->ecpay_hash_iv;
                $created_status = $ecpay_params->ecpay_created_status;
                $succeed_status = $ecpay_params->ecpay_succeed_status;
                $failed_status = $ecpay_params->ecpay_failed_status;
                
                $AIO = new ECPay_AllInOne();
                $ACE = new ECPayExt($merchant_id);
                
                $AIO->MerchantID = $merchant_id;
                $AIO->HashKey = $hash_key;
                $AIO->HashIV = $hash_iv;
                $checkout_feedback = $AIO->CheckOutFeedback();
                if (empty($checkout_feedback)) {
                    throw new Exception(ErrorMsg::C_FD_EMPTY);
                }
                $rtn_code = $checkout_feedback['RtnCode'];
                $rtn_msg = $checkout_feedback['RtnMsg'];
                $payment_method = $ACE->parsePayment($checkout_feedback['PaymentType']);
                $merchant_trade_no = $checkout_feedback['MerchantTradeNo'];
                $cart_order_number = $ACE->getCartOrderID($merchant_trade_no);
                $cart_order_id = $this->getECPayOrderID($cart_order_number);
                $order_info = $this->getOrder((int)@$cart_order_id);
                $cart_order_total = $ACE->roundAmt($order_info->order_full_price);
                $history->amount = $cart_order_total;
                
                $AIO->ServiceURL = $ACE->getServiceURL(URLType::QUERY_ORDER);
                $AIO->Query['MerchantTradeNo'] = $merchant_trade_no;
                $query_feedback = $AIO->QueryTradeInfo();
                if (empty($query_feedback)) {
                    throw new Exception(ErrorMsg::Q_FD_EMPTY);
                }
                $query_trade_amount = $query_feedback['TradeAmt'];
                $query_payment_type = $query_feedback['PaymentType'];
                
                $ACE->validAmount($cart_order_total, $checkout_feedback['TradeAmt'], $query_trade_amount);
                $query_payment = $ACE->parsePayment($query_payment_type);
                $ACE->validPayment($payment_method, $query_payment);
                $ACE->validStatus($created_status, $order_info->order_status);
                
                $comment_tpl = $ACE->getCommentTpl($payment_method, $rtn_code);
                $history->data = $ACE->getComment($payment_method, $comment_tpl, $checkout_feedback);
                
                $is_getcode = $ACE->isGetCode($payment_method, $rtn_code);
                $is_paid = $ACE->isPaid($rtn_code);
                if ($is_getcode) {
                    $update_status = null;
                    $history->notified = 0;
                } else {
                    if ($is_paid) {
                        $update_status = $succeed_status;
                    } else {
                        $update_status = $failed_status;
                    }
                }
            } catch (Exception $e) {
                $exception_msg = $e->getMessage();
                $res_msg = '0|' . $exception_msg;
                $update_status = null;
                if (!empty($ACE)) {
                    $fail_tpl = $ACE->getTpl('fail');
                    $history->data = $ACE->getFailComment($exception_msg, $fail_tpl);
                }
            }

            if (array_key_exists('stage', $_POST)) {
                if ($_POST['stage'] !== '' && $_POST['stage'] > 1) {
                    $payment_method .= ', 分' . $_POST['stage'] . '期';
                }
            }

            $history->data = 'Payment Method: ' . $payment_method . '. ' . $history->data;
            
            $this->modifyOrder($cart_order_id, $update_status, $history, null, $ecpay_params_str);
            
            echo $res_msg;
            exit;
        }
        
        public function onPaymentSave(&$cart, &$rates, &$payment_id) {
            $_SESSION['ecpayChoosePayment'] = hikaInput::get()->getVar('ecpay_choose_payment');

            $usable_method = parent::onPaymentSave($cart, $rates, $payment_id);
            $ecpayPaymentMethods = $this->getECPayPaymentMethods();

            if (
                ! $usable_method 
                && empty($_SESSION['ecpayChoosePayment']) 
                && ! in_array($_SESSION['ecpayChoosePayment'], $ecpayPaymentMethods)
            ) {
                $app = JFactory::getApplication();
                $app->enqueueMessage('請選擇綠界科技付款方式。', 'error');
                return false;
            }

            return $usable_method;
        }

        function onAfterOrderConfirm(&$order,&$methods,$method_id) {
            parent::onAfterOrderConfirm($order, $methods, $method_id);

            $order_id = $order->order_number;
            $payment_type = $order->order_payment_method;
            $hikashop_checkour_url = HIKASHOP_LIVE . 'index.php?option=com_hikashop&ctrl=checkout';
            $return_url = $hikashop_checkour_url . '&task=notify&notif_payment=' . $this->name . '&tmpl=component&lang=' . $this->locale . $this->url_itemid;
            $client_back_url = $hikashop_checkour_url . '&task=after_end&order_id=' . $order->order_id . $this->url_itemid;
            
            $this->invokeExt(JPATH_PLUGINS . '/hikashoppayment/' . $this->name . '/');
            $merchant_id = $this->payment_params->ecpay_merchant_id;
            $choose_installment = 0;
            try {
                $AIO = new ECPay_AllInOne();
                $ACE = new ECPayExt($merchant_id);
                
                $service_url = $ACE->getServiceURL(URLType::CREATE_ORDER);
                $merchant_trade_no = $ACE->getMerchantTradeNo($order_id);
                $order_total = $ACE->roundAmt($order->cart->full_total->prices[0]->price_value_with_tax);
                
                $AIO->MerchantID = $merchant_id;
                $AIO->ServiceURL = $service_url;
                $AIO->Send['MerchantTradeNo'] = $merchant_trade_no;
                $AIO->HashKey = $this->payment_params->ecpay_hash_key;
                $AIO->HashIV = $this->payment_params->ecpay_hash_iv;
                $AIO->Send['ReturnURL'] = $return_url;
                $AIO->Send['ClientBackURL'] = $client_back_url;
                $AIO->Send['MerchantTradeDate'] = date('Y/m/d H:i:s');
                $AIO->Send['TradeDesc'] = 'ecpay_module_hikashop_1_0_0';
                $AIO->Send['TotalAmount'] = $order_total;
                $AIO->Send['NeedExtraPaidInfo'] = 'Y';
                $AIO->Send['Items'] = array();
                array_push(
                    $AIO->Send['Items'],
                    array(
                        'Name' => '網路商品一批',
                        'Price' => $order_total,
                        'Currency' => $this->currency->currency_code,
                        'Quantity' => 1,
                        'URL' => ''
                    )
                );
                $type_pieces = explode('_', $_SESSION['ecpayChoosePayment']);
                $AIO->Send['ChoosePayment'] = $ACE->getPayment($type_pieces[0]);
                if (isset($type_pieces[1])) {
                    $choose_installment = $type_pieces[1];
                }
                $params = array(
                    'Installment' => $choose_installment,
                    'TotalAmount' => $AIO->Send['TotalAmount'],
                    'ReturnURL' => $AIO->Send['ReturnURL']
                );
                $AIO->SendExtend = $ACE->setSendExt($AIO->Send['ChoosePayment'], $params);

                $red_html = $AIO->CheckOut(null);
                echo $red_html;
                exit;
            } catch(Exception $e) {
                $app = JFactory::getApplication();
                $app->enqueueMessage($e->getMessage(), 'error');
                return false;
            }
        }
    
        private function invokeExt($ext_dir) {
            $sdk_res = include_once($ext_dir . 'ECPay.Payment.Integration.php');
            $ext_res = include_once($ext_dir . 'ecpay_ext.php');
            return ($sdk_res and $ext_res);
        }
    
        private function loadECPayPaymentParams() {
            $name = $this->name;
            $payment_params = '';
            $db = JFactory::getDBO();
            $where = array('payment_type=' . $db->Quote($name), 'payment_published="1"');

            $app = JFactory::getApplication();
            if (!$app->isAdmin()) {
                hikashop_addACLFilters($where, 'payment_access');
            }

            $where = ' WHERE '.implode(' AND ', $where);
            
            $db->setQuery('SELECT payment_params FROM `#__hikashop_payment`' . $where . ' ORDER BY payment_ordering');
            $db_result = $db->loadObjectList();
            $payment_params = $db_result[0]->payment_params;

            return $payment_params;
        }
    
        private function getECPayOrderID($order_number) {
            $name = $this->name;
            $ecpay_order_id = '';
            $db = JFactory::getDBO();
            $where = array('order_payment_method=' . $db->Quote($name), 'order_number=' . $db->Quote($order_number));

            $app = JFactory::getApplication();
            if (!$app->isAdmin()) {
                hikashop_addACLFilters($where, 'payment_access');
            }

            $where = ' WHERE '.implode(' AND ', $where);
            $db->setQuery('SELECT order_id FROM `#__hikashop_order`'.$where.' limit 1');
            $db_result = $db->loadObjectList();
            $ecpay_order_id = $db_result[0]->order_id;
            
            return $ecpay_order_id;
        }
    }

    if (! class_exists('hikaInput', true)) {
        class hikaInput {
            protected static $ref = null;

            public static function &get() {
                if (! empty($ref))
                    return $ref;
                $ref =& JFactory::getApplication()->input;
                return $ref;
            }
        }
    }
?>
