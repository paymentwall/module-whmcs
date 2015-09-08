<?php
# Required File Includes
include("../../../init.php");
$whmcs->load_function('gateway');
$whmcs->load_function('invoice');

require_once(ROOTDIR . "/includes/api/paymentwall_api/lib/paymentwall.php");

$gateway = getGatewayVariables("paymentwall");

if (!$gateway["type"]) {
    die("Module Not Activated");
}

Paymentwall_Config::getInstance()->set(array(
    'api_type' => Paymentwall_Config::API_GOODS,
    'public_key' => $gateway['appKey'], // available in your Paymentwall merchant area
    'private_key' => $gateway['secretKey'] // available in your Paymentwall merchant area
));

$pingback = new Paymentwall_Pingback($_GET, $_SERVER['REMOTE_ADDR']);
$invoiceid = checkCbInvoiceID($pingback->getProductId(), $gateway["name"]);

if ($invoiceid && $pingback->validate()) {

    $orderData = mysql_fetch_assoc(select_query('tblorders', 'userid,id', array("invoiceid" => $invoiceid)));
    $userData = mysql_fetch_assoc(select_query('tblclients', 'email, firstname, lastname, country, address1, state, phonenumber, postcode, city, id', array("id" => $orderData['userid'])));

    if ($pingback->isDeliverable()) {

        addInvoicePayment($invoiceid, $pingback->getReferenceId(), null, null, 'paymentwall');

        // Check enable delivery request
        if (isset($gateway['enableDeliveryApi']) && $gateway['enableDeliveryApi'] != '') {

            $invoiceData = localAPI('getinvoice', array('invoiceid' => $invoiceid));
            $hostId = false;

            // Get invoice product
            foreach ($invoiceData['items']['item'] as $item) {
                if ($item['relid'] != 0 && $item['type'] == 'Hosting') {
                    $hostId = $item['relid'];
                }
            }

            // Get products detail
            if ($hostId && $hosting = mysql_fetch_assoc(select_query(
                    'tblhosting',
                    'tblhosting.id,tblhosting.username,tblproducts.autosetup,tblhosting.packageid',
                    array("tblhosting.id" => $hostId), false, false, 1,
                    "tblproducts ON tblhosting.packageid=tblproducts.id"
                ))
            ) {

                // Get Delivery data from DB
                $deliveryData = mysql_fetch_assoc(select_query('pw_delivery_data', '*', array(
                    "package_id" => $hosting['packageid'],
                    "user_id" => $userData['id'],
                    "username" => $hosting['username'],
                    "order_id" => $orderData['id'],
                    "status" => "unsent",
                )));

                if ($deliveryData) {
                    $data = array_merge(array(
                        'payment_id' => $pingback->getReferenceId(),
                        'status' => 'delivered',
                        'estimated_delivery_datetime' => date('Y/m/d H:i:s'),
                        'estimated_update_datetime' => date('Y/m/d H:i:s'),
                        'details' => 'Item will be delivered via email by ' . date('Y/m/d H:i:s')
                    ),
                        json_decode($deliveryData['data'], true));

                    send_delivery($data);

                    // Update status
                    update_query('pw_delivery_data', array(
                        'status' => 'sent',
                        'reference_id' => $pingback->getReferenceId(),
                        'invoice_id' => $invoiceid,
                        'data' => json_encode($data),
                        'updated_date' => time(),
                    ), array(
                        'id' => $deliveryData['id']
                    ));
                }
            }

        }
    } elseif ($pingback->isCancelable()) {
        $cancelStatus = mysql_fetch_assoc(select_query("tblorderstatuses", "title", array("showcancelled" => 1)));
        // Update payment status
        localAPI('updateinvoice', array(
            'invoiceid' => $invoiceid,
            'status' => $cancelStatus['title']
        ), 'admin');
    }
    echo 'OK';
} else {
    echo $pingback->getErrorSummary();
}

function send_delivery($data)
{
    $delivery = new Paymentwall_GenerericApiObject('delivery');
    $response = $delivery->post($data);
}

logTransaction($gateway["name"], $_GET, "");
die;
