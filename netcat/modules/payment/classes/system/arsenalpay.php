<?

class nc_payment_system_arsenalpay extends nc_payment_system {

    const ERROR_TOKEN_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_TOKEN_IS_NOT_VALID;
    const ERROR_KEY_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_KEY_IS_NOT_VALID;
    const ERROR_PAYMENT_TYPE_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_PAYMENT_TYPE_IS_NOT_VALID;
    const ARSENALPAY_CB_URL = "/netcat/modules/payment/callback.php?paySystem=nc_payment_system_arsenalpay";
    const ERROR_MISSING_PARAM = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_MISSING_PARAM;
    const ERROR_INVALID_SIGNATURE = NETCAT_MODULE_PAYMENT_ARSENALPAY_INVALID_SIGNATURE;
    const ERROR_INVALID_AMOUNT = NETCAT_MODULE_PAYMENT_ARSENALPAY_INVALID_AMOUNT;
    const ERROR_INVOICE_NOT_FOUND = NETCAT_MODULE_ARSENALPAY_INVOICE_NOT_FOUND;

    const TARGET_URL = "https://arsenalpay.ru/payframe/pay.php";

    /**
	 * @var boolean  TRUE — автоматический прием платежа, FALSE — ручная проверка
	 */
    protected $automatic = TRUE;

    // @var array  Коды валют, которые принимает платежная система (трехбуквенные коды ISO 4217)
    protected $accepted_currencies = array('RUB', 'RUR'); //уточнить полный список валют

    // Автоматический маппинг кодов валют из внешних в принятые в платежной системе
    protected $currency_map = array('RUR' => 'RUB');

    // Настройки платёжной системы
    protected $settings = array(
		'UniqueToken'=> null,    	
		'SecretKey'  => null,
		'PaymentType'  => null, 
		'CallbackURL' => nc_payment_system_arsenalpay::ARSENALPAY_CB_URL, 
		'AllowedIP'  => null,
        'CssFileUrl'  => "",	 
		'IframeAttributes'=> null,
		'FrameMode' => "1",
    );

    // Дополнительные (изменяемые) параметры запроса к платежной системе  
    protected $request_parameters = array(
    	'MERCH_TYPE' => null,
    	'AMOUNT_FULL' => null,
    );

    /**
     * @var array  Ответ платёжной системы
     */
    protected $callback_response = array(
        'ID'       => null, /* Идентификатор ТСП/ merchant identifier */
        'FUNCTION' => null, /* Тип запроса/ type of request to which the response is received*/
	    'RRN'      => null, /* Идентификатор транзакции/ transaction identifier */
	    'PAYER'    => null, /* Идентификатор плательщика/ payer(customer) identifier */
		'AMOUNT'   => null, /* Сумма платежа/ payment amount */
	    'ACCOUNT'  => null, /* Номер получателя платежа (номер заказа, номер ЛС) на стороне ТСП/ order number */
	    'STATUS'   => null, /* Статус платежа - check - запрос на проверку номера получателя : payment - запрос на передачу статуса платежа
            				/* Payment status. When 'check' - response for the order number checking, when 'payment' - response for status change.*/
		'DATETIME' => null, /* Дата и время в формате ISO-8601 (YYYY-MM-DDThh:mm:ss±hh:mm), УРЛ-кодированное */
            				/* Date and time in ISO-8601 format, urlencoded.*/
		'SIGN'     => null,  /* Подпись запроса/ response sign.
             				/* = md5(md5(ID).md(FUNCTION).md5(RRN).md5(PAYER).md5(AMOUNT).md5(ACCOUNT).md(STATUS).md5(PASSWORD)) */       
    );

    /**
     * Проведение платежа
     */
    public function execute_payment_request(nc_payment_invoice $invoice) {
		ob_end_clean();  	
		$currency = $this->get_currency_code($invoice->get_currency()); 
		$order_id = $invoice->get_id();
		$settings = [
					'src' => $this->get_setting('PaymentType'),
					't' => $this->get_setting('UniqueToken'),
					'n' => $order_id,
					'a' => $invoice->get_amount("%0.2F"),
					'frame' => $this->get_setting('FrameMode'),
					'css' => $this->get_setting('CssFileUrl'),
					'msisdn' => '',
					];
		$iframeAttributes = $this->get_setting('IframeAttributes');
		$frameParams = http_build_query($settings);
		if (strlen($iframeAttributes) == 0) {
			$iframeAttributes = "width=750 height=750 scrolling='auto' frameborder='no' seamless";
		}
		$src = nc_payment_system_arsenalpay::TARGET_URL . "?" . $frameParams;
		//Frame displays the payment after placing the order.
	 	$iframe = "
        <html>
          <body>
                <iframe src='" . $src . "' '" .$iframeAttributes. "'>
                </iframe>
          </body>
        </html>
        ";
        echo $iframe;
        exit;
    }

    /**
     * Анализ обратного вызова платежной системы и
     * вызов методов on_payment_success() или on_payment_failure().
     * @param nc_payment_invoice $invoice
     */
    public function on_response(nc_payment_invoice $invoice = null) {
        $status = $this->get_response_value('STATUS');

        if ($status == 'check') {
            $invoice_id = $this->get_response_value('ACCOUNT');

            $invoice->set('status', nc_payment_invoice::STATUS_WAITING);
            $invoice->save();

            $this->print_callback_answer('YES', $invoice_id);
        } else if ($status == 'payment') {
            $invoice_id = $this->get_response_value('ACCOUNT');

            $invoice->set('status', nc_payment_invoice::STATUS_SUCCESS);
            $invoice->save();

            $this->print_callback_answer("OK", $invoice_id);
            $this->on_payment_success($invoice);
        } 
        else {
        	$this->print_callback_answer("ERR", $invoice_id);
        	$this->on_payment_failure($invoice);
        }
    }

