<?php

function paymentwall_config()
{
    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "Paymentwall"),
        "appKey" => array("FriendlyName" => "Project Key", "Type" => "text", "Size" => "20",),
        "secretKey" => array("FriendlyName" => "Secret Key", "Type" => "text", "Size" => "20",),
        "widget" => array("FriendlyName" => "Widget", "Type" => "text", "Size" => "5",),
        "isTest" => array("FriendlyName" => "Is Test", "Type" => "yesno", "Size" => "5",),
        "enableDeliveryApi" => array("FriendlyName" => "Enable Delivery Api", "Type" => "yesno", "Size" => "5",),
    );
    return $configarray;
}

function init_paymentwall_config($params)
{
    require_once(getcwd() . '/includes/api/paymentwall_api/lib/paymentwall.php');
    Paymentwall_Config::getInstance()->set(array(
        'api_type' => Paymentwall_Config::API_GOODS,
        'public_key' => $params['appKey'], // available in your Paymentwall merchant area
        'private_key' => $params['secretKey'] // available in your Paymentwall merchant area
    ));
}

function paymentwall_link($params)
{
    init_paymentwall_config($params);

    $widget = new Paymentwall_Widget(
        $params['clientdetails']['email'],
        $params['widget'],
        array(
            new Paymentwall_Product(
                (int)$params['invoiceid'],
                $params['amount'],
                $params['currency'],
                $params["description"],
                Paymentwall_Product::TYPE_FIXED
            )
        ),
        array_merge(
            array(
                'integration_module' => 'whmcs',
                'is_test' => (isset($params['isTest']) && $params['isTest'] != '') ? 1 : 0
            ),
            get_user_profile_data($params)
        )
    );
    $widgetUrl = $widget->getUrl();
    $code = '<form method=POST action="' . $widgetUrl . '"><a href="' . $widgetUrl .'"><img src="' . $params['systemurl'] . '/images/paymentwall/button_buy_white_yellow.png" alt="Paymentwall logo" height="34" width="153" /></a></form>';

    return $code;
}

function get_user_profile_data($params){

    return array(
        'customer[city]' => $params['clientdetails']['city'],
        'customer[state]' => $params['clientdetails']['fullstate'],
        'customer[address]' => $params['clientdetails']['address1'],
        'customer[country]' => $params['clientdetails']['countrycode'],
        'customer[zip]' => $params['clientdetails']['postcode'],
        'customer[username]' => $params['clientdetails']['userid'] ? $params['clientdetails']['userid'] : $params['clientdetails']['email'],
        'customer[firstname]' => $params['clientdetails']['firstname'],
        'customer[lastname]' => $params['clientdetails']['lastname'],
    );
}
