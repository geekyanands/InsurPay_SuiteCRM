<?php
//edited in git as a master
class rls_Payment_MasterHookAfterSave extends SugarBean {
	function createNewUser(SugarBean $bean, $event, $arguments) {
		$GLOBALS ['log']->fatal ( 'Inside' );
		$config = new Configurator ();
		$config->loadConfig ();
		$trigger_success_mail = $config->config ['trigger_success_mail'];
		$send_success_payment_email = $config->config ['send_payment_success_email'];
		$send_success_ach_payment = $config->config ['send_payment_success_email_to_vendor'];
		$callTowebservices = true;
		$batch_enable = $config->config ['fax_batch_enabled'];
		$send_success_ach_payment_notification = $config->config ['send_payment_success_email_to_vendor'];
		
		$payment_mode_details = new rls_Payment_Mode_Details ();
		
		$payment_mode_details->retrieve ( trim ( $bean->payment_mode_id ) );
		if (($bean->login1 && $bean->login2 && $bean->payment_method != 'Check') || ($bean->login1 && $bean->approval_prim && $bean->payment_method != 'Check') || ($bean->login2 && $bean->approval_sec && $bean->payment_method != 'Check')) {
			$bean->load_relationship ( 'rls_payment_master_rls_payment_master_extn' );
			$paymentext = $bean->rls_payment_master_rls_payment_master_extn->getBeans ();
			// BeanFactory::getBean('rls_payment_master_rls_payment_master_extn_c',$bean->id);
			
			foreach ( $paymentext as $pmext ) {
				if ($pmext->signed_flag_py != 1 || $pmext->signed_flag_sy != 1) {
					$callTowebservices = false;
				}
				// Defect fix 230
				if ($bean->payment_type == 'Contacts_and_Vendor' && (($bean->approval_prim == 1 && $bean->approval_sec == 0 && $pmext->signed_flag_py == 1 && $pmext->signed_flag_sy == 0) || ($bean->approval_prim == 0 && $bean->approval_sec == 1 && $pmext->signed_flag_py == 0 && $pmext->signed_flag_sy == 1) || ($bean->approval_prim == 0 && $bean->approval_sec == 0))) // 261 fix
{
					$callTowebservices = true;
				}
			}
		}
		//Added for IN-46 CR
		if ($bean->payment_type == 'Contacts_and_Mortgagee' && $bean->status == 'In Progress' ){
			$TotalLossbean = new rls_Loss_Drafts();
			$TotalLossbean->retrieve_by_string_fields(array(
				'rls_payment_master_id_c' => $bean->id
			));
			$GLOBALS ['log']->fatal ( 'Inside Mortgagee Payment Block TL ID: '.$TotalLossbean->id);
			 $GLOBALS ['log']->fatal ( 'Inside Mortgagee Payment Block');
			if ($TotalLossbean->lienholder_decision == 'Unknown'){
			$callTowebservices = false;
			}
		}//End
		if ($callTowebservices == true && $bean->status == 'In Progress' && $bean->deleted == 0) {
			
			$GLOBALS ['log']->fatal ( 'Inside Call to webservices true' . $callTowebservices );
			$config = new Configurator ();
			$config->loadConfig ();
			$api_key = $config->config ['esign_api_key'];
			// $filename = $config->config['esign_filename'];
			$email = $config->config ['esign_email'];
			$password = $config->config ['esign_password'];
			$aux_url = $config->config ['aux_service_url'];
			
			// Payment service block
			if ($bean->payment_method != 'Generate_Barcode') {
				if ($bean->payment_method != 'Let_Customer_Pickup') {
					$GLOBALS ['log']->fatal ( 'Entered PaymentService Block' );
					try {
						$client = new SoapClient ( $config->config ['aux_service_wsdl'], array (
								'location' => $config->config ['aux_service_url'],
								'trace' => 1,
								'exceptions' => 1 
						) );
						$r1 = $client->SubmitPaymentRequest ( array (
								'PaymentId' => $bean->id 
						) );
						$GLOBALS ['log']->fatal ( 'Payment Service Response: ' . json_encode ( $r1 ) );
						
						if ($r1->return->SubmitPaymentResponse->TxnStatus != '1') {
							// $GLOBALS['log']->fatal('EchoSign Error: Aux service is fault');
							$bean->error_code = 'Payment service Error Code XXX';
							$bean->error_message = 'Payment service Error. Response is fault';
							$bean->status = 'Error';
						} else {
							$bean->error_code = '';
							$bean->error_message = '';
							$bean->tranid = 'TestTranId-' . date ( 'Ymdhms' );
							$bean->status = 'Success';
						}
					} catch ( Exception $e ) {
						$GLOBALS ['log']->fatal ( 'Webservice is down' );
					}
					$bean->retrieve ( $bean->id ); // updating bean
				}
			}
		}
		
		
		
		if ($trigger_success_mail) {
			// We added this piece of code for triggering an email to the successful contact and vendor payments
			if (($bean->status == 'Issued') && ($bean->payment_type == 'Contacts_and_Vendor' || ($bean->payment_type == 'Contacts' && $bean->login1 && $bean->login2))) {
				$GLOBALS ['log']->fatal ( 'InsideTriggerEmail' );
				require_once ('include/SugarQueue/SugarJobQueue.php');
				$job = new SchedulersJob ();
				$job->name = "Trigger Email - {$bean->name}";
				$job->data = $bean->id;
				$job->target = "function::triggerSuccessMail";
				$job->assigned_user_id = $bean->assigned_user_id;
				$jq = new SugarJobQueue ();
				$jobid = $jq->submitJob ( $job );
			}
		}
		// Added by Divya to trigger success email notification to vendor on approval with ACH payment
		if ($send_success_ach_payment_notification) {
			if (($bean->status == 'Success' && $bean->payment_type == 'Contacts_and_Vendor' && $payment_mode_details->payment_mode == 'ACH') && ($bean->approval_prim == 1 || $bean->approval_sec == 1)) {
				$GLOBALS ['log']->fatal ( 'Inside Send success email job to Vendor' );
				require_once ('include/SugarQueue/SugarJobQueue.php');
				$job = new SchedulersJob ();
				$job->name = "Send Success Email Notification To Vendor-{$bean->name}";
				$job->data = $bean->id;
				$job->target = "function::sendSuccessEmailNotificationVendorOnArroval";
				$job->assigned_user_id = $bean->assigned_user_id;
				$jq = new SugarJobQueue ();
				$jobid = $jq->submitJob ( $job );
			}
		}
		
		if (($bean->status == 'Issued') && $bean->payment_method == 'Virtual_Card' && ($bean->vc_delivery_method == 'Fax' || $bean->vc_delivery_method == 'eMail') && $batch_enable == 1) {
			
			$GLOBALS ['log']->fatal ( 'InsideFaxJob' );
			// Sending Fax - to customers if payment mode is virtual card and vc_delivery_method is Fax
			require_once ('include/SugarQueue/SugarJobQueue.php');
			$job = new SchedulersJob ();
			$job->name = "Sending Fax - {$bean->name}";
			$job->data = $bean->id;
			$job->target = "function::sendVirtualCardDocuments";
			$job->assigned_user_id = $bean->assigned_user_id;
			$jq = new SugarJobQueue ();
			$jobid = $jq->submitJob ( $job );
		}
			//Commented to disable from job queue and Scheduled in UI - 28-july- 2018
		if ($send_success_payment_email) {
			
		if (($bean->status == 'Success' || $bean->status == 'Issued') && ($bean->payment_type == 'Contacts' || $bean->payment_type == 'Contacts_and_Mortgagee' || $bean->payment_type == 'Vendor' || $bean->payment_type == 'Contacts_and_Vendor')) {
			
			$GLOBALS ['log']->fatal ( 'Inside Send success email job' );
			require_once ('include/SugarQueue/SugarJobQueue.php');
			$job = new SchedulersJob ();
			$job->name = "Send Success Email Notification-{$bean->name}";
			$job->data = $bean->id;
			$job->target = "function::sendSuccessEmailJob";
			$job->assigned_user_id = $bean->assigned_user_id;
			$jq = new SugarJobQueue ();
			$jobid = $jq->submitJob ( $job );
		}
			}

		//Update the status of the Prepaid_Card child payment if its parent already having Success Status :Sukesh :2019-06-20
		if (!empty($bean->stored_payment_method) && $bean->status == 'Issued' && ($payment_mode_details->payment_mode =='Prepaid_Card' )) {

			$payment_master = BeanFactory::getBean ( 'rls_Payment_Master' );

			$payment_master_list = $payment_master->get_full_list ( '', "(  rls_payment_master.status IN ( 'Success','Stopped') AND (rls_payment_master.cleared_date != null OR rls_payment_master.cleared_date != '') AND  rls_payment_master.payment_mode_id ='" . $bean->payment_mode_id . "')" );
			if($payment_master_list != null)
			{
				 $GLOBALS ['log']->fatal ( 'After save: Inside');
				 $update = "UPDATE rls_payment_master set status = 'Success', cleared_date = now() WHERE id = '". $bean->id ."'";
				 $GLOBALS ['db']->query( $update );
			}
		}

		//Update the cleared date if the payment got Success: Sukesh
                if ($bean->status == 'Success' && empty($bean->cleared_date) && ($bean->payment_method == 'PayPal' || $bean->payment_method == 'Venmo' || $bean->payment_method == 'Electronic' || $payment_mode_details->payment_mode == 'Debit_Card')){
                        $update = "UPDATE rls_payment_master set cleared_date = now() WHERE id = '". $bean->id ."'";
                        $GLOBALS ['db']->query( $update );
                }
		
		/*if ($bean->status == 'Issued' && $bean->payment_method == 'Virtual_Card' && empty ( $payment_mode_details->pc_cvv )) {
			
			require_once ('include/SugarQueue/SugarJobQueue.php');
			$job = new SchedulersJob ();
			$job->name = "Virtual Card CVV - {$bean->name}";
			$job->data = $bean->id;
			$job->target = "function::getCardCVV";
			$job->assigned_user_id = $bean->assigned_user_id;
			$jq = new SugarJobQueue ();
			$jobid = $jq->submitJob ( $job );
			// end of getCVV
		}*/
		if ($bean->status == 'Error' && (! empty ( $bean->pm_groupid ) || trim ( $bean->pm_groupid ) != '')) {
			$GLOBALS ['log']->fatal ( 'Add Group Payment Job' );
			require_once ('include/SugarQueue/SugarJobQueue.php');
			$job = new SchedulersJob ();
			$job->name = "Group Payment Stop - {$bean->name}";
			$job->data = $bean->pm_groupid;
			$job->target = "function::groupStopPayment";
			$job->assigned_user_id = $bean->assigned_user_id;
			$jq = new SugarJobQueue ();
			$jobid = $jq->submitJob ( $job );
		}
	}
}
