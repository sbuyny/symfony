<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Session\Session;
use AppBundle\Entity\AdminUsers;
use AppBundle\Entity\RechargeLogs;
use AppBundle\Entity\PaymentTransactions;
use AppBundle\Entity\B2bWalletLogs;
use AppBundle\Entity\UserDetails;
use AppBundle\Entity\Users;

/**
 * Payment controller.
 */
class PaymentController extends Controller
{
    /**
    * @Route("/payment/", name="payment")
    * @Method("GET")
    */

    public function indexAction(Request $request)
    {
        $helper = $this->get('app.helper')->checkUserSession();
        $title = "payment";
        $metadetails = $this->get('app.helper')->GetMetaDetails(21);

        $session = new Session();
        $user_session_id =  $session->get('temp_session_user');
        $session_e_recharge = $session->get('erecharge_b2b_user_id');

        if(!$user_session_id){
            return $this->redirectToRoute('index');
        }

        $em = $this->getDoctrine()->getManager();

        $rechargelogs = $em->getRepository('AppBundle:RechargeLogs')->findBySessionVal($user_session_id);

        $host = $request->getHost();

        $clientToken = $this->getClientToken($host);

        $braintree_url = "http://".$host."/paydez/vendor/braintree/checkout.php";

        $paysafe_url = "http://".$host."/paydez/vendor/paysafecard/checkout.php";

        $payment_system = '';
        $vals = $em->getRepository('AppBundle:SocialLoginSettings')->findAll();
        foreach($vals as $key=>$val){
            if($val->getId()==6)$payment_system=$val->getValue();
        }
        
        if($payment_system == 'stripe'){
            $id =  $session->get('user_id');
            $em = $this->getDoctrine()->getManager();
            $users = $em->getRepository('AppBundle:Users')->findOneById($id);
            $user_details = $em->getRepository('AppBundle:UserDetails')->findOneByUser($users);
            $stripe=$user_details->getStripe();
            if(isset($stripe)){
                $session->set('stripe', $stripe);
                \Stripe\Stripe::setApiKey("sk_test_D92bO1lJ3HNrHJN2wYnELWoS");
                $customer = \Stripe\Customer::retrieve($stripe);
                foreach($customer->sources->data as $k=>$v){
                    if(!isset($stripe_card_digits))$stripe_card_digits=$v->last4;
                }
                $session->set('stripe_card_digits', $stripe_card_digits);
            }
            $template = 'payment-stripe.html.twig';
        }
        else
        {
            if($session_e_recharge){
                $template = 'e-recharge-payment.html.twig';
            }
            else{
                $template = 'payment.html.twig';
            }
        }

        return $this->render('index/'.$template, array(
                'title' => $title,
                'data' => $rechargelogs,
                'braintreeurl' => $braintree_url,
                'paysafeurl' => $paysafe_url,
                'rechargetype' => 0,
                'clientToken' => $clientToken,
                'metadetails' => $metadetails
        ));
    }

    /**
    * @Route("/sendtopup/{id}", name="sendtopup")
    */

    public function sendtopup(Request $request ,$id)
    {
        $helper = $this->get('app.helper')->checkUserSession();

        $title = "payment";

        $metadetails = $this->get('app.helper')->GetMetaDetails(22);

        $det = base64_decode($id);

        $dets = explode(":",$det);

        $logid = $dets[0];
        
        $em = $this->getDoctrine()->getManager();

        $details = $em->getRepository('AppBundle:RechargeLogs')->findById($logid);

        $session = new Session();

        $user_session_id =  $session->get('temp_session_user');
       
        if($user_session_id){
            $session->set('dum_temp_session_user',$user_session_id);
            $sessval = $details[0]->getsessionVal();
            $session->set('temp_session_user',$sessval);
        }else{
            $sessval = $details[0]->getsessionVal();
            $session->set('temp_session_user',$sessval);
        }

        $user_session_id =  $session->get('temp_session_user');

        $host = $request->getHost();
        $clientToken = $this->getClientToken($host);
        $braintree_url = "http://".$host."/paydez/vendor/braintree/checkout.php";
        $paysafe_url = "http://".$host."/paydez/vendor/paysafecard/checkout.php";

        return $this->render('index/payment.html.twig', array(
                'title' => $title,
                'data' => $details,
                'braintreeurl' => $braintree_url,
                'paysafeurl' => $paysafe_url,
                'rechargetype' => 1,
                'id' => $logid,
                'clientToken' => $clientToken,
                'metadetails' => $metadetails
        ));
    } 

    /**
    * @Route("/set-realex-request/", name="set_realex_request")
    * @Method("POST")
    */
    public function setRealexRequest(){
        
        $session = new Session();
        $uid  = $session->get('user_id');
        $amt  = $session->get('pay_amt');
        $curr = $session->get('pay_curr');
        $lastTxData = $this->getLastTransactionDetails($uid);
        $MERCHANT_ID = 'gistpallimited';
        $ORDER_ID    = time();
        $AMOUNT      = $amt*100; //convert to lowest unit of currency
        $CURRENCY    = $curr;
        $TIMESTAMP   = date('YmdHis');
        $PAYER_REF   = $lastTxData['PAYER_REF'];
        $PMT_REF     = $lastTxData['PMT_REF'];
        $PAYER_EXIST = $lastTxData['PAYER_EXIST'];
        $HPP_FILTER  = $_POST['HPP_FRAUDFILTER_MODE'];
        $CUST_NUM    = $this->getUserName();

        $str = $TIMESTAMP.'.'.$MERCHANT_ID.'.'.$ORDER_ID.'.'.$AMOUNT.'.'.$CURRENCY.'.'.$PAYER_REF.'.'.$PMT_REF.'.'.$HPP_FILTER;
        $str1 = sha1($str);
        $str2 = $str1.'.V2NytodMXv'; //merchant secret
        $SHA1HASH = sha1($str2);

        $data = array(
                'MERCHANT_ID' => $MERCHANT_ID,
                'ORDER_ID'    => $ORDER_ID,
                'AMOUNT'      => $AMOUNT,
                'CURRENCY'    => $CURRENCY,
                'TIMESTAMP'   => $TIMESTAMP,
                'PAYER_REF'   => $PAYER_REF,
                'PMT_REF'     => $PMT_REF,
                'SHA1HASH'    => $SHA1HASH,
                'PAYER_EXIST' => $PAYER_EXIST,
                'CUST_NUM'    => $CUST_NUM
            );

        echo  json_encode($data);
    } 

