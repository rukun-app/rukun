<?php

return [
    'payment_type' => 'WiFi requires a must_settle_in_period and pass_through payment type.',
    'unused_type' => 'Use a dedicated WiFi payment type without existing invoices.',
    'overlap' => 'Household WiFi subscription periods overlap.',
    'end_boundary' => 'Termination must start on the first day of a month after activation.',
    'end_billed' => 'Termination cannot precede an already billed period.',
    'inactive_period' => 'Subscription is inactive for this period.',
    'household_changed' => 'Household area or status changed; end this subscription and register the appropriate one.',
    'duplicate' => 'Package, customer or bill already exists.',
    'protected_receipt' => 'Reverse WiFi remittance/recovery and active gallon benefits before reversing this receipt.',
    'protected_ledger' => 'Use the WiFi finance reversal endpoint.',
    'ineligible' => 'Payment was not fully settled by the cutoff.',
    'expired' => 'Benefit period has expired.',
    'quota' => 'Insufficient gallon quota.',
    'reverse_claims' => 'Reverse outstanding claims first.',
    'unavailable' => 'Benefit is not available.',
    'already_remitted' => 'Bill already remitted.',
    'advance_disabled' => 'Advance is disabled for this package.',
    'recovery_source' => 'Recovery requires new, unconsumed receipt allocations.',
    'managed_invoice' => 'Use the WiFi customer billing endpoint.',
];
