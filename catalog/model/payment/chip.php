<?php
namespace Opencart\Catalog\Model\Extension\Chip\Payment;

class Chip extends \Opencart\System\Engine\Model {
  const DUITNOW_GROUP = ['duitnow_qr', 'dnqr'];

  private string $private_key;
  private string $brand_id;

  private static array $payment_methods_cache = [];

  public function getMethods(array $address): array {
    $this->load->language('extension/chip/payment/chip');

    if ($this->cart->hasSubscription()) {
      return [];
    }

    $geo_zone_id = $this->config->get('payment_chip_geo_zone_id');

    if (!$geo_zone_id) {
      $status = true;
    } else {
      $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE `geo_zone_id` = '" . (int)$geo_zone_id . "' AND `country_id` = '" . (int)$address['country_id'] . "' AND (`zone_id` = '" . (int)$address['zone_id'] . "' OR `zone_id` = '0')");
      
      $status = (bool)$query->num_rows;
    }

    if (!$status) {
      return [];
    }

    $method_data = [
      'name'       => $this->language->get('heading_title'),
      'code'       => 'chip',
      'title'      => nl2br($this->config->get('payment_chip_payment_name_' . $this->config->get('config_language_id'))),
      'sort_order' => $this->config->get('payment_chip_sort_order')
    ];

    // Get available tokens for the customer
    $option_data = [];
    $has_tokens = false;
    if ($this->customer->getId()) {
      $tokens = $this->getTokens($this->customer->getId());
      
      // Always add the option to use a new card
      $option_data['chip'] = [
        'code' => 'chip.chip',
        'name' => nl2br($this->config->get('payment_chip_payment_name_' . $this->config->get('config_language_id')))
      ];

      foreach ($tokens as $token) {
        $option_data[$token['chip_token_id']] = [
          'code' => 'chip.' . $token['chip_token_id'],
          'name' => $this->language->get('text_card_use') . ' ' . $this->language->get('text_' . $token['type']) . ' ' . $token['card_number']
        ];
      }
    }

    $method_data['option'] = $option_data;

    return $method_data;
  }

  public function setKeys(string $private_key, string $brand_id): void {
    $this->private_key = $private_key;
    $this->brand_id = $brand_id;
  }

  public function createPurchase(array $params): array {
    return $this->call('POST', '/purchases/', $params);
  }

  public function payment_methods(string $currency, string $language, int $amount): ?array {
    return $this->call(
      'GET',
      "/payment_methods/?brand_id={$this->brand_id}&currency={$currency}&language={$language}&amount={$amount}"
    );
  }

  public function resolve_payment_method_whitelist(array $whitelist, string $currency, int $amount): array {
    $whitelist = array_values($whitelist);

    // 1. Short-circuit: no dnqr-group member configured → return untouched (no API call).
    $has_group_member = count(array_intersect($whitelist, self::DUITNOW_GROUP)) > 0;
    if (!$has_group_member) {
      return $whitelist;
    }

    // 2. Expand group in memory.
    $expanded = array_values(array_unique(array_merge($whitelist, self::DUITNOW_GROUP)));

    // 3. Cache key: brand + currency + amount-bucket (round to 100-sen steps).
    $cache_key = $this->brand_id . '|' . $currency . '|' . intval($amount / 100);

    // 4. Try static cache. On miss, call /payment_methods/.
    if (!isset(self::$payment_methods_cache[$cache_key])) {
      $response = $this->payment_methods($currency, '', $amount);
      if (!is_array($response) || !isset($response['available_payment_methods'])) {
        // 4a. Fallback: return expanded whitelist unchanged on API failure.
        return $expanded;
      }
      self::$payment_methods_cache[$cache_key] = $response['available_payment_methods'];
    }
    $available = self::$payment_methods_cache[$cache_key];

    // 5. Intersect: keep only group members the merchant actually has.
    $resolved_group = array_values(array_intersect(self::DUITNOW_GROUP, $available));

    // 6. Priority: dnqr wins when both are present.
    if (in_array('dnqr', $resolved_group, true)) {
      $resolved_group = array_values(array_diff($resolved_group, ['duitnow_qr']));
    }

    // 7. Final: original non-group entries + resolved group.
    $final = array_values(array_diff($expanded, self::DUITNOW_GROUP));
    $final = array_merge($final, $resolved_group);

    return $final;
  }

