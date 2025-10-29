<?php
namespace Opencart\Catalog\Controller\Extension\Chip\Payment;
class Chip extends \Opencart\System\Engine\Controller
{
  public function index(): string {
    $this->load->language('extension/chip/payment/chip');

    $data['payment_chip_allow_instruction'] = $this->config->get('payment_chip_allow_instruction');
    $data['payment_chip_instruction'] = nl2br($this->config->get('payment_chip_instruction_' . $this->config->get('config_language_id')));


    if (isset($this->session->data['payment_method'])) {
			// $data['logged'] = $this->customer->isLogged();
      // $data['subscription] = $this->cart->hasSubscription();

			$data['types'] = [];

			foreach (['visa', 'mastercard'] as $type) {
				$data['types'][] = [
					'text'  => $this->language->get('text_' . $type),
					'value' => $type
				];
			}

			// Card storage
			if ($this->session->data['payment_method']['code'] == 'chip.chip') {
				return $this->load->view('extension/chip/payment/chip', $data);
			} else {
				$data['text_title'] = $this->session->data['payment_method']['name'];

				return $this->load->view('extension/chip/payment/stored', $data);
			}
		}

    return '';
  }

  public function create_purchase() {
    $this->load->language('extension/chip/payment/chip');

    $json = [];

    if (!isset($this->session->data['order_id'])) {
      $json['error'] = $this->language->get('error_order_id');
      $this->response->addHeader('Content-Type: application/json');
		  $this->response->setOutput(json_encode($json));
      return;
    }

    if (!isset($this->session->data['payment_method']) || $this->session->data['payment_method']['code'] != 'chip.chip') {
      $json['error'] = $this->language->get('error_payment_method');
      $this->response->addHeader('Content-Type: application/json');
		  $this->response->setOutput(json_encode($json));
      return;
    }

    $this->load->model('extension/chip/payment/chip');
    $this->load->model('checkout/order');

    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    $products = $this->model_checkout_order->getProducts($this->session->data['order_id']);

    /* Reject if MYR currency is not set up */

    if (!$this->currency->has('MYR')){
      $json['error'] = $this->language->get('pending_myr_setup');
      $this->response->addHeader('Content-Type: application/json');
		  $this->response->setOutput(json_encode($json));
      return;
    }

    $total_override = $order_info['total'];
    
    if ($this->config->get('payment_chip_convert_to_processing') == 0 AND $this->config->get('config_currency') != 'MYR') {
      $json['error'] = $this->language->get('convert_to_processing_disabled');
      $this->response->addHeader('Content-Type: application/json');
		  $this->response->setOutput(json_encode($json));
      return;
    }

    if ($this->config->get('config_currency') != 'MYR') {
      $total_override = $this->currency->convert($order_info['total'], $this->config->get('config_currency'), 'MYR');
    }

    $success_callback_url = $this->url->link('extension/chip/payment/chip.success_callback', 'order_id=' . $order_info['order_id'], true);
    $success_redirect_url = $this->url->link('extension/chip/payment/chip.success_redirect', 'order_id=' . $order_info['order_id'], true);

    $params = array(
      'success_callback' => $success_callback_url,
      'success_redirect' => $success_redirect_url,
      'failure_redirect' => $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')),
      'cancel_redirect'  => $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')),
      'creator_agent'    => 'OC41: 1.0.0',
      'reference'        => $this->session->data['order_id'],
      'platform'         => 'opencart',
      'due'              => time() + (abs( (int) $this->config->get('payment_chip_due_strict_timing') ) * 60),
      'brand_id'         => $this->config->get('payment_chip_brand_id'),
      'client'           => [],
      'purchase'         => array(
        'total_override' => round($total_override * 100),
        'timezone'       => $this->config->get('payment_chip_time_zone'),
        'currency'       => 'MYR',
        'due_strict'     => $this->config->get('payment_chip_due_strict'),
        'products'       => array(),
      ),
    );

    if ($this->config->get('payment_chip_disable_success_redirect')) {
      unset($params['success_redirect']);
    }

    if ($this->config->get('payment_chip_disable_success_callback')) {
      unset($params['success_callback']);
    }

    if ($this->config->get('payment_chip_canceled_behavior') == 'cancel_order') {
      $cancel_redirect_url = $this->url->link('extension/chip/payment/chip.cancel_redirect', 'order_id=' . $order_info['order_id'], true);
      $params['cancel_redirect'] = $cancel_redirect_url;
    }

    if ($this->config->get('payment_chip_failed_behavior') == 'fail_order') {
      $failure_redirect_url = $this->url->link('extension/chip/payment/chip.failure_redirect', 'order_id=' . $order_info['order_id'], true);
      $params['failure_redirect'] = $failure_redirect_url;
    }

    $payment_method_whitelist = $this->config->get('payment_chip_payment_method_whitelist');
    if (!empty($payment_method_whitelist)) {
      $params['payment_method_whitelist'] = $payment_method_whitelist;
    }

    foreach ($products as $product) {
      $product_price = $this->currency->convert($product['price'], $this->config->get('config_currency'), 'MYR');

      $params['purchase']['products'][] = array(
        'name' => substr($product['name'], 0, 256),
        'quantity' => $product['quantity'],
        'price' => round($product_price * 100),
        'category' => $product['product_id']
      );
    }

    if (!empty($order_info['comment'])) {
      $params['purchase']['notes'] = substr($order_info['comment'], 0, 10000);
    }

    if (!empty($order_info['email'])) {
      $params['client']['email'] = $order_info['email'];
    }

    if (!empty($order_info['telephone'])) {
      $params['client']['phone'] = $order_info['telephone'];
    }

    $params_client_full_name = array();
    if ($order_info['payment_firstname']) {
      $params_client_full_name[] = $order_info['payment_firstname'];
    }

    if ($order_info['payment_lastname']) {
      $params_client_full_name[] = ' ' . $order_info['payment_lastname'];
    }

    if (!empty(trim(implode($params_client_full_name)))){
      $params['client']['full_name'] = substr(implode($params_client_full_name), 0, 30);
    }

    /* Start of payment information */

    $params_client_street_address = array();
    if (!empty($order_info['payment_address_1'])) {
      $params_client_street_address[] = $order_info['payment_address_1'];
    }

    if (!empty($order_info['payment_address_2'])) {
      $params_client_street_address[] = $order_info['payment_address_2'];
    }

    if (!empty($params_client_street_address)){
      $params['client']['street_address'] = substr(implode($params_client_street_address), 0, 128);
    }

    if (!empty($order_info['payment_postcode'])) {
      $params['client']['zip_code'] = substr($order_info['payment_postcode'], 0, 32);
    }

    if (!empty($order_info['payment_city'])) {
      $params['client']['city'] = substr($order_info['payment_city'], 0, 128);
    }

    if (!empty($order_info['payment_iso_code_2'])) {
      $params['client']['country'] = $order_info['payment_iso_code_2'];
    }

    /* End of payment information */
    /* Start of shipping information */

    $params_client_shipping_street_address = array();
    if (!empty($order_info['shipping_address_1'])) {
      $params_client_shipping_street_address[] = $order_info['shipping_address_1'];
    }

    if (!empty($order_info['shipping_address_2'])) {
      $params_client_shipping_street_address[] = ' ' . $order_info['shipping_address_2'];
    }

    if (!empty($params_client_shipping_street_address)) {
      $params['client']['shipping_street_address'] = substr(implode($params_client_shipping_street_address), 0, 128);
    }

    if (!empty($order_info['shipping_postcode'])) {
      $params['client']['shipping_zip_code'] = substr($order_info['shipping_postcode'], 0, 32);
    }

    if (!empty($order_info['shipping_city'])) {
      $params['client']['shipping_city'] = substr($order_info['shipping_city'], 0, 128);
    }

    if (!empty($order_info['shipping_iso_code_2'])) {
      $params['client']['shipping_country'] = $order_info['shipping_iso_code_2'];
    }

    /* End of shipping information */

    $this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), 'brand-id');

    $purchase = $this->model_extension_chip_payment_chip->createPurchase($params);

    if ( !array_key_exists('id', $purchase) ) {
      $json['error'] = print_r($purchase, true);

      if ($this->config->get('payment_chip_debug')) {
        $this->log->write('CHIP API /purchase/ failed for order #' . $this->session->data['order_id'] . '. Response Body: ' . json_encode($purchase));
      }

      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    // Save to chip_report table
    $customer_id = $order_info['customer_id'];
    $chip_id = $purchase['id'];
    $order_id = $order_info['order_id'];
    $status = isset($purchase['status']) ? $purchase['status'] : 'pending';
    $amount = $params['purchase']['total_override'] / 100;
    $environment_type = isset($purchase['is_test']) && $purchase['is_test'] ? 'staging' : 'production';

    $this->model_extension_chip_payment_chip->addReport(array(
      'customer_id' => $customer_id,
      'chip_id' => $chip_id,
      'order_id' => $order_id,
      'status' => $status,
      'amount' => $amount,
      'environment_type' => $environment_type
    ));

    $json['redirect'] = $purchase['checkout_url'];

    $this->response->addHeader('Content-Type: application/json');
    $this->response->setOutput(json_encode($json));
  }

  public function create_purchase_stored() {
    $this->load->language('extension/chip/payment/chip');

    $json = [];

    if (!isset($this->session->data['order_id'])) {
      $json['error'] = $this->language->get('error_order_id');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    if (!isset($this->session->data['payment_method']) || strstr($this->session->data['payment_method']['code'], '.', true) != 'chip') {
      $json['error'] = $this->language->get('error_payment_method');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    // Extract chip_token_id from payment method code (e.g., 'chip.123' -> '123')
    $payment_code = $this->session->data['payment_method']['code'];
    $chip_token_id = str_replace('chip.', '', $payment_code);

    if ($chip_token_id == 'chip' || empty($chip_token_id)) {
      $json['error'] = $this->language->get('error_payment_method');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    $this->load->model('extension/chip/payment/chip');
    $this->load->model('checkout/order');

    $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);
    $products = $this->model_checkout_order->getProducts($this->session->data['order_id']);

    /* Reject if MYR currency is not set up */

    if (!$this->currency->has('MYR')){
      $json['error'] = $this->language->get('pending_myr_setup');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    $total_override = $order_info['total'];
    
    if ($this->config->get('payment_chip_convert_to_processing') == 0 AND $this->config->get('config_currency') != 'MYR') {
      $json['error'] = $this->language->get('convert_to_processing_disabled');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    if ($this->config->get('config_currency') != 'MYR') {
      $total_override = $this->currency->convert($order_info['total'], $this->config->get('config_currency'), 'MYR');
    }

    $success_callback_url = $this->url->link('extension/chip/payment/chip.success_callback', 'order_id=' . $order_info['order_id'], true);
    $success_redirect_url = $this->url->link('extension/chip/payment/chip.success_redirect', 'order_id=' . $order_info['order_id'], true);

    $params = array(
      'success_callback' => $success_callback_url,
      'success_redirect' => $success_redirect_url,
      'failure_redirect' => $this->url->link('checkout/checkout', 'language=' . $this->config->get('config_language')),
      'cancel_redirect'  => $this->url->link('checkout/cart', 'language=' . $this->config->get('config_language')),
      'creator_agent'    => 'OC41: 1.0.0',
      'reference'        => $this->session->data['order_id'],
      'platform'         => 'opencart',
      'due'              => time() + (abs( (int) $this->config->get('payment_chip_due_strict_timing') ) * 60),
      'brand_id'         => $this->config->get('payment_chip_brand_id'),
      'client'           => [],
      'purchase'         => array(
        'total_override' => round($total_override * 100),
        'timezone'       => $this->config->get('payment_chip_time_zone'),
        'currency'       => 'MYR',
        'due_strict'     => $this->config->get('payment_chip_due_strict'),
        'products'       => array(),
      ),
    );

    if ($this->config->get('payment_chip_disable_success_redirect')) {
      unset($params['success_redirect']);
    }

    if ($this->config->get('payment_chip_disable_success_callback')) {
      unset($params['success_callback']);
    }

    if ($this->config->get('payment_chip_canceled_behavior') == 'cancel_order') {
      $cancel_redirect_url = $this->url->link('extension/chip/payment/chip.cancel_redirect', 'order_id=' . $order_info['order_id'], true);
      $params['cancel_redirect'] = $cancel_redirect_url;
    }

    if ($this->config->get('payment_chip_failed_behavior') == 'fail_order') {
      $failure_redirect_url = $this->url->link('extension/chip/payment/chip.failure_redirect', 'order_id=' . $order_info['order_id'], true);
      $params['failure_redirect'] = $failure_redirect_url;
    }

    $payment_method_whitelist = $this->config->get('payment_chip_payment_method_whitelist');
    if (!empty($payment_method_whitelist)) {
      $params['payment_method_whitelist'] = $payment_method_whitelist;
    }

    foreach ($products as $product) {
      $product_price = $this->currency->convert($product['price'], $this->config->get('config_currency'), 'MYR');

      $params['purchase']['products'][] = array(
        'name' => substr($product['name'], 0, 256),
        'quantity' => $product['quantity'],
        'price' => round($product_price * 100),
        'category' => $product['product_id']
      );
    }

    if (!empty($order_info['comment'])) {
      $params['purchase']['notes'] = substr($order_info['comment'], 0, 10000);
    }

    if (!empty($order_info['email'])) {
      $params['client']['email'] = $order_info['email'];
    }

    if (!empty($order_info['telephone'])) {
      $params['client']['phone'] = $order_info['telephone'];
    }

    $params_client_full_name = array();
    if ($order_info['payment_firstname']) {
      $params_client_full_name[] = $order_info['payment_firstname'];
    }

    if ($order_info['payment_lastname']) {
      $params_client_full_name[] = ' ' . $order_info['payment_lastname'];
    }

    if (!empty(trim(implode($params_client_full_name)))){
      $params['client']['full_name'] = substr(implode($params_client_full_name), 0, 30);
    }

    /* Start of payment information */

    $params_client_street_address = array();
    if (!empty($order_info['payment_address_1'])) {
      $params_client_street_address[] = $order_info['payment_address_1'];
    }

    if (!empty($order_info['payment_address_2'])) {
      $params_client_street_address[] = $order_info['payment_address_2'];
    }

    if (!empty($params_client_street_address)){
      $params['client']['street_address'] = substr(implode($params_client_street_address), 0, 128);
    }

    if (!empty($order_info['payment_postcode'])) {
      $params['client']['zip_code'] = substr($order_info['payment_postcode'], 0, 32);
    }

    if (!empty($order_info['payment_city'])) {
      $params['client']['city'] = substr($order_info['payment_city'], 0, 128);
    }

    if (!empty($order_info['payment_iso_code_2'])) {
      $params['client']['country'] = $order_info['payment_iso_code_2'];
    }

    /* End of payment information */
    /* Start of shipping information */

    $params_client_shipping_street_address = array();
    if (!empty($order_info['shipping_address_1'])) {
      $params_client_shipping_street_address[] = $order_info['shipping_address_1'];
    }

    if (!empty($order_info['shipping_address_2'])) {
      $params_client_shipping_street_address[] = ' ' . $order_info['shipping_address_2'];
    }

    if (!empty($params_client_shipping_street_address)) {
      $params['client']['shipping_street_address'] = substr(implode($params_client_shipping_street_address), 0, 128);
    }

    if (!empty($order_info['shipping_postcode'])) {
      $params['client']['shipping_zip_code'] = substr($order_info['shipping_postcode'], 0, 32);
    }

    if (!empty($order_info['shipping_city'])) {
      $params['client']['shipping_city'] = substr($order_info['shipping_city'], 0, 128);
    }

    if (!empty($order_info['shipping_iso_code_2'])) {
      $params['client']['shipping_country'] = $order_info['shipping_iso_code_2'];
    }

    /* End of shipping information */

    $this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), 'brand-id');

    $purchase = $this->model_extension_chip_payment_chip->createPurchase($params);

    if ( !array_key_exists('id', $purchase) ) {
      $json['error'] = print_r($purchase, true);

      if ($this->config->get('payment_chip_debug')) {
        $this->log->write('CHIP API /purchase/ failed for order #' . $this->session->data['order_id'] . '. Response Body: ' . json_encode($purchase));
      }

      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    }

    // Save to chip_report table
    $customer_id = $order_info['customer_id'];
    $chip_id = $purchase['id'];
    $order_id = $order_info['order_id'];
    $status = isset($purchase['status']) ? $purchase['status'] : 'pending';
    $amount = $params['purchase']['total_override'] / 100;
    $environment_type = isset($purchase['is_test']) && $purchase['is_test'] ? 'staging' : 'production';

    $this->model_extension_chip_payment_chip->addReport(array(
      'customer_id' => $customer_id,
      'chip_id' => $chip_id,
      'order_id' => $order_id,
      'status' => $status,
      'amount' => $amount,
      'environment_type' => $environment_type
    ));

    // Get token data from database
    $token_data = $this->model_extension_chip_payment_chip->getTokenByChipTokenId((int)$chip_token_id);

    if (!$token_data || !isset($token_data['token_id'])) {
      $json['error'] = $this->language->get('error_invalid_token');
      $this->response->addHeader('Content-Type: application/json');
      $this->response->setOutput(json_encode($json));
      return;
    } else {
      // Charge the stored token
      $charge_response = $this->model_extension_chip_payment_chip->chargeToken($chip_id, $token_data['token_id']);
    }

    $json['redirect'] = $purchase['checkout_url'];

    $this->response->addHeader('Content-Type: application/json');
    $this->response->setOutput(json_encode($json));
  }

  public function success_callback() {
    $this->load->model('checkout/order');
    $this->load->model('extension/chip/payment/chip');
    $this->language->load('extension/chip/payment/chip');

    $public_key = $this->config->get('payment_chip_general_public_key');

    if (!isset($this->request->server['HTTP_X_SIGNATURE'])) {
      exit('No HTTP_X_SIGNATURE detected');
    }

    $HTTP_X_SIGNATURE = $this->request->server['HTTP_X_SIGNATURE'];

    $purchase_json = file_get_contents('php://input');

    if (openssl_verify( $purchase_json,  base64_decode($HTTP_X_SIGNATURE), $public_key, 'sha256WithRSAEncryption' ) != 1) {
      $this->response->addHeader($this->request->server['SERVER_PROTOCOL'] . '/1.1 401 Unauthorized');
      exit;
    }

    $purchase = json_decode($purchase_json, true);

    if ($purchase['status'] != 'paid') {
      exit;
    }

    $purchase_id = $purchase['id'];

    $this->db->query("SELECT GET_LOCK('payment_chip_payment_$purchase_id', 15);");

    $order_info = $this->model_checkout_order->getOrder($purchase['reference']);
    if ($order_info['order_status_id'] != $this->config->get('payment_chip_paid_order_status_id')) {
      $this->model_checkout_order->addHistory($purchase['reference'], $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('payment_successful') . ' ' . $purchase_id, true);
      $this->model_checkout_order->addHistory($purchase['reference'], $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('payment_method') . strtoupper($purchase['transaction_data']['payment_method']));

      if ($purchase['is_test'] == true) {
        $this->model_checkout_order->addHistory($purchase['reference'], $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('test_mode_disclaimer'));
      }
    }

    // Update chip_report status to paid
    $this->model_extension_chip_payment_chip->updateReportStatus($purchase_id, 'paid');

    // Save token if is_recurring_token is true
    if (isset($purchase['is_recurring_token']) && $purchase['is_recurring_token'] === true) {
      $this->saveToken($purchase, $order_info['customer_id']);
    }

    $this->db->query("SELECT RELEASE_LOCK('payment_chip_payment_$purchase_id');");

    exit;
  }

  public function success_redirect() {
    $this->language->load('extension/chip/payment/chip');

    // Get order_id from request parameter
    if (!isset($this->request->get['order_id'])) {
      exit($this->language->get('invalid_redirect'));
    }

    $order_id = (int)$this->request->get['order_id'];

    // Load model and get report data
    $this->load->model('extension/chip/payment/chip');
    $report = $this->model_extension_chip_payment_chip->getReportByOrderId($order_id);

    if (!$report) {
      exit($this->language->get('invalid_redirect'));
    }

    $purchase_id = $report['chip_id'];

    $this->load->model('checkout/order');
    $this->load->model('extension/chip/payment/chip');

    $this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), '');
    $purchase = $this->model_extension_chip_payment_chip->getPurchase($purchase_id);

    if ( !array_key_exists('id', $purchase) ) {
      $json['error'] = print_r($purchase, true);

      if ($this->config->get('payment_chip_debug')) {
        $this->log->write('CHIP API /purchase/'.$purchase_id. '/ failed for order #' . $order_id . '. Response Body: ' . json_encode($purchase));
      }

      $this->response->redirect($this->url->link('checkout/failure'));
    }

    if ($purchase['status'] != 'paid') {
      echo '<pre>' . print_r($purchase, true) . '</pre>';
      exit;
    }

    $this->db->query("SELECT GET_LOCK('payment_chip_payment_$purchase_id', 15);");

    $order_info = $this->model_checkout_order->getOrder($order_id);
    if ($order_info['order_status_id'] != $this->config->get('payment_chip_paid_order_status_id')) {
      $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('payment_successful') .' '. $purchase_id, true);
      $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('payment_method') . strtoupper($purchase['transaction_data']['payment_method']));

      if ($purchase['is_test'] == true) {
        $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_chip_paid_order_status_id'), $this->language->get('test_mode_disclaimer'));
      }
    }

    // Update chip_report status to paid
    $this->model_extension_chip_payment_chip->updateReportStatus($purchase_id, 'paid');

    // Save token if is_recurring_token is true
    if (isset($purchase['is_recurring_token']) && $purchase['is_recurring_token'] === true) {
      $this->saveToken($purchase, $order_info['customer_id']);
    }

    $this->db->query("SELECT RELEASE_LOCK('payment_chip_payment_$purchase_id');");

    $this->response->redirect($this->url->link('checkout/success'));
  }

  public function cancel_redirect() {
    $this->load->language('extension/chip/payment/chip');

    // Get order_id from request parameter
    if (!isset($this->request->get['order_id'])) {
      exit($this->language->get('invalid_redirect'));
    }

    $order_id = (int)$this->request->get['order_id'];

    // Load model and get report data
    $this->load->model('extension/chip/payment/chip');
    $report = $this->model_extension_chip_payment_chip->getReportByOrderId($order_id);

    if (!$report) {
      exit($this->language->get('invalid_redirect'));
    }

    $purchase_id = $report['chip_id'];
    $this->load->model('checkout/order');

    $this->db->query("SELECT GET_LOCK('payment_chip_payment_$purchase_id', 15);");

    $order_info = $this->model_checkout_order->getOrder($order_id);
    if ($order_info['order_status_id'] != $this->config->get('payment_chip_canceled_order_status_id')) {
      $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_chip_canceled_order_status_id'), $this->language->get('payment_canceled') .' '. $purchase_id, true);
    }

    // Update chip_report status to canceled
    $this->model_extension_chip_payment_chip->updateReportStatus($purchase_id, 'canceled');

    $this->db->query("SELECT RELEASE_LOCK('payment_chip_payment_$purchase_id');");

    $this->response->redirect($this->url->link('checkout/failure'));
  }

  public function failure_redirect() {
    $this->load->language('extension/chip/payment/chip');

    // Get order_id from request parameter
    if (!isset($this->request->get['order_id'])) {
      exit($this->language->get('invalid_redirect'));
    }

    $order_id = (int)$this->request->get['order_id'];

    // Load model and get report data
    $this->load->model('extension/chip/payment/chip');
    $report = $this->model_extension_chip_payment_chip->getReportByOrderId($order_id);

    if (!$report) {
      exit($this->language->get('invalid_redirect'));
    }

    $purchase_id = $report['chip_id'];
    $this->load->model('checkout/order');

    $this->db->query("SELECT GET_LOCK('payment_chip_payment_$purchase_id', 15);");

    $order_info = $this->model_checkout_order->getOrder($order_id);
    if ($order_info['order_status_id'] != $this->config->get('payment_chip_failed_order_status_id')) {
      $this->model_checkout_order->addHistory($order_id, $this->config->get('payment_chip_failed_order_status_id'), $this->language->get('payment_failed') .' '. $purchase_id, true);
    }

    // Update chip_report status to failed
    $this->model_extension_chip_payment_chip->updateReportStatus($purchase_id, 'failed');

    $this->db->query("SELECT RELEASE_LOCK('payment_chip_payment_$purchase_id');");

    $this->response->redirect($this->url->link('checkout/failure'));
  }

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