<?php
define("CLIENTAREA", true);
define("FORCESSL", true);

include("init.php");
require_once(ROOTDIR . '/modules/gateways/paymentwall/helpers/helper.php');

$whmcs->load_function('clientarea');

$pagetitle = "Manage Credit Card (Brick)";
initialiseClientArea($pagetitle);

$brick_config = getGatewayVariablesByName('brick');

if ($brick_config && $_SESSION['uid']) {
	Menu::addContext();
	Menu::secondarySidebar('invoiceList');
	if ($_SERVER['REQUEST_METHOD'] == 'POST') {
		$data = $_POST;
		$tokenId = $data['brick_token'];
		$token = get_token_by_tokenId($tokenId);

		if ($token && $token->user_id == $_SESSION['uid']) {
			delete_token_by_id($token->id);
		}
	}
	$tokens = get_tokens_by_user_id($_SESSION['uid']);
	$smartyvalues['tokens'] = $tokens;
} else {
    header("Location: " . $CONFIG['SystemURL'] . "/");
}

outputClientArea('/modules/gateways/paymentwall/templates/pwmanagecard.tpl');