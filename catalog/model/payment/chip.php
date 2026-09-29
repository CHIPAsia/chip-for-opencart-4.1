<?php
namespace Opencart\Catalog\Model\Extension\Chip\Payment;

class Chip extends \Opencart\System\Engine\Model {
	const DUITNOW_GROUP = ['duitnow_qr', 'dnqr'];
	const SHOPEE_GROUP = ['razer_shopeepay', 'shopee_pay'];

	/**
	 * Card methods that can back a recurring charge.
	 *
	 * CHIP issues recurring tokens for card payments only, so this list must
	 * never be widened - no other payment method can be charged on a cycle.
	 */
	const RECURRING_CARD_METHODS = ['visa', 'mastercard', 'maestro'];

	/**
	 * Days after the due date to retry a failed renewal charge.
	 *
	 * Offsets are applied to the date the cycle was ACTUALLY due, which the
	 * caller recovers via ladderAnchor() - not to the raw `date_next`
	 * column, which a previous failed attempt has already overwritten with
	 * its own retry time. Measuring from that raw value made the offsets
	 * compound (D+1, D+4, D+9) instead of the intended 1/3/5.
	 */
	const RETRY_OFFSETS_DAYS = [1, 3, 5];

	/**
	 * Gateway error code from the most recent API call ('' on success).
	 *
	 * @var string
	 */
	private $last_error_code = '';

	private string $private_key;
	private string $brand_id;

	private static array $payment_methods_cache = [];

	/**
	 * OpenCart 4.0.2.0+ / 4.1.x entry point.
	 *
	 * Core's catalog/model/checkout/payment_method.php calls getMethods() from
	 * 4.0.2.0 onward, and merges the returned array keyed by code.
	 */
	public function getMethods(array $address = []): array {
		return $this->methodData($address);
	}

	/**
	 * OpenCart 4.0.0.0 / 4.0.1.1 legacy entry point.
	 *
	 * Those versions call getMethod() DIRECTLY, with no existence guard:
	 *
	 *   $payment_method = $this->{'model_extension_' . ...}->getMethod($payment_address);
	 *
	 * So this method must exist for one build to serve 4.0.0.0 through 4.1.0.x.
	 * The legacy template renders a plain <select> off payment_method.code and
	 * payment_method.title, and has no `option` radio support, so only the flat
	 * shape is returned here.
	 */
	public function getMethod(array $address = []): array {
		$method_data = $this->methodData($address);

		if (!$method_data) {
			return [];
		}

		return [
			'code'       => $method_data['code'],
			'title'      => $method_data['title'],
			'sort_order' => $method_data['sort_order']
		];
	}