    /**
    * @Route("/set-realex-request-addfund/", name="set_realex_request_addfund")
    * @Method("POST")
    */
    public function setRealexRequestAddFund(){
        $session = new Session();
        $uid  = $session->get('user_id');
        $amt  = $session->get('pay_amt');
        $curr = $session->get('pay_curr');
        $lastTxData = $this->getLastTransactionDetails($uid);
        $MERCHANT_ID = 'gistpallimited';
        $ORDER_ID    = time();
        $AMOUNT      = $amt*100; //convert to lowest unit of currency
        $CURRENCY    = $curr;
        $TIMESTAMP   = date('YmdHis');
        $PAYER_REF   = $lastTxData['PAYER_REF'];
        $PMT_REF     = $lastTxData['PMT_REF'];
        $PAYER_EXIST = $lastTxData['PAYER_EXIST'];
        $HPP_FILTER  = $_POST['HPP_FRAUDFILTER_MODE'];
        $CUST_NUM    = $this->getUserName(); 

        $str = $TIMESTAMP.'.'.$MERCHANT_ID.'.'.$ORDER_ID.'.'.$AMOUNT.'.'.$CURRENCY.'.'.$PAYER_REF.'.'.$PMT_REF.'.'.$HPP_FILTER;
        $str1 = sha1($str);
        $str2 = $str1.'.1111111'; //merchant secret
        $SHA1HASH = sha1($str2);

        $data = array(
                'MERCHANT_ID' => $MERCHANT_ID,
                'ORDER_ID'    => $ORDER_ID,
                'AMOUNT'      => $AMOUNT,
                'CURRENCY'    => $CURRENCY,
                'TIMESTAMP'   => $TIMESTAMP,
                'PAYER_REF'   => $PAYER_REF,
                'PMT_REF'     => $PMT_REF,
                'SHA1HASH'    => $SHA1HASH,
                'PAYER_EXIST' => $PAYER_EXIST,
                'CUST_NUM'    => $CUST_NUM
            );

        echo  json_encode($data);
    }

    //get last successfull transaction details
    private function getLastTransactionDetails($uid){
        $em = $this->getDoctrine()->getManager();

        $data = $em->createQueryBuilder()
                ->select('pt')
                ->from('AppBundle:PaymentTransactions','pt')
                ->where('pt.userId = :uid')
                ->andWhere('pt.status = 2')
                ->andWhere('pt.billingDetails != :null')
                ->orderBy('pt.id','DESC')
                ->setMaxResults(1)
                ->setParameter('uid',$uid)
                ->setParameter('null','')
                ->getQuery()
                ->getResult(3);
        if($data){
            $billingDetails = $data[0]['pt_billingDetails'];
            if($billingDetails != ""){
                $billingData = json_decode($billingDetails,true);

                if(isset($billingData['SAVED_PAYER_REF'])){
                    $PAYER_REF   = $billingData['SAVED_PAYER_REF'];
                    $PAYER_EXIST = 1;
                    $PMT_REF     = $billingData['SAVED_PMT_REF'];
                }
                else{
                    $PAYER_REF   = "";
                    $PAYER_EXIST = 0;
                    $PMT_REF     = "";
                }
            }else{
                $PAYER_REF   = "";
                $PAYER_EXIST = 0;
                $PMT_REF     = "";
            }
        }else{
                $PAYER_REF   = "";
                $PAYER_EXIST = 0;
                $PMT_REF     = "";
        } 

        $arrayData = array(
                'PAYER_REF'   => $PAYER_REF,
                'PAYER_EXIST' => $PAYER_EXIST,
                'PMT_REF'     => $PMT_REF
            );

        return $arrayData;        
    } 

    private function getUserName(){
        $session = new Session();
        $user_id = $session->get('user_id');
        $em = $this->getDoctrine()->getManager();
        $userData = $em->getRepository('AppBundle:Users')->findOneById($user_id);
        $userName = $userData->getUserName();
        return $userName;
    }
     

    /**
     * @Route("/payment-client-token/", name="paymenttok")
     * @Method("GET")
     */
    public function getClientToken($host){

      // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "http://".$host."/paydez/vendor/braintree/get-client-token.php");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);
        
        return $output;
    }

     /**
     * @Route("/get-realex-request/", name="getRealexRequest")
     * @Method({"POST","GET"})
    */
     public function getRealexRequest(Request $request){
        $session = new Session();

        if($session->get('temp_session_user_add_fund')){
            $sessionVal = $session->get('temp_session_user_add_fund');
        }
        else{
            $sessionVal = $session->get('temp_session_user');
        }

        $data = array(
                'amount'            => $request->get('amount'),
                'currency'          => 'USD',
                'customerNumber'    => $request->get('user_id'),
                'cardStorageEnable' => 1,
                'shippingcode'      => $request->get('billingcode'),   
                'shippingcountry'   => $request->get('billingcountry'),   
                'billingcode'       => $request->get('billingcode'),
                'billingcountry'    => $request->get('billingcountry'),
                'var_ref'           => $request->get('user_id'),
                'prod_id'           => $sessionVal,
                'billingname'       => $request->get('billingname'),
                'billingadress'     => $request->get('billingadress'),
                'billingtown'       => $request->get('billingtown'),
                'save_status'       => $request->get('saveBillingDetails'),
                'wallet_status'     => $request->get('wallet_status')
            );
        
        $host = $request->getHost();
        $mainPath = $this->get('app.helper')->getMainPath();
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://".$_SERVER['SERVER_NAME']."/vendor/realex/payment.php");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // execute the request
        $output = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
        // close curl resource to free up system resources
        curl_close($ch);
        echo $output;
     }



