<?php
defined('BASEPATH') or exit('No direct script access allowed');

class easypaisa extends MX_Controller
{
	public $tb_users;
	public $tb_transaction_logs;
	public $tb_payments;
	public $tb_payments_bonuses;
	public $paypal;
	public $payment_type;
	public $payment_id;
	public $currency_code;
	public $payment_lib;
	public $mode;

	public $paytm_mid;
	public $merchant_key;
	public $currency_rate_to_usd;

	public function __construct($payment = "")
	{
		parent::__construct();
		$this->load->model('add_funds_model', 'model');

		$this->tb_users            = USERS;
		$this->tb_transaction_logs = TRANSACTION_LOGS;
		$this->tb_payments         = PAYMENTS_METHOD;
		$this->tb_payments_bonuses = PAYMENTS_BONUSES;
		$this->payment_type		   = get_class($this);
		$this->currency_code       = get_option("currency_code", "USD");
		if ($this->currency_code == "") {
			$this->currency_code = 'USD';
		}
		if (!$payment) {
			$payment = $this->model->get('id, type, name, params', $this->tb_payments, ['type' => $this->payment_type]);
		}
		$this->payment_id 	      = $payment->id;
		$params  			      = $payment->params;
		$option                   = get_value($params, 'option');
		$this->mode               = get_value($option, 'environment');

		// Payment Option
		$this->easypaisa_mid        = get_value($option, 'easypaisa_mid');
		$this->currency_rate_to_usd     = get_value($option, 'rate_to_usd');
		$this->load->helper("paytm");
		// $this->payment_lib = new paytmapi($this->merchant_key, $this->paytm_mid, $this->mode, get_option('website_name'));
	}

	public function index()
	{
		redirect(cn('add_funds'));
	}

	/**
	 *
	 * Create payment
	 *
	 */
	public function create_payment($data_payment = "")
	{
		_is_ajax($data_payment['module']);
		$amount = $data_payment['amount'];
		if (!$amount) {
			_validation('error', lang('There_was_an_error_processing_your_request_Please_try_again_later'));
		}

		$ORDER_ID = session('qrtransaction_id');
		$TXN_AMOUNT = $amount;

		$data = array(
			"uid" => session('uid'),
		);


		$check_transactionsqr = get_field(TRANSACTION_LOGS, ["transaction_id" => $ORDER_ID], 'id');

		if (empty($check_transactionsqr)) {
			$converted_amount = $amount / $this->currency_rate_to_usd;
			$data_tnx_log = array(
				"ids" 				=> ids(),
				"uid" 				=> session("uid"),
				"type" 				=> $this->payment_type,
				"transaction_id" 	=> $ORDER_ID,
				"amount" 	        => round($converted_amount, 4),
				'txn_fee'           => round($converted_amount * ($this->payment_fee / 100), 4),
				"note" 	            => $TXN_AMOUNT,
				"status" 	        => 0,
				"created" 			=> NOW,
			);
			$transaction_log_id = $this->db->insert($this->tb_transaction_logs, $data_tnx_log);
			$this->load->view("easypaisa/redirect", $data);
		} else {
			ms(array(
				"status"  => "error",
				"message" => lang("transaction_id_already_used"),
			));
		}
	}

	public function complete()
	{
		$requestParamList = array("MID" => $this->easypaisa_mid, "ORDERID" => session('qrtransaction_id'));

		$responseParamList = array();

		$responseParamList = getTxnStatusNew($requestParamList);

		// if ($this->easypaisa_mid != $responseParamList["MID"]) {
		// 	redirect(cn("add_funds/unsuccess"));
		// }

		$tnx_id = $responseParamList["ORDERID"];
		$transaction = $this->model->get('*', $this->tb_transaction_logs, ['transaction_id' => $tnx_id, 'status' => 0, 'type' => $this->payment_type]);
		if (empty($transaction)) {
			// redirect(cn("add_funds/unsuccess"));
			echo "wrong txn id";
		}

		set_session("uid", $transaction->uid);

		if ($responseParamList["STATUS"] == "TXN_SUCCESS"  && $transaction && $responseParamList["TXNAMOUNT"] == $transaction->note) {
			$this->db->update($this->tb_transaction_logs, ['status' => 1, 'transaction_id' => $responseParamList["ORDERID"]],  ['id' => $transaction->id]);

			// Update Balance 
			require_once 'add_funds.php';
			$add_funds = new add_funds();
			$add_funds->add_funds_bonus_email($transaction, $this->payment_id);

			set_session("transaction_id", $transaction->id);
			redirect(cn("add_funds/success"));
		} else {
			echo "else";
			$this->db->update($this->tb_transaction_logs, ['status' => -1, 'transaction_id' => $responseParamList["ORDERID"]],  ['id' => $transaction->id]);
			redirect(cn("add_funds/unsuccess"));
		}
	}
}