    /**
     * Проверка параметров для проведения платежа.
     * В случае ошибок вызывать метод add_error($string)
     */
    public function validate_payment_request_parameters() {
        if (!$this->get_setting('UniqueToken')) {
            $this->add_error(nc_payment_system_arsenalpay::ERROR_TOKEN_IS_NOT_VALID);
        }
		if (!$this->get_setting('SecretKey')) {
            $this->add_error(nc_payment_system_arsenalpay::ERROR_KEY_IS_NOT_VALID);
        }
		if (!$this->get_setting('PaymentType')) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_PAYMENT_TYPE_IS_NOT_VALID);
        }
    }

	/**
	 * Проверка параметров при поступлении обратного
     * вызова платежной системы.
     * В случае ошибок вызывать метод add_error($string)
     */
    public function validate_payment_callback_response(nc_payment_invoice $invoice = null) {
        $error = false;
        $status = $this->get_response_value('STATUS');
        $invoice_id = $this->get_response_value('ACCOUNT');
        // reading AllowedIP from config params
        $allowed_ip = $this->get_setting('AllowedIP');
        $remote_address = $_SERVER["REMOTE_ADDR"];
        $log_string = date("Y-m-d H:i:s") . " " . $remote_address . " ";
        if( strlen( $allowed_ip ) > 0 && $allowed_ip != $remote_address ) {
            $error = nc_payment_invoice::STATUS_CALLBACK_ERROR;
            $this->post_log(nc_payment_system_arsenalpay::ERROR_NOT_ALLOWED_IP);
            $this->add_error(nc_payment_system_arsenalpay::ERROR_NOT_ALLOWED_IP);
        }
        foreach( $this->get_response() as $key => $val ) {
            if( empty( $this->get_response_value($key) ) && (($key != 'MERCH_TYPE') && ($key != 'AMOUNT_FULL')) ) {
                $error = nc_payment_invoice::STATUS_CALLBACK_ERROR;
                $this->post_log(nc_payment_system_arsenalpay::ERROR_MISSING_PARAM . $key);
                $this->add_error(nc_payment_system_arsenalpay::ERROR_MISSING_PARAM);
            } 
            else {
                $log_string .= "{$key}={$val}&";
            }
        }
        $this->post_log($log_string);
        
        if ($invoice) {
            $lessAmount = false;
            if (!($this->check_sign())) {
                $error = nc_payment_invoice::STATUS_CALLBACK_ERROR;
                
                $this->post_log(nc_payment_system_arsenalpay::ERROR_INVALID_SIGNATURE);
                $this->add_error(nc_payment_system_arsenalpay::ERROR_INVALID_SIGNATURE);
            } 
            else if ($this->get_response_value('MERCH_TYPE') == 0 && $invoice->get_amount() ==  $this->get_response_value('AMOUNT')) {
                $lessAmount = false;
            } 
            else if($this->get_response_value('MERCH_TYPE') == 1 && $invoice->get_amount() >= $this->get_response_value('AMOUNT') && 
                $invoice->get_amount() ==  $this->get_response_value('AMOUNT_FULL')) {
                $lessAmount = true;
            } 
            else {
                $error = nc_payment_invoice::STATUS_CALLBACK_WRONG_SUM;
                $this->post_log(nc_payment_system_arsenalpay::ERROR_INVALID_AMOUNT);
                $this->add_error(nc_payment_system_arsenalpay::ERROR_INVALID_AMOUNT);
            }
            
            if ($lessAmount) {
                $this->post_log("Callback response with less amount {$this->get_response_value('AMOUNT')}");
            }
            if ($error) {
                $invoice->set('status', $error);
                $invoice->save();
            }
        } 
        else {
            $error = true;
            $this->post_log(nc_payment_system_arsenalpay::ERROR_INVOICE_NOT_FOUND);
            $this->add_error(nc_payment_system_arsenalpay::ERROR_INVOICE_NOT_FOUND);
        }
        
        if ($error) {
            if ($status == 'check') {
                $this->print_callback_answer('NO', $invoice_id);
            } 
            else {
                $this->print_callback_answer('ERR', $invoice_id);
            }
        }
    }
    
    /**
     * @return nc_payment_invoice|boolean 
     */
    public function load_invoice_on_callback() {
        return $this->load_invoice($this->get_response_value('ACCOUNT'));
    }
    
    private function check_sign() {
        $secret_key = $this->get_setting('SecretKey');
        $validSign = ( $this->get_response_value('SIGN') === md5(md5($this->get_response_value('ID')). 
                md5($this->get_response_value('FUNCTION')).md5($this->get_response_value('RRN')). 
                md5($this->get_response_value('PAYER')).md5($this->get_response_value('AMOUNT')).md5($this->get_response_value('ACCOUNT')). 
                md5($this->get_response_value('STATUS')).md5($secret_key) ) )? true : false;
        return $validSign;
    }
    
    private function post_log($str)
    {
        $fp = fopen(realpath(dirname(__FILE__)) . "/arsenalpay/callback.log", "a+");
        fwrite($fp, $str . "\r\n");
        fclose($fp);
    }	
    
    private function print_callback_answer($answer, $invoice_id) {
        $this->post_log("invoice id: " . $invoice_id . "; answer: " . $answer);
        echo $answer;
    }
}

