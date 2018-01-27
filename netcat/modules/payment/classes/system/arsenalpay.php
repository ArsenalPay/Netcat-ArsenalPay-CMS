<?

class nc_payment_system_arsenalpay extends nc_payment_system {

	const ERROR_CALLBACK_KEY_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_CALLBACK_KEY_IS_NOT_VALID;
	const ERROR_WIDGET_ID_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_WIDGET_ID_IS_NOT_VALID;
	const ERROR_WIDGET_KEY_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_WIDGET_KEY_IS_NOT_VALID;
	const ERROR_MISSING_PARAM = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_MISSING_PARAM;
	const INVALID_SIGNATURE = NETCAT_MODULE_PAYMENT_ARSENALPAY_INVALID_SIGNATURE;
	const ERROR_FUNCTION = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_FUNCTION;
	const ERROR_AMOUNT = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_AMOUNT;
	const ERROR_STATUS = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_STATUS;
	const INVOICE_NOT_FOUND = NETCAT_MODULE_PAYMENT_ARSENALPAY_INVOICE_NOT_FOUND;
	const ERROR_NOT_ALLOWED_IP = NETCAT_MODULE_PAYMENT_ARSENALPAY_ERROR_NOT_ALLOWED_IP;
	/**
	 * @var boolean  TRUE — автоматический прием платежа, FALSE — ручная проверка
	 */
	protected $automatic = true;

	// @var array  Коды валют, которые принимает платежная система (трехбуквенные коды ISO 4217)
	protected $accepted_currencies = array('RUB', 'RUR');

	// Автоматический маппинг кодов валют из внешних в принятые в платежной системе
	protected $currency_map = array('RUR' => 'RUB');

	// Настройки платёжной системы
	protected $settings = array(
		'WidgetId'    => null,
		'WidgetKey'   => null,
		'CallbackKey' => null,
		'AllowedIP'   => null,
	);

	// Дополнительные (изменяемые) параметры запроса к платежной системе
	protected $request_parameters = array(
		'MERCH_TYPE'  => null,
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
		'SIGN'     => null, /* Подпись запроса/ response sign.
                            /* = md5(md5(ID).md(FUNCTION).md5(RRN).md5(PAYER).md5(AMOUNT).md5(ACCOUNT).md(STATUS).md5(PASSWORD)) */
	);

	/**
	 * Проведение платежа
	 */
	public function execute_payment_request(nc_payment_invoice $invoice) {
		ob_end_clean();
		$userId = $invoice->get_customer_contact_for_receipt();
		if ($userId == nc_payment_register::get_default_customer_email()) {
			$userId = '';
		}
		$destination = $invoice->get_id();
		$amount      = $invoice->get_amount("%0.2F");
		$widget      = $this->get_setting('WidgetId');
		$widgetKey   = $this->get_setting('WidgetKey');
		$nonce       = md5(microtime(true) . mt_rand(100000, 999999));
		$signParam   = "$userId;$destination;$amount;$widget;$nonce";
		$widgetSign  = hash_hmac('sha256', $signParam, $widgetKey);
		$html        = "
				<div id='arsenalpay-widget'></div>
				<script src='https://arsenalpay.ru/widget/script.js'></script>
				<script>
					var widget = new ArsenalpayWidget();
					widget.element = 'arsenalpay-widget';
					widget.widget = {$widget};
					widget.destination = '{$destination}';
					widget.amount = '{$amount}';
					widget.userId = '{$userId}';
					widget.nonce = '{$nonce}';
					widget.widgetSign = '{$widgetSign}';
					widget.render();
				</script>
				";
		echo $html;
		exit;
	}

	/**
	 * Анализ обратного вызова платежной системы и
	 * вызов методов on_payment_success() или on_payment_failure().
	 *
	 * @param nc_payment_invoice $invoice
	 */
	public function on_response(nc_payment_invoice $invoice = null) {
		$function = $this->get_response_value('FUNCTION');

		switch ($function) {
			case 'check':
				$invoice->set('status', nc_payment_invoice::STATUS_WAITING);
				$invoice->save();
				$this->exitf('YES');
				break;

			case 'payment':
				$this->on_payment_success($invoice);
				$this->exitf('OK');
				break;

			case 'hold':
				$invoice->set('status', nc_payment_invoice::STATUS_WAITING);
				$invoice->save();
				$this->exitf('OK');
				break;

			case 'cancel':
				$this->on_payment_rejected($invoice);
				$this->exitf('OK');
				break;

			case 'cancelinit':
				$this->on_payment_rejected($invoice);
				$this->exitf('OK');
				break;

			default:
				$this->on_payment_failure($invoice);
				$this->exitf('ERR');
				break;
		}
	}

