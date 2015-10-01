<?php
define("CLIENTAREA", true);
define("FORCESSL", true);

include("init.php");
require_once(ROOTDIR . '/includes/api/paymentwall_api/lib/paymentwall.php');
$whmcs->load_function('gateway');
$whmcs->load_function('clientarea');
$whmcs->load_function('invoice');

$gateway = getGatewayVariables("brick");

$whmcsVer = substr($CONFIG['Version'], 0, 1);
if ($whmcsVer <= 5) {
    $gateways = new WHMCS_Gateways();
} else {
    $gateways = new WHMCS\Gateways();
}
$publicKey = $gateway['isTest'] ? $gateway['publicTestKey'] : $gateway['publicKey'];
Paymentwall_Config::getInstance()->set(array(
    'api_type' => Paymentwall_Config::API_GOODS,
    'public_key' => $publicKey, // available in your Paymentwall merchant area
    'private_key' => $gateway['isTest'] ? $gateway['privateTestKey'] : $gateway['privateKey'] // available in your Paymentwall merchant area
));

$pagetitle = $_LANG['clientareatitle'] . " - Pay via Brick (Powered by Paymentwall)";
initialiseClientArea($pagetitle, '', 'Pay via Brick');

# Check login status
if ($_SESSION['uid'] && isset($_POST['data']) && $post = json_decode(decrypt($_POST['data']), true)) {

    $smartyvalues = array_merge($smartyvalues, $post);
    $smartyvalues["data"] = $_POST['data'];
    $smartyvalues["whmcsVer"] = $whmcsVer;
    $smartyvalues["publicKey"] = $publicKey;
    $smartyvalues["processingerror"] = '';
    $smartyvalues["success"] = false;

    if ($_POST['frominvoice'] == "true" || $_POST['fromCCForm'] == 'true') {

        if ($whmcsVer <= 5) {
            $invoice = new WHMCS_Invoice();
        } else {
            $invoice = new WHMCS\Invoice();
        }
        $invoice->setID($_POST["invoiceid"]);
        $invoiceData = $invoice->getOutput();

        // Prepare form data
        $smartyvalues["client"] = $invoiceData['clientsdetails'];
        $smartyvalues['months'] = $gateways->getCCDateMonths();
        $smartyvalues['years'] = $gateways->getCCExpiryDateYears();
        $smartyvalues['invoice'] = $invoiceData;
        $smartyvalues['invoiceItems'] = $invoice->getLineItems();

        if ($_POST['fromCCForm'] == 'true') { # Check form submit & capture payment

            $cardInfo = array(
                'email' => $invoiceData['clientsdetails']['email'],
                'amount' => $post['amount'],
                'currency' => $post["currency"],
                'token' => $_POST['brick_token'],
                'fingerprint' => $_POST['brick_fingerprint'],
                'description' => $invoiceData['pagetitle']
            );

            $charge = new Paymentwall_Charge();
            $charge->create(array_merge(
                $cardInfo,
                brick_get_user_profile_data($invoiceData)
            ));
            $response = $charge->getPublicData();

            if ($charge->isSuccessful()) {
                if ($charge->isCaptured()) {
                    addInvoicePayment($_POST["invoiceid"], $charge->getId(), null, null, 'brick');
                    logTransaction($gateway["name"], $cardInfo, "Successful");
                } elseif ($charge->isUnderReview()) {
                    // decide on risk charge
                    logTransaction($gateway["name"], $cardInfo, "Unsuccessful");
                }
                $smartyvalues["success"] = true;
            } else {
                $error = json_decode($response, true);
                $smartyvalues["processingerror"] = '<li>' . $error['error']['message'] . '</li>';
                logTransaction($gateway["name"], $cardInfo, "Unsuccessful");
            }

        }

    } else { // User is logged in but they shouldn't be here (i.e. they weren't here from an invoice)
        header("Location: " . $CONFIG['SystemURL'] . "/clientarea.php?action=details");
    }
} else {
    header("Location: " . $CONFIG['SystemURL'] . "/");
}

outputClientArea("/modules/gateways/paymentwall/templates/ccform.tpl");

function brick_get_user_profile_data($params)
{
    return array(
        'customer[city]' => $params['clientsdetails']['city'],
        'customer[state]' => $params['clientsdetails']['fullstate'],
        'customer[address]' => $params['clientsdetails']['address1'],
        'customer[country]' => $params['clientsdetails']['countrycode'],
        'customer[zip]' => $params['clientsdetails']['postcode'],
        'customer[username]' => $params['clientsdetails']['userid'] ? $params['clientsdetails']['userid'] : $params['clientsdetails']['email'],
        'customer[firstname]' => $params['clientsdetails']['firstname'],
        'customer[lastname]' => $params['clientsdetails']['lastname'],
    );
}