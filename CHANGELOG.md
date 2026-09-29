== Changelog ==

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