    /**
    * @Route("/get-realex-response/", name="getRealexResponse")
    * @Method({"GET", "POST"})
    */
    public function getRealexResponse(Request $request){

        $responseObj = $request->request->all();
        $rechargeType = $responseObj['RECHARGE_TYPE'];
        if($rechargeType == 1){
            $action_url =  'https://www.paydez.com/action-process-recharge/';
        }
        else{
            $action_url =  'https://www.paydez.com/get-realex-addfund-response/';
        }

        return $this->render('index/realex-response.html.twig',array(
            'data' => json_encode($responseObj),
            'actionUrl' => $action_url
            )
        );
    }

    /**
    * @Route("/action-process-recharge/", name="actionProcessRecharge")
    * @Method({"GET", "POST"})
    */
     public function actionProcessRecharge(Request $request){
       
        $checkUserSession = $this->get('app.helper')->checkUserSession();

        if($checkUserSession == 0){
            return $this->redirectToRoute('index');
        }
        
        $em = $this->getDoctrine()->getManager();
        $payment_system = '';
        $vals = $em->getRepository('AppBundle:SocialLoginSettings')->findAll();
        foreach($vals as $key=>$val){
            if($val->getId()==6)$payment_system=$val->getValue();
        }
        
        if($payment_system == 'stripe'){
            $session = new Session();
            $amount=$session->get('pay_amt');
            $amount=$amount*100;
            $cur=$session->get('pay_curr');
            $em = $this->getDoctrine()->getManager();
            $id =  $session->get('user_id');

            \Stripe\Stripe::setApiKey("11111111111111111111");
        
        //first step 3d secure
        if($_POST['source'] && !$_GET[client_secret]){
        
        $_SESSION[stripe_exp_month]=$_POST['card-expiry-month'];
        $_SESSION[stripe_exp_year]=$_POST['card-expiry-year'];
        $_SESSION[stripe_card_number]=$_POST['card-number'];
        $_SESSION[stripe_cvc]=$_POST['card-cvc'];
                
        $source = \Stripe\Source::create(array(
            "amount" => $amount,
            "currency" => $cur,
            "type" => "three_d_secure",
            "three_d_secure" => array("card" => $_POST["source"]),
            "redirect" => array("return_url" => "http://eserve4.site/paydez/web/app.php/action-process-recharge/"),//http://eserve4.site/paydez/web/app.php/action-process-recharge/
        ));

        if(isset($source->id)){
            header('Location: '.$source->redirect->url);
            exit;
        }

        $_SESSION[source_id]=$source->id;
        }
        
        
        if(!$_POST['stripe_customer_id']){
        //second step 3d secure
        if($_GET[client_secret] && $_GET[source]){
        echo'<script type="text/javascript" src="https://js.stripe.com/v2/"></script>
            <script type="text/javascript" src="../bundles/paydez-assets/theme/js/jquery-1.12.1.min.js"></script>
                                        <script type="text/javascript">
                                            $(document).ready(function(){
                                                Stripe.setPublishableKey("pk_test_Tt6ViTx9g3qQIyOc5VasFgAs");
                                                Stripe.source.poll(
                                                    "'.$_GET['source'].'",
                                                    "'.$_GET['client_secret'].'",
                                                    function(status, source) {
                                                        source.chargeable=true;
                                                    }
                                                );
                                            }
                                            );
                                        </script>
            ';
        
        $charge = \Stripe\Charge::create(array(
            "amount" => $amount,
            "currency" => $cur,
            "source" => $_GET['source'],
        ));
        
        //if no customer, go to error page
        if(!isset($charge->paid)){
        $this->addFlash(
                    'notice',
                    'Payment declined !'
                );
                return $this->redirectToRoute('purchase_history');
                exit;
        }

        $arr=array(
            'object'=>'card',
            'exp_month'=>$_SESSION[stripe_exp_month],
            'exp_year'=>$_SESSION[stripe_exp_year],
            'number'=>$_SESSION[stripe_card_number],
            'currency'=>'gbp',
            'cvc'=>$_SESSION[stripe_cvc],
            'default_for_currency'=>'gbp',
        );

        // Create a Customer:
        $customer = \Stripe\Customer::create(array(
            "source" => $arr,
        ));

        }

        //save customer id into database
        $stripe=$customer->id;
        $q = $em->createQueryBuilder()
		            ->update('AppBundle:UserDetails', 'u')
		            ->set('u.stripe', '?1')
		            ->where('u.id = ?2')
		            ->setParameter(1, $stripe)
                            ->setParameter(2, $id)
		            ->getQuery();
		    $p = $q->execute(); 
                    
        }
        else{
            //When it's time to charge the customer again, retrieve the customer ID.
            
            $charge = \Stripe\Charge::create(array(
              "amount" => $amount, // $15.00 this time
              "currency" => $cur,
              "customer" => $_POST['stripe_customer_id']
            ));
        }
        
        if( !isset($charge->paid) || $charge->paid !=true ){
        $this->addFlash(
                    'notice',
                    'Payment declined !'
                );
                return $this->redirectToRoute('purchase_history');
                exit;
        }

        $resultStatus = 00;
        $transaction_id = $_POST['ORDER_ID'];
        $amount_in_main_currency = $amount/100;
        $currency = 'GBP';
        $message='';
        $authCode = '';
        $supplementaryData = '';
        $responseObj['WALLET_STATUS']=0;
        $responseObj['PROMO_CD']=0;
        }
        else
        {
            $responseObj = json_decode($_POST['dataArray'],true);
            $resultStatus = $responseObj['RESULT'];
            $transaction_id = $responseObj['ORDER_ID'];
            $message = $responseObj['MESSAGE'];
            $amount_in_main_currency = $responseObj['AMOUNT']/100;
            $currency = 'GBP';
            $authCode = $responseObj['AUTHCODE'];
            $supplementaryData = $responseObj;
        }

        $session = new Session();
        $user_id = $session->get('user_id');
        $session_val = $session->get('temp_session_user');
        $em = $this->getDoctrine()->getManager();
        $userObj = $em->getRepository('AppBundle:Users')->findOneById($user_id);

        $outputrealex = array(
                                    'result' => $resultStatus,
                                    'transaction_id' => $transaction_id, 
                                    'message' => $message, 
                                    'amount' => $amount_in_main_currency, 
                                    'currency' => $currency, 
                                    'authCode' => $authCode,
                                    'suppledata' => $supplementaryData
                                    
                                   );
        $status = 0;

        if($resultStatus == 00){
            //successfull transaction
            if($responseObj['WALLET_STATUS']==1){
                $walletStatus = $responseObj['WALLET_STATUS'];
                $walletAmount = $this->actionCleanWallet($userObj);
            }

            if($responseObj['PROMO_CD'] > 0){
                $promoID = $responseObj['PROMO_CD'];
            }
            
            if($payment_system == 'stripe'){
                $status = 2;
                $transId = time().rand();
                $cur_date = date("Y-m-d H:i:s");
                $rechargeType=0;
                $em = $this->getDoctrine()->getManager();
                $paymentTransactions = new PaymentTransactions();
                $paymentTransactions->setUserId($id);
                $paymentTransactions->setStatus($status); 
                $paymentTransactions->setResponse($responseObj['WALLET_STATUS']);
                $paymentTransactions->setGateWayType($rechargeType);//wallet purchase 
                $paymentTransactions->setTransactionId($transId);
                $paymentTransactions->setCreatedDate($cur_date);
                $paymentTransactions->setModifiedDate($cur_date);
                $paymentTransactions->setSessionValue($session_val);
                $paymentTransactions->setIp($_SERVER['REMOTE_ADDR']);
                $em->persist($paymentTransactions);
                $em->flush();
            }
           
            $RechargeLogs = $em->createQueryBuilder()
                                        ->select('r')
                                        ->addSelect('s')
                                        ->from('AppBundle:RechargeLogs','r')
                                        ->innerJoin('AppBundle:ApiServicesMaster','s','WITH','r.serviceType=s.id')
                                        ->where('r.sessionVal = :session_val')
                                        ->setParameter('session_val',$session_val)
                                        ->getQuery()
                                        ->getResult(3);
            if($RechargeLogs){
                $subPath = $this->get('app.helper')->getSubPath();
                foreach($RechargeLogs as $k=>$value){

                            switch($value['s_id']){
                                case 1 ://television
                                case 4 ://bundles
                                case 5 ://transportation
                                case 7 ://wifi-recharge
                                case 8 ://landline-recharge
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $session_val
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/dth-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break; 
                                case 6 ://grocery
                                case 3 ://pharmacy
                                case 2 ://electricity vouchers
                                case 10://electricity billing
                                
                                            $data = array(
                                            'id' => $value['r_id'],
                                            'user_id' => $user_id,
                                            'recharge_session' => $session_val
                                            );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/service-get-vouchers/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break;    

                                case 9 ://topup mobile
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $session_val
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/topup-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                        break;

                                default ://
                                        break; 
                        
                        
                            }

                        }

                        return $this->redirectToRoute('recharge_success');
            }
           
        }
        $action = $this->actionVaultTransaction($request,$outputrealex,$user_id,$session_val,$status);
        return $this->redirectToRoute('transactionfail');
     }

