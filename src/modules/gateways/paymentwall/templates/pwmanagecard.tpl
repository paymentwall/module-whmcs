<table class="table table-bordered">
	<thead>
		<tr>
			<th>Method</th>
			<th>Expires</th>
			<th>Action</th>
		</tr>
	</thead>
	<tbody>
		{if $tokens}
		{foreach from=$tokens  item=token}
		<tr>
			<td>{$token->card_type} ending in {$token->cardlastfour}</td>
			<td>{$token->expiry_month}/{$token->expiry_year}</td>
			<td>
				<form action="" method="POST">
					<input type="hidden" name="brick_token" value="{$token->id}">
					<button type="submit" onclick="return confirm('Do you want to remove this credit card?')" class="btn btn-danger">Delete</button>
				</form>
			</td>
		</tr>
		{/foreach}
		{else}
		<tr>
			<td colspan="3" style="text-align: center;">No credit card saved</td>
		</tr>
		{/if}
	</tbody>
</table>

<style>
	th, td {
		text-align: center;
	}
</style>