	/**
	 * Availability + method data, shared by getMethod() and getMethods() so the
	 * two public entry points cannot drift on geo-zone rules or token handling.
	 */
	private function methodData(array $address = []): array {
		$this->load->language('extension/chip/payment/chip');

		// Subscriptions are supported: CHIP is deliberately NOT hidden when the
		// cart holds a subscription product, it is offered card-only instead.
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

	/**
	 * Snake_case aliases kept for parity with the 4.0 build, so both trees
	 * expose the same shape. The camelCase originals stay authoritative - the
	 * 4.1 controllers call those.
	 */
	public function set_keys(string $private_key, string $brand_id): void {
		$this->setKeys($private_key, $brand_id);
	}

	public function create_purchase(array $params): array {
		return $this->createPurchase($params);
	}

	public function get_purchase(string $purchase_id): array {
		return $this->getPurchase($purchase_id);
	}

	public function createPurchase(array $params): ?array {
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

		// In-memory migration: legacy 'razer_shopeepay' → modern 'shopee_pay'.
		// Backward-compatible: only rewrite when the legacy key is present and the
		// modern key is not, so a merchant who already saved 'shopee_pay' is untouched.
		if (in_array('razer_shopeepay', $whitelist, true) && !in_array('shopee_pay', $whitelist, true)) {
			$whitelist = array_map(
				static fn($method) => $method === 'razer_shopeepay' ? 'shopee_pay' : $method,
				$whitelist
			);
			$whitelist = array_values($whitelist);
		}

		$groups = [
			'dnqr'       => self::DUITNOW_GROUP,
			'shopee_pay' => self::SHOPEE_GROUP,
		];

		// 1. Short-circuit: no group member configured → return untouched (no API call).
		$has_group_member = false;
		foreach ($groups as $group) {
			if (count(array_intersect($whitelist, $group)) > 0) {
				$has_group_member = true;
				break;
			}
		}
		if (!$has_group_member) {
			return $whitelist;
		}

		// 2. Expand all configured groups in memory.
		$expanded = $whitelist;
		foreach ($groups as $group) {
			$expanded = array_values(array_unique(array_merge($expanded, $group)));
		}

		// 3. Cache key: brand + currency + amount-bucket (round to 100-sen steps).
		$cache_key = $this->brand_id . '|' . $currency . '|' . intval($amount / 100);

		// 4. Try static cache. On miss, call /payment_methods/ once.
		if (!isset(self::$payment_methods_cache[$cache_key])) {
			$response = $this->payment_methods($currency, '', $amount);
			if (!is_array($response) || !isset($response['available_payment_methods'])) {
				// 4a. Fallback: return expanded whitelist unchanged on API failure.
				return $expanded;
			}
			self::$payment_methods_cache[$cache_key] = $response['available_payment_methods'];
		}
		$available = self::$payment_methods_cache[$cache_key];

		// 5. Resolve each configured group against what the merchant actually has.
		$resolved = [];
		foreach ($groups as $preferred => $group) {
			if (count(array_intersect($whitelist, $group)) === 0) {
				continue;
			}
			$resolved_group = array_values(array_intersect($group, $available));
			// 6. Priority: preferred member wins when both are present.
			if (in_array($preferred, $resolved_group, true)) {
				$non_preferred = array_values(array_diff($group, [$preferred]));
				$resolved_group = array_values(array_diff($resolved_group, $non_preferred));
			}
			$resolved = array_merge($resolved, $resolved_group);
		}

		// 7. Final: original non-group entries + resolved groups.
		$final = $expanded;
		foreach ($groups as $group) {
			$final = array_values(array_diff($final, $group));
		}
		$final = array_merge($final, $resolved);

		return $final;
	}

	public function getPurchase(string $purchase_id): ?array {
		return $this->call('GET', "/purchases/{$purchase_id}/");
	}

	public function chargeToken(string $purchase_id, string $token_id): ?array {
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

	/**
	 * Whether the current cart contains a subscription product.
	 *
	 * OpenCart 4.x keeps subscription plans on the cart line itself, so this
	 * reads the same `subscription` key core's own Cart::hasSubscription()
	 * uses rather than a bespoke query.
	 */
	public function cartHasSubscription(): bool {
		return $this->cart->hasSubscription();
	}

	/**
	 * Extra purchase params required to obtain a recurring token.
	 *
	 * Returns an empty array for a normal cart. This must stay gated: an
	 * unconditional `force_recurring` would tokenise one-time payments too and
	 * change behaviour for every existing merchant.
	 */
	public function recurringPurchaseParams(): array {
		if (!$this->cartHasSubscription()) {
			return [];
		}

		return [
			'force_recurring'          => true,
			'payment_method_whitelist' => self::RECURRING_CARD_METHODS
		];
	}

	/**
	 * Charge a renewal against a stored recurring token.
	 *
	 * @param string $purchase_id Purchase to charge (a freshly created one).
	 * @param string $token_id    The customer's recurring token.
	 */
	public function chargeRecurring(string $purchase_id, string $token_id): ?array {
		return $this->call('POST', "/purchases/{$purchase_id}/charge/", [
			'recurring_token' => $token_id
		]);
	}

	/**
	 * Delete a recurring token at the gateway.
	 *
	 * @param string $purchase_id The purchase that issued the token.
	 */
	public function deleteRecurringToken(string $purchase_id): ?array {
		return $this->call('POST', "/purchases/{$purchase_id}/delete_recurring_token/");
	}

	/**
	 * Add a subscription row.
	 *
	 * The row is created `pending` with no recurring token, so it is never
	 * charged until the payment that produced the token has actually paid.
	 */
	public function addSubscription(array $data): int {
		$this->db->query("INSERT INTO `" . DB_PREFIX . "chip_subscription`
			SET `order_id` = " . (int)$data['order_id'] . ",
			`order_recurring_id` = " . (int)$data['order_recurring_id'] . ",
			`customer_id` = " . (int)$data['customer_id'] . ",
			`customer_email` = '" . $this->db->escape($data['customer_email']) . "',
			`chip_token_id` = " . (int)$data['chip_token_id'] . ",
			`recurring_token` = '" . $this->db->escape($data['recurring_token']) . "',
			`product_name` = '" . $this->db->escape($data['product_name']) . "',
			`product_quantity` = " . (int)$data['product_quantity'] . ",
			`recurring_frequency` = '" . $this->db->escape($data['recurring_frequency']) . "',
			`recurring_cycle` = " . (int)$data['recurring_cycle'] . ",
			`recurring_duration` = " . (int)$data['recurring_duration'] . ",
			`recurring_price` = '" . (float)$data['recurring_price'] . "',
			`trial_price` = '" . (float)$data['trial_price'] . "',
			`trial_cycle` = " . (int)$data['trial_cycle'] . ",
			`trial_frequency` = '" . $this->db->escape($data['trial_frequency']) . "',
			`trial_duration` = " . (int)$data['trial_duration'] . ",
			`remaining` = " . (int)$data['remaining'] . ",
			`trial_remaining` = " . (int)$data['trial_remaining'] . ",
			`status` = '" . $this->db->escape($data['status']) . "',
			`date_next` = '" . $this->db->escape($data['date_next']) . "',
			`date_last_charge` = '" . $this->db->escape($data['date_last_charge']) . "',
			`retry_count` = " . (int)$data['retry_count'] . ",
			`date_added` = NOW(),
			`date_modified` = NOW()");

		return $this->db->getLastId();
	}

	/**
	 * Fetch one subscription.
	 */
	public function getSubscription(int $chip_subscription_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_subscription`
			WHERE `chip_subscription_id` = " . (int)$chip_subscription_id);

		if ($query->num_rows) {
			return $query->row;
		}

		return null;
	}

	/**
	 * Fetch the subscription attached to an order.
	 */
	public function getSubscriptionByOrderId(int $order_id): ?array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_subscription`
			WHERE `order_id` = " . (int)$order_id . "
			ORDER BY `chip_subscription_id` DESC LIMIT 1");

		if ($query->num_rows) {
			return $query->row;
		}

		return null;
	}

	/**
	 * All subscriptions belonging to an order.
	 */
	public function getSubscriptionsByOrderId(int $order_id): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_subscription`
			WHERE `order_id` = " . (int)$order_id . "
			ORDER BY `chip_subscription_id` ASC");

		return $query->rows;
	}

	/**
	 * Attach a recurring token and make the subscription chargeable.
	 *
	 * Only `active` rows are charged by the cron, so this is the single point
	 * where a plan becomes live.
	 */
	public function activateSubscription(int $chip_subscription_id, string $recurring_token, int $chip_token_id, string $date_next = ''): void {
		/*
		 * A suspended subscription is re-armed here, so the schedule MUST be
		 * restored in the same statement.
		 *
		 * Suspension writes date_next='0000-00-00 00:00:00' and leaves
		 * retry_count at the spent value. Setting status='active' without
		 * fixing those leaves a row that getDueSubscriptions() never selects
		 * again (it requires a non-zero date_next) and whose retry ladder is
		 * already exhausted - so the customer is billed never, while every
		 * screen says the subscription is active.
		 *
		 * The caller passes the next date, computed from the date the plan was
		 * ORIGINALLY due. Deriving it from "now" instead would hand out a free
		 * period (or bill early) depending on how long the suspension lasted.
		 */
		$rearm = '';

		if ($date_next !== '') {
			$rearm = ",
			`date_next` = '" . $this->db->escape($date_next) . "',
			`retry_count` = 0";
		}

		$this->db->query("UPDATE `" . DB_PREFIX . "chip_subscription`
			SET `recurring_token` = '" . $this->db->escape($recurring_token) . "',
			`chip_token_id` = " . (int)$chip_token_id . ",
			`status` = 'active',
			`date_last_charge` = NOW(),
			`date_modified` = NOW()" . $rearm . "
			WHERE `chip_subscription_id` = " . (int)$chip_subscription_id);
	}

	/**
	 * Subscriptions due to be charged.
	 *
	 * Only `active` rows are returned: `suspended` means the dunning ladder was
	 * exhausted and a human has to intervene, so the cron must leave them alone.
	 *
	 * @param string $date_now Cut-off (Y-m-d H:i:s).
	 */
	public function getDueSubscriptions(string $date_now, int $limit = 10): array {
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "chip_subscription`
			WHERE `status` = 'active'
			AND `date_next` != '0000-00-00 00:00:00'
			AND `date_next` <= '" . $this->db->escape($date_now) . "'
			ORDER BY `date_next` ASC
			LIMIT " . (int)$limit);

		return $query->rows;
	}

	/**
	 * Advance the schedule BEFORE the charge is attempted.
	 *
	 * The claim-first ordering is deliberate: a crash after this call costs one
	 * billing cycle, recoverable by hand. A crash before it would re-charge a
	 * real customer. When in doubt, under-charge.
	 */
	public function claimSubscription(int $chip_subscription_id, string $date_next): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "chip_subscription`
			SET `date_next` = '" . $this->db->escape($date_next) . "',
			`date_modified` = NOW()
			WHERE `chip_subscription_id` = " . (int)$chip_subscription_id);
	}

	/**
	 * Record a successful renewal.
	 *
	 * @param int $remaining       Cycles still to charge on the recurring schedule.
	 * @param int $trial_remaining Cycles still to charge on the trial schedule.
	 */
	public function recordSubscriptionPayment(int $chip_subscription_id, int $remaining, int $trial_remaining = 0): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "chip_subscription`
			SET `date_last_charge` = NOW(),
			`remaining` = " . (int)$remaining . ",
			`trial_remaining` = " . (int)$trial_remaining . ",
			`retry_count` = 0,
			`date_modified` = NOW()
			WHERE `chip_subscription_id` = " . (int)$chip_subscription_id);
	}