     /**
    * @Route("/get-realex-addfund-response/", name="getRealexAddFundResponse")
    * @Method({"GET", "POST"})
    */
     public function getRealexAddFundResponse(Request $request){
        $checkUserSession = $this->get('app.helper')->checkUserSession();

        if($checkUserSession == 0){
            return $this->redirectToRoute('index');
        }

        $responseObj = json_decode($_POST['dataArray'],true);
        $resultStatus = $responseObj['RESULT'];
        $transaction_id = $responseObj['ORDER_ID'];
        $message        = $responseObj['MESSAGE'];
        $amount_in_main_currency = $responseObj['AMOUNT']/100;
        $currency = 'GBP';
        $authCode = $responseObj['AUTHCODE'];
        $supplementaryData = $responseObj;

        $session = new Session();
        $user_id = $session->get('user_id');
        $session_val = $session->get('temp_session_user');
        $em = $this->getDoctrine()->getManager();
        $userObj = $em->getRepository('AppBundle:Users')->findOneById($user_id);

        $outputrealex = array(
                                    'result' => $resultStatus,
                                    'transaction_id' => $transaction_id, 
                                    'message' => $message, 
                                    'amount' => $amount_in_main_currency, 
                                    'currency' => $currency, 
                                    'authCode' => $authCode,
                                    'suppledata' => $supplementaryData
                                    
                            );
        $status = 0;
        $walletStatus = 0;
        $walletAmount = 0;

        $session = new Session();
        $user_id = $session->get('user_id');

        $em = $this->getDoctrine()->getManager();
        $userObj = $em->getRepository('AppBundle:Users')->findOneById($user_id);

        $session_val = $session->get('temp_session_user_add_fund');
        $em = $this->getDoctrine()->getManager();

        if($outputrealex['result'] == 00){//successfull transaction
            $status = 2;
            $action = $this->actionVaultTransaction($request,$outputrealex,$user_id,$session_val,$status);
            $updateUserWallet = $this->actionUpdateWallet($userObj,$outputrealex['amount']);
            $updateAddFundLog = $this->actionUpdateAddFundLog($userObj,$session_val,$updateUserWallet->getWalletAmount());
            
            return $this->redirectToRoute('addfund_success');

        }
        $action = $this->actionVaultTransaction($request,$outputrealex,$user_id,$session_val,$status);
        return $this->redirectToRoute('addfund_success');
     }

