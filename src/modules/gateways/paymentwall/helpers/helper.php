<?php
if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');

function get_period_type($recurringCycleUnits)
{
    $cycleUnits = strtoupper(substr($recurringCycleUnits, 0, 1));
    return ($cycleUnits == 'Y') ? Paymentwall_Product::PERIOD_TYPE_YEAR : Paymentwall_Product::PERIOD_TYPE_MONTH;
}