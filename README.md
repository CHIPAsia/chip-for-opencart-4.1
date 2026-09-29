<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for OpenCart 4.x

This module adds CHIP payment method option to your OpenCart 4.x.

## Compatibility

This module is supported and tested on OpenCart versions **4.0.0.0** through **4.1.0.x**.

### Which repository to use

**Use this repository for all of OpenCart 4.x.** It implements both payment entry
points (`getMethod()` and `getMethods()`), so one build covers the whole 4.x line.

| OpenCart version | Payments | Subscription renewals |
| --- | --- | --- |
| **4.1.0.x** | ✅ | ✅ |
| **4.0.2.x** | ✅ | ❌ not wired up |
| **4.0.0.0 – 4.0.1.1** | ✅ | ❌ not possible |

Payments work across the entire range. OpenCart **4.0.0.0 – 4.0.1.1** calls the
payment extension through `getMethod()` and **4.0.2.0 and later** call
`getMethods()`; this module implements both against one shared implementation, so
checkout succeeds on either without a version-specific download.

**Subscription renewals require OpenCart 4.1.0.0 or later.** Renewals are driven by
OpenCart's own scheduler, which calls the payment extension back, and only 4.1.0.0
and later do that. On 4.0.x a customer can pay for a subscription product, but
nothing renews it — so if renewals are needed, use 4.1.0.x.

The `4.0` folder in [`chip-for-opencart`](https://github.com/CHIPAsia/chip-for-opencart)
is no longer required for new installs. Stores already running it keep working.

## Installation

* [Download zip file of OpenCart plugin](https://github.com/CHIPAsia/chip-for-opencart-4.1/releases/latest/download/chip.ocmod.zip)
* Upload to the Extension Installer and Install
* Navigate to : **Extensions** -> **Payments**
* Click **Install**, for CHIP Payment Gateway.

Keep the file named `chip.ocmod.zip`. OpenCart 4.x derives the extension code from
the filename, and this module's routes are resolved under `extension/chip/...` —
renaming the zip installs the files where no route can reach them.

## Configuration

Set the **Brand ID** and **Secret Key** in the plugins settings.

### Recurring payments

Renewals run from OpenCart's built-in cron, which must be configured on the store's
server:

```
php /path/to/opencart/cron.php
```

A failed renewal is retried after 1, 3 and 5 days, measured from the original due
date. After the fourth failed attempt the subscription is **suspended**; the stored
card is retained, so a later payment reactivates the subscription with a fresh
schedule. A card the gateway rejects as no longer valid suspends the subscription
immediately rather than retrying it.

### Important Requirement

**Session SameSite Cookie Setting**: For the CHIP payment integration to work properly, merchants need to set the session samesite cookie to **Lax** instead of **Strict**. 

To configure this setting:
1. Navigate to **OpenCart Admin >> System >> Settings >> Stores >> Server**
2. Set the **Session SameSite Cookie** to **Lax**

![Session SameSite Cookie Setting](Lax-Settings.png)

This setting is required for the payment gateway redirects and callbacks to function correctly.

## Other

See [CHANGELOG.md](CHANGELOG.md) for release history.

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
