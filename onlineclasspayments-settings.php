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
$tabs=['cv'=>'Site Variables','email'=>'Email Templates'];
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
        case "cv":  
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>