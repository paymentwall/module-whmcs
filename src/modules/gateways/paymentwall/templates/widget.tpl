{if $whmcsVer <= 5}
	{include file="$template/pageheader.tpl" showbreadcrumb=false}
{else}
	{include file="$template/includes/pageheader.tpl" showbreadcrumb=false}
{/if}

<div class="col-md-12 paymentwall-widget">
	{$iframe}
</div>