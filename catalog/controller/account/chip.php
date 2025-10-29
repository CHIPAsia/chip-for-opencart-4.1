<?php
namespace Opencart\Catalog\Controller\Extension\Chip\Account;

class Chip extends \Opencart\System\Engine\Controller {
	public function index(): string {
		$this->load->language('extension/chip/account/chip');

		if (!$this->customer->isLogged() || (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token']))) {
			$this->session->data['redirect'] = $this->url->link('account/payment_method', 'language=' . $this->config->get('config_language'));

			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language'), true));
		}

		$data['list'] = $this->getList();

		$data['types'] = [];

		foreach (['visa', 'mastercard', 'amex', 'discover', 'jcb', 'maestro'] as $type) {
			$data['types'][] = [
				'text'  => $this->language->get('text_' . $type),
				'value' => $type
			];
		}

		$data['months'] = [];

		foreach (range(1, 12) as $month) {
			$data['months'][] = date('m', mktime(0, 0, 0, $month, 1));
		}

		$data['years'] = [];

		foreach (range(date('Y'), date('Y', strtotime('+10 year'))) as $year) {
			$data['years'][] = $year;
		}

		$data['language'] = $this->config->get('config_language');

		$data['customer_token'] = $this->session->data['customer_token'];

		$data['heading_title'] = $this->language->get('heading_title');
		$data['text_description'] = $this->language->get('text_description');
		$data['entry_card_name'] = $this->language->get('entry_card_name');
		$data['entry_card_type'] = $this->language->get('entry_card_type');
		$data['entry_card_number'] = $this->language->get('entry_card_number');
		$data['entry_card_expire'] = $this->language->get('entry_card_expire');
		$data['entry_card_cvv'] = $this->language->get('entry_card_cvv');
		$data['text_select'] = $this->language->get('text_select');
		$data['text_month'] = $this->language->get('text_month');
		$data['text_year'] = $this->language->get('text_year');
		$data['text_confirm'] = $this->language->get('text_confirm');
		$data['button_save'] = $this->language->get('button_save');
		$data['button_delete'] = $this->language->get('button_delete');
		$data['button_add'] = $this->language->get('button_add');
		$data['text_credit_card_add'] = $this->language->get('text_credit_card_add');
		$data['column_credit_card'] = $this->language->get('column_credit_card');
		$data['column_date_expire'] = $this->language->get('column_date_expire');
		$data['column_action'] = $this->language->get('column_action');
		$data['text_no_results'] = $this->language->get('text_no_results');

		return $this->load->view('extension/chip/account/chip', $data);
	}

	/**
	 * List
	 *
	 * @return void
	 */
	public function list(): void {
		$this->load->language('extension/chip/account/chip');

		if (!$this->customer->isLogged() || (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token']))) {
			$this->session->data['redirect'] = $this->url->link('account/payment_method', 'language=' . $this->config->get('config_language'));

			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language'), true));
		}

		$this->response->setOutput($this->getList());
	}

	/**
	 * Get List
	 *
	 * @return string
	 */
	protected function getList(): string {
		$data['credit_cards'] = [];

		$this->load->model('extension/chip/payment/chip');

		$results = $this->model_extension_chip_payment_chip->getTokens($this->customer->getId());

		foreach ($results as $result) {
			$data['credit_cards'][] = [
				'chip_token_id' => $result['chip_token_id'],
                //TODO: Add image
				'image'       => HTTP_SERVER . 'extension/chip/image/' . $result['type'] . '.svg',
				'card_type'   => $this->language->get('text_' . strtolower($result['type'])),
				'type'        => strtolower($result['type']),
				'card_number' => $result['card_number'],
				'date_expire' => $result['card_expire_month'] . '/' . $result['card_expire_year'],
				'delete'      => $this->url->link('extension/chip/account/chip.delete', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'] . '&chip_token_id=' . $result['chip_token_id'])
			] + $result;
		}

		$data['button_delete'] = $this->language->get('button_delete');
		$data['button_add'] = $this->language->get('button_add');
		$data['text_confirm'] = $this->language->get('text_confirm');
		$data['text_no_results'] = $this->language->get('text_no_results');
		$data['column_credit_card'] = $this->language->get('column_credit_card');
		$data['column_date_expire'] = $this->language->get('column_date_expire');
		$data['column_action'] = $this->language->get('column_action');
		$data['add_card_url'] = $this->url->link('extension/chip/account/chip.create_payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token']);

		return $this->load->view('extension/chip/account/chip_list', $data);
	}

	public function save(): void {
		$this->load->language('extension/chip/account/chip');

		$json = [];

		if (!$this->customer->isLogged() || (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token']))) {
			$this->session->data['redirect'] = $this->url->link('account/payment_method', 'language=' . $this->config->get('config_language'));

			$json['redirect'] = $this->url->link('account/login', 'language=' . $this->config->get('config_language'), true);
		}

		// Chip tokens are automatically saved during payment processing
		// This method is kept for compatibility
		$json['success'] = $this->language->get('text_success');

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Delete Credit Card
	 */
	public function delete(): void {
		$this->load->language('extension/chip/account/chip');

		$json = [];

		if (isset($this->request->get['chip_token_id'])) {
			$chip_token_id = (int)$this->request->get['chip_token_id'];
		} else {
			$chip_token_id = 0;
		}

		if (!$this->customer->isLogged()) {
			$json['error'] = $this->language->get('error_logged');
		}

		$this->load->model('extension/chip/payment/chip');

		$token_info = $this->model_extension_chip_payment_chip->getToken($this->customer->getId(), $chip_token_id);

		if (!$token_info) {
			$json['error'] = $this->language->get('error_token');
		}

		if (!$json) {
			$this->model_extension_chip_payment_chip->deleteToken($this->customer->getId(), $chip_token_id);

			$json['success'] = $this->language->get('text_delete');

			// Clear payment and shipping methods
			unset($this->session->data['shipping_method']);
			unset($this->session->data['shipping_methods']);
			unset($this->session->data['payment_method']);
			unset($this->session->data['payment_methods']);
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Create purchase for adding payment method
	 */
	public function create_payment_method(): void {
		$this->load->language('extension/chip/account/chip');

		if (!$this->customer->isLogged() || (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token']))) {
			$this->session->data['redirect'] = $this->url->link('account/payment_method', 'language=' . $this->config->get('config_language'));
			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language'), true));
		}

		// Load customer model
		$this->load->model('account/customer');

		// Get customer information
		$customer_id = $this->customer->getId();
		$customer_info = $this->model_account_customer->getCustomer($customer_id);

		// Build full name
		$customer_full_name = trim(($customer_info['firstname'] ?? '') . ' ' . ($customer_info['lastname'] ?? ''));
		
		// Get customer email
		$customer_email = $customer_info['email'] ?? '';

		// Build URLs
		$success_redirect_url = $this->url->link('extension/chip/account/chip.success_add_card', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true);

		// Prepare purchase parameters
		$params = array(
			'success_redirect' => $success_redirect_url,
			'creator_agent'    => 'OC41: 1.0.0',
			'reference'        => $customer_id,
			'platform'         => 'opencart',
			'brand_id'         => $this->config->get('payment_chip_brand_id'),
			'client'           => array(
				'full_name' => $customer_full_name,
				'email'     => $customer_email,
			),
			'purchase'         => array(
				'timezone'       => $this->config->get('payment_chip_time_zone'),
				'currency'       => 'MYR',
				'due_strict'     => $this->config->get('payment_chip_due_strict'),
				'products'       => array(array('name' => 'Add payment method', 'quantity' => 1, 'price' => 0)),
			),
			'skip_capture'     => true,
			'force_recurring'  => true,
		);

		// Initialize model with keys
		$this->load->model('extension/chip/payment/chip');
		$this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), 'brand-id');

		// Create purchase
		$purchase = $this->model_extension_chip_payment_chip->createPurchase($params);

		// Check if purchase creation was successful
		if (!array_key_exists('id', $purchase)) {
			$this->response->redirect($this->url->link('account/payment_method', 'language=' . $this->config->get('config_language') . '&error=' . urlencode('Failed to create payment method')));
			return;
		}

		// Store purchase ID in session
		$this->session->data['chip_add_card_purchase_id'] = $purchase['id'];

		// Redirect to checkout URL
		$this->response->redirect($purchase['checkout_url']);
	}

	/**
	 * Success redirect for adding payment method
	 */
	public function success_add_card(): void {
		if (!isset($this->request->get['customer_token']) || !isset($this->session->data['customer_token']) || ($this->request->get['customer_token'] != $this->session->data['customer_token'])) {
			$this->response->redirect($this->url->link('account/login', 'language=' . $this->config->get('config_language'), true));
			return;
		}

		// Get purchase ID from session
		if (!isset($this->session->data['chip_add_card_purchase_id'])) {
			$this->response->redirect($this->url->link('account/payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true));
			return;
		}

		$purchase_id = $this->session->data['chip_add_card_purchase_id'];

		// Load model and get purchase details from CHIP API
		$this->load->model('extension/chip/payment/chip');
		$this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), '');
		$purchase = $this->model_extension_chip_payment_chip->getPurchase($purchase_id);

		if (!array_key_exists('id', $purchase)) {
			if ($this->config->get('payment_chip_debug')) {
				$this->log->write('CHIP API /purchase/' . $purchase_id . '/ failed in add card flow. Response Body: ' . json_encode($purchase));
			}
			// Clear session and redirect
			unset($this->session->data['chip_add_card_purchase_id']);
			$this->response->redirect($this->url->link('account/payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true));
			return;
		}

		// Check if purchase is preauthorized (expected status for add card flow)
		if ($purchase['status'] != 'preauthorized') {
			// Clear session and redirect
			unset($this->session->data['chip_add_card_purchase_id']);
			$this->response->redirect($this->url->link('account/payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true));
			return;
		}

		$this->db->query("SELECT GET_LOCK('payment_chip_add_card_$purchase_id', 15);");

		// Get customer ID from reference (we used customer_id as reference)
		$customer_id = $purchase['reference'];

		// Save token if available
		if (isset($purchase['is_recurring_token']) && $purchase['is_recurring_token'] === true) {
			$this->saveToken($purchase, $customer_id);
		}

		$this->db->query("SELECT RELEASE_LOCK('payment_chip_add_card_$purchase_id');");

		// Clear session
		unset($this->session->data['chip_add_card_purchase_id']);

		// Redirect to payment method page
		$redirect_url = $this->url->link('account/payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true);
		$this->response->redirect($redirect_url);
	}

	/**
	 * Save token from purchase
	 */
	private function saveToken(array $purchase, int $customer_id): void {
		if (!isset($purchase['transaction_data']['extra'])) {
			return;
		}

		$extra = $purchase['transaction_data']['extra'];

		// Check if required fields exist
		if (!isset($extra['card_type']) || !isset($extra['masked_pan']) || 
			!isset($extra['expiry_month']) || !isset($extra['expiry_year'])) {
			return;
		}

		$token_data = array(
			'customer_id' => $customer_id,
			'token_id' => $purchase['id'],
			'type' => $extra['card_brand'],
			'card_name' => isset($extra['cardholder_name']) ? $extra['cardholder_name'] : '',
			'card_number' => $extra['masked_pan'],
			'card_expire_month' => $extra['expiry_month'],
			'card_expire_year' => $extra['expiry_year']
		);

		$this->model_extension_chip_payment_chip->addToken($token_data);
	}
}