	/**
	 * Record a failed attempt: store the next retry time and bump the counter.
	 */
	public function recordSubscriptionFailure(int $chip_subscription_id, string $date_next, int $retry_count, string $status = 'active'): void {
		$this->db->query("UPDATE `" . DB_PREFIX . "chip_subscription`
			SET `date_next` = '" . $this->db->escape($date_next) . "',
			`retry_count` = " . (int)$retry_count . ",
			`status` = '" . $this->db->escape($status) . "',
			`date_modified` = NOW()
			WHERE `chip_subscription_id` = " . (int)$chip_subscription_id);
	}

	/**
	 * The next retry time for a failed charge, or null when the ladder is spent.
	 *
	 * Offsets are measured from the ORIGINAL due date so a slow cron cannot
	 * stretch the ladder.
	 *
	 * @param string $due_date    Original due date (Y-m-d H:i:s).
	 * @param int    $retry_count Failures so far, 0-based.
	 */
	public function nextRetryAt(string $due_date, int $retry_count): ?string {
		if ($retry_count >= count(self::RETRY_OFFSETS_DAYS)) {
			return null;
		}

		$offset = self::RETRY_OFFSETS_DAYS[$retry_count];
		$timestamp = strtotime($due_date);

		if ($timestamp === false) {
			return null;
		}

return date('Y-m-d H:i:s', strtotime('+' . $offset . ' day', $timestamp));
	}

	/**
	 * The true due date a retry ladder is working from.
	 *
	 * `date_next` is rewritten twice per attempt: claimSubscription() moves it to
	 * the next billing cycle, then recordSubscriptionFailure() moves it to the
	 * retry time. So on a retry it holds the PREVIOUS retry time, not the date the
	 * cycle was actually due - and measuring offsets from it makes them compound
	 * (D+1, D+4, D+9) instead of the intended 1/3/5.
	 *
	 * The previous retry time is exactly `original + RETRY_OFFSETS_DAYS[retry_count - 1]`,
	 * so the original is recovered by subtracting that same offset back off - which
	 * is why this needs no schema change.
	 *
	 * @param string $date_next   Current `date_next` value.
	 * @param int    $retry_count Failures so far (0-based).
	 *
	 * @return string
	 */
	public function ladderAnchor($date_next, $retry_count) {
		$retry_count = (int)$retry_count;

		if ($retry_count <= 0) {
			// Nothing retried yet: the stored date IS the due date.
			return $date_next;
		}

		if ($retry_count > count(self::RETRY_OFFSETS_DAYS)) {
			// Counter from before this fix, or otherwise unexpected: do not guess.
			return $date_next;
		}

		$offset    = self::RETRY_OFFSETS_DAYS[$retry_count - 1];
		$timestamp = strtotime($date_next);

		if ($timestamp === false) {
			return $date_next;
		}

		return date('Y-m-d H:i:s', strtotime('-' . $offset . ' day', $timestamp));
	}


	/**
	 * Advance a due date by one billing cycle.
	 *
	 * Anchored to the previous due date rather than "now", so a subscription
	 * billed on the 1st stays on the 1st even when the cron runs late.
	 *
	 * @param string $from_date Base date (Y-m-d H:i:s).
	 * @param string $frequency one of day/week/semi_month/month/year.
	 */
	public function nextCycleDate(string $from_date, string $frequency, int $cycle): ?string {
		$cycle = max(1, (int)$cycle);
		$timestamp = strtotime($from_date);

		if ($timestamp === false) {
			return null;
		}

		switch ($frequency) {
			case 'day':
				$interval = '+' . $cycle . ' day';
				break;
			case 'week':
				$interval = '+' . ($cycle * 7) . ' day';
				break;
			case 'semi_month':
				$interval = '+' . ($cycle * 15) . ' day';
				break;
			case 'month':
				$interval = '+' . $cycle . ' month';
				break;
			case 'year':
				$interval = '+' . $cycle . ' year';
				break;
			default:
				return null;
		}

		return date('Y-m-d H:i:s', strtotime($interval, $timestamp));
	}

	/**
	 * Find the chip_token row the given purchase produced.
	 */
	public function findTokenIdByPurchase(string $purchase_id): int {
		$query = $this->db->query("SELECT `chip_token_id` FROM `" . DB_PREFIX . "chip_token`
			WHERE `token_id` = '" . $this->db->escape($purchase_id) . "' LIMIT 1");

		if ($query->num_rows) {
			return (int)$query->row['chip_token_id'];
		}

		return 0;
	}

	/**
	 * Gateway error code from the most recent API call ('' on success).
	 *
	 * CHIP reports failures as `{"__all__": [{"code": "..."}]}`; a legacy
	 * `errors` key is also accepted. The code used to be discarded, which made a
	 * dead token indistinguishable from a card decline - so the dunning ladder
	 * spent its whole budget retrying a token that could never work.
	 *
	 * @return string
	 */
	public function getLastErrorCode() {
		return $this->last_error_code;
	}

	/**
	 * Pull the error code out of a gateway error body, if there is one.
	 *
	 * @param array $result Decoded response body.
	 *
	 * @return string Empty when the body carries no error.
	 */
	private function extractErrorCode($result) {
		foreach (['__all__', 'errors'] as $key) {
			if (empty($result[$key])) {
				continue;
			}

			$first = $result[$key];

			if (isset($first[0]['code'])) {
				return (string)$first[0]['code'];
			}

			if (isset($first['code'])) {
				return (string)$first['code'];
			}

			if (is_string($first)) {
				return $first;
			}
		}

		return '';
	}

	private function call(string $method, string $route, array $params = []): ?array {
		$this->last_error_code = '';

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
			$this->last_error_code = 'invalid_response';
			return null;
		}

		// Failures arrive under `__all__`; older responses used `errors`.
		// Neither may be returned as though it were a successful body, and the
		// code must survive so the caller can tell a dead token from a decline.
		$error_code = $this->extractErrorCode($result);

		if ($error_code !== '') {
			$this->last_error_code = $error_code;
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
