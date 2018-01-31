{if $success != true}
	{$formHTML}
	<div class="alert alert-danger" id="payment-errors" {if !$processingerror}style="display: none"{/if}>
		<strong>The following errors occurred:</strong>
		<ul id="error-list">
			{$processingerror}
		</ul>
	</div>
	<form class="form-horizontal" action="" method="POST" id="payment-form">
		<div class="col-md-5">
			<div id="invoiceIdSummary" class="invoice-summary">
				<h2 class="text-center">{$LANG.invoicenumber}{$invoiceid}</h2>

				<div class="invoice-summary-table">
					<table class="table table-condensed">
						<tr>
							<td class="text-center"><strong>{$LANG.invoicesdescription}</strong></td>
							<td width="150" class="text-center"><strong>{$LANG.invoicesamount}</strong></td>
						</tr>
						{foreach $invoiceItems as $item}
							<tr>
								<td>{$item.description}</td>
								<td class="text-center">{$item.amount}</td>
							</tr>
						{/foreach}
						<tr>
							<td class="total-row text-right">{$LANG.invoicessubtotal}</td>
							<td class="total-row text-center">{$invoice.subtotal}</td>
						</tr>
						{if $invoice.taxrate}
							<tr>
								<td class="total-row text-right">{$invoice.taxrate}% {$invoice.taxname}</td>
								<td class="total-row text-center">{$invoice.tax}</td>
							</tr>
						{/if}
						{if $invoice.taxrate2}
							<tr>
								<td class="total-row text-right">{$invoice.taxrate2}% {$invoice.taxname2}</td>
								<td class="total-row text-center">{$invoice.tax2}</td>
							</tr>
						{/if}
						<tr>
							<td class="total-row text-right">{$LANG.invoicescredit}</td>
							<td class="total-row text-center">{$invoice.credit}</td>
						</tr>
						<tr>
							<td class="total-row text-right">{$LANG.invoicestotaldue}</td>
							<td class="total-row text-center">{$invoice.total}</td>
						</tr>
					</table>
				</div>
			</div>
		</div>
		<div class="col-md-7" style="margin-top: 20px;">
			{if $sumBrickToken >= 1}
			<div class="paymwentwall-brick-token">
				<!-- list all token  -->
				<ul style="list-style-type: none; font-size: 18px;">
					{foreach from=$tokens  item=token}
					  	<li>
					  		<label name="brick-payment-token"><input type="radio" name="brick-payment-token" value="{$token->id}" /> {$token->card_type} ending in {$token->cardlastfour} (expires {$token->expiry_month}/{$token->expiry_year})</label>
					  	</li>
					{/foreach}
				  	<li>
				  		<label name="brick-payment-token"><input type="radio" name="brick-payment-token" value="new" /> Use a new payment method</label>
				  	</li>
				  	
				</ul>
			</div>
			{/if}
			<div class="paymwentwall-brick-form-new">
				<div style="margin-bottom: 20px">
					<h2 class="text-center">Credit Card Details</h2>
				</div>
				<div class="form-group cc-details">
					<label for="inputCardNumber" class="col-sm-4 control-label">Card Number</label>

					<div class="col-sm-7">
						<input type="text" data-brick="card-number" id="inputCardNumber" size="30" value="" autocomplete="off"
						       class="form-control newccinfo">
					</div>
				</div>
				<div class="form-group cc-details">
					<label for="inputCardExpiry" class="col-sm-4 control-label">Expiry Date</label>

					<div class="col-sm-8">
						<select data-brick="card-expiration-month" id="inputCardExpiry" class="form-control select-inline">
							{foreach from=$months item=month}
								<option value="{$month}">{$month}</option>
							{/foreach}
						</select>
						<select data-brick="card-expiration-year" id="inputCardExpiryYear" class="form-control select-inline">
							{foreach from=$years item=year}
								<option value="{$year}">{$year}</option>
							{/foreach}
						</select>
					</div>
				</div>
				<div class="form-group">
					<label for="cctype" class="col-sm-4 control-label">CVV/CVC2 Number</label>

					<div class="col-sm-7">
						<input type="text" data-brick="card-cvv" id="inputCardCvv" autocomplete="off"
						       class="form-control input-inline input-inline-100" maxlength="4">
						<button type="button" class="btn btn-link" data-toggle="popover"
						        data-content="<img src='{$systemurl}assets/img/ccv.gif' width='210' />"
						        data-original-title="" title="">
							Where do I find this?
						</button>
					</div>
				</div>
				
				{if $savedCards=='on' && !$isSubscription}
					<div class="form-group">
						<div class="col-sm-7" style="margin-left: 220px;">
							<label name="save-brick-payment-token"><input type="checkbox" name="save-brick-payment-token" value="true" /> Save card</label>
						</div>
					</div>
				{/if}
			</div>
			
			<div class="form-group">
				<div class="text-center">
					<input id="hiddenToken" name="brick_token" type="hidden"/>
					<input id="hiddenFingerprint" name="brick_fingerprint" type="hidden"/>
					<input class="btn btn-primary btn-lg" id="buttonPayNow" type="submit" value="Pay Now"/>
					<a href="{$systemurl}viewinvoice.php?id={$invoiceid}" class="btn">Cancel Payment</a>
				</div>
			</div>
		</div>
		<input name="fromCCForm" type="hidden" value="true"/>
		<input name="data" type="hidden" value="{$data}"/>
		<input name="invoiceid" type="hidden" value="{$invoiceid}"/>
	</form>
	<script src="https://api.paymentwall.com/brick/brick.1.4.js"></script>
	<script type="text/javascript">
		$(document).ready(function () {
			var sumBrickToken = {$sumBrickToken};
			var publicKey = '{$publicKey}';
			
			{literal}
			var $form = $('#payment-form');
			var brick = new Brick({
				public_key: publicKey,
				form: {formatter: true}
			}, 'custom');

			if (jQuery('input[name=brick-payment-token]').length > 1) {
		        jQuery('input:radio[name=brick-payment-token]:first').attr('checked', true);
		        jQuery('.paymwentwall-brick-form-new').hide();
	        }

	        jQuery('input[name=brick-payment-token]').click(function() {
	            if (jQuery(this).val() == 'new') {
	                jQuery('.paymwentwall-brick-form-new').show();
	                
	            } else {
	                jQuery('.paymwentwall-brick-form-new').hide();
	            }
	        });

			$form.submit(function (e) {
				if (jQuery('input[name=brick-payment-token]:checked').val() == 'new' || sumBrickToken == 0) {
					e.preventDefault();

					brick.tokenizeCard({
						card_number: $('#inputCardNumber').val(),
						card_expiration_month: $('#inputCardExpiry').val(),
						card_expiration_year: $('#inputCardExpiryYear').val(),
						card_cvv: $('#inputCardCvv').val()
					}, function (response) {
						if (response.type == 'Error') {
							// handle errors
							$("#error-list").html('');
							$("#error-list").append(function () {
								if (typeof response.error == 'string') {
									return '<li>' + response.error + '</li>';
								}
								for (var i in response.error) {
									return '<li>' + response.error.join("</li><li>") + '</li>';
								}
							});
							$("#payment-errors").show();
						} else {
							$form.append($('#hiddenToken').val(response.token));
							$form.append($('#hiddenFingerprint').val(Brick.getFingerprint()));
							$("#payment-errors").hide();
							$form.get(0).submit();
						}
					});
					return false;
				}
				return true
			});
			{/literal}
		});
	</script>
	<div style="clear: both"></div>
{/if}
{if $success == true}
	<div class="text-center">
		<h1>Thank you!</h1>
		<p>Your transaction may be put under review and the invoice will be marked as Unpaid for a few minutes. Please contact us if the status of the invoice remains unchanged afterward.</p>
		<p><a href="{$systemurl}viewinvoice.php?id={$invoiceid}" title="Invoice #{$invoiceid}">Click
				here</a> to view your invoice.</p>
	</div>
{/if}
<div class="text-center" style="margin:40px 0 0 0;">
	<img src="https://www.paymentwall.com/uploaded/files/brand_pw_logo_black.png">
</div>
<br/>
<div style="clear: both"></div>
<style>
	.invoice-summary {
		height: auto
	}
	label[name="brick-payment-token"], label[name="save-brick-payment-token"]{
		font-weight: 500;
	}
</style>