	/**
	 * Проверка параметров для проведения платежа.
	 * В случае ошибок вызывать метод add_error($string)
	 */
	public function validate_payment_request_parameters() {
		if (!$this->get_setting('CallbackKey')) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_CALLBACK_KEY_IS_NOT_VALID);
		}
		if (!$this->get_setting('WidgetId')) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_WIDGET_ID_IS_NOT_VALID);
		}
		if (!$this->get_setting('WidgetKey')) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_WIDGET_KEY_IS_NOT_VALID);
		}
	}

	/**
	 * Проверка параметров при поступлении обратного
	 * вызова платежной системы.
	 * В случае ошибок вызывать метод add_error($string)
	 */
	public function validate_payment_callback_response(nc_payment_invoice $invoice = null) {
		$callback_params = $this->get_response();

		// reading AllowedIP from config params
		$allowed_ip     = trim($this->get_setting('AllowedIP'));
		$remote_address = $_SERVER["REMOTE_ADDR"];
		$this->log(date("Y-m-d H:i:s") . " " . $remote_address . " ");
		if (strlen($allowed_ip) > 0 && $allowed_ip != $remote_address) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_NOT_ALLOWED_IP);
		}

		if (!$this->check_params($callback_params)) {
			$this->add_error(nc_payment_system_arsenalpay::ERROR_MISSING_PARAM);
		}
		if (!$this->check_sign($callback_params, $this->get_setting('CallbackKey'))) {
			$this->add_error(nc_payment_system_arsenalpay::INVALID_SIGNATURE);
		}
		if (!$invoice) {
			$this->add_error(nc_payment_system_arsenalpay::INVOICE_NOT_FOUND);
		}
		$function = $callback_params['FUNCTION'];
		if (count($this->get_errors()) == 0) {
			$error = false;
			switch ($function) {
				case 'check':
					$error = $this->callback_check($callback_params, $invoice);
					break;

				case 'payment':
					$error = $this->callback_payment($callback_params, $invoice);
					break;

				case 'hold':
					$error = $this->callback_hold($callback_params, $invoice);
					break;

				case 'cancel':
					$error = $this->callback_cancel($callback_params, $invoice);
					break;

				case 'cancelinit':
					$error = $this->callback_cancel($callback_params, $invoice);
					break;

				default:
					$error = sprintf(nc_payment_system_arsenalpay::ERROR_FUNCTION, $function);
					break;
			}

			if ($error) {
				$this->add_error($error);
			}
		}

		if (count($this->get_errors()) > 0) {
			$final_statuses = array(
				nc_payment_invoice::STATUS_CANCELLED,
				nc_payment_invoice::STATUS_REJECTED,
				nc_payment_invoice::STATUS_SUCCESS,
			);

			if ($invoice && !in_array($invoice->get('status'), $final_statuses)) {
				$invoice->set('status', nc_payment_invoice::STATUS_CALLBACK_ERROR);
				$invoice->save();
			}

			if ($function == 'check') {
				$this->exitf('NO');
			}
			else {
				$this->exitf('ERR');
			}
		}
	}

	/**
	 * @param $callback_params array
	 * @param $invoice         nc_payment_invoice
	 *
	 * @return string|false
	 */
	protected function callback_check($callback_params, $invoice) {
		$order_status      = $invoice->get('status');
		$rejected_statuses = array(
			nc_payment_invoice::STATUS_CANCELLED,
			nc_payment_invoice::STATUS_REJECTED,
			nc_payment_invoice::STATUS_SUCCESS,
		);

		if (in_array($order_status, $rejected_statuses)) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_STATUS, $order_status, nc_payment_invoice::STATUS_WAITING);

			return $error;
		}
		$total           = $invoice->get_amount("%0.2F");
		$isCorrectAmount = ($callback_params['MERCH_TYPE'] == 0 && $total == $callback_params['AMOUNT']) ||
		                   ($callback_params['MERCH_TYPE'] == 1 && $total >= $callback_params['AMOUNT'] && $total == $callback_params['AMOUNT_FULL']);

		if (!$isCorrectAmount) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_AMOUNT, $total, $callback_params['AMOUNT']);

			return $error;
		}

		return false;
	}

	/**
	 * @param $callback_params array
	 * @param $invoice         nc_payment_invoice
	 *
	 * @return string|false
	 */
	protected function callback_hold($callback_params, $invoice) {
		$order_status      = $invoice->get('status');
		$rejected_statuses = array(
			nc_payment_invoice::STATUS_CANCELLED,
			nc_payment_invoice::STATUS_REJECTED,
			nc_payment_invoice::STATUS_SUCCESS,
		);

		if (in_array($order_status, $rejected_statuses)) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_STATUS, $order_status, nc_payment_invoice::STATUS_WAITING);

			return $error;
		}
		$total           = $invoice->get_amount("%0.2F");
		$isCorrectAmount = ($callback_params['MERCH_TYPE'] == 0 && $total == $callback_params['AMOUNT']) ||
		                   ($callback_params['MERCH_TYPE'] == 1 && $total >= $callback_params['AMOUNT'] && $total == $callback_params['AMOUNT_FULL']);

		if (!$isCorrectAmount) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_AMOUNT, $total, $callback_params['AMOUNT']);

			return $error;
		}

		return false;
	}

	/**
	 * @param $callback_params array
	 * @param $invoice         nc_payment_invoice
	 *
	 * @return string|false
	 */
	protected function callback_cancel($callback_params, $invoice) {
		$order_status      = $invoice->get('status');
		$rejected_statuses = array(
			nc_payment_invoice::STATUS_CANCELLED,
			nc_payment_invoice::STATUS_REJECTED,
			nc_payment_invoice::STATUS_SUCCESS,
		);

		if (in_array($order_status, $rejected_statuses)) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_STATUS, $order_status, nc_payment_invoice::STATUS_REJECTED);

			return $error;
		}

		return false;
	}

	/**
	 * @param $callback_params array
	 * @param $invoice         nc_payment_invoice
	 *
	 * @return string|false
	 */
	protected function callback_payment($callback_params, $invoice) {
		$order_status      = $invoice->get('status');
		$rejected_statuses = array(
			nc_payment_invoice::STATUS_CANCELLED,
			nc_payment_invoice::STATUS_REJECTED,
			nc_payment_invoice::STATUS_SUCCESS,
		);

		if (in_array($order_status, $rejected_statuses)) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_STATUS, $order_status, nc_payment_invoice::STATUS_SUCCESS);

			return $error;
		}
		$total           = $invoice->get_amount("%0.2F");
		$isCorrectAmount = ($callback_params['MERCH_TYPE'] == 0 && $total == $callback_params['AMOUNT']) ||
		                   ($callback_params['MERCH_TYPE'] == 1 && $total >= $callback_params['AMOUNT'] && $total == $callback_params['AMOUNT_FULL']);

		if (!$isCorrectAmount) {
			$error = sprintf(nc_payment_system_arsenalpay::ERROR_AMOUNT, $total, $callback_params['AMOUNT']);

			return $error;
		}

		return false;
	}

	/**
	 * @return nc_payment_invoice|boolean
	 */
	public function load_invoice_on_callback() {
		return $this->load_invoice($this->get_response_value('ACCOUNT'));
	}

	protected function check_params($callback_params) {
		$required_keys = array
		(
			'ID',           /* Merchant identifier */
			'FUNCTION',     /* Type of request to which the response is received*/
			'RRN',          /* Transaction identifier */
			'PAYER',        /* Payer(customer) identifier */
			'AMOUNT',       /* Payment amount */
			'ACCOUNT',      /* Order number */
			'STATUS',       /* When /check/ - response for the order number checking, when
									// payment/ - response for status change.*/
			'DATETIME',     /* Date and time in ISO-8601 format, urlencoded.*/
			'SIGN',         /* Response sign  = md5(md5(ID).md(FUNCTION).md5(RRN).md5(PAYER).md5(request amount).
									// md5(ACCOUNT).md(STATUS).md5(PASSWORD)) */
		);

		/**
		 * Checking the absence of each parameter in the post request.
		 */
		foreach ($required_keys as $key) {
			if (empty($callback_params[$key]) || !array_key_exists($key, $callback_params)) {
				$this->log('Error in callback parameters ERR' . $key);

				return false;
			}
			else {
				$this->log("    $key=$callback_params[$key]");

			}
		}

		if ($callback_params['FUNCTION'] != $callback_params['STATUS']) {
			$this->log("Error: FUNCTION ({$callback_params['FUNCTION']} not equal STATUS ({$callback_params['STATUS']})");

			return false;
		}

		return true;
	}

	protected function check_sign($ars_callback, $pass) {
		$validSign = ($ars_callback['SIGN'] === md5(md5($ars_callback['ID']) .
		                                            md5($ars_callback['FUNCTION']) . md5($ars_callback['RRN']) .
		                                            md5($ars_callback['PAYER']) . md5($ars_callback['AMOUNT']) . md5($ars_callback['ACCOUNT']) .
		                                            md5($ars_callback['STATUS']) . md5($pass))) ? true : false;

		return $validSign;
	}

	protected function add_error($string) {
		$this->log($string);
		parent::add_error($string);
	}

	protected function log($str) {
		$fp = fopen(realpath(dirname(__FILE__)) . "/arsenalpay/callback.log", "a+");
		$dt = date('c');
		fwrite($fp, $dt . ' : ' . $str . "\r\n");
		fclose($fp);
	}

	protected function exitf($response) {
		$this->log("Response: " . $response);
		echo $response;
	}
}

