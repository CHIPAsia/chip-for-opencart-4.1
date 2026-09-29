== Changelog ==

## [1.3.0] - 2026-09-30

### Fixed
- A recovered subscription was never billed again. `rearmDate()` returned an empty schedule, so a plan that came back `active` after a recovery payment kept `date_next = 0000-00-00 00:00:00` and was never selected by the renewal cron again. The helper now receives the loaded model and computes the next date.
- The renewal cron could charge a customer twice for one billing period. The due list was read before the per-subscription lock was taken, so two overlapping runs both saw the same row as due and both billed it. The row is re-read once the lock is held and the charge proceeds only if it is still due.

## [1.2.0] - 2026-09-29

### Added
- Dunning with a 1, 3 and 5 day retry ladder measured from the original due date, suspending after the fourth failed attempt. A suspended subscription keeps the stored card so it can be recovered.
- Claim-before-charge ordering, so a cron that runs twice in one window cannot charge a customer twice.
- Trial-aware billing: while trial cycles remain the charge is the trial price and the cycle steps by the trial schedule.
- `refunded_order_status_id` setting.

### Changed
- A dead or revoked card suspends the subscription at once instead of spending the whole retry ladder on a charge that can never succeed. The gateway documents `invalid_recurring_token` as "do not retry, re-prompt the buyer for a new card".
- A suspended subscription can be recovered. Previously the reactivation path only restored `status`, leaving `date_next` at zero, so the subscription read as active on every screen and was never billed again.
- A charge still settling at the acquirer (`pending_charge`) no longer counts as a failure. It is neither retried nor suspended, and the cycle is not consumed.
- This build now serves the whole 4.x line, so `chip-for-opencart`'s `4.0` folder is no longer required. See the README for the per-feature breakdown: payments work from 4.0.2.0, and renewals require 4.1.0.0 or later because earlier core releases never call a payment extension's cron controller.

### Fixed
- The renewal retry ladder compounded its offsets, landing on D+1, D+4 and D+9 instead of D+1, D+3 and D+5.
- A successful recovery permanently shifted the next billing date.
- The failure path re-loaded the payment model, and OpenCart's `Loader::model()` always constructs a new instance — so the gateway error code recorded by the charge was discarded before it was read, making the dead-card check unreachable.
- Removed dead card-type code that no template rendered.

## [1.1.0] - 2026-08-24

### Changed
- Clarified the OpenCart 4.0.x compatibility range in the README.

## [1.0.0] - 2025-10-29

### Added
- Initial release: CHIP payment gateway for OpenCart 4.1.x, with stored-card support.
