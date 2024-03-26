<?php 
use DonorPress\Donation;
use DonorPress\Donor;
use DonorPress\DonorType;
use DonorPress\DonationCategory;
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly   
function donorpress_upgrade(){
	global $donor_press_db_version;
	$current_db_version=get_option( "donor_press_db_version");

	if (!$current_db_version || version_compare($current_db_version, '0.0.3', '<')){
		donorpress_upgrade_003();
	}
	
	if (version_compare($current_db_version, '0.0.4', '<')){ 
		donorpress_upgrade_004();
	}
	
	if (version_compare($current_db_version, '0.0.5', '<')){ 
		donorpress_upgrade_005();
	}
	
	if (version_compare($current_db_version, '0.0.6', '<')){ 
		donorpress_upgrade_006();
	}
	
	if (version_compare($current_db_version, '0.0.7', '<')){ 
		donorpress_upgrade_007();
	}
	
	if (version_compare($current_db_version, '0.0.8', '<')){ 
		donorpress_upgrade_008();
	}
	
	if (version_compare($current_db_version, '0.0.9', '<')){ 
		donorpress_upgrade_009();
	}
	if (version_compare($current_db_version, '0.1.0', '<')){ 
		donorpress_upgrade_010();
	}

	if ($current_db_version){
		update_option( "donor_press_db_version", $donor_press_db_version );
	}else{
		add_option( "donor_press_db_version", $donor_press_db_version );
	}
	Donor::display_notice("Donor Press Database Upgraded from ".$current_db_version." to ".$donor_press_db_version);
}

function donorpress_upgrade_003(){
	DonorType::create_table();
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".Donor::get_table_name()."` ADD COLUMN `TypeId` NOT NULL DEFAULT '0' AFTER `Country`;";
	$wpdb->query( $aSQL );
	$aSQL="ALTER TABLE `".Donation::get_table_name()."` CHANGE `NotTaxDeductible` `TransactionType` INT NULL DEFAULT '0' COMMENT '0=TaxExempt 1=NotTaxExcempt 2=Service -1=Expense';";
	$wpdb->query(  $aSQL );
}

function donorpress_upgrade_004(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonorType::get_table_name()."` CHANGE `QBAccountId` `QBItemId` INT NULL DEFAULT '0';";
	$wpdb->query( $aSQL );	
	$aSQL="ALTER TABLE `".Donation::get_table_name()."` ADD COLUMN `QBOPaymentId` INT NULL DEFAULT NULL AFTER `QBOInvoiceId`;";	
	$wpdb->query( $aSQL );
}

function donorpress_upgrade_005(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".Donation::get_table_name()."`
	CHANGE COLUMN `Gross` `Gross` DECIMAL(10,2) NOT NULL ,
	CHANGE COLUMN `Net` `Net` DECIMAL(10,2) NULL DEFAULT NULL ;";
	$wpdb->query( $aSQL );	
}

function donorpress_upgrade_006(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonationReceipt::get_table_name()."`
	ADD COLUMN `Subject` varchar(256);";
	$wpdb->query( $aSQL );	
}

function donorpress_upgrade_007(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".DonationCategory::get_table_name()."`
	ADD COLUMN `TransactionType` INT NULL DEFAULT NULL AFTER `TemplateId`;";
	$wpdb->query( $aSQL );	
}

function donorpress_upgrade_008(){
	//Transasition expenses to the 100 range.
	$wpdb=Donor::db();
	$uSQL="UPDATE `".Donation::get_table_name()."` SET TransactionType=100 WHERE TransactionType=2";
	$wpdb->query( $uSQL );		
}

function donorpress_upgrade_009(){
	$wpdb=Donor::db();
	$aSQL="ALTER TABLE `".Donor::get_table_name()."`
	ADD COLUMN `AddressStatus` tinyint(4) NOT NULL DEFAULT '1' COMMENT '-2 Unsubscripted -1=Returned 1=Active' AFTER `Country`;";
	$wpdb->query( $aSQL );	
}

function donorpress_upgrade_010(){
	$wpdb=Donor::db();
	$tableNames=donorpress_tables();
	foreach($tableNames as $table){	
		$class="DonorPress\\".$table;
		$newTableName=$class::get_table_name();
		$oldTableName=str_replace("donorpress_","",$newTableName);
		$aSQL="RENAME TABLE `".$oldTableName."` TO `".$newTableName."`;";
		$wpdb->query( $aSQL );		
	}
	$aSQL="UPDATE ".$wpdb->prefix."options SET option_name=REPLACE(option_name,'donation_','donorpress_') WHERE option_name LIKE 'donation_%';";
	$wpdb->query( $aSQL );

	// add an update Donation thank youtemplates
	$aSQL="UPDATE ".$wpdb->prefix."posts SET post_type='donorpress' WHERE post_type='donortemplate'";
	$wpdb->query( $aSQL );
}
