<?php
namespace Opencart\Admin\Model\Extension\Chip\Payment;

class Chip extends \Opencart\System\Engine\Model
{

  public static $require_empty_string_encoding = false;
  public static $private_key;
  public static $brand_id;

  public function install() {
    $this->db->query("
      CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "chip_report` (
        `chip_report_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `customer_id` bigint(20) NOT NULL,
        `chip_id` varchar(64) NOT NULL,
        `order_id` bigint(20) NOT NULL,
        `status` varchar(64) NOT NULL,
        `amount` decimal(15,2) NOT NULL,
        `environment_type` varchar(32) NOT NULL,
        `date_added` datetime NOT NULL,
        PRIMARY KEY (`chip_report_id`),
        KEY `order_id` (`order_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ");

    $this->db->query("
      CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "chip_token` (
        `chip_token_id` bigint(20) NOT NULL AUTO_INCREMENT,
        `customer_id` bigint(20) NOT NULL,
        `token_id` varchar(64) NOT NULL,
        `type` varchar(64) NOT NULL,
        `card_name` varchar(64) NOT NULL,
        `card_number` varchar(64) NOT NULL,
        `card_expire_month` varchar(64) NOT NULL,
        `card_expire_year` varchar(64) NOT NULL,
        `date_added` datetime NOT NULL,
        PRIMARY KEY (`chip_token_id`),
        KEY `customer_id` (`customer_id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
    ");
  }

  public function uninstall() {
    $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "chip_report`");
    $this->db->query("DROP TABLE IF EXISTS `" . DB_PREFIX . "chip_token`");
  }

  public function set_keys($private_key, $brand_id) {
    self::$private_key = $private_key;
    self::$brand_id    = $brand_id;
  }

  public function get_public_key()
  {
    $result = $this->call('GET', "/public_key/");

    return $result;
  }

  private function call($method, $route, $params = [])
  {
    $private_key = self::$private_key;
    if (!empty($params)) {
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

  private function request($method, $url, $params = [], $headers = [])
  {
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

    // this to prevent error when account balance called
    if (self::$require_empty_string_encoding){
      curl_setopt($ch, CURLOPT_ENCODING, '');
    }

    $response = curl_exec($ch);

    curl_close($ch);

    return $response;
  }
}