     private function actionVaultTransaction($request,$result,$user_id,$session_val,$status,$walletStatus=0,$walletAmount=0,$promoID=""){
        $em = $this->getDoctrine()->getManager();
        $supplementaryData = $result['suppledata'];
        $rechargeType = $supplementaryData['RECHARGE_TYPE'];

        //send sift science request
        $sift_response = $this->get('app.helper')->sendSiftScienceRequest($supplementaryData,$request);

        unset($supplementaryData['MERCHANT_RESPONSE_URL']);

        $supplementaryData = json_encode($supplementaryData,true);
        $response = array(
            $result['result'],
            $result['amount'],
            $result['currency'],
            $result['message'],
            $result['authCode']
        );
        $ip = $request->getClientIp();
        $conversionRates = $this->get('app.helper')->getConversionRates();
        $response = json_encode($response);

        $PT = new PaymentTransactions();
        $PT->setUserId($user_id);
        $PT->setTransactionId($result['transaction_id']);
        $PT->setSessionValue($session_val);
        $PT->setStatus($status);
        $PT->setGatewayType($rechargeType);
        $PT->setResponse($response);
        $PT->setCreatedDate(date("Y-m-d H:i:s"));
        $PT->setModifiedDate(date("Y-m-d H:i:s"));
        $PT->setWalletPurchase($walletStatus);
        $PT->setWalletPurchaseAmount($walletAmount);
        $PT->setIp($ip);
        $PT->setSiftResponse($sift_response);
        $PT->setConversionRates($conversionRates);
        $PT->setBillingDetails($supplementaryData);

        if($promoID != "" && $promoID != 0){
			$PT->setPromoCodeId($promoID);
		}

        $em->persist($PT);
        $em->flush();

        if($status==2){
            if($rechargeType == 1){
                //recharges only
                $actionB2BWalletUpdate = $this->deductB2BWallet($user_id,$session_val);
            }
            $actionSendSMS = $this->sendSMSRechargePayment(
                $user_id,
                3,
                $result['amount'],
                $result['currency'],
                $result['transaction_id']
            );
        }
        else{
           $actionSendSM = $this->sendSMSRechargePayment(
               $user_id,
               4,
               $result['amount'],
               $result['currency'],
               $result['transaction_id']
           );
        }
        return 1;
     }

     private function actionUpdateAddFundLog($user_id, $session_val, $balance){
        $em = $this->getDoctrine()->getmanager();

        $addFundLogs = $em->getRepository('AppBundle:AddFundLogs')->findOneBy(array(
                'user'     => $user_id,
                'sessionVal' => $session_val
            ));

        if($addFundLogs){
            $addFundLogs->setStatus(1);
            $em->flush();
            $amount   = $addFundLogs->getAmount()." ".$addFundLogs->getCurrency(); //amount to display
            $user     = $addFundLogs->getUser();//user object
            $currency = $addFundLogs->getCurrency();
            $actionSendEmail = $this->sendAddFundEmail($amount,$user,$balance,$currency);
            return 1;//successfully updated
        }

        return 0;//failed to update
     }

     private function sendAddFundEmail($amount, $user, $balance, $currency){
        $em = $this->getDoctrine()->getManager();
        $userDetails = $em->getRepository('AppBundle:UserDetails')->findOneByUser($user);
        $email = $userDetails->getEmail();
        $balance = $balance." ".$currency;
        $template = $em->getRepository('AppBundle:EmailTemplates')->findOneByTemplateName('Paydez - B2C Amount Credit');

        if($template){
            $emailContent = $template->getTemplateContent();
            $emailBody = str_replace('{USERNAME}', $user->getUserName(), $emailContent);
            $emailBody = str_replace('{AMOUNT}', $amount, $emailBody);
            $emailBody = str_replace('{EFFECTIVEBAL}', $balance, $emailBody);
            $actionEmail = $this->get('app.helper')->sendMail('Paydez E-Wallet Credited', $emailBody, $email);
            return 1;//success
        }

        return 0;//fail
     }

     private function actionUpdateWallet($user_id,$amount){
        $em = $this->getDoctrine()->getManager();
        $userWallet = $em->getRepository('AppBundle:UserWallet')->findOneByUser($user_id);

        if($userWallet){
            $walletAmount = $userWallet->getWalletAmount();
            $walletAmount = $walletAmount+$amount;
            //update
            $userWallet->setWalletAmount($walletAmount);
            $em->flush();
            return $userWallet; //wallet object updated
        }

        return 0; //failed
     }
    
    /**
     * @Route("/check-promo-code/", name="check_promo_code")
     * @Method("POST")
    */
    public function checkPromoCode(){

        if(isset($_POST['code'])){
            $em = $this->getDoctrine()->getManager();

            $data = $em->createQueryBuilder()
                    ->select('pc')
                    ->from('AppBundle:PromoCodes','pc')
                    ->where('pc.promoCode = :code')
                    ->andWhere('pc.status = 1')
                    ->andWhere('pc.startDate < :cur_date')
                    ->andWhere('pc.expiryDate > :cur_date')
                    ->setParameter('code',$_POST['code'])
                    ->setParameter('cur_date', date("Y-m-d H:i:s"))
                    ->getQuery()
                    ->getScalarResult(1);

            if($data){
                $checkPrevTransaction  = $em->getRepository('AppBundle:PaymentTransactions')->findBy(array(
                        'userId' => $_POST['user_id'],
                        'promoCodeId' => $data[0]['pc_id'],
                        'status' => 2
                    ));

                if($checkPrevTransaction){
                    echo json_encode(array('status' => 0 , 'response_text' =>  "you've already used this promo code once"));
                    exit;
                }

                  echo json_encode(array('status' => 1 ,'code_id' => $data[0]['pc_id'], 'percentage' => $data[0]['pc_discountPercentage'], 'response_text' => 'successfully applied the promo code'));
                  exit;
            }        
        }

        echo json_encode(array('status' => 0 , 'response_text' => 'invalid promo code '));
        exit;
    }


