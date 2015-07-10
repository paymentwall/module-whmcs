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
    $shippingParams = getShippingParams($params);
    $extraParams = array_merge(
        $shippingParams,
        array(
            'integration_module' => 'whmcs',
            'is_test' => (isset($params['isTest']) && $params['isTest'] != '') ? 1 : 0
        )
    );
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
        $extraParams
    );
    $widgetUrl = $widget->getUrl();
    $code = '<form method=POST action="' . $widgetUrl . '"><a href="' . $widgetUrl .'"><img src="' . $params['systemurl'] . '/images/paymentwall/button_buy_white_yellow.png" alt="Paymentwall logo" height="34" width="153" /></a></form>';

    return $code;
}

function getShippingParams($params)
{

    return array(
        'customer' => array(
            'email' => $params['clientdetails']['email'],
            'firstname' => $params['clientdetails']['firstname'],
            'lastname' => $params['clientdetails']['lastname'],
            'street1' => $params['clientdetails']['address1'],
            'street2' => $params['clientdetails']['address2'],
            'city' => $params['clientdetails']['city'],
            'state' => $params['clientdetails']['state'],
            'postcode' => $params['clientdetails']['postcode'],
            'country' => $params['clientdetails']['country'],
            'phone' => $params['clientdetails']['phonenumber']
        ),
        'shipping_address' => array(
            'firstname' => $params['clientdetails']['firstname'],
            'lastname' => $params['clientdetails']['lastname'],
            'company' => $params['companyname'],
            'street1' => $params['clientdetails']['address1'],
            'street2' => $params['clientdetails']['address2'],
            'city' => $params['clientdetails']['city'],
            'state' => $params['clientdetails']['state'],
            'postcode' => $params['clientdetails']['postcode'],
            'country' => $params['clientdetails']['country'],
            'phone' => $params['clientdetails']['phonenumber']
        ),
        'shipping_fee' => array(
            'amount' => 0,
            'currency' => $params['currency']
        )
    );
}

?>