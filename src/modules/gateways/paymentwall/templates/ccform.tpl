{if $success != true}
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
	<script src="https://api.paymentwall.com/brick/brick.1.3.js"></script>
	<script type="text/javascript">
		$(document).ready(function () {
			var publicKey = '{$publicKey}';
			{literal}
			var $form = $('#payment-form');
			var brick = new Brick({
				public_key: publicKey,
				form: {formatter: true}
			}, 'custom');

			$form.submit(function (e) {
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
			});
			{/literal}
		});
	</script>
	<div style="clear: both"></div>
{/if}
{if $success == true}
	<div class="text-center">
		<h1>Success</h1>
		<p>Your credit card payment was successful.</p>
		<p><a href="{$systemurl}viewinvoice.php?id={$invoiceid}&paymentsuccess=true" title="Invoice #{$invoiceid}">Click
				here</a> to view your paid invoice.</p>
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
</style>