<?php
namespace Opencart\Catalog\Controller\Extension\Chip\Cron;
// Version reported to the gateway. Keep in step with install.json.
if (!defined('CHIP_OPENCART_VERSION')) {
	define('CHIP_OPENCART_VERSION', '1.4.0');
}

/**
 * Class Chip
 *
 * Renewal runner for CHIP subscriptions.
 *
 * OpenCart 4.x core's own cron/subscription.php creates the renewal order and
 * then calls the payment extension's cron controller:
 *
 *   $store->load->controller('extension/' . $extension_info['extension'] . '/cron/' . $extension_info['code']);
 *
 * For an extension installed as `chip`, that resolves to extension/chip/cron/chip.
 * We hook that route rather than shipping a bespoke endpoint so the merchant
 * configures OpenCart's single built-in cron and inherits core's scheduling and
 * order creation.
 *
 * @package Opencart\Catalog\Controller\Extension\Chip\Cron
 */
class Chip extends \Opencart\System\Engine\Controller {
	/**
	 * Route this controller is reached through when someone hits it directly
	 * over HTTP, e.g. index.php?route=extension/chip/cron/chip.
	 */
	private const ROUTE = 'extension/chip/cron/chip';

	/**
	 * Index
	 *
	 * Renewals run ONLY when core's cron invokes us. In OpenCart 4.x core owns
	 * scheduling and order creation, and reaches this controller internally:
	 *
	 *   $store->load->controller('extension/' . $extension . '/cron/' . $code);
	 *
	 * That internal call carries the OUTER route, not this one. A direct HTTP
	 * request, by contrast, always arrives with `route` beginning with this
	 * controller's path — including the `chip.index` spelling the router also
	 * accepts. The prefix test is what closes that second spelling; an equality
	 * test against the bare path alone lets `chip.index` through and is a real
	 * bypass, not a theoretical one.
	 *
	 * When cron.php is served over HTTP (a common merchant cron setup) it boots
	 * outside index.php, so `route` is not set at all and the internal call is
	 * correctly allowed through.
	 *
	 * This endpoint charges stored cards and advances the dunning ladder, so
	 * without the gate a single anonymous GET could drive a paying customer's
	 * subscription to `suspended` by exhausting its retry ladder.
	 *
	 * @return void
	 */
	public function index(): void {
		$route = (string)($this->request->get['route'] ?? '');

		if ($route !== '' && str_starts_with($route, self::ROUTE)) {
			/*
			 * Hygiene, not a behaviour fix: the previous spelling built
			 * "HTTP/1.1/1.1 403 Forbidden", which PHP still REPAIRS into a
			 * correct 403 - measured on a live 4.1.0.4 store, this endpoint
			 * answered 403 before and after. It is normalised to the valid
			 * status line only so the code reads honestly and matches the
			 * callback's guard; no client-visible change is claimed.
			 */
			$this->response->setOutput('Forbidden');
			$this->response->addHeader('HTTP/1.1 403 Forbidden');

			return;
		}

		$this->load->language('extension/chip/payment/chip');
		$this->load->model('extension/chip/payment/chip');

		$store            = $this->getStore();
		$renewal_order_id = $this->getRenewalOrderId();

		$now  = date('Y-m-d H:i:s');
		$due  = $this->model_extension_chip_payment_chip->getDueSubscriptions($now, 10);
		$done = 0;

		foreach ($due as $subscription) {
			$lock = 'payment_chip_subscription_' . (int)$subscription['chip_subscription_id'];

			$acquired = $this->db->query("SELECT GET_LOCK('" . $lock . "', 5) AS acquired");

			if (!$acquired->row['acquired']) {
				// Another cron run holds this subscription. Skip, do not double-charge.
				continue;
			}

			/*
			 * Re-read the row now that the lock is held, and re-check that it is
			 * still ours to bill. The due-list was read BEFORE the lock, so a run
			 * that lost the race would otherwise charge this stale snapshot: both
			 * runs pass the "is it due" test, both take the lock in turn, and the
			 * customer is billed twice for one billing period.
			 */
			$fresh = $this->model_extension_chip_payment_chip->getSubscription(
				(int)$subscription['chip_subscription_id']);

			if (!$fresh
				|| $fresh['status'] !== 'active'
				|| $fresh['date_next'] === '0000-00-00 00:00:00'
				|| $fresh['date_next'] !== $subscription['date_next']) {
				// Already billed by the run that beat us to the lock, or no longer
				// due. Release and skip rather than charge again.
				$this->db->query("SELECT RELEASE_LOCK('" . $lock . "');");
				continue;
			}

			// Bill the fresh row, never the snapshot the due-list handed us.
			$subscription = $fresh;

			/*
			 * Core's cron owns the order id, not us. When the row records one
			 * (a plan saved against a manually created order) use it; otherwise
			 * this is the renewal order core just created for this pass.
			 */
			$order_id = (int)$subscription['order_id'];

			if (!$order_id) {
				$order_id = $renewal_order_id;
			}

			$subscription['order_id'] = $order_id;

			if ($this->chargeSubscription($subscription, $store)) {
				$done++;
			}

			$this->db->query("SELECT RELEASE_LOCK('" . $lock . "');");
		}

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode(['due' => count($due), 'charged' => $done]));
	}

	/**
	 * The order id core's subscription cron created for this pass, or 0.
	 *
	 * Core does not persist the subscription row's order_id, so the only place
	 * the renewal order is recorded is the store session core set up before it
	 * called us.
	 */
	private function getRenewalOrderId(): int {
		return isset($this->session->data['order_id']) ? (int)$this->session->data['order_id'] : 0;
	}

	/**
	 * Charge one renewal, applying the dunning ladder on failure.
	 *
	 * @param array $subscription chip_subscription row.
	 * @param mixed $store        Store instance the renewal order belongs to.
	 *
	 * @return bool Whether the charge succeeded (or the plan was closed out).
	 */
	private function chargeSubscription(array $subscription, $store): bool {
		$order_id        = (int)$subscription['order_id'];
		/*
		 * `date_next` may hold a retry time left by the previous attempt
		 * rather than the date this cycle was due: claimSubscription() and
		 * recordSubscriptionFailure() both rewrite the column. Recover the real
		 * due date first, so the 1/3/5 retry ladder is measured from it and the
		 * claim below advances the billing schedule from it too.
		 */
		$due_date        = $this->model_extension_chip_payment_chip->ladderAnchor($subscription['date_next'], (int)$subscription['retry_count']);
		$frequency       = $subscription['recurring_frequency'];
		$cycle           = (int)$subscription['recurring_cycle'];
		$duration        = (int)$subscription['recurring_duration'];
		$remaining       = (int)$subscription['remaining'];
		$trial_remaining = (int)$subscription['trial_remaining'];
		$retry_count     = (int)$subscription['retry_count'];

		/*
		 * A trial cycle bills the trial price, not the recurring price.
		 *
		 * Charging recurring_price while trial cycles remain would overcharge
		 * the customer for the whole trial period.
		 */
		$in_trial   = $trial_remaining > 0;
		$unit_price = $in_trial ? (float)$subscription['trial_price'] : (float)$subscription['recurring_price'];

		/*
		 * Claim first, charge second.
		 *
		 * Advancing the due date BEFORE the charge is what makes a cron that
		 * runs more than once per window safe. A crash here costs one cycle and
		 * is recoverable by hand; a crash the other way round would charge a
		 * real customer twice.
		 */
		if ($in_trial) {
			$step_frequency = (string)$subscription['trial_frequency'];
			$step_cycle     = (int)$subscription['trial_cycle'];
		} else {
			$step_frequency = $frequency;
			$step_cycle     = $cycle;
		}

		$next_cycle = $this->model_extension_chip_payment_chip->nextCycleDate($due_date, $step_frequency, $step_cycle);

		if ($next_cycle === null) {
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], '0000-00-00 00:00:00', $retry_count, 'suspended');

			return false;
		}

		/*
		 * Fixed-duration plan with no cycles left: close it out.
		 *
		 * `remaining` counts cycles still to charge (set to `duration` on
		 * activation and decremented per successful renewal), so the terminal
		 * test is <= 0 - not <= 1, which would hand the customer a free final
		 * period by completing the plan without charging it.
		 */
		if ($duration > 0 && $remaining <= 0) {
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], '0000-00-00 00:00:00', 0, 'completed');

			return true;
		}

		$this->model_extension_chip_payment_chip->claimSubscription((int)$subscription['chip_subscription_id'], $next_cycle);

		// Mint a fresh purchase for the renewal amount and charge the token.
		$params = [
			'reference'       => $order_id,
			'platform'        => 'opencart',
			'creator_agent'   => 'OC41: ' . CHIP_OPENCART_VERSION,
			'brand_id'        => $this->config->get('payment_chip_brand_id'),
			'client'          => [
				'email' => $subscription['customer_email']
			],
			'purchase'        => [
				'timezone' => $this->config->get('payment_chip_time_zone'),
				'currency' => 'MYR',
				'products' => [
					[
						'name'     => substr($subscription['product_name'], 0, 256),
						'quantity' => (int)$subscription['product_quantity'],
						'price'    => round($unit_price * 100)
					]
				]
			],
			'recurring_token' => $subscription['recurring_token']
		];

		$this->model_extension_chip_payment_chip->setKeys($this->config->get('payment_chip_secret_key'), '');

		$purchase = $this->model_extension_chip_payment_chip->createPurchase($params);

		if (!is_array($purchase) || !array_key_exists('id', $purchase)) {
			return $this->failSubscription($subscription, $due_date, $retry_count, $this->language->get('error_renewal_purchase'), $store);
		}

		$charge = $this->model_extension_chip_payment_chip->chargeRecurring($purchase['id'], $subscription['recurring_token']);

		/*
		 * An unresolved charge is NOT a failure.
		 *
		 * CHIP answers HTTP 200 with `status = 'pending_charge'` when the
		 * acquirer has not finalised, and follows up with a `purchase.paid`
		 * or `purchase.payment_failed` callback. Treating that as a decline
		 * walked the retry ladder and re-charged on the next step while the
		 * first charge was still settling - a double-charge window.
		 *
		 * So: do not start a second charge. Leave the billing date that
		 * claimSubscription() already advanced (this row is therefore not
		 * due again in this window), keep the retry ladder untouched since
		 * nothing failed, and do not consume a cycle.
		 */
		if (is_array($charge) && isset($charge['status']) && $charge['status'] === 'pending_charge') {
			/*
			 * Logged against the order's CURRENT status, not a paid or failed
			 * one: a pending charge has resolved to neither, and flipping the
			 * order either way would misreport it to the merchant.
			 */
			$this->load->model('checkout/order');

			$order_info = $this->model_checkout_order->getOrder($subscription['order_id']);

			$order_status_id = isset($order_info['order_status_id']) ? (int)$order_info['order_status_id'] : 0;

			if ($order_status_id) {
				$this->addOrderHistory($store, (int)$subscription['order_id'], $order_status_id, $this->language->get('text_renewal_pending'), false);
			}

			return false;
		}

		if (!is_array($charge) || !isset($charge['status']) || $charge['status'] !== 'paid') {
			return $this->failSubscription($subscription, $due_date, $retry_count, $this->language->get('error_renewal_charge'), $store);
		}

		// Success.
		if ($in_trial) {
			$new_trial_remaining = max(0, $trial_remaining - 1);
			$new_remaining       = $remaining;
		} else {
			$new_trial_remaining = 0;
			$new_remaining       = $duration > 0 ? max(0, $remaining - 1) : 0;
		}

		$this->model_extension_chip_payment_chip->recordSubscriptionPayment(
			(int)$subscription['chip_subscription_id'], $new_remaining, $new_trial_remaining);

		$this->addOrderHistory(
			$store,
			$order_id,
			$this->config->get('payment_chip_paid_order_status_id'),
			$this->language->get('text_renewal_success') . ' ' . $charge['id'],
			true
		);

		if ($duration > 0 && !$in_trial && $new_remaining <= 0) {
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], '0000-00-00 00:00:00', 0, 'completed');
		}

		return true;
	}

	/**
	 * Handle a failed renewal: step down the dunning ladder, or suspend.
	 *
	 * @param array  $subscription
	 * @param string $due_date     The due date this attempt belonged to.
	 * @param int    $retry_count
	 * @param string $reason
	 * @param mixed  $store        Store instance the renewal order belongs to.
	 *
	 * @return bool Always false.
	 */
	private function failSubscription($subscription, $due_date, $retry_count, $reason, $store): bool {
$next_retry = $this->model_extension_chip_payment_chip->nextRetryAt($due_date, $retry_count);

		$error_code = (string)$this->model_extension_chip_payment_chip->getLastErrorCode();

		/*
		 * A dead or revoked token can never succeed. CHIP documents
		 * `invalid_recurring_token` as "do not retry, re-prompt the buyer for a new
		 * card", so suspend now instead of spending the whole ladder on a charge
		 * that is guaranteed to fail.
		 */
		if ($error_code === 'invalid_recurring_token') {
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], '0000-00-00 00:00:00', $retry_count + 1, 'suspended');

			$this->addOrderHistory(
				$store,
				(int)$subscription['order_id'],
				$this->config->get('payment_chip_failed_order_status_id'),
				$this->language->get('text_renewal_token_dead'),
				true
			);

			return false;
		}


		if ($next_retry === null) {
			/*
			 * Ladder exhausted. Suspend rather than cancel: the card is kept so
			 * the merchant can recover the subscription once the customer tops
			 * up or replaces the card.
			 */
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], '0000-00-00 00:00:00', $retry_count + 1, 'suspended');

			$this->addOrderHistory(
				$store,
				(int)$subscription['order_id'],
				$this->config->get('payment_chip_failed_order_status_id'),
				$this->language->get('text_renewal_suspended'),
				true
			);
		} else {
			$this->model_extension_chip_payment_chip->recordSubscriptionFailure(
				(int)$subscription['chip_subscription_id'], $next_retry, $retry_count + 1, 'active');

			$this->addOrderHistory(
				$store,
				(int)$subscription['order_id'],
				$this->config->get('payment_chip_failed_order_status_id'),
				$this->language->get('text_renewal_failed') . ' ' . $reason . ' - ' . $this->language->get('text_renewal_retry') . ' ' . $next_retry,
				false
			);
		}

		return false;
	}

	/**
	 * Add an order history entry against the order's own store instance.
	 *
	 * The renewal order created by core's cron belongs to a different store
	 * instance than the one this controller runs in, so the history has to be
	 * written through that instance's model.
	 */
	private function addOrderHistory($store, int $order_id, $order_status_id, string $comment, bool $notify = false): void {
		$order_status_id = (int)$order_status_id;

		if (!$order_status_id) {
			return;
		}

		if ($store && isset($store->model_checkout_order)) {
			$store->model_checkout_order->addHistory($order_id, $order_status_id, $comment, $notify);

			return;
		}

		$this->load->model('checkout/order');

		$this->model_checkout_order->addHistory($order_id, $order_status_id, $comment, $notify);
	}

	/**
	 * The store instance the renewal order belongs to, or null.
	 */
	private function getStore() {
		if (!isset($this->session->data['order_id'])) {
			return null;
		}

		$this->load->model('checkout/order');

		$order_info = $this->model_checkout_order->getOrder((int)$this->session->data['order_id']);

		if (!$order_info) {
			return null;
		}

		$this->load->model('setting/store');

		return $this->model_setting_store->createStoreInstance(
			(int)$order_info['store_id'],
			(string)$order_info['language_code'],
			(string)$order_info['currency_code']
		);
	}
}
