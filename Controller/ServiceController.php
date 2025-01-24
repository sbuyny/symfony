<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Session\Session;
use AppBundle\Entity\RechargeLogs;
use AppBundle\Entity\UsedReferalKeys;
use AppBundle\Entity\SmsTemplate;

/**
 * Service controller.
 */
class ServiceController extends Controller
{
    const STATUS_PUBLIC = 0;
    const STATUS_WAITING = 1;
    const STATUS_REJECTED = 2;

    /**
    * @Route("/service/topup-recharge/", name="topup_recharge")
    * @Method({"GET","POST"})
    */
    public function rechargeAction()
    {
         $session = new Session();

         $user_session_id = $_POST['user_id'];
         $rechargeSession = $_POST['recharge_session'];

         $id = $_POST['id'];

         $em = $this->getDoctrine()->getManager();

         $value = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);

         if($value){
            $transDetails   = array();
            $totalRecharges = count($value);
            $success_count   = 0;
            $failure_count  = 0;
            $successIds   = array();
            $failureIds   = array();

            if($value->getStatus()==0){
                    // TransferTo account credentials
                    $login = "TestLogin";
                    $password = "11111111111";
                    // MD5 calculation
                    $key                   = time();
                    $md5                   = md5($login.$password.$key);
                    $msisdn                = 'TestLogin';
                    $action                = 'topup';  // {'simulation','topup'}
                    $delivered_amount_info = 1;
                    $phone_num             = $value->getExtensionCode().$value->getNum();
                    $destination_msisdn    = $phone_num;
                    $product1               = $value->getProductName();
                    $product2               = explode(" ",$product1);
                    $product = $product2[0];

                    //sms details
                    $sender_sms_bool = 'no'; //whether to send sms to sender or not
                    $recipient_sms_bool = 'no'; //whether to send sms to recipient or not

                    $sender_message = urlencode($value->getSenderSms());
                    $recipient_message = urlencode($value->getReceiverSms());

                    $sender_num = $value->getSenderNumber();
                    $rec_num    = $value->getReceiverNumber();


                    if($sender_num != ""){
                        $sender_sms_bool = 'yes';
                        $msisdn = $sender_num;
                    }
                    if($rec_num != ""){
                        $recipient_sms_bool = 'yes';
                    }

                    $data = "";

                    $url =
                    "https://fm.transfer-to.com/cgi-bin/shop/topup?login=TestLogin"."&key=".$key
                        ."&md5=".$md5."&delivered_amount_info=".$delivered_amount_info
                        ."&destination_msisdn=".$destination_msisdn."&msisdn=".$destination_msisdn
                        ."&product=".$product."&action=".$action."&send_sms=".$recipient_sms_bool
                        ."&sms=".$recipient_message."&sender_sms=".$sender_sms_bool."&sender_text=".$sender_message;

                    $responses = split("\n", file_get_contents($url) );

                    if($responses){

                        $resultArray = $this->processArray($responses);
                        foreach ($resultArray as $key => $res) {

                            if(array_key_exists('error_code', $res) == true){

                                if($res['error_code']==0){

                                    //set status as success in rechargeLog if successfull transaction 
                                    $jsonData = json_encode($resultArray);
                                    if($value->getUserId() == ""){
                                    //check whether this is send topup functionality
                                        $value->setUserId($user_session_id);
                                    }
                                    $value->setResponseText($jsonData);
                                    $value->setStatus(2);
                                    $value->setModifiedDate();
                                    $em->flush();

                                    $success_count = $success_count+1;
                                    $successIds[] = $value->getId();
                                    return new Response('success');
                                }
                                else{
                                    $jsonData = json_encode($resultArray);

                                    $value->setUserId($user_session_id);
                                    $value->setResponseText($jsonData);
                                    $value->setStatus(0);
                                    $value->setModifiedDate();
                                    $em->flush();
                                    
                                    $failure_count = $failure_count+1;
                                    $failureIds[] = $value->getId();


                                    $refundUser = $em->getRepository('AppBundle:PaymentTransactions')->findOneBySessionValue($rechargeSession);

                                    $refundUserId = $refundUser->getUserId();

                                    $userWallet = $this->getUserWallet($refundUserId);
                                    
                                    $walletCurrency = $userWallet->getWalletCurrency();

                                    $conv_ratesArray = $this->getConversionRates();

                                    $walletDataConverted = $this->currencyConversionToAny($conv_ratesArray,$value->getOriginalCurrency(), $value->getOriginalPrice(), $walletCurrency);

                                    $refundamount = $walletDataConverted[0];

                                    $refundRecharge = $this->refundToWallet($refundUserId, $refundamount);

                                    return new Response('failed');
                                }

                            }

                        }
                        
                    }
                }
         }