     /**
    * show Details.
    * @Route("/wallet-purchase/", name="wallet_purchase")
    * @Method("GET")
    */
    public function actionWalletPurchase(Request $request) {
        $session = new Session();
        $amount          = $session->get('amount'); // amount in USD
        $walletStatus    = $session->get('wallet_status'); //{1= use wallet, 0 = no use wallet}
        $user_id         = $session->get('user_id');
        $sessionRecharge = $session->get('temp_session_user');

        if($walletStatus == 1 && $user_id && $sessionRecharge){
            if($user_id){
                $em = $this->getDoctrine()->getManager();
                $userDetails = $em->getRepository('AppBundle:Users')->findOneById($user_id);
                $walletDetails  = $em->getRepository('AppBundle:UserWallet')->findOneByUser($userDetails);

                if($walletDetails && $walletDetails->getWalletAmount() > 0){
                    $walletAmount = $walletDetails->getWalletAmount();
                    $newBalance = $amount-$walletAmount;
                    if($newBalance > 0){
                        //if balance is +ve set for online payment
                        echo "you need to pay ".$newBalance." ".$walletDetails->getWalletCurrency()." by online payment";
                        exit;
                    }
                    else{
                        //if balance is -ve/zero add it to wallet balance
                        $newWalletAmount = abs($newBalance);  
                        $status = 2;
                        $transId = time().rand();
                        $cur_date = date("Y-m-d H:i:s");
                        $ip = $request->getClientIp();
                        $response = array('00', $amount, $walletDetails->getWalletCurrency(), "wallet purchase", "0111");
                        $response = json_encode($response);
                        $paymentTransactions = new PaymentTransactions();
                        $paymentTransactions->setUserId($user_id);
                        $paymentTransactions->setStatus($status); 
                        $paymentTransactions->setResponse($response); 
                        $paymentTransactions->setGateWayType(0);//wallet purchase 
                        $paymentTransactions->setTransactionId($transId);
                        $paymentTransactions->setCreatedDate($cur_date);
                        $paymentTransactions->setModifiedDate($cur_date);
                        $paymentTransactions->setSessionValue($sessionRecharge);
                        $paymentTransactions->setWalletPurchase(1);
                        $paymentTransactions->setWalletPurchaseAmount($amount);
                        $paymentTransactions->setIp($ip);
                        $em->persist($paymentTransactions);
                        //update wallet with balance amount
                        $walletDetails->setWalletAmount($newWalletAmount);
                        $em->flush();

                         if($status==2){
                            $actionSendSMS = $this->sendSMSRechargePayment(
                                $user_id,
                                3,
                                $amount,
                                $walletDetails->getWalletCurrency(),
                                $transId
                            );
                        }
                        else{
                           $actionSendSMS = $this->sendSMSRechargePayment(
                               $user_id,
                               3,
                               $amount,
                               $walletDetails->getWalletCurrency(),
                               $transId
                           );
                        }

                        $em = $this->getDoctrine()->getManager();

                        $RechargeLogs = $em->createQueryBuilder()
                                        ->select('r')
                                        ->addSelect('s')
                                        ->from('AppBundle:RechargeLogs','r')
                                        ->innerJoin('AppBundle:ApiServicesMaster','s','WITH','r.serviceType=s.id')
                                        ->where('r.sessionVal = :session_val')
                                        ->setParameter('session_val',$sessionRecharge)
                                        ->getQuery()
                                        ->getResult(3);

                        $subPath = $this->get('app.helper')->getSubPath();                

                        foreach($RechargeLogs as $k=>$value){

                            switch($value['s_id']){
                                case 1 ://television
                                case 4 ://bundles
                                case 5 ://transportation
                                case 7 ://wifi-recharge
                                case 8 ://landline-recharge
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $sessionRecharge
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/dth-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break; 
                                case 6 ://grocery
                                case 3 ://pharmacy
                                case 2 ://electricity vouchers
                                case 10://electricity billing
                                
                                            $data = array(
                                            'id' => $value['r_id'],
                                            'user_id' => $user_id,
                                            'recharge_session' => $sessionRecharge
                                            );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/service-get-vouchers/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break;    

                                case 9 ://topup mobile
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $sessionRecharge
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/topup-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                        break;

                                default ://
                                        break;        
                            }
                        }

                        return $this->redirectToRoute('recharge_success');
                    }

                    return $this->redirectToRoute('payment');
                }
            }
        }

        return $this->redirectToRoute('payment');
    }

