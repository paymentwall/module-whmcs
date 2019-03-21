<?php

/*
 * Plugin Name: Paymentwall for WHMCS
 * Plugin URI: https://docs.paymentwall.com/modules/whmcs
 * Description: Official Paymentwall module for Prestashop.
 * Version: v1.6.2
 * Author: The Paymentwall Team
 * Author URI: http://www.paymentwall.com/
 * License: The MIT License (MIT)
 *
 */

if (!defined("WHMCS")) {
    exit("This file cannot be accessed directly");
}

function brick_config()
{
    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Brick (Powered by Paymentwall)"),
        "publicKey" => array("FriendlyName" => "Public Key", "Type" => "text", "Size" => "40"),
        "privateKey" => array("FriendlyName" => "Private Key", "Type" => "text", "Size" => "40"),
        "publicTestKey" => array("FriendlyName" => "Public Test Key", "Type" => "text", "Size" => "40"),
        "privateTestKey" => array("FriendlyName" => "Private Test Key", "Type" => "text", "Size" => "40"),
        "secretKey" => array("FriendlyName" => "Secret Key", "Type" => "text", "Size" => "40"),
        "isTest" => array("FriendlyName" => "Is Test", "Type" => "yesno", "Size" => "5"),
        "savedCards" => array("FriendlyName" => "Saved Cards", "Type" => "yesno", "Size" => "5"),
    );

    return $configarray;
}

function init_brick_config($params)
{
    require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
    Paymentwall_Config::getInstance()->set(array(
        'api_type' => Paymentwall_Config::API_GOODS,
        'public_key' => $params['isTest'] ? $params['publicTestKey'] : $params['publicKey'], // available in your Paymentwall merchant area
        'private_key' => $params['isTest'] ? $params['privateTestKey'] : $params['privateKey'] // available in your Paymentwall merchant area
    ));
}

function brick_link($params)
{
    init_brick_config($params);
    # Invoice Variables
    $invoiceid = $params['invoiceid'];

    # Enter your code submit to the gateway...
        $code = '<form method="post" action="' . $params['systemurl'] . '/brickccform.php">
            <input type="hidden" name="data" value="' . encrypt(json_encode(array(
            'invoiceid' => $params['invoiceid'],
            'description' => $params['description'],
            'amount' => $params['amount'],
            'currency' => $params['currency']
        ))) . '" />
            <input type="hidden" name="invoiceid" value="' . $invoiceid . '" />
            <input type="hidden" name="frominvoice" value="true" />
            <button type="submit"><img src="' . $params['systemurl'] . '/images/paymentwall/brick_logo.png" alt="Pay via Brick" /></button>
            </form>';
    
    return $code;
}
