<?php
require_once 'classes/QuickBooks.php';
$qb=new Quickbooks();
?>
<div id="pluginwrap">
    <h2>Quickbooks Sync</h2><?php  
    if (Quickbooks::is_setup()){
        if ($qb->request_handler()){ }     
        else $qb->show();
    }else{ 
        $qb->missing_api_error();
    }
?>
</div>