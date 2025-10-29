<?php

$_['heading_title'] = 'CHIP  - Better Payment & Business Solutions';

$_['text_extension']   = 'Extensions';
$_['text_title'] = 'Online Banking / Credit Card / Debit Card / Duitnow QR (CHIP)';
$_['text_edit'] = 'Edit CHIP';
$_['text_success'] = 'Success: You have modified CHIP account details!';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_all_zones'] = 'All Zones';
$_['text_browse'] = 'Browse';
$_['text_clear'] = 'Clear';
$_['text_payment'] = 'Payment';
$_['text_chip'] = '<a href="https://www.chip-in.asia/" taget="_blank"><img src="'.HTTP_CATALOG . 'extension/chip/admin/view/image/payment/chip.png" alt="CHIP" title="CHIP" style="height: 14px;" /></a>';

$_['tab_general'] = 'General';
$_['tab_order_status'] = 'Order status & behavior';
$_['tab_miscellaneous'] = 'Miscellaneous';
$_['tab_checkout'] = 'Customize checkout';
$_['tab_troubleshoot'] = 'Troubleshoot';
$_['tab_report'] = 'Report';
$_['tab_token'] = 'Token';

$_['behavior_missing_order'] = 'Missing Order';
$_['behavior_cancel_order'] = 'Cancel Order';
$_['behavior_fail_order'] = 'Fail Order';

$_['entry_payment_name'] = 'Payment Name';
$_['entry_secret_key'] = 'Secret Key';
$_['entry_brand_id'] = 'Brand ID';
$_['entry_general_public_key'] = 'General Public Key';
$_['entry_due_strict'] = 'Purchase Due Strict';
$_['entry_due_strict_timing'] = 'Purchase Due Strict Timing';
$_['entry_geo_zone'] = 'Geo Zone:';
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort Order';
$_['entry_canceled_order_status'] = 'Canceled Order Status';
$_['entry_failed_order_status'] = 'Failed Order Status';
$_['entry_paid_order_status'] = 'Paid Order Status';
$_['entry_allow_instruction'] = 'Instruction';
$_['entry_instruction'] = 'CHIP Instructions';
$_['entry_time_zone'] = 'Time Zone';
$_['entry_debug'] = 'Debug logging';
$_['entry_convert_to_processing'] = 'Convert To Processing';
$_['entry_payment_method_whitelist'] = 'Payment Method Whitelist';
$_['entry_disable_success_redirect'] = 'Disable Success Redirect';
$_['entry_disable_success_callback'] = 'Disable Success Callback';
$_['entry_canceled_behavior'] = 'Canceled Order Behavior';
$_['entry_failed_behavior'] = 'Failed Order Behavior';

$_['help_payment_name'] = 'Name that will be displayed on checkout page';
$_['help_secret_key'] = 'Secret Key can be retrieved from CHIP Collect Dashboard';
$_['help_brand_id'] = 'Secret Key can be retrieved from CHIP Collect Dashboard';
$_['help_general_public_key'] = 'This public key will be filled automatically when the secret key is set correctly. It requires no configuration';
$_['help_geo_zone'] = 'Only customers in the selected geo zone will see this payment method';
$_['help_due_strict'] = 'This will prevent payment after passing due strict timing. Recommended to set to Yes';
$_['help_due_strict_timing'] = 'Set due strict timing in minutes. Setting 60 will make the payment timeout in 1 hour. If leave blank, default to 60 minutes';
$_['help_status'] = 'Whether to enable/disable CHIP payment gateway';
$_['help_sort_order'] = 'Payment gateway sorting on checkout page';
$_['help_canceled_order_status'] = 'Default to Canceled';
$_['help_failed_order_status'] = 'Default to Failed';
$_['help_paid_order_status'] = 'It can be %s';
$_['help_allow_instruction'] = 'To insturction on checkout page';
$_['help_instruction'] = 'This will show on checkout page before customer make payment';
$_['help_time_zone'] = 'This will dictate the timestamp zone on the invoice and receipt';
$_['help_convert_to_processing'] = 'This to allow payment if the store currency is set to other than MYR';
$_['help_payment_method_whitelist'] = 'Select which payment methods to allow. Leave empty to allow all methods.';
$_['help_disable_success_redirect'] = 'Default to No. Only tick yes if you are performing a test';
$_['help_disable_success_callback'] = 'Default to No. Only tick yes if you are performing a test';
$_['help_canceled_behavior'] = 'Missing Order is the default behavior. If you require the order status to be updated to canceled, change to Cancel Order';
$_['help_failed_behavior'] = 'Missing Order is the default behavior. If you require the order status to be updated to failed, change to Fail Order';

$_['error_permission'] = 'Warning: You do not have permission to modify CHIP!';
$_['error_secret_key'] = 'Error! You are required to set CHIP Secret Key';
$_['error_secret_key_invalid'] = 'Error! CHIP Secret Key is not valid';
$_['error_brand_id'] = 'Error! You are required to set Brand ID';
$_['error_due_strict_timing'] = 'Error! You are required to set Due Strict Timing';
$_['error_payment_name'] = 'Error! You are required to set Payment Name';
$_['error_instruction'] = 'Error! You are required to set CHIP Instructions';

$_['column_order'] = 'Order';
$_['column_chip_id'] = 'Purchase ID';
$_['column_status'] = 'Status';
$_['column_amount'] = 'Amount';
$_['column_environment'] = 'Environment';
$_['column_date_added'] = 'Date Added';
$_['column_customer'] = 'Customer';
$_['column_token_id'] = 'Token ID';
$_['column_card_type'] = 'Card Type';
$_['column_card_name'] = 'Card Name';
$_['column_card_number'] = 'Card Number';
$_['column_card_expire'] = 'Card Expire';
$_['text_no_results'] = 'No Results';
$_['text_pagination'] = 'Showing %d to %d of %d (%d Pages)';