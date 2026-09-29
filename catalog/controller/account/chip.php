<?php
namespace Opencart\Catalog\Controller\Extension\Chip\Account;
// Version reported to the gateway. Keep in step with install.json.
if (!defined('CHIP_OPENCART_VERSION')) {
	define('CHIP_OPENCART_VERSION', '1.3.0');
}

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

		$customer_id = (int)$this->customer->getId();

		$results = $this->model_extension_chip_payment_chip->getTokens($customer_id);

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

		/*
		 * Suspended plans, so the buyer can actually recover them.
		 *
		 * A suspended row is never billed again and the cron will not touch it,
		 * so without this list the only thing telling the customer their plan
		 * died is an order-history comment - with nothing to click. Both the
		 * saved-card list and the new-card action are offered here because
		 * either can be the one that works: the saved card may simply have been
		 * replaced, or the plan may need a card that has not been declined.
		 */
		$data['recoverable'] = [];

		foreach ($this->model_extension_chip_payment_chip->getRecoverableSubscriptions($customer_id) as $sub) {
			$amount = $this->model_extension_chip_payment_chip->currentCycleAmount($sub);

			$data['recoverable'][] = [
				'chip_subscription_id' => (int)$sub['chip_subscription_id'],
				'product_name'         => $sub['product_name'],
				'amount'               => $this->currency->format($amount['price'] / 100, 'MYR'),
				'paused_since'         => ($sub['date_modified'] === '0000-00-00 00:00:00') ? '' : date('d/m/Y', strtotime($sub['date_modified'])),
				/*
				 * Deliberately no per-row "reason".
				 *
				 * The row does not record why it suspended: `retry_count` is
				 * incremented both by a declined card walking the ladder and by
				 * a dead token, so the column cannot tell them apart and any
				 * label derived from it would be a guess shown to the buyer as
				 * fact. The shared intro states what is certainly true - the
				 * card must be replaced - and the buyer's own card list tells
				 * them which one is dead.
				 */
				'pay_new_card'         => $this->url->link('extension/chip/account/chip.recover_with_new_card', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'] . '&chip_subscription_id=' . $sub['chip_subscription_id'])
			];
		}

		$data['button_delete'] = $this->language->get('button_delete');
		$data['button_add'] = $this->language->get('button_add');
		$data['text_confirm'] = $this->language->get('text_confirm');
		$data['text_no_results'] = $this->language->get('text_no_results');
		$data['column_credit_card'] = $this->language->get('column_credit_card');
		$data['column_date_expire'] = $this->language->get('column_date_expire');
		$data['column_action'] = $this->language->get('column_action');
		$data['add_card_url'] = $this->url->link('extension/chip/account/chip.create_payment_method', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token']);

		$data['heading_recovery'] = $this->language->get('heading_recovery');
		$data['text_recovery_intro'] = $this->language->get('text_recovery_intro');
		$data['text_recovery_amount'] = $this->language->get('text_recovery_amount');
		$data['text_recovery_due'] = $this->language->get('text_recovery_due');
		$data['text_recovery_reason'] = $this->language->get('text_recovery_reason');
		$data['text_pay_saved_card'] = $this->language->get('text_pay_saved_card');
		$data['text_pay_new_card'] = $this->language->get('text_pay_new_card');
		$data['text_choose_card'] = $this->language->get('text_choose_card');
		$data['text_recovered'] = $this->language->get('text_recovered');
		$data['recover_url'] = $this->url->link('extension/chip/account/chip.recover_with_stored_card', 'language=' . $this->config->get('config_language') . '&customer_token=' . $this->session->data['customer_token'], true);

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
	 * Recover a suspended subscription by paying with a NEW card.
	 *
	 * Mirrors the add-card flow, but the purchase carries `reference` = the
	 * SUBSCRIPTION id, so the paid callback can find the plan. The add-card
	 * flow cannot do this: it sends the customer id as the reference, so
	 * `attachSubscriptionToken()` (which looks rows up by order id) never sees
	 * the subscription - the new card lands in the wallet and the plan stays
	 * suspended forever.
	 *
	 * @return void
	 */
	public function recover_with_new_card(): void {
		$this->load->language('extension/chip/account/chip');
		$this->load->model('extension/chip/payment/chip');
		$this->load->model('account/customer');

		$chip_subscription_id = isset($this->request->get['chip_subscription_id'])
			? (int)$this->request->get['chip_subscription_id'] : 0;

		$subscription = $this->model_extension_chip_payment_chip->getSubscriptionForCustomer(
			$chip_subscription_id, (int)$this->customer->getId());

		if (!$subscription || $subscription['status'] !== 'suspended') {
			$this->response->redirect($this->recoverUrl('error_subscription'));

			return;
		}

		$customer = $this->model_account_customer->getCustomer($this->customer->getId());

		$amount = $this->model_extension_chip_payment_chip->currentCycleAmount($subscription);

		$params = [
			'success_callback' => $this->url->link('extension/chip/payment/chip|success_callback'),
			/*
			 * Back to the card page, not `success_redirect`.
			 *
			 * `success_redirect()` requires an `oc_chip_report` row for the
			 * order, and this recovery purchase has none (no checkout wrote
			 * one) - so it would exit with 'invalid_redirect' and the buyer
			 * would be stranded on a blank page after paying. The re-arm itself
			 * happens on the signed callback, not here.
			 */
			'success_redirect' => $this->recoverUrl(),
			'failure_redirect' => $this->recoverUrl(),
			'cancel_redirect'  => $this->recoverUrl(),
			'creator_agent'    => 'OC40: ' . CHIP_OPENCART_VERSION,
			/*
			 * The ORDER id, not the subscription id.
			 *
			 * `success_callback()` resolves the reference with
			 * `getOrder($purchase['reference'])` and `attachSubscriptionToken()`
			 * then looks the plan up with `getSubscriptionsByOrderId()` - so the
			 * reference must be the order. Sending the subscription id here
			 * would make the callback fetch a non-existent order and the plan
			 * would stay suspended. This is the one route already proven to
			 * re-arm a suspended row.
			 */
			'reference'        => (int)$subscription['order_id'],
			'platform'         => 'opencart',
			'brand_id'         => $this->config->get('payment_chip_brand_id'),
			'client'           => [
				'email'     => $customer['email'] ?? $subscription['customer_email'],
				'full_name' => trim(($customer['firstname'] ?? '') . ' ' . ($customer['lastname'] ?? ''))
			],
			'purchase'         => [
				'timezone'   => $this->config->get('payment_chip_time_zone'),
				'currency'   => 'MYR',
				'products'   => [[
					'name'     => substr((string)$subscription['product_name'], 0, 256),
					'quantity' => max(1, (int)$subscription['product_quantity']),
					'price'    => $amount['price']
				]]
			],
			'force_recurring'  => true
		];

		$this->model_extension_chip_payment_chip->set_keys($this->config->get('payment_chip_secret_key'), '');

		$purchase = $this->model_extension_chip_payment_chip->create_purchase($params);

		if (!is_array($purchase) || !array_key_exists('id', $purchase)) {
			$this->response->redirect($this->recoverUrl('error_purchase'));

			return;
		}

		$this->session->data['chip_recover_subscription_id'] = $chip_subscription_id;

		$this->response->redirect($purchase['checkout_url']);
	}

	/**
	 * Recover a suspended subscription by charging a STORED card.
	 *
	 * The buyer picks a card they already tokenised, so no gateway redirect is
	 * needed: the stored token is charged directly and, only on success, the
	 * plan is switched over to it and re-armed. A failed charge changes
	 * nothing - the plan stays suspended on its previous card.
	 *
	 * @return void
	 */
	public function recover_with_stored_card(): void {
		$this->load->language('extension/chip/account/chip');
		$this->load->model('extension/chip/payment/chip');

		$json = [];

		if (!$this->customer->isLogged()) {
			$json['error'] = $this->language->get('error_logged');
		}

		$chip_subscription_id = isset($this->request->post['chip_subscription_id'])
			? (int)$this->request->post['chip_subscription_id'] : 0;
		$chip_token_id = isset($this->request->post['chip_token_id'])
			? (int)$this->request->post['chip_token_id'] : 0;

		$customer_id = (int)$this->customer->getId();

		$subscription = $this->model_extension_chip_payment_chip->getSubscriptionForCustomer(
			$chip_subscription_id, $customer_id);

		if (!$subscription || $subscription['status'] !== 'suspended') {
			$json['error'] = $this->language->get('error_subscription');
		}

		$token = $this->model_extension_chip_payment_chip->getToken($customer_id, $chip_token_id);

		if (!$token) {
			$json['error'] = $this->language->get('error_token');
		}

		if ($json) {
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));

			return;
		}

		$amount = $this->model_extension_chip_payment_chip->currentCycleAmount($subscription);

		$params = [
			'reference'      => (int)$subscription['order_id'],
			'platform'       => 'opencart',
			'creator_agent'  => 'OC40: ' . CHIP_OPENCART_VERSION,
			'brand_id'       => $this->config->get('payment_chip_brand_id'),
			'client'         => ['email' => $subscription['customer_email']],
			'purchase'       => [
				'timezone' => $this->config->get('payment_chip_time_zone'),
				'currency' => 'MYR',
				'products' => [[
					'name'     => substr((string)$subscription['product_name'], 0, 256),
					'quantity' => max(1, (int)$subscription['product_quantity']),
					'price'    => $amount['price']
				]]
			]
		];

		$this->model_extension_chip_payment_chip->set_keys($this->config->get('payment_chip_secret_key'), '');

		$purchase = $this->model_extension_chip_payment_chip->create_purchase($params);

		if (!is_array($purchase) || !array_key_exists('id', $purchase)) {
			$json['error'] = $this->language->get('error_purchase');
			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));

			return;
		}

		$charge = $this->model_extension_chip_payment_chip->chargeRecurring(
			(string)$purchase['id'], (string)$token['token_id']);

		/*
		 * Only a settled, successful charge may change the plan.
		 *
		 * `pending_charge` means the acquirer has not finalised - the module
		 * treats that as unresolved elsewhere rather than a decline, and
		 * flipping the plan to a token that may still fail would leave it
		 * active on a card that never paid.
		 */
		$status = is_array($charge) && isset($charge['status']) ? (string)$charge['status'] : '';

		if ($status !== 'paid') {
			$json['error'] = $this->language->get('error_charge_failed');

			$this->response->addHeader('Content-Type: application/json');
			$this->response->setOutput(json_encode($json));

			return;
		}

		$rearm = $this->model_extension_chip_payment_chip->rearmSubscription($subscription);

		$this->model_extension_chip_payment_chip->activateSubscription(
			$chip_subscription_id,
			(string)$token['token_id'],
			(int)$token['chip_token_id'],
			$rearm
		);

		$json['success'] = $this->language->get('text_recovered');

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	/**
	 * Build a recovery redirect back to the card page.
	 *
	 * @param string $error Optional language key to surface.
	 *
	 * @return string
	 */
	private function recoverUrl(string $error = ''): string {
		$url = 'extension/chip/account/chip&language=' . $this->config->get('config_language')
			. '&customer_token=' . $this->session->data['customer_token'];

		if ($error !== '') {
			$url .= '&error=' . urlencode($this->language->get($error));
		}

		return $this->url->link($url, '', true);
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
			'creator_agent'    => 'OC41: ' . CHIP_OPENCART_VERSION,
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
		if ( !is_array($purchase) || !array_key_exists('id', $purchase) ) {
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

		if ( !is_array($purchase) || !array_key_exists('id', $purchase) ) {
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