         return $this->redirectToRoute('index');
    }

     /**
     * @Route("/service/recharge-success/", name="recharge_success")
     * @Method({"GET","POST"})
     */
     public function showRechargeSuccess(Request $request){
        $em = $this->getDoctrine()->getManager();
        $payment_system = '';
        $vals = $em->getRepository('AppBundle:SocialLoginSettings')->findAll();
        foreach($vals as $key=>$val){
            if($val->getId()==6)$payment_system=$val->getValue();
        }
        
        $session = new Session();

        $rechargeSession = $session->get('temp_session_user');

        $metadetails = $this->get('app.helper')->GetMetaDetails(31);

        $user_id = $session->get('user_id_b2c');

        $referlink =  $session->get('reference');

        $is_E_recharge = $session->get('erecharge_b2b_user_id');

        $em = $this->getDoctrine()->getManager();

        if($referlink){

            $default_country =  $session->get('default_country');

            $amount1 = $em->getRepository('AppBundle:SecurityCentre')->findOneById(11);

            $amount = $amount1->statusValue;

            $amountcur1 = $em->getRepository('AppBundle:SecurityCentre')->findOneById(9);

            $amountcur = $amountcur1->statusValue;

            if($default_country!=$amountcur){

                $amount = $this->currencyConversion($default_country,$amountcur,$amount);

            }

           $query = $em->createQueryBuilder()
            ->select('u.userId')
            ->from('AppBundle:ReferalKeys', 'u')
            ->where('u.userkey= :id')
            ->setParameter('id', $referlink);
            $user_exists = $query->getQuery()->getResult(); 
               
            $refer = $user_exists[0]['userId'];
           
            $wlt_det = $em->getRepository('AppBundle:UserWallet')->findOneByUser($refer);
            
            $orgamnt = $wlt_det->getWalletAmount();
           
            $orgamount = $orgamnt+$amount;
            
            if($wlt_det){

                $wlt_det->setWalletAmount($orgamount);

                $em->flush();

            }

            $logs = new UsedReferalKeys();

            $logs->setUserId($user_id);

            $logs->setReferalKey($referlink);

            $logs->setAmount($amount);

            $logs->setStatus(1);

            $em->persist($logs);

            $em->flush();

            $session->remove('reference');

        }
        
        $em = $this->getDoctrine()->getManager();

        $rechargeLog = $em->getRepository('AppBundle:RechargeLogs')->findBySessionVal($rechargeSession);
        $transaction = $em->getRepository('AppBundle:PaymentTransactions')->findOneBySessionValue($rechargeSession);

        if($rechargeLog){
            //success mail
            if($payment_system != 'stripe')$this->sendSuccessMail($transaction->getId(),$request);
            if($is_E_recharge){
                $session->remove('user_id');

                $session->remove('user_type_b2c');

                $session->remove('log_track_id');

                $session->remove('erecharge_b2b_user_id');

                $session->remove('temp_session_user');
            }
            
            $this->unsetSession();

            $user_session_id =  $session->get('dum_temp_session_user');

            if($user_session_id){
                $session->set('temp_session_user',$user_session_id);
                $session->remove('dum_temp_session_user');
                $s = $session->get('temp_session_user');
            }
            
            if($payment_system != 'stripe'){
                return $this->render('/index/recharge-topup.html.twig', array(
                        'data' => $rechargeLog,
                        'transactionData' => $transaction,
                        'title'  => 'Sucessfull Transaction',
                        'metadetails' => $metadetails
                    ));
            }
            else{
                $this->addFlash(
                    'notice',
                    'Payment successfully received !'
                );
                return $this->redirectToRoute('purchase_history');
            }
        }

        return $this->redirectToRoute('index');
     }

     private function sendSuccessMail($trans_id,$request){
        $em = $this->getDoctrine()->getManager();

        $transDetails  = $em->getRepository('AppBundle:PaymentTransactions')->findOneBy(array(
                'id' => $trans_id,
                'status' => 2
            ));
        if($transDetails){
            $sessionId = $transDetails->getSessionValue();
            $user_id = $transDetails->getUserId();
            $userDetails = $em->getRepository('AppBundle:Users')->findOneById($user_id);

            $sendMailToUser = $this->sendMailToUser($sessionId,$userDetails,$request);
            $sendMailToAdmin = $this->sendMailToAdmin($sessionId,$request);
            return 1;
        }
        return 0;
     }

     private function sendMailToAdmin($sessionId,$request){
        $em = $this->getDoctrine()->getManager();

        $adminUsers = $em->getRepository('AppBundle:AdminUsers')->findOneBy(array(
            'parentId' => 0,
            'userType' => 0
            ));
        if($adminUsers){
            $to = $adminUsers->getEmail();
            $subject = "Paydez - New Recharge Invoice";
            $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneByTemplateName($subject);
            $host = $request->getHost();
            $url = "http://".$host.$this->generateUrl("recharge_invoice",array('id' => $sessionId));
            $emailBody = $emailTemplate->getTemplateContent();
            $emailBody = str_replace('{URL}', $url , $emailBody);
            $actionEmail = $this->get('app.helper')->sendMail($subject, $emailBody, $to);
            return 1;
        }
        return 0;
     }
     
     private function sendMailToUser($sessionId,$user,$request){
         $em = $this->getDoctrine()->getManager();
         $parentId = $user->getParentId();
         $userDetails = $em->getRepository('AppBundle:UserDetails')->findOneByUser($user);
         $to = $userDetails->getEmail();
         $subject = "Paydez - User Recharge Invoice";
         $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneByTemplateName($subject);
         $host = $request->getHost();
         $url = "http://".$host.$this->generateUrl("recharge_invoice",array('id' => $sessionId));
         $emailBody = $emailTemplate->getTemplateContent();
         $emailBody = str_replace('{URL}', $url , $emailBody);
         $emailBody = str_replace('{USERNAME}', $user->getUserName() , $emailBody);
         $actionEmail = $this->get('app.helper')->sendMail($subject, $emailBody, $to);
     }


     function currencyConversion($default_country,$amountcur,$amount){

        $em = $this->getDoctrine()->getManager();

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $default_currency_cd = $details->currencyCode;

        $details1 = $em->getRepository('AppBundle:Countries')->findOneById($amountcur);

        $amount_currency_cd = $details1->currencyCode;
        
        $endpoint = 'live';
        $access_key = '11111111111111111111111';

        // Initialize CURL:
        $ch = curl_init('http://apilayer.net/api/'.$endpoint.'?access_key='.$access_key.'');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Store the data:
        $json = curl_exec($ch);
        curl_close($ch);

        // Decode JSON response:
        $exchangeRates = json_decode($json, true);

        if($default_currency_cd!=$amount_currency_cd){
            
            $currncy_change = "1";

            $con = "USD"."$amount_currency_cd";

            if($default_currency_cd!="USD"){
                $con1  = "USD".$default_currency_cd;
                $rate1 = $exchangeRates['quotes'][trim($con)];
                $rate2 = $exchangeRates['quotes'][trim($con1)];
                $rate  = $rate1/$rate2;
            }else{
                $rate = $exchangeRates['quotes'][trim($con)];
            }
            $amount_currency_cd = $default_currency_cd;

        }else{
            $currncy_change = "0";
        }

        if($currncy_change=="1"){
            $amount = $amount/$rate;
            $amount = number_format($amount,2);
        }

        return $amount;
    }

     /**
     * @Route("/service/service-television/", name="service_television")
     * @Route("/service/send-television-recharge/{rechargesendrequest}/", name="sendtelevision")
     * @Route("/service/television-recharge-operator/{countryId}/{oprId}/", name="sendtelevisionfixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceTelevision(){
        $metadetails = $this->get('app.helper')->GetMetaDetails(32);
        $id=1;
        $api_key = '1111111111111111111';
        $api_secret = '11111111111111111111111';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';
        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();


        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
          $flag = $value->flag;
          $flag = strtolower($flag);
          $value->flag = $flag;

        }

        $session = new Session();
        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-television.html.twig',array(
                'title' => 'Television',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 1, //api_service_id (television) from db 
                'metadetails' => $metadetails
            ));
     }

     /**
     * @Route("/service/get-service-api-operators/", name="get_api_service_operators")
     * @Method({"GET","POST"})
     */
     public function getOperators(){
        
        $serviceId = $_POST['service_id'];
        $countryId = $_POST['country_id'];

        $api_key = '1111111111111111111111';
        $api_secret = '111111111111111111111';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators?country_id=$countryId&service_id=$serviceId");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);
        foreach ($array->operators as $key => $value) { 
            echo '<option value="'.$value->operator_id.'">'.$value->operator.'</option>';
        }

        exit;
     }
     
    /**
     * @Route("/service/get-api-products/", name="get_api_products")
     * @Method({"GET","POST"})
     */
     public function getProductsForOperatorFixedValueRecharges(){

        $api_key = '1111111111111111';
        $api_secret = '1111111111111111111111';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';
        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        $operator_id = $_POST['operator_id'];

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators/$operator_id/products");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);
        $html = "";
        $ratesArray = $this->getConversionRates();

        foreach($array->fixed_value_recharges as $key=>$value){
            $localRate = $this->currencyConvertor($ratesArray, $value->account_currency, $value->retail_price);
            $html.= '<a href="javascript:void(0)" onclick="setamount('.$localRate[0].', '.$value->local_value.', \''.$localRate[1].'\', \''.$value->local_currency.'\', '.$value->product_id.', \''.$value->product_name.'\', '.$value->operator_id.', \''.$value->operator.'\',this)"><div class="col-md-12 border-bt"><div class="col-md-3 col-xs-12 br_btm nopad"><div class="mn_amount" id="mn_amount"><h6>'.$value->product_name.'</h6></div></div><div class="col-md-5 col-xs-12 br_btm nopad"><div class="mn_validity"><h6 id="plan_desc">'.$value->product_short_desc.'</h6></div></div><div class="col-md-2 col-xs-12 br_btm nopad"><div class="mn_validity"><h6 id="plan_price">'.$value->local_value.'  '.$value->local_currency.'</h6><p>'.$localRate[0].' '.$localRate[1].'</p></div></div><div class="col-md-2 col-xs-12 nopad"><div class="mn_button">Select</div></div></div></a>';
        }
        
        echo $html."<>".$value->operator;
        exit;
     }  


     /**
     * @Route("/service/get-api-products-vouchers/", name="get_api_products_vouchers")
     * @Method({"GET","POST"})
     */
     public function getProductsForOperatorFixedValueVouchers(){

        $api_key = '1111111111111111';
        $api_secret = '1111111111111111111111';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        $operator_id = $_POST['operator_id'];

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators/$operator_id/products");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);
        $html = "";
        $ratesArray = $this->getConversionRates();

        foreach($array->fixed_value_vouchers as $key=>$value){
            $localRate = $this->currencyConvertor($ratesArray, $value->account_currency, $value->retail_price);
            $html.= '<a href="javascript:void(0)" onclick="setamount('.$localRate[0].', '.$value->local_value.', \''.$localRate[1].'\', \''.$value->local_currency.'\', '.$value->product_id.', \''.$value->product_name.'\', '.$value->operator_id.', \''.$value->operator.'\',this)"><div class="col-md-12 border-bt"><div class="col-md-3 col-xs-12 nopad"><div class="mn_amount" id="mn_amount"><h6>'.$value->product_name.'</h6></div></div><div class="col-md-5 col-xs-12 nopad"><div class="mn_validity"><h6 id="plan_desc">'.$value->product_short_desc.'</h6></div></div><div class="col-md-2 col-xs-12 nopad"><div class="mn_validity"><h6 id="plan_price">'.$value->local_value.'  '.$value->local_currency.'</h6><p>'.$localRate[0].' '.$localRate[1].'</p></div></div><div class="col-md-2 col-xs-12 nopad"><div class="mn_button"> Select</div></div></div></a>';
        }
        
        echo $html."<>".$value->operator;
        exit;
     } 


    /**
     * @Route("/service/get-api-products-payments/", name="get_api_products_payments")
     * @Method({"GET","POST"})
     */
     public function getProductsForOperatorVariableValuePayments(){

        $api_key = '11111111111111111111111';
        $api_secret = '111111111111111111111111111111';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        $operator_id = $_POST['operator_id'];

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators/$operator_id/products");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);
        $html = "";

        foreach($array->variable_value_payments as $key=>$value){
            // $localRate = $this->currencyConvertor($ratesArray, $value->local_currency, $value->local_value);
            $html .= '<option value="'.$value->product_id.'">'.$value->product_name.'</option>';
            
        }
        
        echo $html;
        exit;
     }

    private function processArray($array) {
        $resultArray = array();

        foreach ($array as $key => $value) {
           if($value){
               $val = explode('=', $value);
               $key = $val[0];
               $array_val = $val[1];

               $resultArray[] = array( $key => $array_val);
           }
        }
        return $resultArray;
    }

    /**
     * @Route("/service/get-electricity-bill/", name="getElectricityBill")
     * @Method("POST")
     */
    public function getElectricityBill(){
      $api_key = '111111111111111111';
      $api_secret = '11111111111111111111';
      $nonce = time();
      $host = 'https://api.transferto.com/v1.1/';

      $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
      // echo "hmac : $hmac".PHP_EOL;

      $account_number = $_POST['customer_id'];
      $product = $_POST['product'];

      // set up the curl resource
      $ch = curl_init();
      curl_setopt($ch, CURLOPT_URL, "$host/product/variable_value_payments/$product?account_number=$account_number");  
      // curl_setopt($ch, CURLOPT_URL, "$host/product/variable_value_payments/159?account_number=325500078395");  
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
          "X-TransferTo-apikey: $api_key",
          "X-TransferTo-nonce: $nonce",
          "X-TransferTo-hmac: $hmac",
      ));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      // execute the request
      $output = curl_exec($ch);
      $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
      // close curl resource to free up system resources
      curl_close($ch);

      $json_decoded_array = json_decode($output);
      $errors = 0;
      $error_code = 0;
      $message = '';
      $data = $json_decoded_array;

      if(isset($json_decoded_array->errors)){

        foreach ($json_decoded_array->errors as $key => $value) {
          $errors = $errors+1;
          $error_code = $value->code;
          $message = $value->message;
          $dataResponse = array(
            'errors'     => $errors,
            'error_code' => $error_code,
            'message'    => $message,
            'data'       => $json_decoded_array,
          );
        }
      }
      else{
          $ratesArray = $this->getConversionRates();
          $localRate = $this->currencyConvertor($ratesArray, $json_decoded_array->local_currency, $json_decoded_array->local_value);
          $html  = '<tr><td>Account Id</td><td>'.$account_number.'</td></tr>
          <tr><td>Name</td><td>'.$json_decoded_array->payment->name.'</td></tr>
          <tr><td>Amount</td><td>'.$json_decoded_array->local_value.' '.$json_decoded_array->local_currency.'</td></tr>
          <tr><td>Converted Amount</td><td>'.$localRate[0].' '.$localRate[1].'</td></tr>';

          $dataResponse = array(
            'errors'     => $errors,
            'error_code' => $error_code,
            'message'    => $message,
            'data'       => $html,
            'converted_amount' => $localRate[0],
            'converted_currency' => $localRate[1],
            'rowData'     => $json_decoded_array
          );
      }

      echo json_encode($dataResponse);
      exit;
    }

    /**
     * @Route("/service/set-service-recharge-details/", name="setDTHRechargeDetails")
     * @Method("POST")
     */
    public function setDTHRechargeDetails(){
      $RechargeLogs = new RechargeLogs();
      $service_type = $_POST['service_type'];
      $operator_id = $_POST['operator_id'];
      $operator_name = $_POST['operator_name'];
      $product_id = $_POST['product_id'];
      $product_name = $_POST['product_name'];
      $original_currency = $_POST['converted_currency'];
      $original_price = $_POST['converted_price'];
      $converted_currency = $_POST['converted_currency'];
      $converted_price = floatval($_POST['converted_price']);
      $email = $_POST['email'];

      //check for duplicate account number in the cart
      $checkAccountNumber = $this->get('app.helper')->checkDuplicateAccountNumber($_POST['account_number'],$service_type);
      if($checkAccountNumber){
         $this->addFlash(
                        'notice',
                        'This account number is already in the cart ! '
                    );
          echo 'success';
          exit();
      }

      if(isset($_POST['account_number'])){
        $account_number = $_POST['account_number'];
      }
      if(isset($_POST['extension_code'])){
        $account_number = $_POST['extension_code'].$_POST['account_number'];
        $RechargeLogs->setExtensionCode($_POST['extension_code']);
        $RechargeLogs->setNum($_POST['account_number']);
      }

      $session = new Session();
      $session_user =  $session->get('temp_session_user');
        if($session_user){
            $session_val = $session_user;
        }else{
             $session_val = md5($account_number.time());
             $session->set('temp_session_user',$session_val);
        }

     

      $em = $this->getDoctrine()->getManager();
      $service_type_obj = $em->getRepository('AppBundle:ApiServicesMaster')->findOneById($service_type);
      $login="TestLogin";
      $password="1111111111111";
      $key=time();
      $md5=md5($login.$password.$key);
      $url = "https://fm.transfer-to.com/cgi-bin/shop/topup?login=paydez"."&key=".$key."&md5=".$md5."&content=".$operator_id."&info_type=operator&action=pricelist";
                
      $responses = split("\n", file_get_contents($url) );
      $det = explode("=",$responses[1]);
      $country_id = $det[1];
      $api_country_id = $em->getRepository('AppBundle:Countries')->findOneByApiCountryId($country_id);
      $country = $api_country_id->id;
      $details = $this->getVatandTax($service_type,$country);

      if($details){
          $vat = $details[0]['vat'];
          $tax = $details[0]['tax'];
      }else{
        $vat = 0;
        $tax = 0;
      }

      $currentvat = $converted_price*($vat/100);
      $currenttax = $converted_price*($tax/100);
      $RechargeLogs->setVat($currentvat);
      $RechargeLogs->setTax($currenttax);
      $RechargeLogs->setOprId($operator_id);
      $RechargeLogs->setOprName($operator_name);
      $RechargeLogs->setCurrency($converted_currency);
      $RechargeLogs->setConvertedPrice($converted_price);
      $RechargeLogs->setOriginalPrice($original_price);
      $RechargeLogs->setOriginalCurrency($original_currency);
      $RechargeLogs->setStatus(0);
      $RechargeLogs->setCreatedDate();
      $RechargeLogs->setModifiedDate();
      $RechargeLogs->setAccountNumber($account_number);
      $RechargeLogs->setProductId($product_id);
      $RechargeLogs->setProductName($product_name);
      $RechargeLogs->setServiceType($service_type_obj);
      $RechargeLogs->setSessionVal($session_val);
      $RechargeLogs->setEmailId($email);
      $RechargeLogs->setTimeSend(time());
      
      if($email!=""){
        $RechargeLogs->setrechargeType(1);
        $sesion_val = md5($account_number.time());
        $userid = $session->get('user_id');
        $RechargeLogs->setUserId($userid);
        $RechargeLogs->setSessionVal($sesion_val);
      }else{
        $userid = $session->get('user_id');
        $RechargeLogs->setUserId($userid);
        $RechargeLogs->setrechargeType(0);
        $RechargeLogs->setSessionVal($session_val);
      }

      $em->persist($RechargeLogs);
      $em->flush();

      if($email!=""){
            $to      = $email;
			$session = new Session();
            $userid =  $session->get('user_id');
            $userSenderdetails = $em->getRepository('AppBundle:Users')->findOneById($userid);
            $userSender = $em->getRepository('AppBundle:UserDetails')->findOneByUser($userSenderdetails);
			$userReceipientdetails = $em->getRepository('AppBundle:UserDetails')->findOneByEmail($email);
			$username1 = $userReceipientdetails->getUser()->getfName();
			$username2 = $account_number;
            $serviceTypeName = $service_type_obj->getLabel(); 
			$senderEmail = $userSender->getEmail();
			$emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneById(43);

            if($emailTemplate){
				$subject = $emailTemplate->gettemplateName();
				$content = $emailTemplate->gettemplateContent();
				$content = str_replace('{YOUREMAIL}', $email , $content);
                $content = str_replace('{USEREMAIL}', $senderEmail , $content);
                $content = str_replace('{SERVICETYPE}', $serviceTypeName , $content);
                $content = str_replace('{ACCOUNTNUMBER}', $username2 , $content);
                echo $this->sendMail($subject, $content, $email);
            }
      }

      echo 'success';
      exit();
    }

    private function sendMail($subject, $emailBody, $to, $from='service@paydez.com'){

        $transport = \Swift_SmtpTransport::newInstance('paydez-com.mail.protection.outlook.com', 25);
        // Create the Mailer using your created Transport
        $mailer = \Swift_Mailer::newInstance($transport);
        // Create a message
        $message = \Swift_Message::newInstance($subject)
          ->setFrom(array($from => 'Paydez'))
          ->setTo(array($to))
          ->setBody($emailBody,'text/html');
        // Send the message
        $numSent = $this->get('mailer')->send($message);
        return $numSent;
    }

    private function getVatandTax($serviceid,$country){

        $em = $this->getDoctrine()->getManager();
        $query = $em->createQueryBuilder()
            ->select('u.vat,u.tax')
            ->from('AppBundle:Taxes', 'u')
            ->where('u.countryId = :id and u.serviceId = :value')
            ->setParameter('id', $country)
            ->setParameter('value', $serviceid);
        return $query->getQuery()->getResult();
    }


    /**
     * Set Recharge details of fixed_value_vouchers
     * @Route("/service/set-service-fixed-value-vouchers-details/", name="setVouchersDetails")
     * @Method("POST")
     */
    public function setVouchersDetails(){
      $RechargeLogs = new RechargeLogs();
      $service_type = $_POST['service_type'];
      $operator_id = $_POST['operator_id'];
      $operator_name = $_POST['operator_name'];
      $product_id = $_POST['product_id'];
      $product_name = $_POST['product_name'];
      $original_currency = $_POST['converted_currency'];
      $original_price = $_POST['converted_price'];
      $converted_currency = $_POST['converted_currency'];
      $converted_price = floatval($_POST['converted_price']);
      $sender_num = $_POST['sender_number'];
      $reciever_num = $_POST['reciever_number'];

      //check for duplicate account number in the cart
      $checkAccountNumber = $this->get('app.helper')->checkDuplicateAccountNumber($_POST['account_number'],$service_type);
      if($checkAccountNumber){
         $this->addFlash(
                        'notice',
                        'This account number is already in the cart ! '
                    );

          echo 'success';
          exit();
      }
      
      if(isset($_POST['account_number'])){
        $account_number = $_POST['account_number'];
      }

      if(isset($_POST['extension_code'])){
        $account_number = $_POST['extension_code'].$_POST['account_number'];
        $RechargeLogs->setExtensionCode($_POST['extension_code']);
        $RechargeLogs->setNum($_POST['account_number']);
      }

      $email = $_POST['email'];
      $session = new Session();
      $session_user =  $session->get('temp_session_user');

        if($session_user){
            $session_val = $session_user;
        }else{
             $session_val = md5($account_number.time());
             $session->set('temp_session_user',$session_val);
        }

      $em = $this->getDoctrine()->getManager();
      $service_type_obj = $em->getRepository('AppBundle:ApiServicesMaster')->findOneById($service_type);

      $login="TestLogin";
      $password="111111111111111";
      $key=time();
      $md5=md5($login.$password.$key);
      $url = "https://fm.transfer-to.com/cgi-bin/shop/topup?login=paydez"."&key=".$key."&md5=".$md5."&content=".$operator_id."&info_type=operator&action=pricelist";

      $responses = split("\n", file_get_contents($url) );
      $det = explode("=",$responses[1]);

      $country_id = $det[1];
      $api_country_id = $em->getRepository('AppBundle:Countries')->findOneByApiCountryId($country_id);
      $country = $api_country_id->id;

      $details = $this->getVatandTax($service_type,$country);

      if($details){
          $vat = $details[0]['vat'];
          $tax = $details[0]['tax'];
      }else{
            $vat = 0;
            $tax = 0;
      }

      $currentvat = $converted_price*($vat/100);

      $currenttax = $converted_price*($tax/100);

      $RechargeLogs->setVat($currentvat);
      $RechargeLogs->setUserId($session_user);
      $RechargeLogs->setTax($currenttax);
      $RechargeLogs->setSenderNumber($sender_num);
      $RechargeLogs->setReceiverNumber($reciever_num);
      $RechargeLogs->setOprId($operator_id);
      $RechargeLogs->setOprName($operator_name);
      $RechargeLogs->setCurrency($converted_currency);
      $RechargeLogs->setConvertedPrice($converted_price);
      $RechargeLogs->setOriginalPrice($original_price);
      $RechargeLogs->setOriginalCurrency($original_currency);
      $RechargeLogs->setStatus(0);
      $RechargeLogs->setCreatedDate();
      $RechargeLogs->setModifiedDate();
      $RechargeLogs->setAccountNumber($account_number);
      $RechargeLogs->setProductId($product_id);
      $RechargeLogs->setProductName($product_name);
      $RechargeLogs->setServiceType($service_type_obj);
      $RechargeLogs->setSessionVal($session_val);
      $RechargeLogs->setEmailId($email);
      $RechargeLogs->setTimeSend(time());
      
      if($email!=""){
        $RechargeLogs->setrechargeType(1);
        $sesion_val = md5($account_number.time());
        $RechargeLogs->setUserId($session->get('user_id'));
        $RechargeLogs->setSessionVal($sesion_val);
      }else{
        $userid = $session->get('user_id');
        $RechargeLogs->setUserId($userid);
        $RechargeLogs->setrechargeType(0);
        $RechargeLogs->setSessionVal($session_val);
      }

      $em->persist($RechargeLogs);
      $em->flush();

      if($email!=""){
            $to = $email;
			$session = new Session();
            $userid =  $session->get('user_id');
            $userSenderdetails = $em->getRepository('AppBundle:Users')->findOneById($userid);
            $userSender = $em->getRepository('AppBundle:UserDetails')->findOneByUser($userSenderdetails);
			$userReceipientdetails = $em->getRepository('AppBundle:UserDetails')->findOneByEmail($email);
			$username1 = $userReceipientdetails->getUser()->getfName();
			$username2 = $account_number;
            $serviceTypeName = $service_type_obj->getLabel(); 
			$senderEmail = $userSender->getEmail();
			$emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneById(43);

            if($emailTemplate){
				$subject = $emailTemplate->gettemplateName();
				$content = $emailTemplate->gettemplateContent();
				$content = str_replace('{YOUREMAIL}', $email , $content);
                $content = str_replace('{USEREMAIL}', $senderEmail , $content);
                $content = str_replace('{SERVICETYPE}', $serviceTypeName , $content);
                $content = str_replace('{ACCOUNTNUMBER}', $username2 , $content);
                echo $sendEmail = $this->sendMail($subject, $content, $email);
            }
        }
      
      echo 'success';
      exit();
    }

    /**
     * All Fixed Value Recharges done here
     * @Route("/service/dth-recharge/", name="service_recharge_dth")
     * @Method({"GET","POST"})
     */
    public function actionDTHRecharge(){
       $session = new Session();
       $user_session_id = $_POST['user_id'];
       $rechargeSession = $_POST['recharge_session'];
       $id = $_POST['id'];
       $em = $this->getDoctrine()->getManager();
       $value = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);

      if($value){
        $transDetails   = array();
        $totalRecharges = count($value);
        $success_count   = 0;
        $failure_count  = 0;
        $successIds   = array();
        $failureIds   = array();
          $api_key = '11111111111111111';
          $api_secret = '111111111111111';
          $nonce = time();
          $host = 'https://api.transferto.com/v1.1/';
          $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
          $account_number = $value->getAccountNumber();
          $product = $value->getProductId();

          //sms details
          $sender_sms_bool = 0;
          $recipient_sms_bool = 0;
          $sender_message = $value->getSenderSms();
          $recipient_message = $value->getReceiverSms();
          $sender_num = $value->getSenderNumber();
          $rec_num    = $value->getReceiverNumber();

          if($sender_num != ""){
            $sender_sms_bool = 1;
          }
          if($rec_num != ""){
            $recipient_sms_bool = 1;
          }

          $data_array = array(
            "account_number" => $account_number,
            "product_id" => $product,
            "external_id" => time(),
            "simulation" => 0,
            "sender_sms_notification" => $sender_sms_bool,
            "sender_sms_text" => $sender_message,
            "recipient_sms_notification" => $recipient_sms_bool,
            "recipient_sms_text" => $recipient_message,
            "sender" => array(
                "mobile" => $sender_num
            ),
            "recipient" => array(
                "mobile" => $rec_num
            )
          );

          $data = json_encode($data_array);
          // set up the curl resource
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL, "$host/transactions/fixed_value_recharges");  
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              "X-TransferTo-apikey: $api_key",
              "X-TransferTo-nonce: $nonce",
              "X-TransferTo-hmac: $hmac",
              'Content-Type: application/json',                                                                                
              'Content-Length: ' . strlen($data)
          ));
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
          curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

          // execute the request
          $output = curl_exec($ch);
          $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
          // close curl resource to free up system resources
          curl_close($ch);

          $array = json_decode($output);

          if(!isset($array->errors)){
            $value->setUserId($user_session_id);
            $value->setResponseText($output);
            $value->setStatus(2);
            $value->setModifiedDate();
            $em->flush();
            return new Response("success");
          }

            $value->setUserId($user_session_id);
            $value->setResponseText($output);
            $value->setStatus(0);
            $value->setModifiedDate();
            $em->flush();
            $refundUser = $em->getRepository('AppBundle:PaymentTransactions')->findOneBySessionValue($rechargeSession);
            $refundUserId = $refundUser->getUserId();
            $userWallet = $this->getUserWallet($refundUserId);
            $walletCurrency = $userWallet->getWalletCurrency();
            $conv_ratesArray = $this->getConversionRates();
            $walletDataConverted = $this->currencyConversionToAny(
                $conv_ratesArray,
                $value->getOriginalCurrency(),
                $value->getOriginalPrice(),
                $walletCurrency
            );
            $refundamount = $walletDataConverted[0];
            $refundRecharge = $this->refundToWallet($refundUserId, $refundamount);
      } 

      return new Response('failed');
    }


    /**
     * All Fixed Value Vouchers done here
     * @Route("/service/service-get-vouchers/", name="service_get_vouchers")
     * @Method({"GET","POST"})
     */
    public function actionGetVoucher(){

       $session = new Session();
       $user_session_id = $_POST['user_id'];
       $rechargeSession = $_POST['recharge_session'];
       $id = $_POST['id'];
       $em = $this->getDoctrine()->getManager();
       $value = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);
       $sender_num = $value->getSenderNumber();
       $reciever_num = $value->getReceiverNumber();

      if($value){
        $transDetails   = array();
        $totalRecharges = count($value);
        $success_count   = 0;
        $failure_count  = 0;
        $successIds   = array();
        $failureIds   = array();
        $account_number = $value->getAccountNumber();
        $product = $value->getProductId();

        //sms details
        $sender_sms_bool = 0;
        $recipient_sms_bool = 0;
        $sender_message = $value->getSenderSms();
        $recipient_message = $value->getReceiverSms();
        $sender_num = $value->getSenderNumber();
        $rec_num    = $value->getReceiverNumber();

        if($sender_num != ""){
            $sender_sms_bool = 1;
        }
        if($rec_num != ""){
            $recipient_sms_bool = 1;
        }

        if($value->getServiceType()->getId() == 10){
          $data_array = array(
            "account_number" => $account_number,
            "product_id" => $product,
            "external_id" => time(),
            "simulation" => 0,
            "sender_sms_notification" => $sender_sms_bool,
            "sender_sms_text" => $sender_message,
            "recipient_sms_notification" => $recipient_sms_bool,
            "recipient_sms_text" => $recipient_message,
            "sender" => array(
                "mobile" => $sender_num
            ),
            "recipient" => array(
                "mobile" => $rec_num
            )
          );

          $url_val = "variable_value_payments";
        }
        else{
          $data_array = array(
            "account_number" => $account_number,
            "product_id" => $product,
            "external_id" => time(),
            "simulation" => 0,
            "sender_sms_notification" => $sender_sms_bool,
            "sender_sms_text" => $sender_message,
            "recipient_sms_notification" => $recipient_sms_bool,
            "recipient_sms_text" => $recipient_message,
            "sender" => array(
                "mobile" => $sender_num
            ),
            "recipient" => array(
                "mobile" => $rec_num
            )
          );

          $url_val = "fixed_value_vouchers";
        }

          $api_key = '11111111111111111111111111111';
          $api_secret = '11111111111111111';
          $nonce = time();
          $host = 'https://api.transferto.com/v1.1/';
          $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

          $data = json_encode($data_array);
          // set up the curl resource
          $ch = curl_init();
          curl_setopt($ch, CURLOPT_URL, "$host/transactions/".$url_val);  
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
              "X-TransferTo-apikey: $api_key",
              "X-TransferTo-nonce: $nonce",
              "X-TransferTo-hmac: $hmac",
              'Content-Type: application/json',                                                                                
              'Content-Length: ' . strlen($data)
          ));
          curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");                                                                     
          curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

          // execute the request
          $output = curl_exec($ch);
          $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
          // close curl resource to free up system resources
          curl_close($ch);

          $array = json_decode($output);

          if(!isset($array->errors)){
            $value->setUserId($user_session_id);
            $value->setResponseText($output);
            $value->setStatus(2);
            $value->setModifiedDate();
            $em->flush();

            return new Response("success");
          }

            $value->setUserId($user_session_id);
            $value->setResponseText($output);
            $value->setStatus(0);
            $value->setModifiedDate();
            $em->flush();

            $refundUser = $em->getRepository('AppBundle:PaymentTransactions')->findOneBySessionValue($rechargeSession);

            $refundUserId = $refundUser->getUserId();

            $userWallet = $this->getUserWallet($refundUserId);
            $walletCurrency = $userWallet->getWalletCurrency();

            $conv_ratesArray = $this->getConversionRates();

            $walletDataConverted = $this->currencyConversionToAny($conv_ratesArray,$value->getOriginalCurrency(), $value->getOriginalPrice(), $walletCurrency);

            $refundamount = $walletDataConverted[0];

            $refundRecharge = $this->refundToWallet($refundUserId, $refundamount);
      } 

      return new Response('failed');
    }

    private function getUserWallet($user_id){
        $em = $this->getDoctrine()->getManager();

        $userWallet = $em->getRepository('AppBundle:UserWallet')->findOneByUserId($user_id);

        return $userWallet;
    }

    /*
    * convert to any currency
    */
    private function currencyConversionToAny($conv_ratesArray, $source_currency, $source_amount , $destinationCurrency){

            $exchangeRates = json_decode($conv_ratesArray,true);

            $default_currency = $destinationCurrency;

            if($default_currency!=$source_currency){
    
                $currncy_change = "1";

                $con = "USD"."$source_currency";

                if($default_currency!="USD"){

                    $con1  = "USD".$default_currency;

                    $rate1 = $exchangeRates['quotes'][trim($con)];

                    $rate2 = $exchangeRates['quotes'][trim($con1)];

                    $rate  = $rate1/$rate2;
                
                }else{
                    $rate = $exchangeRates['quotes'][trim($con)];
                }

                $source_currency = $default_currency;

            }else{

                $currncy_change = "0";
            }

            $org_price = $source_amount;

            $org_currency = $source_currency;

            if($currncy_change=="1"){

                $source_amount = $source_amount/$rate;

                $source_amount = round($source_amount,2);
            }

            return array($source_amount,$default_currency);
    }

    /**
    * @Route("/service/service-data-bundle/", name="service_databundle")
    * @Route("/service/send-databaundle-recharge/{rechargesendrequest}", name="senddatabaundle")
    * @Route("/service/databaundle-recharge-operator/{countryId}/{oprId}/", name="sendbundlesfixedOperator")
    * @Method({"GET","POST"})
    */
     public function serviceDatabundle(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(33);

        $id=7;
        $api_key = '46fc5328-d654-417b-aabe-87fc53f2ae2f';
        $api_secret = 'd91c7d6c-8573-40e7-9b13-257d2bf3ee2d';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        // echo "hmac : $hmac".PHP_EOL;

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);


        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();


        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
          $flag = $value->flag;
          $flag = strtolower($flag);
          $value->flag = $flag;
        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){

            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            
            $default_country = $defaultcountry->statusValue;

        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-databundle.html.twig',array(
                'title' => 'Data Bundle',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 4, //api_service_id (databundle) from db 
                'metadetails' => $metadetails
            ));
     }

    /**
     * @Route("/service/service-wifi-recharge/", name="service_wifi_recharge")
     * @Route("/service/send-wifi-recharge/{rechargesendrequest}", name="sendwifi")
     * @Route("/service/wifi-recharge-operator/{countryId}/{oprId}/", name="sendwifi_rechargefixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceWifiRecharge(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(34);
        $id=8;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        // echo "hmac : $hmac".PHP_EOL;

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();


        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
            
          $flag = $value->flag;
            
          $flag = strtolower($flag);

          $value->flag = $flag;

        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){

            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            
            $default_country = $defaultcountry->statusValue;

        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-wifi.html.twig',array(
                'title' => 'Wifi Recharge',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 7, //api_service_id (wifi-recharge) from db
                'metadetails' => $metadetails 
            ));
     } 

     /**
     * @Route("/service/service-transportation-recharge/", name="service_transportation_recharge")
     * @Route("/service/send-transport-recharge/{rechargesendrequest}", name="sendtransport")
     * @Route("/service/transport-recharge-operator/{countryId}/{oprId}/", name="sendtransportationfixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceTransportationRecharge(){
        $metadetails = $this->get('app.helper')->GetMetaDetails(35);
        $id=4;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();

        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
            $flag = $value->flag;
            $flag = strtolower($flag);
            $value->flag = $flag;
        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-transportation.html.twig',array(
                'title' => 'Transportation Recharge',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 5, //api_service_id (transportation-recharge) from db 
                'metadetails' => $metadetails
            ));
     } 

     /**
     * @Route("/service/service-landline-recharge/", name="service_landline_recharge")
     * @Route("/service/send-landline-recharge/{rechargesendrequest}", name="sendlandline")
     * @Route("/service/landline-recharge-operator/{countryId}/{oprId}/", name="sendlandlinefixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceLandlineRecharge(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(36);
        $id=10;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';
        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();


        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
            
          $flag = $value->flag;
            
          $flag = strtolower($flag);

          $value->flag = $flag;

        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-landline.html.twig',array(
                'title' => 'Landline Prepaid Recharge',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 8, //api_service_id (wifi-recharge) from db 
                'metadetails' => $metadetails
            ));
     }

    /**
     * @Route("/service/service-grocery/", name="service_grocery")
     * @Route("/service/send-grocery-recharge/{rechargesendrequest}", name="sendgrocery")
     * @Route("/service/grocery-recharge-operator/{countryId}/{oprId}/", name="sendgroceryfixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceGrocery(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(37);
        $id=2;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';
        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if($array->countries==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();

        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();

        foreach ($data as $key => $value) {
            $flag = $value->flag;
            $flag = strtolower($flag);
            $value->flag = $flag;
        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-grocery.html.twig',array(
        // return $this->render('/index (14_march)/index.html.twig',array(
                'title' => 'Grocery',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 6, //api_service_id (grocery) from db 
                'metadetails' => $metadetails
            ));
     } 

      /**
     * @Route("/service/service-pharmacy/", name="service_pharmacy")
     * @Route("/service/send-pharmacy-recharge/{rechargesendrequest}", name="sendpharmacy")
     * @Route("/service/pharmacy-recharge-operator/{countryId}/{oprId}/", name="sendpharmacyfixedOperator")
     * @Method({"GET","POST"})
     */
     public function servicePharmacy(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(38);
        $id=3;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';
        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();


        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
            $flag = $value->flag;
            $flag = strtolower($flag);
            $value->flag = $flag;
        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-pharmacy.html.twig',array(
                'title' => 'Pharmacy',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 3, //api_service_id (pharmacy) from db 
                'metadetails' => $metadetails
            ));
        
     } 


     /**
     * @Route("/service/service-electricity/", name="service_electricity")
     * @Route("/service/send-electricity-recharge/{rechargesendrequest}", name="sendelectricity")
     * @Route("/service/electricity-recharge-operator/{countryId}/{oprId}/", name="sendelectricityfixedOperator")
     * @Method({"GET","POST"})
     */
     public function serviceElectricity(){

        $metadetails = $this->get('app.helper')->GetMetaDetails(39);
        $id=6;
        $api_key = '';
        $api_secret = '';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        // echo "hmac : $hmac".PHP_EOL;

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/countries?service_id=$id");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);

        if(count($array->countries)==0){
            $this->redirectToRoute('index');
        }

        foreach ($array->countries as $key => $value) {
            $countryData[] = $value->country_id;
        }
        $em = $this->getDoctrine()->getManager();

        $data = $em->getRepository('AppBundle:Countries')->createQueryBuilder('c')
            ->where('c.status = :status')
            ->andwhere('c.apiCountryId IN (:country)')
            ->setParameter('status', 1  )
            ->setParameter('country', $countryData)
            ->orderBy('c.name', 'ASC')
            ->getQuery()->getResult();
        foreach ($data as $key => $value) {
            $flag = $value->flag;
            $flag = strtolower($flag);
            $value->flag = $flag;
        }

        $session = new Session();

        $default_country =  $session->get('default_country');

        if($default_country==""){
            $defaultcountry = $em->getRepository('AppBundle:SecurityCentre')->findOneById('9');
            $default_country = $defaultcountry->statusValue;
        }

        $details = $em->getRepository('AppBundle:Countries')->findOneById($default_country);

        $currency_symbol = $details->currencyCode;

        return $this->render('/services/service-electricity.html.twig',array(
                'title' => 'Electricity',
                'country' => $data,
                'currency_symbol' =>$currency_symbol,
                'api_service_id' => 2, //api_service_id (electricity) from db 
                'metadetails' => $metadetails
            ));
     }  


    /**
    * @Route("/service/set-sms-details/", name="setSMSDetails")
    * @Method("POST")
    */
    public function setSMSDetails(){
        $session = new Session();
        $session->get('temp_session_user');

        if($session && isset($_POST)){

            $em = $this->getDoctrine()->getManager();

            $RechargeLogs = $em->getRepository('AppBundle:RechargeLogs')->findOneById($_POST['recharge_id']);

            $RechargeLogs->setSenderNumber($_POST['sender_number']);
            $RechargeLogs->setReceiverNumber($_POST['reciever_number']);
            $RechargeLogs->setSenderSms($_POST['sender_sms']);
            $RechargeLogs->setReceiverSms($_POST['receiver_sms']);

            $em->flush();

            echo "Successfully saved the details";exit;

        }
        echO "Oops ! Something went wrong. Try again";
        exit;
    }

    /**
     * @Route("/service/get-country-service-operators/", name="get_country_service_operators")
     * @Method({"GET","POST"})
     */
     public function getCoverageOperators(){

        $serviceId = $_POST['service_id'];
        $countryId = $_POST['country_id'];

        $destination_route = "send".$_POST['apiserviceName']."fixedOperator";

        $api_key = '';
        $api_secret = '';
        $nonce = time()+rand();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));
        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators?country_id=$countryId&service_id=$serviceId");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "X-TransferTo-apikey: $api_key",
            "X-TransferTo-nonce: $nonce",
            "X-TransferTo-hmac: $hmac",
        ));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);

        $array = json_decode($output);
       
        $html = "";
        foreach ($array->operators as $key => $value) { 
            $html .= '<div class="wd100 bdrpp">
                            <div class="col-md-2 col-sm-4 col-xs-12">
                            <div class="im-rsp">
                              <img src="https://fm.transfer-to.com/logo_operator/logo-'.$value->operator_id.'-1.png"/>
                              </div>
                            </div>
                            <div class="col-md-7 col-sm7 col-xs-12">
                              <p> <span class="sz16 ble" style="line-height:50px;">'.$value->operator.'</span></p>
                            </div>
                            <div class="col-md-3 col-sm-3col-xs-12">
                                <p class="pp_rgt"> <a href="'.$this->generateUrl($destination_route, array('countryId' => $countryId, 'oprId' => $value->operator_id), true).'" class="btn btn-primary btbg" type="button">Send Top-up</a></p>
                            </div>
                        </div>' ;
        } 

            echo $html;
            exit;
     }

      /**
     * @Route("/service/get-country-topup-operators/", name="get_country_topup_operators")
     * @Method({"GET","POST"})
     */
     public function getAllTopupCountriesOperators(){

        $login="";

        $password="";

        $countryId = $_POST['country_id'];
        $destination_route = "send".$_POST['apiserviceName']."fixedOperator";

        // MD5 calculation
        $key=time();

        $md5=md5($login.$password.$key);

        $data = "";
        $url = "https://fm.transfer-to.com/cgi-bin/shop/topup?login=paydez"."&key=".$key."&md5=".$md5."&info_type=country&content=".$countryId."&action=pricelist";


        $responses = split("\n", file_get_contents($url) );

        $oprRaw = explode("=",$responses[2]);
        $oprIdRaw = explode("=",$responses[3]);

        $oprArray = explode(",",$oprRaw[1]);
        $oprIdArray = explode(",",$oprIdRaw[1]);
        $html = "";
        foreach ($oprIdArray as $key => $value) {
            $opr[] = array('operator' => $oprArray[$key], 'operator_id' => $value );
             $html .= '<div class="wd100 bdrpp">
                            <div class="col-md-2 col-sm-4 col-xs-12">
                            <div class="im-rsp">
                              <img src="https://fm.transfer-to.com/logo_operator/logo-'.$value.'-1.png"/>
                              </div>
                            </div>
                            <div class="col-md-7 col-sm-7 col-xs-12">
                              <p> <span class="sz16 ble" style="line-height:50px;">'.$oprArray[$key].'</span></p>
                            </div>
                             <div class="col-md-3 col-sm-3 col-xs-12">
                               <p class="pp_rgt"> <a href="'.$this->generateUrl($destination_route, array('oprName' => $oprArray[$key]), true).'" class="btn btn-primary btbg" type="button">Send Top-up</a></p>
                            </div>
                        </div>' ;

        }

        echo $html;
        exit;
    }


    /**
     * @Route("/service/go-home/", name="go_home")
     * @Method("GET")
     */
    public function unsetSession(){
        $session = new Session();
        $session->remove('temp_session_user');
    }

    private function getConversionRates(){
      $endpoint = '';
      $access_key = '';

      // Initialize CURL:
      $ch = curl_init('http://apilayer.net/api/'.$endpoint.'?access_key='.$access_key.'');
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

      // Store the data:
      $json = curl_exec($ch);
      curl_close($ch);

      return $json;
    }

    private function currencyConvertor($conv_ratesArray, $source_currency, $source_amount ){

            $exchangeRates = json_decode($conv_ratesArray,true);

            $em = $this->getDoctrine()->getManager();

            $session = new Session();

            $country_id =  $session->get('default_country');

            $details = $em->getRepository('AppBundle:Countries')->findOneById($country_id);

            $default_currency = $details->currencyCode;

            if($default_currency!=$source_currency){
    
                $currncy_change = "1";

                $con = "USD"."$source_currency";

                if($default_currency!="USD"){

                    $con1  = "USD".$default_currency;

                    $rate1 = $exchangeRates['quotes'][trim($con)];

                    $rate2 = $exchangeRates['quotes'][trim($con1)];

                    $rate  = $rate1/$rate2;
                
                }else{
                    $rate = $exchangeRates['quotes'][trim($con)];
                }

                $source_currency = $default_currency;

            }else{

                $currncy_change = "0";
            }

            $org_price = $source_amount;

            $org_currency = $source_currency;

            if($currncy_change=="1"){
                $source_amount = $source_amount/$rate;
                $source_amount = round($source_amount,2);
            }

            return array($source_amount,$default_currency);

    }

    private function refundToWallet($user_id, $amount){
        $em = $this->getDoctrine()->getManager();

        $userWallet = $em->getRepository('AppBundle:UserWallet')->findOneByUser($user_id);

        if($userWallet){
            $newWalletAmount = $userWallet->getWalletAmount()+$amount;

            $userWallet->setWalletAmount($newWalletAmount);
            $em->flush();
            return $userWallet;
        }

        return 0;
    }

    private function currencyConversionToUSD($amount_currency_cd,$amount){

        $endpoint = '';
        $access_key = '';

        // Initialize CURL:
        $ch = curl_init('http://apilayer.net/api/'.$endpoint.'?access_key='.$access_key.'');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Store the data:
        $json = curl_exec($ch);
        curl_close($ch);

        // Decode JSON response:
        $exchangeRates = json_decode($json, true);

        if('USD' != $amount_currency_cd){
            $currncy_change = "1";
            $con = "USD"."$amount_currency_cd";
            $rate = $exchangeRates['quotes'][trim($con)];
        }else{
            $currncy_change = "0";
        }

        if($currncy_change=="1"){
            $amount = $amount/$rate;
            $amount = number_format($amount,2);
        }

        return $amount;
    }
}
