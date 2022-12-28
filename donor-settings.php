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
$tabs=['cv'=>'Site Variables','email'=>'Email Templates','cat'=>'Donation Categories'];
$active_tab=Donor::show_tabs($tabs,$active_tab);
?>
<div id="pluginwrap">
	<?php
    if (DonationCategory::request_handler()) { print "</div>"; return;}	
    if (DonorTemplate::request_handler()) { print "</div>"; return;}  
    if (CustomVariables::request_handler()) { print "</div>"; return;}    
    ?>
    <h1>Settings: <?php print $tabs[$active_tab]?></h1><?php
    switch($active_tab){       
        case "cat":  DonationCategory::list(); break;
        case "email": DonorTemplate::list(); break;
        case "cv":  
        default:
            CustomVariables::form(); 
        break;
    }
    ?>		
</div>