<?php
define("CLIENTAREA", true);
define("FORCESSL", true);

include("init.php");
require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');

$whmcs->load_function('clientarea');

$pagetitle = "Manage Credit Card (Paymentwall)";
initialiseClientArea($pagetitle);

$brickConfigs = getGatewayVariablesByName('brick');

if ($brickConfigs && $_SESSION['uid']) {
	Menu::addContext();
	Menu::secondarySidebar('invoiceList');
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$data = $_POST;
		$tokenId = $data['brick_token'];
		$token = getTokenById($tokenId);

		if ($token) {
			deleteToken($token->id, $_SESSION['uid']);
		}
	}
	$tokens = getTokensByUserId($_SESSION['uid']);
	$smartyvalues['tokens'] = $tokens;
} else {
    header("Location: " . $CONFIG['SystemURL'] . "/");
}

outputClientArea('/modules/gateways/paymentwall/templates/pwmanagecard.tpl');