    /**
    * show Details.
    * @Route("/action-e-recharge/{amount}/", name="action_e-recharge")
    * @Method("GET")
    */
    public function actionERecharge($amount,Request $request) {

        $session = new Session();
        $user_id         = $session->get('user_id');
        $sessionRecharge = $session->get('temp_session_user');
        $b2bUser         = $session->get('erecharge_b2b_user_id');

        if($b2bUser && $user_id && $sessionRecharge){

            $actionDeduct = $this->deductB2BWallet($user_id,$sessionRecharge);

            if($actionDeduct == 1){//success
                        $em = $this->getDoctrine()->getManager();
                        $walletDetails = $em->getRepository('AppBundle:AdminUsers')->findOneById($b2bUser);
                        $status = 2;
                        $transId = time().rand();
                        $cur_date = date("Y-m-d H:i:s");
                        $ip = $request->getClientIp();

                        $response = array('00', $amount, $walletDetails->getWalletCurrency(), "wallet purchase", "0111");
                        $response = json_encode($response);

                        $paymentTransactions = new PaymentTransactions();
                        $paymentTransactions->setUserId($user_id);
                        $paymentTransactions->setStatus($status); 
                        $paymentTransactions->setResponse($response); 
                        $paymentTransactions->setGateWayType(0);//wallet purchase 
                        $paymentTransactions->setTransactionId($transId);
                        $paymentTransactions->setCreatedDate($cur_date);
                        $paymentTransactions->setModifiedDate($cur_date);
                        $paymentTransactions->setSessionValue($sessionRecharge);
                        $paymentTransactions->setWalletPurchase(1);
                        $paymentTransactions->setWalletPurchaseAmount($amount);
                        $paymentTransactions->setB2bUserRecharge($b2bUser);
                        $paymentTransactions->setIp($ip);
                        $em->persist($paymentTransactions);
                        $em->flush();

                        $RechargeLogs = $em->createQueryBuilder()
                                        ->select('r')
                                        ->addSelect('s')
                                        ->from('AppBundle:RechargeLogs','r')
                                        ->innerJoin('AppBundle:ApiServicesMaster','s','WITH','r.serviceType=s.id')
                                        ->where('r.sessionVal = :session_val')
                                        ->setParameter('session_val',$sessionRecharge)
                                        ->getQuery()
                                        ->getResult(3);
                        $subPath = $this->get('app.helper')->getSubPath();                

                        foreach($RechargeLogs as $k=>$value){

                            switch($value['s_id']){
                                case 1 ://television
                                case 4 ://bundles
                                case 5 ://transportation
                                case 7 ://wifi-recharge
                                case 8 ://landline-recharge
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $sessionRecharge
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/dth-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break; 
                                case 6 ://grocery
                                case 3 ://pharmacy
                                case 2 ://electricity vouchers
                                case 10://electricity billing
                                
                                            $data = array(
                                            'id' => $value['r_id'],
                                            'user_id' => $user_id,
                                            'recharge_session' => $sessionRecharge
                                            );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/service-get-vouchers/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                         break;    

                                case 9 ://topup mobile
                                            $data = array(
                                                'id' => $value['r_id'],
                                                'user_id' => $user_id,
                                                'recharge_session' => $sessionRecharge
                                                );
                                            $url = "https://".$_SERVER['SERVER_NAME']."/service/topup-recharge/";
                                            $ch = curl_init();
                                            curl_setopt($ch, CURLOPT_URL, $url);
                                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");            
                                            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

                                            // execute the request
                                            $output = curl_exec($ch);
                                            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
                                            // close curl resource to free up system resources
                                            curl_close($ch);
                                        break;

                                default ://
                                        break;        
                            }
                        }

                        return $this->redirectToRoute('recharge_success');
            }
        }

        return $this->redirectToRoute('payment');
        
    }


    /**
     * @Route("/check-wallet/", name="checkwallet_purchase")
     * @Method("POST")
    */

    public function checkwallet_purchase(){

        $subtotal = $_POST['sub_total'];
        $sub_total_price = $_POST['sub_total_price'];

        if($subtotal){
            $session = new Session();
            $user_session_id =  $session->get('temp_session_user');
            $user_id = $session->get('user_id');
            $amountdet = explode(" ",$subtotal);
            $amount = $amountdet[1];
            $currency = $amountdet[0];
            $em = $this->getDoctrine()->getManager();
            $wlt_det = $em->getRepository('AppBundle:UserWallet')->findOneByUser($user_id);
            $wall_amnt = $wlt_det->getWalletAmount();
            $wall_curr = $wlt_det->getWalletCurrency();

            if($currency == "GBP"){

                if($wall_amnt>=$sub_total_price){
                    $amnt_rem = 0;
                    $status = 0;
                    $amnt_rem_price = $wall_amnt-$sub_total_price;
                    $session->set('wallet_status',1);
                    $session->set('amount',$sub_total_price);
                }else{
                    $status = 1;
                    $amnt_rem_price = $sub_total_price-$wall_amnt;
                    $amnt_rem_price = number_format($amnt_rem_price,'2');
                    $amnt_rem = $currency." ".$amnt_rem_price;
                    $session->set('wallet_status',1);
                    $session->set('amount',$sub_total_price);
                }

            }else{
                //get conversion rates in json
                $conversionRates = $this->get('app.helper')->getConversionRates();

                //convert to  currency
                $convertedPrice = $this->get('app.helper')->currencyConvertor($conversionRates, $wall_curr, $wall_amnt, $currency);

                $wall_amnt = $convertedPrice[0];//amount converted to user wallet currency

                if($wall_amnt>=$amount){
 
                    $wall_rem = $wall_amnt-$amount;

                    $amnt_rem = 0;

                    $status = 0;

                    $amnt_rem_price = 0;

                    $session->set('wallet_status',1);

                    $session->set('amount',$sub_total_price);

                }else{

                    $amnt_rem = $amount-$wall_amnt;

                    //convert to  back to wallet currency
                    $convertBackPrice = $this->get('app.helper')->currencyConvertor($conversionRates, $currency, $amnt_rem, $wall_curr);


                    $amnt_rem_price = $convertBackPrice[0];

                    $amnt_rem_price = number_format($amnt_rem_price,'2');

                    $amnt_rem = $currency." ".$amnt_rem; 

                    $status = 1;

                    $session->set('wallet_status',1);

                    $session->set('amount',$sub_total_price);
                }
            }
        }

        $data = $this->get('app.helper')->setAmtSession($amnt_rem_price);
        echo json_encode(array('status' => $status , 'response_text' => $amnt_rem,'amnt_rem_price' =>  $amnt_rem_price));
        exit;
    }


    public function changecurrecy($currency){

        $endpoint = 'live';
        $access_key = '111111111111111111111111';

        // Initialize CURL:
        $ch = curl_init('http://apila 8yer.net/api/'.$endpoint.'?access_key='.$access_key.'');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Store the data:
        $json = curl_exec($ch);
        curl_close($ch);

        // Decode JSON response:
        $exchangeRates = json_decode($json, true);
        $con = "USD".$currency;
        $rate1 = $exchangeRates['quotes'][trim($con)];

        return number_format($rate1,'2');

    }