  public function getPurchase(string $purchase_id): array {
    return $this->call('GET', "/purchases/{$purchase_id}/");
  }

  public function chargeToken(string $purchase_id, string $token_id): array {
    $params = [
      'recurring_token' => $token_id
    ];
    return $this->call('POST', "/purchases/{$purchase_id}/charge/", $params);
  }

  public function getTokenByChipTokenId(int $chip_token_id): ?array {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_token` WHERE `chip_token_id` = " . (int)$chip_token_id);
    
    if ($query->num_rows) {
      return $query->row;
    }
    
    return null;
  }


  public function addReport(array $data): void {
    $this->db->query("INSERT INTO `" . DB_PREFIX . "chip_report` 
      (`customer_id`, `chip_id`, `order_id`, `status`, `amount`, `environment_type`, `date_added`) 
      VALUES (" . (int)$data['customer_id'] . ", '" . $this->db->escape($data['chip_id']) . "', " . (int)$data['order_id'] . ", 
      '" . $this->db->escape($data['status']) . "', '" . (float)$data['amount'] . "', 
      '" . $this->db->escape($data['environment_type']) . "', NOW())");
  }

  public function updateReportStatus(string $chip_id, string $status): void {
    $this->db->query("UPDATE `" . DB_PREFIX . "chip_report` 
      SET `status` = '" . $this->db->escape($status) . "' 
      WHERE `chip_id` = '" . $this->db->escape($chip_id) . "'");
  }

  public function getReportByOrderId(int $order_id): ?array {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_report` WHERE `order_id` = " . (int)$order_id . " ORDER BY `date_added` DESC LIMIT 1");
    
    if ($query->num_rows) {
      return $query->row;
    }
    
    return null;
  }

  public function addToken(array $data): void {
    $this->db->query("INSERT INTO `" . DB_PREFIX . "chip_token` 
      (`customer_id`, `token_id`, `type`, `card_name`, `card_number`, `card_expire_month`, `card_expire_year`, `date_added`) 
      VALUES (" . (int)$data['customer_id'] . ", 
      '" . $this->db->escape($data['token_id']) . "', 
      '" . $this->db->escape($data['type']) . "', 
      '" . $this->db->escape($data['card_name']) . "', 
      '" . $this->db->escape($data['card_number']) . "', 
      '" . $this->db->escape($data['card_expire_month']) . "', 
      '" . $this->db->escape($data['card_expire_year']) . "', 
      NOW())");
  }

  public function getTokens(int $customer_id): array {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_token` 
      WHERE `customer_id` = " . (int)$customer_id . " 
      ORDER BY `date_added` DESC");

    return $query->rows;
  }

  public function getToken(int $customer_id, int $chip_token_id): ?array {
    $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_token` 
      WHERE `customer_id` = " . (int)$customer_id . " 
      AND `chip_token_id` = " . (int)$chip_token_id);
    
    if ($query->num_rows) {
      return $query->row;
    }
    
    return null;
  }

  public function deleteToken(int $customer_id, int $chip_token_id): void {
    $this->db->query("DELETE FROM `" . DB_PREFIX . "chip_token` 
      WHERE `customer_id` = " . (int)$customer_id . " 
      AND `chip_token_id` = " . (int)$chip_token_id);
  }

  private function call(string $method, string $route, array $params = []): ?array {
    $private_key = $this->private_key;
    if (!empty($params) || is_array($params)) {
      $params = json_encode($params);
    }

    $response = $this->request(
      $method,
      sprintf("%s/api/v1%s", 'https://gate.chip-in.asia', $route),
      $params,
      [
        'Content-type: application/json',
        'Authorization: ' . "Bearer " . $private_key,
      ]
    );

    $result = json_decode($response, true);
    if (!$result) {
      return null;
    }

    if (!empty($result['errors'])) {
      return null;
    }

    return $result;
  }

  private function request(string $method, string $url, string $params = '', array $headers = []): string {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);

    if ($method == 'POST') {
      curl_setopt($ch, CURLOPT_POST, 1);
    }

    if ($method == 'PUT') {
      curl_setopt($ch, CURLOPT_PUT, 1);
    }

    if ($method == 'PUT' or $method == 'POST') {
      curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    }

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);

    curl_close($ch);

    return $response;
  }
}
