<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for OpenCart 4.1.x

This module adds CHIP payment method option to your OpenCart 4.1.x.

## Compatibility

This module is supported and tested on OpenCart versions **4.0.2.x** through **4.1.0.x**.

### Which repository to use

OpenCart 4.x is split across two repositories:

| OpenCart version | Repository | Notes |
| --- | --- | --- |
| **4.0.2.0** – **4.1.0.x** | **this repository** (`chip-for-opencart-4.1`) | Uses the `getMethods()` payment entry point and the `account/payment_method` stored-card flow. |
| **4.0.0.0** – **4.0.1.1** | [`chip-for-opencart`](https://github.com/CHIPAsia/chip-for-opencart) (folder `4.0`) | Uses the older `getMethod()` payment entry point. |

Do not install this module on OpenCart **4.0.0.0 – 4.0.1.1**. Those releases call the payment
extension through `getMethod()`, which this module does not implement — checkout will fail with
a fatal error. Use the `4.0` build in `chip-for-opencart`, which implements both entry points,
instead.

## Installation

* [Download zip file of OpenCart plugin](https://github.com/CHIPAsia/chip-for-opencart-4.1/releases/latest/download/chip.ocmod.zip)
* Upload to the Extension Installer and Install
* Navigate to : **Extensions** -> **Payments**
* Click **Install**, for CHIP Payment Gateway.

## Configuration

Set the **Brand ID** and **Secret Key** in the plugins settings.

### Important Requirement

**Session SameSite Cookie Setting**: For the CHIP payment integration to work properly, merchants need to set the session samesite cookie to **Lax** instead of **Strict**. 

To configure this setting:
1. Navigate to **OpenCart Admin >> System >> Settings >> Stores >> Server**
2. Set the **Session SameSite Cookie** to **Lax**

![Session SameSite Cookie Setting](Lax-Settings.png)

This setting is required for the payment gateway redirects and callbacks to function correctly.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)
