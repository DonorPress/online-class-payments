
<div id="pluginwrap">
	<?php
	if (Donation::request_handler()) { print "</div>"; return;} //important to do this first
	if (Donor::request_handler())  { print "</div>"; return;}
	if (DonationCategory::request_handler()) { print "</div>"; return;}
	global $donor_press_db_version;
	?>
	<h1>Donor Manager <span style="font-size:60%">Version: <?php print $donor_press_db_version;?></span></h1>
	<form method="get">
	<input type="hidden" name="page" value="<?php print $_GET['page']?>"/>
	<!-- <div class="auto-search-wrapper">
		<input type="text" id="basic" placeholder="type w">
	</div> -->
		Donor Search: <input id="donorSearch" name="dsearch" value="<?php print $_GET['dsearch']?>"/><button class="button-primary" type="submit">Go</button> <button class="button-secondary" name="f" value="AddDonor">Add New Donor</button>
	</form>
	<script>
		//https://tomik23.github.io/autocomplete/
		new Autocomplete("basic", {
		delay: 100,
		clearButton: true,
		howManyCharacters: 2,
		onSearch: ({ currentValue }) => {
			const api = `?donorAutocomplete=t&query=${encodeURI(
			currentValue
			)}`;
			return new Promise((resolve) => {
			fetch(api)
				.then((response) => response.json())
				.then((data) => {
				resolve(data);
				})
				.catch((error) => {
				console.error(error);
				});
			});
		},

		onResults: ({ matches }) =>
			matches.map((el) => `<li>>${el.Name}</li>`).join(""),
		}); //<a href="?page=donor-index&DonorId=${el.DonorId}"
	</script>

	<?php if (trim($_GET['dsearch'])<>''){
		$list=Donor::get(array("(UPPER(Name) LIKE '%".strtoupper($_GET['dsearch'])."%' 
		OR UPPER(Name2)  LIKE '%".strtoupper($_GET['dsearch'])."%'
		OR UPPER(Email) LIKE '%".strtoupper($_GET['dsearch'])."%'
		OR UPPER(Phone) LIKE '%".strtoupper($_GET['dsearch'])."%')","(MergedId =0 OR MergedId IS NULL)"));
		//print "do lookup here...";
		if ($list){
			print Donor::show_results($list,"",['DonorId',"Name","Name2","Email","Phone","Address"]);
		}else{
			Donor::display_error("No results found for: ".$_GET['dsearch']);
		}
				
	}?>

<form action="" method="post" enctype="multipart/form-data" style="border:1px solid gray; padding: 20px; margin-top:10px;">
	<h2>Upload Generic CSV file</h2>
  <input type="file" name="fileToUpload" accept=".csv,.xls,.xlsx"> 
  <input type="hidden" name="uploadGenericFile" value="true" checked/>
  <?php submit_button('Upload File','primary','submit',false) ?>
 </form>
	
<form action="" method="post" enctype="multipart/form-data" style="border:1px solid gray; padding: 20px; margin-top:10px;">
	<h2>Paypal CSV File</h2>	
	<div><em>Note: it is recommended setting up Paypal API integration access instead</em></div>
	Import Paypal Exported File: (.csv)
<input type="file" name="fileToUpload" id="fileToUpload" accept=".csv"> 
<input type="hidden" name="uploadSummary" value="true" checked/>

<?php submit_button('Upload','primary','submit',false) ?>
<br><a target="help" href="https://www.paypal.com/us/smarthelp/article/how-do-i-download-my-transaction-history-faq1007">Read how to download .csv transaction history from Paypal</a>	
</form>