    private function actionCleanWallet($userObj){
        $em = $this->getDoctrine()->getManager();

        $data = $em->getRepository('AppBundle:UserWallet')->findOneByUser($userObj);
        $walletAmount = 0;
        if($data){
            $walletAmount = $data->getWalletAmount();
            $data->setWalletAmount(0);
            $em->flush();
        }

        return $walletAmount;
    }

    //used to deduct B2B wallet by recharge amount
    private function deductB2BWallet($user_id,$session_val){
        $em = $this->getDoctrine()->getManager();

        $isB2BUser = $em->createQueryBuilder()
                     ->select("u")
                     ->from("AppBundle:Users","u")
                     ->where("u.id = :user_id")
                     ->andWhere("u.parentId != 0 and u.parentId != 1")
                     ->setParameter("user_id",$user_id)
                     ->getQuery()
                     ->getOneOrNullResult();

        if($isB2BUser != ""){
            $parentId       = $isB2BUser->getParentId();
            $b2bUserDetails = $em->getRepository('AppBundle:AdminUsers')->findOneById($parentId);

            $b2bWalletAmount   = $b2bUserDetails->getWalletAmount();
            $b2bWalletCurrency = $b2bUserDetails->getWalletCurrency();

            $rechargeLogs = $em->getRepository('AppBundle:RechargeLogs')->findBy(array(
                    'sessionVal' => $session_val
                ));

            //get conversion rates in json
            $conversionRates = $this->get('app.helper')->getConversionRates();
            $convertedAmountData[] = 0;

            foreach ($rechargeLogs as $key => $value) {
                $rechargePrice    = $value->getOriginalPrice();
                $rechargeCurrency = $value->getOriginalCurrency();

                //convert to b2b Walllet currency
                $convertedPrice = $this->get('app.helper')->currencyConvertor(
                    $conversionRates,
                    $rechargeCurrency,
                    $rechargePrice,
                    $b2bWalletCurrency
                );
                $convertedAmountData[] = $convertedPrice[0]; //price
            }

            //total amount recharged
            $subTotalAmountRecharge = array_sum($convertedAmountData);

            //get the markup for the B2B user
            $b2bMarkups   = $em->getRepository('AppBundle:B2cMarkups')->findOneByUserId($parentId);
            $markupAmount = 0;//default amount if markup is not set

            if($b2bMarkups){
                $markupType = $b2bMarkups->getMarkupType();//type ( % or fixed value )

                if($markupType == 2 ){//fixed value

                    //get fixed values of markups
                    $markupAmount   = $b2bMarkups->getFixedAmount();
                    $markupCurrency = $b2bMarkups->getFixedAmountCurrency();

                    //convert it to b2b Wallet Currency
                    $convertDataMarkup = $this->get('app.helper')->currencyConvertor($conversionRates, $markupCurrency, $markupAmount, $b2bWalletCurrency);

                    $markupAmountToDeduct = $convertDataMarkup[0];

                    //value to deduct from B2B wallet
                    $amountDeduct = round($subTotalAmountRecharge-$markupAmountToDeduct,2);
                }
                else{
                    //percentage

                    //get value in percentage
                    $markupAmountPercent = $b2bMarkups->getPercentage();

                    //value to deduct from B2B wallet
                    $amountDeduct = ($markupAmountPercent/100)*$subTotalAmountRecharge;
                    $amountDeduct = round($amountDeduct,2);
                    $amountDeduct = $subTotalAmountRecharge-$amountDeduct;
                }

                //deduct from B2B Wallet
                $newb2bWalletAmount = $b2bWalletAmount-$amountDeduct;
                $b2bUserDetails->setWalletAmount($newb2bWalletAmount);
                $em->flush();

                $em = $this->getDoctrine()->getManager();

                $b2bLogs = new B2bWalletLogs();
                $b2bLogs->setB2bId($parentId);
                $b2bLogs->setUserId($user_id);
                $b2bLogs->setSessionId($session_val);
                $b2bLogs->setMarkupId($b2bMarkups->getId());
                $b2bLogs->setAmountDeducted($amountDeduct);
                $b2bLogs->setLedgerBalance($b2bWalletAmount);
                $b2bLogs->setClosingBalance($newb2bWalletAmount);
                $b2bLogs->setWalletCurrency($b2bWalletCurrency);

                $em->persist($b2bLogs);

                $b2bUserDetails->setWalletAmount($newb2bWalletAmount);
                $em->flush();

                $this->get('app.helper')->sendB2bWalletAlert($parentId);
            }
            
            return 1;
        }
        return 0;
    }

    private function sendSMSRechargePayment($userId, $templateId, $currency, $amount, $trans_id){
        $em = $this->getDoctrine()->getManager();

        $user = $em->getRepository('AppBundle:Users')->findOneById($userId);

        if($userId){
            $userDetails = $em->getRepository('AppBundle:UserDetails')->findOneByUser($user);

            if($userDetails){
                if($userDetails->getCountryCode() == "" || $userDetails->getPhone() == ""){
                    return 0;
                }
                $phone = $userDetails->getPhone();
                $code  = $userDetails->getCountryCode()->getCode();

                $toNum = $code.$phone;

                $template = $em->getRepository('AppBundle:SmsTemplate')->findOneById($templateId);

                if($template){
                    
                    $message_text = $template->getTemplateContent(); 
                    $smsText = str_replace('{currency}', $currency, $message_text);
                    $smsText = str_replace('{amount}', $amount, $smsText);
                    $smsText = str_replace('{trans_id}', $trans_id, $smsText);

                    //send SMS API
                    $actionSMS = $this->get('app.helper')->sendSMS($toNum, $smsText);

                    return 1;//success
                }

            }
            return 0;
        }

        return 0;
    }

    /**
    * @Route("/set-amt-pay/",name="setAmtSession")
    * @Method("POST")
    */
    public function setAmtPay(){
        $amt = $_POST['amt'];
        $data = $this->get('app.helper')->setAmtSession($amt);
        // echo json_encode($data);
        echo 'success';
        exit;
    }


}
