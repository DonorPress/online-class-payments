<?php
use OnlineClassPayments\Client;
use OnlineClassPayments\ClientType;
use OnlineClassPayments\PaymentCategory;
use OnlineClassPayments\PaymentTemplate;
use OnlineClassPayments\CustomVariables; 
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
?>
<style>
@media print
{    
    #adminmenumain,#wpadminbar,.no-print, .no-print *
    {
        display: none !important;
    }
	body { background-color:white;}
	#wpcontent, #wpfooter{ margin-left:0px;}
	
}
</style>
<?php
$tabs=['cv'=>'Site Variables','email'=>'Email Templates','bak'=>'Backup/Restore'];
$active_tab=Client::show_tabs($tabs);

?>
<div id="pluginwrap">
	<?php

    if (PaymentTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>
    <h1>Settings: <?php print esc_html($tabs[$active_tab])?></h1><?php
    switch($active_tab){ 

        case "email": PaymentTemplate::list(); break; 
        case "bak":
            ?><form method="post" enctype="multipart/form-data">
                <h2>Backup</h2>
                <div>
                    <button name="Function" value="BackupOnlineClassPayments">Backup OnlineClassPayments Tables/Settings</button>
                </div>                                
                <hr>
                <h2>Restore</h2>                
                <div>
                <input type="file" name="fileToUpload" accept=".json">
                <button name="Function" value="RestoreOnlineClassPayments">Restore from File</button>
                Server Upload Limit: <?php print esc_html(ini_get("upload_max_filesize"));?>  <em>Caution - will remove current plugin Data</em>
                </div>
                <hr>
                <h2>Nuke Site</h2>
                <button name="Function" value="NukeOnlineClassPayments">Clear Out Online Class Data</button> - Useful for uninstalls or during testing.
                <!-- <h2>Load Test Data</h2>
                <button name="Function" value="LoadTestData">Load Test Records</button>  Records: <input type="number" name="records" value="20"/> - Useful for testing the plugin. This will add new donor and donations records (but not remove existing data). -->
            </form><?php
            break;       
        case "cv":  
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>