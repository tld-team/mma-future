<?php
require __DIR__.'/bootstrap.php';
set_exception_handler(function($e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
function check_contact($condition,$message){if(!$condition){throw new RuntimeException($message);}echo "PASS: $message\n";}
wp_set_current_user(0);$input=['contact_nonce'=>wp_create_nonce('mmaf_contact'),'name'=>'Isolated test','email'=>'reply@example.com','reason'=>'general-enquiry','message'=>'Clearly marked isolated contact test.'];
$error=mmaf_contact_submit(array_merge($input,['contact_nonce'=>'invalid']));check_contact(is_wp_error($error)&&$error->get_error_code()==='expired','invalid nonce is rejected');
$error=mmaf_contact_submit(array_merge($input,['company_fax'=>'bot']));check_contact(is_wp_error($error)&&$error->get_error_code()==='spam','honeypot is rejected');
$error=mmaf_contact_submit(array_merge($input,['email'=>'bad','message'=>'','reason'=>'unknown']));$fields=$error->get_error_data()['fields'];check_contact(isset($fields['email'],$fields['message'],$fields['reason']),'email, required message and allowed reason are validated');
$error=mmaf_contact_submit(array_merge($input,['message'=>str_repeat('x',6001)]));check_contact(isset($error->get_error_data()['fields']['message']),'server rejects overlong messages');
\MMAF\DataEngine\Services\RegularImport\Transaction::begin();
try{
 foreach(acf_get_field_groups() as $group){foreach(acf_get_fields($group)?:[] as $field){if($field['name']==='contact_recipient'){update_field($field['key'],'test@example.com','option');}}}
 $_SERVER['REMOTE_ADDR']='isolated-contact-test-'.bin2hex(random_bytes(4));$captured=null;$fail=false;
 $mock=function($return,$attributes)use(&$captured,&$fail){$captured=$attributes;return !$fail;};add_filter('pre_wp_mail',$mock,10,2);
 $result=mmaf_contact_submit($input);check_contact(!is_wp_error($result)&&$result['success'],'success requires mail transport acceptance');
 check_contact($captured['to']==='test@example.com'&&in_array('Reply-To: reply@example.com',$captured['headers'],true),'private recipient and validated Reply-To are used');
 $result=mmaf_contact_submit($input);check_contact(is_wp_error($result)&&$result->get_error_code()==='rate_limit','rapid repeat submissions are rejected');
 $_SERVER['REMOTE_ADDR']='isolated-mail-failure-'.bin2hex(random_bytes(4));$fail=true;$result=mmaf_contact_submit($input);check_contact(is_wp_error($result)&&$result->get_error_code()==='mail_failed','transport failure never reports success');
 remove_filter('pre_wp_mail',$mock,10);
 $request=new WP_REST_Request('POST','/mma-future-site/v1/contact');$request->set_body_params(['contact_nonce'=>'invalid']);$response=rest_do_request($request);check_contact($response->get_status()===403,'REST endpoint uses the same server validation');
}finally{\MMAF\DataEngine\Services\RegularImport\Transaction::rollback();}
echo "No external message was sent by these isolated tests.\n";
