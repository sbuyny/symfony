<?php

namespace AppBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Session\Session;
use AppBundle\Entity\AdminUsers;
use AppBundle\Entity\RechargeLogs;
use AppBundle\Entity\ContactList;
use AppBundle\Entity\AddFundLogs;
use AppBundle\Entity\UserDetails;
use AppBundle\Entity\NotificationReadStatus;
use AppBundle\Entity\UserDeleteKeys;

/**
 * Profile controller.
 */
class ProfileController extends Controller
{
    /**
     * Hydrates an object graph. This is the default behavior.
     */
    const HYDRATE_OBJECT = 1;

    /**
     * Hydrates an array graph.
     */
    const HYDRATE_ARRAY = 2;

    /**
     * Hydrates a flat, rectangular result set with scalar values.
     */
    const HYDRATE_SCALAR = 3;

    /**
     * Hydrates a single scalar value.
     */
    const HYDRATE_SINGLE_SCALAR = 4;

    /**
     * Very simple object hydrator (optimized for performance).
     */
    const HYDRATE_SIMPLEOBJECT = 5;

    /**
    * @Route("/account/", name="account")
    */
    public function account() {
        $helper = $this->get('app.helper')->checkUserSession();
        $session = new Session();
        $session_user =  $session->get('user_id');
        $em = $this->getDoctrine()->getManager();

        $metadetails = $this->get('app.helper')->GetMetaDetails(23);
        
        if($session_user){

          $recentRecharges =  $em->createQueryBuilder()
                              ->select('r')
                              ->from('AppBundle:RechargeLogs','r')
                              ->where('r.userId = :session_user')
                              ->andWhere('r.serviceType != 10')
                              ->orderBy('r.id','DESC')
                              ->setMaxResults(4)
                              ->setParameter('session_user', $session_user)
                              ->getQuery()
                              ->getResult(3);

          return $this->render('index/account.html.twig', array(

                    'title' => 'Account Overview',

                    'errors' => array(),

                    'recentRecharges' => $recentRecharges,

                    'metadetails' => $metadetails

          ));  
        }

        return $this->redirectToRoute('index');
    } 
    
    /**
    * @Route("/my-account/", name="my_account")
    */
    public function myaccount() {
        $helper = $this->get('app.helper')->checkUserSession();
        $session = new Session();
        $session_user =  $session->get('user_id');
        $errorsUsers = array();
        $errorsUserDetails = array();
	    $errorsUserDetails1 = array();
        $metadetails = $this->get('app.helper')->GetMetaDetails(24);

        if($session_user){

            $em = $this->getDoctrine()->getManager();

            $user = $em->createQueryBuilder()
                  ->select('u,ud')
                  ->from('AppBundle:Users', 'u')
                  ->innerJoin('AppBundle:UserDetails', 'ud', 'WITH', 'u.id=ud.user')
                  ->where('u.id = :uid')
                  ->setParameter('uid',$session_user)
                  ->getQuery()
                  ->getResult();

            $transactions =  $em->createQueryBuilder()
                    ->select('r')
                    ->addSelect('p')
                    ->addSelect('ap')
                    ->from('AppBundle:RechargeLogs', 'r')
                    ->innerJoin('AppBundle:PaymentTransactions','p','WITH','r.sessionVal=p.sessionValue')
                    ->innerJoin('AppBundle:ApiServicesMaster','ap','WITH','r.serviceType=ap.id')
                    ->where('r.userId = :uid')
                    ->orderBy('r.id','DESC')
                    ->setParameter('uid',$session_user)
                    ->getQuery()
                    ->getResult(3);
    if(isset($_POST['update-myaccount-profile'])){

		$phone = $_POST['user_phone'];
		$phncount = $this->get('app.helper')->checkPhonenumberexists($session_user,$phone);

		if($phncount>0){
			$errorsUserDetails1['0']['message'] = "Phone Number Already Existing";
		}
		
                $users = $em->getRepository('AppBundle:Users')->findOneById($session_user);
                $user_details = $em->getRepository('AppBundle:UserDetails')->findOneByUser($users);
                $country = $em->getRepository('AppBundle:Countries')->findOneByCode($_POST['extension_code']);
                $users->setPassword($users->getPassword());
                $users->confirmPassword = $users->getPassword();
                $users->setFName($_POST['fname']);
                $users->setLName($_POST['lname']);
                $user_details->setPhone($_POST['user_phone']);
                $user_details->setCountryCode($country);
                $user_details->setEmail($_POST['user_email']);
                $user_details->setModifiedDate();
                $cityId = $em->getRepository('AppBundle:Cities')->findOneById($_POST['city']);
                $user_details->setCity($cityId);
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                           
                if(isset($request->files)){
                  $user_details->setProfileImage($request->files);  
                }

                $validator         = $this->get('validator');
                $errorsUsers       = $validator->validate($users);
                $errorsUserDetails = $validator->validate($user_details);

                if(count($errorsUsers) == 0 && count($errorsUserDetails) == 0 && count($errorsUserDetails1)==0){
                    $em->flush();
                    $this->addFlash(
                        'notice',
                        'Successfully updated !'
                    );
                }
            }      

            return $this->render('index/myaccount.html.twig', array(
                    'title' => 'My Account',
                    'data' => $user,
                    'transactions' => $transactions,
                    'metadetails' => $metadetails,
                    'errors' => array($errorsUsers,$errorsUserDetails,$errorsUserDetails1)
            ));
        }

        return $this->redirectToRoute('index');   
    }


    /**
    * @Route("/my-account-update-password/", name="my_account_update_password")
    */
    public function changePassword(){
        $helper = $this->get('app.helper')->checkUserSession();
        $session = new Session();
        $session_user_id = $session->get('user_id');
        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:Users')->findOneById($session_user_id);
        $pass = $_POST['new_pwd'];
        if(isset($_POST['current_password']) && $user){

            $current_password = $user->getPassword();

            if($current_password != md5($_POST['current_password'])){
                echo "You've entered a wrong password";
                exit;
            }
            elseif($current_password == $_POST['new_pwd']){
                echo "Please provide a new password to update";exit;
            }
            elseif($pass){

                $em = $this->getDoctrine()->getManager();

                $policy  = $em->getRepository('AppBundle:PasswordPolicy')->findAll();

                foreach ($policy as $key => $value) {

                    if($value->getid() == 1){

                        $vals = $value->getvalue();

                        if(strlen($pass)<$vals){
                            echo "Password must contains altleast ".$vals." characters";
                            exit();
                        }
                    }

                    if($value->getid() == 2){
                        $vals = $value->getvalue();

                        if($vals == 1){
                            if(preg_match("/[A-Z]/", $pass)===0) {
                                
                                echo "Password must contains  uppercase characters";
                                exit();

                            }
                        }

                    }

                    if($value->getid() == 3){

                        $vals = $value->getvalue();

                        if($vals == 1){
                            if(preg_match("/[a-z]/", $pass)===0) {
                                echo "Password must contains lowercase characters";
                                exit;
                            }
                        }
                    }
                }

            }
            else{

                $user->setPassword(md5($_POST['new_pwd']));
                $user->confirmPassword = md5($_POST['new_c_pwd']);

                $validator = $this->get('validator');
                $errors = $validator->validate($user);

                if(count($errors) > 0){
                    echo $errors->get(0)->getMessage();exit;
                }

                $em->flush();
                echo "Password updated successfully";exit;
            }


            exit; 
        }

        echo "failed";exit;
    }

    /**
    * @Route("/my-account-add-contact/", name="my_account_add_contact")
    */
    public function addContact(){
        $helper = $this->get('app.helper')->checkUserSession();
        $session = new Session();
        $session_user_id = $session->get('user_id');


        if(isset($_POST) && $session_user_id){

            $em = $this->getDoctrine()->getManager();

            $user = $em->getRepository('AppBundle:Users')->findOneById($session_user_id);

            $ContactList = new ContactList();
            $ContactList->setName($_POST['contact_name']);
            $ContactList->setEmail($_POST['contact_email']);
            $ContactList->setPhone($_POST['contact_phone']);
            $ContactList->setExtCode($_POST['extension_code']);
            $ContactList->setUser($user);
            $ContactList->setCreatedDate();

            $validator = $this->get('validator');
            $errors = $validator->validate($ContactList);

            if(count($errors) > 0){
                echo $errors->get(0)->getMessage();exit;
            }
            $em->persist($ContactList);
            $em->flush();
            
            echo "Successfully added to contact list";exit;

        }
        
        echo "failed";exit;
    }

    /**
    * @Route("/my-account-delete/{id}/", name="my_account_delete_action")
    */
    public function deleteMyAccountAction($id){
        $key = base64_decode($id);
        if(strpos($key, '::') == false){
            return $this->redirectToRoute('index');
        }

        $keyArray = explode("::",base64_decode($id));
        
        $user_id = $keyArray[1];

        if($user_id){
            $em = $this->getDoctrine()->getManager();
            
            $userDeleteKey = $em->getRepository('AppBundle:UserDeleteKeys')->findOneBy(array(
                    'userId'    => $user_id,
                    'status'    => 0,
                    'deleteKey' => $id 
                ));

            if($userDeleteKey){

                return $this->render('index/delete-my-account.html.twig',array('id' => $id, 'title' => 'Delete My Account'));

            }

        }


        return $this->redirectToRoute('index');
    }


    /**
    * @Route("/my-account-delete/", name="my_account_delete")
    */
    public function deleteMyAccount(Request $request){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            echo "failed";exit; 
        }

        $session = new Session();
        $session_user_id = $session->get('user_id');

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:Users')->findOneById($session_user_id);

        if($user){
            $userDetails = $em->getRepository('AppBundle:UserDetails')->findOneByUser($user);

            $str = time()."::".$session_user_id;
            $encode = base64_encode($str);
            $status = 0;
            $host = $request->getHost();
            $url = "http://".$host.$this->generateUrl("my_account_delete_action",array('id' => $encode));
            $UserDeleteKeys = new UserDeleteKeys();
            $UserDeleteKeys->setUserId($session_user_id);
            $UserDeleteKeys->setDeleteKey($encode);
            $UserDeleteKeys->setStatus($status);
            $em->persist($UserDeleteKeys);            
            $em->flush();

            $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneByTemplateName('Paydez - Account Delete Request');

                if($emailTemplate){
                    $subject = $emailTemplate->getTemplateName();
                    $msg = $emailTemplate->getTemplateContent();
                    $msg = str_replace('{USERNAME}', $user->getUserName(), $msg);
                    $msg = str_replace('{delete_url}', $url, $msg);
                    $emailId = $userDetails->getEmail();
                    $sendEmail = $this->get('app.helper')->sendMail($subject, $msg, $emailId);

                    echo "success";
                    exit;
                }

            echo "success";exit;
        }

        echo "failed";exit;
    }

    /**
    * @Route("/delete-my-account-confirm", name="delete_my_account_confirm")
    * @Method("POST")
    */
    public function deleteMyAccountConfirm(){
        $id = $_POST['id'];
        $keyArray = explode("::",base64_decode($id));
        $user_id = $keyArray[1];

        if($user_id){
            $em = $this->getDoctrine()->getManager();
            
            $userDeleteKey = $em->getRepository('AppBundle:UserDeleteKeys')->findOneBy(array(
                    'userId'    => $user_id,
                    'status'    => 0,
                    'deleteKey' => $id 
                ));

            if($userDeleteKey){

                
                $user = $em->getRepository('AppBundle:Users')->findOneById($user_id);

                if($user){
                    $user->setStatus(3);

                    $userDeleteKey->setStatus(1);

                    $removeLoginTracker = $em->createQueryBuilder()
                                          ->update('AppBundle:LoginTracker','l')
                                          ->set('l.loginStatus',0)
                                          ->getQuery()
                                          ->execute();

                    $em->flush();

                    echo "success";exit;
                }
            }
        }

        echo "failed";exit;
    }

    /**
    * @Route("/resend-recharge/{id}", name="my_account_resend_recharge")
    */
    public function setResendRecharge($id){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user_id = $session->get('user_id');

        $em = $this->getDoctrine()->getManager();
        $user = $em->getRepository('AppBundle:Users')->findOneById($session_user_id);

        if($user){
            $RechargeLogs = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);
            $phone            = $RechargeLogs->getNum();
            $operator_id      = $RechargeLogs->getOprId();
            $OprName          = $RechargeLogs->getOprName();
            $AccountNumber    = $RechargeLogs->getAccountNumber();
            $ProductId        = $RechargeLogs->getProductId();
            $ProductName      = $RechargeLogs->getProductName();
            $OriginalCurrency = $RechargeLogs->getOriginalCurrency();
            $ConvCurrency     = $RechargeLogs->getCurrency();
            $OriginalPrice    = $RechargeLogs->getOriginalPrice();
            $ConvertedPrice   = $RechargeLogs->getConvertedPrice();
            $ExtensionCode    = $RechargeLogs->getExtensionCode();
            $SenderNumber     = $RechargeLogs->getSenderNumber();
            $ReceiverNumber   = $RechargeLogs->getReceiverNumber();
            $ServiceType      = $RechargeLogs->getServiceType();

            if($ServiceType->getId() == 9){
                //check for duplicate account number in the cart
                $checkAccountNumber = $this->get('app.helper')->checkDuplicateAccountNumber($phone,$ServiceType);
            }
            else{
                //check for duplicate account number in the cart
                $checkAccountNumber = $this->get('app.helper')->checkDuplicateAccountNumber($AccountNumber,$ServiceType);
            }

            
            if($checkAccountNumber == 0){
                $rechargeLogs = new RechargeLogs();

                $rechargeLogs->setNum($phone);
                $rechargeLogs->setOprId($operator_id);
                $rechargeLogs->setOprName($OprName);
                $rechargeLogs->setAccountNumber($AccountNumber);
                $rechargeLogs->setProductId($ProductId);
                $rechargeLogs->setProductName($ProductName);
                $rechargeLogs->setOriginalCurrency($OriginalCurrency);
                $rechargeLogs->setOriginalPrice($OriginalPrice);
                $rechargeLogs->setExtensionCode($ExtensionCode);
                $rechargeLogs->setSenderNumber($SenderNumber);
                $rechargeLogs->setReceiverNumber($ReceiverNumber);
                $rechargeLogs->setServiceType($ServiceType);
                $rechargeLogs->setUserId($session_user_id);
                $rechargeLogs->setCreatedDate();
                $rechargeLogs->setModifiedDate(); 
                $rechargeLogs->setStatus(0); 

                //get converted price
                $ratesArray = $this->getConversionRates();
                $localRate = $this->currencyConvertor($ratesArray, $OriginalCurrency, $OriginalPrice);
                $rechargeLogs->setCurrency($localRate[1]);
                $rechargeLogs->setConvertedPrice($localRate[0]);

                $login="paydez";

                $password="889928166440";

                $key=time();

                $md5=md5($login.$password.$key);

                $url = "https://fm.transfer-to.com/cgi-bin/shop/topup?login=paydez"."&key=".$key."&md5=".$md5."&content=".$operator_id."&info_type=operator&action=pricelist";
                    
                $responses = split("\n", file_get_contents($url) );

                //set new price for the recharge from api
                if($ServiceType->getId() == 9){

                    $productlist       = explode("=",$responses[5]);
                    $productlistArray  = explode(",",$productlist[1]);
                    $planName          = explode(" ",$ProductName);

                    $retail_price_list = explode("=",$responses[6]);
                    $retail_price_listArray  = explode(",",$retail_price_list[1]);

                    foreach ($productlistArray as $key => $pvalue) {
                        if($pvalue == $planName[0]){
                            $planPrice = $retail_price_listArray[$key];
                        }
                    }

                }
                else{
                    $planPrice = $this->getPlanPrice($ServiceType,$operator_id,$ProductId);
                }   

                $det = explode("=",$responses[1]);
                $country_id = $det[1];

                $api_country_id = $em->getRepository('AppBundle:Countries')->findOneByApiCountryId($country_id);

                $country = $api_country_id->id;

                $details = $this->getVatandTax($ServiceType,$country);

                if($details){

                $vat = $details[0]['vat'];

                $tax = $details[0]['tax'];

                }else{

                $vat = 0;

                $tax = 0;

                }

                $currentvat = $localRate[0]*($vat/100);

                $currenttax = $localRate[0]*($tax/100);

                $rechargeLogs->setOriginalPrice($planPrice);

                $rechargeLogs->setVat($currentvat);

                $rechargeLogs->setUserId($session_user_id);

                $rechargeLogs->setTax($currenttax);


                if($session->get('temp_session_user')){
                   $session_val =  $session->get('temp_session_user');
                }
                elseif($AccountNumber != ""){

                    $session_val = md5($AccountNumber.time());

                } 
                else{

                    $session_val = md5($phone.time());

                } 
                
                $session->set('temp_session_user',$session_val);

                $rechargeLogs->setSessionVal($session_val);

                $em->persist($rechargeLogs);
                $em->flush();
                
            }    
            else{
                $this->addFlash(
                    'notice',
                    'This account number is already in the cart ! '
                );
            }

            return $this->redirectToRoute('orders');
        }

        return $this->redirectToRoute('index');
    }

    private function getPlanPrice($ServiceType,$operator_id,$productId){
        $em = $this->getDoctrine();

        $api_key = '46fc5328-d654-417b-aabe-87fc53f2ae2f';
        $api_secret = 'd91c7d6c-8573-40e7-9b13-257d2bf3ee2d';
        $nonce = time();
        $host = 'https://api.transferto.com/v1.1/';

        $hmac = base64_encode(hash_hmac('sha256', $api_key.$nonce, $api_secret, true ));

        // set up the curl resource
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$host/operators/".$operator_id."/products");
        // curl_setopt($ch, CURLOPT_URL, "$host/products/58");
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

        $array = json_decode($output,true);
        $serviceTypeMaster = $ServiceType->getTransactionMethod()->getName();
        $planPrice = 0;
        foreach ($array[$serviceTypeMaster] as $key => $pvalue) {
            if($pvalue['product_id'] == $productId){
                $planPrice = $pvalue['retail_price'];
            }
        }

        return $planPrice;
    }

    /**
    * @Route("/purchase-history/", name="purchase_history")
    */
    public function purchasehistory(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $metadetails = $this->get('app.helper')->GetMetaDetails(25);
        $session_user =  $session->get('user_id');

        if($session_user){
            $em= $this->getDoctrine()->getManager();

            $date1 = date("Y-m-d 00:00:00");
            $date2 = date("Y-m-d H:i:s", strtotime("+1 day",strtotime($date1)));

            $transactions =  $em->createQueryBuilder()
                        ->select('r')
                        ->addSelect('p')
                        ->addSelect('ap')
                        ->from('AppBundle:RechargeLogs', 'r')
                        ->leftJoin('AppBundle:PaymentTransactions','p','WITH','r.sessionVal=p.sessionValue')
                        ->leftJoin('AppBundle:ApiServicesMaster','ap','WITH','r.serviceType=ap.id')
                        ->where('r.userId = :uid')
                        //->andWhere('r.createdDate > :date1')
                        //->andWhere('r.createdDate < :date2')
                        ->orderBy('r.id','DESC')
                        ->setParameter('uid',$session_user)
                        //->setParameter('date1',$date1)
                        //->setParameter('date2',$date2)
                        ->getQuery()
                        ->getResult(3);

            return $this->render('index/purchases.html.twig', array(

                        'title' => 'Purchase History',

                        'errors' => array(),

                        'purchaseData' => $transactions,

                        'metadetails' => $metadetails

            ));
        }

        return $this->redirectToRoute('index');
    }

     /**
    * @Route("/purchase-history-date-filter/", name="dateFilterPurchaseHistory")
    */
    public function purchasehistoryFilter(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user =  $session->get('user_id');

        if($session_user && isset($_POST)){
            $em= $this->getDoctrine()->getManager();

            $date1 = date("Y-m-d H:i:s",strtotime($_POST['date1']));
            $date2 = date("Y-m-d H:i:s",strtotime($_POST['date2']));

            $transactions =  $em->createQueryBuilder()
                        ->select('r')
                        ->addSelect('p')
                        ->addSelect('ap')
                        ->from('AppBundle:RechargeLogs', 'r')
                        ->innerJoin('AppBundle:PaymentTransactions','p','WITH','r.sessionVal=p.sessionValue')
                        ->innerJoin('AppBundle:ApiServicesMaster','ap','WITH','r.serviceType=ap.id')
                        ->where('r.userId = :uid')
                        ->andWhere('r.createdDate > :date1')
                        ->andWhere('r.createdDate < :date2')
                        ->orderBy('r.id','DESC')
                        ->setParameter('uid',$session_user)
                        ->setParameter('date1',$date1)
                        ->setParameter('date2',$date2)
                        ->getQuery()
                        ->getScalarResult();

            if(count($transactions)>0){

                  $html ='<table class="col-md-12 table-bordered table-striped table-condensed cf" data-pagination="true" data-search="true" data-filter="true" id="purchase_table" data-toggle="table" data-url="x"><thead class="cf"><tr><th class="tbl rpd">S.No</th><th class="numeric tbl rpd">Phone / Ac No</th><th class="tbl rpd">Operator</th><th class="numeric tbl rpd">Mode of Purchase</th><th class="numeric tbl rpd">Amount</th><th class="numeric tbl rpd">Recharge Status</th><th class="numeric tbl rpd">Payment Status</th><th class="numeric tbl rpd">Date</th><th class="numeric tbl rpd text-center">Action</th></tr></thead><tbody id="results-filter">';

                  foreach ($transactions as $key => $value) {

                      $url_resend = $this->generateUrl("my_account_resend_recharge",array('id' => $value["r_id"]));
                      $url_print = $this->generateUrl("recharge_invoice",array('id' => $value["p_sessionValue"]));

                      $payMentStatus = ($value['p_status'] == 2) ? '<span class="label label-success">Success</span>' : '<span class="label label-danger">Pending</span>';
                      
                      $rechargeStatus = ($value['r_status'] == 2) ? '<span class="label label-success">Success</span>' : '<span class="label label-danger">Pending/Refunded</span>';

                      // $dateCreated = (object)$value["r_createdDate"]->date;
                      $html .= '<tr><td data-title="S.No" class="rpd"  >'.($key+1).'</td><td data-title="Phone No" class="rpd">'.($value["r_num"] == "" ? $value["r_accountNumber"] : '+'.$value["r_extensionCode"].' '.$value["r_num"]).'</td><td data-title="Operator" class="rpd">'.$value["r_oprName"].'</td><td data-title="Mode of Purchase" class="rpd">'.$value["ap_label"].'</td><td data-title="Amount" class="rpd">'.$value["r_originalPrice"].' '.$value["r_originalCurrency"].'</td><td data-title="Recharge Status" class="rpd">'.$rechargeStatus.'</td><td data-title="Payment Status" class="rpd">'.$payMentStatus.'</td><td data-title="Date" class="rpd">'.$value["r_createdDate"]->format("d M Y g:i A").'</td><td data-title="Action" align="center" class="rpd"><a href="'.$url_resend.'" title="Resend Topup ?"><i class="fa fa-share-square ctsz"></i></a> <a href="javascript:void(0);" onclick="return window.open(\''.$url_print.'\')" title="Print Invoice"><i class="fa fa-print fa-lg"></i></a></td></tr>';
                  }  

                  $html .= '</tbody></table>';  
            }          
              
            echo $html;
        }

        return $this->redirectToRoute('index');
    }

    /**
    * @Route("/contacts/", name="contacts")
    */
    public function contacts(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user =  $session->get('user_id');
        $metadetails = $this->get('app.helper')->GetMetaDetails(26);

        if($session_user){

            $em= $this->getDoctrine()->getManager();
            $user = $em->getRepository('AppBundle:Users')->findOneById($session_user);
            $ContactList = $em->getRepository('AppBundle:ContactList')->findBy(array(
                    'user' => $user,
                    'status' => 1
                ));

            return $this->render('index/contacts.html.twig', array(

                        'title' => 'My Contacts',

                        'errors' => array(),

                        'contactListData' => $ContactList,

                        'metadetails' => $metadetails

            ));  
        }

        return $this->redirectToRoute('index');
    }

    /**
    * @Route("/delete-my-contact/{id}", name="delete-my-contact")
    */
    public function deleteMyContact($id,Request $request){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user =  $session->get('user_id');


        if($session_user){

            $em= $this->getDoctrine()->getManager();  

            $user = $em->getRepository('AppBundle:Users')->findOneById($session_user);  

            $ContactList = $em->getRepository('AppBundle:ContactList')->findOneById($id);
            
            if($ContactList){

                $em->remove($ContactList);

                $em->flush();

            }

            return $this->redirectToRoute('contacts');
        }

        return $this->redirectToRoute('index');
    }

    

    /**
    * @Route("/add-fund/", name="addfund")
    */
    public function addfund(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $em = $this->getDoctrine()->getManager();

        $session = new Session();
        $session_user =  $session->get('user_id');

        $metadetails = $this->get('app.helper')->GetMetaDetails(27);

        if($session_user){

             $fundData = $em->createQueryBuilder()
              ->select('a,p')
              ->from('AppBundle:AddFundLogs', 'a')
              ->innerJoin('AppBundle:PaymentTransactions', 'p', 'WITH', 'a.sessionVal=p.sessionValue')
              ->where('a.user = :uid')
              ->setParameter('uid',$session_user)
              ->getQuery()
              ->getResult(3);

            $security      = $em->getRepository('AppBundle:SecurityCentre')->findOneById(15);
             $amountadd = $security->statusValue;
  

            return $this->render('index/addfund.html.twig', array(

                        'title' => 'Addfund',

                        'data' => $fundData,

                        'errors' => '',

                        'maxamount' => $amountadd,

                        'metadetails' => $metadetails

            ));
        }

        return $this->redirectToRoute('index');
    }

     /**
    * @Route("/add-fund-payment/", name="addfund_payment")
    */
    public function addFundPayment(Request $request){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user =  $session->get('user_id');
        $metadetails = $this->get('app.helper')->GetMetaDetails(28);

        if($session_user && isset($_POST['add_fund_amount'])){

            $em = $this->getDoctrine()->getManager();

            $user = $em->getRepository('AppBundle:Users')->findOneById($session_user);

            $session_val = md5($session_user.time());

            $session->set('temp_session_user_add_fund',$session_val);

            $AddFundLogs = new AddFundLogs();

            $AddFundLogs->setAmount($_POST['add_fund_amount']);
            $AddFundLogs->setRemarks($_POST['add_fund_remarks']);
            $AddFundLogs->setStatus(0);
            $AddFundLogs->setUser($user);
            $AddFundLogs->setSessionVal($session_val);
            $AddFundLogs->setcurrency($_POST['add_fund_currency']);

            $em->persist($AddFundLogs);
            $em->flush();

            $host = $request->getHost();

            $clientToken = $this->getClientToken($host);

            $braintree_url = "http://".$host."/paydez/vendor/braintree/checkout-add-fund.php";

            $paysafe_url = "http://".$host."/paydez/vendor/paysafecard/checkout.php";

            return $this->render('index/addfund-payment.html.twig', array(

                        'title' => 'Addfund Payment',
                        'data' => array($AddFundLogs),
                        'braintreeurl' => $braintree_url,
                        'paysafeurl' => $paysafe_url,
                        'clientToken' => $clientToken,
                        'metadetails' => $metadetails



            ));
        }

        return $this->redirectToRoute('addfund');
    }

     /**
    * @Route("/add-fund-success/", name="addfund_success")
    */
    public function addFundSuccess(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        $session = new Session();
        $session_user =  $session->get('user_id');
        $session_add_fund_session =  $session->get('temp_session_user_add_fund');
        $metadetails = $this->get('app.helper')->GetMetaDetails(29);
        if($session_user && $session_add_fund_session){
            $em = $this->getDoctrine()->getManager();

            $data =  $em->createQueryBuilder()
                    ->select('a')
                    ->addSelect('p')
                    ->from('AppBundle:AddFundLogs', 'a')
                    ->innerJoin('AppBundle:PaymentTransactions','p','WITH','a.sessionVal=p.sessionValue')
                    ->where('a.user = :uid')
                    ->andWhere('a.sessionVal = :sid')
                    ->setParameter('uid',$session_user)
                    ->setParameter('sid',$session_add_fund_session)
                    ->orderBy('a.id','DESC')
                    ->getQuery()
                    ->getResult(3);

            return $this->render('index/addfund-success.html.twig', array(

                        'title' => 'Addfund Success',
                        'data' => $data,
                        'metadetails' => $metadetails

            ));
        }

        return $this->redirectToRoute('index');
    }

    /**
    * @Route("/show-user-notify-details", name="showNotifyDetails")
    */
    public function showNotifyDetails() {
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }
        if(isset($_POST['notify_id'])){

            $notify_id = $_POST['notify_id'];

            $em = $this->getDoctrine()->getManager();

            $notifyData = $em->getRepository('AppBundle:NoticeBoard')->findOneById($notify_id);

            $html = '<div class="modal-header bgbl">
                        <button data-dismiss="modal" class="close mrtcl" type="button">×</button>
                        <h4 class="modal-title text-center">'.$notifyData->getTitle().'</h4>
                      </div>
                      <div class="modal-body">
                        <div class="row">
                          <div class="col-md-12">
                            <p class="pstl">'.$notifyData->getDescription().'</p>
                          </div>
                        </div>
                      </div>';

            //set this status as read
            $session = new Session();

            $user_id = $session->get('user_id');

            $noticeReadStatus = new NotificationReadStatus();
            $noticeReadStatus->setNoticeBoardId($notify_id);
            $noticeReadStatus->setUserId($user_id);
            $validator = $this->get('validator');
            $errors    = $validator->validate($noticeReadStatus);

            echo $html;
            if(count($errors) == 0){
                $em->persist($noticeReadStatus);
                $em->flush();          
            }
            exit;           
        }

        return $this->redirectToRoute('index');
    }

    private function getConversionRates(){
      $endpoint = 'live';
      $access_key = '11111111111111111';

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

    /**
    * @Route("/send-request/", name="sendrequest")
    */
    public function sendrequest(){
        $helper = $this->get('app.helper')->checkUserSession();
        if($helper == 0){
            return $this->redirectToRoute('index');
        }

        $metadetails = $this->get('app.helper')->GetMetaDetails(30);
        $em = $this->getDoctrine()->getManager();
        $data = $em->getRepository('AppBundle:Countries')->findByStatus(1);
        $new_data = array();

        foreach ($data as $key => $value) {
          $flag = $value->flag;
          $flag = strtolower($flag);
          $value->flag = $flag;
        }

        $services = $em->getRepository('AppBundle:ApiServicesMaster')->findAll();
        $session = new Session();
        $session_user =  $session->get('user_id');
        $userdata = $em->getRepository('AppBundle:Users')->findOneById($session_user);
        $userdet = $em->getRepository('AppBundle:UserDetails')->findOneByUser($userdata);
        $email = $userdet->getemail();

        if($session_user){
            $query = $em->createQueryBuilder()
              ->select('u.id,u.num,u.oprName,u.originalCurrency,u.currency,u.convertedPrice,u.originalPrice,u.extensionCode,u.userId,u.createdDate,u.status,u.emailId,u.accountNumber')
              ->from('AppBundle:RechargeLogs','u')
              ->where('u.emailId= :email')
              ->setParameter('email', $email);
             
            $details = $query->getQuery()->getResult();

            $query = $em->createQueryBuilder()
              ->select('u.id,u.num,u.oprName,u.originalCurrency,u.currency,u.convertedPrice,u.originalPrice,u.extensionCode,u.userId,u.createdDate,u.status,u.emailId,u.accountNumber')
              ->from('AppBundle:RechargeLogs','u')
              ->where('u.userId= :userid')
              ->andwhere('u.rechargeType = 1 ')
              ->setParameter('userid', $session_user);
             
            $details2 = $query->getQuery()->getResult();

            return $this->render('index/sendrequest.html.twig', array(

                        'title' => 'Send Request For Recharge',

                        'data' => $details,

                        'data1' => $details2,

                        'country' => $data,

                        'api_service_id' => 9,

                        'services' => $services,

                        'errors' => '',

                        'metadetails' => $metadetails

            ));
        }

        return $this->redirectToRoute('index');
    }

    /**
    * @Route("/forgot-password/", name="forgot_password_user")
    * @Method("POST")
    */
    public function resetPassword(){

        $em = $this->getDoctrine()->getManager();

        if(isset($_POST)){

            $userEmail =  $_POST['user_email'];
            $userDetails = $em->getRepository('AppBundle:UserDetails')->findOneByEmail($userEmail);

            if($userDetails){
                $userEmail = $userDetails->getEmail();
                $newPassword = $this->generateRandomString();
                $userData = $userDetails->getUser();
                $userData->setPassword(md5($newPassword));
                $em->flush();
                $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')
                    ->findOneByTemplateName('Paydez - Reset Password');
                $subject = $emailTemplate->getTemplateName();
                $msg = $emailTemplate->getTemplateContent();
                $msg = str_replace('{username}', $userData->getUserName(), $msg);
                $msg = str_replace('{password}', $newPassword, $msg);

                if($emailTemplate){
                    $content = $emailTemplate->gettemplateContent();
                    $sendEmail = $this->get('app.helper')->sendMail($subject, $msg, $userEmail);
                    echo "Your password has been reset and mailed to ".$userEmail;
                    exit;
                }
            }

            echo "No account is associated with ".$userEmail;
            exit;
        }

        echo "Please enter your valid Email Id";
        exit;
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

    /**
    * @Route("/Saverechargerequest/", name="Saverechargerequest")
    */

    public function saverechargerequest(){
        
        $rechargelogs = new RechargeLogs();
        $service_type = $_POST['service_type'];
        $mobilenum = $_POST['mobilenum'];
        $operator_id = $_POST['operator_id'];
        $operator_name = $_POST['operator_name'];
        $currency = $_POST['currency'];
        $original_currency = $_POST['original_currency'];
        $converted_price = $_POST['converted_price'];
        $original_price = $_POST['original_price'];
        $extension_code = $_POST['extension_code'];
        $email = $_POST['emailid'];
        $session = new Session();
        $session_user =  $session->get('user_id');

        if($session_user){
            $sesion_val = $session_user;
        }else{
             $sesion_val = md5($mobilenum.time());
             $session->set('temp_session_user',$sesion_val);
        }

        $em = $this->getDoctrine()->getManager();
        $service_type_obj = $em->getRepository('AppBundle:ApiServicesMaster')->findOneById($service_type);
        $login="paydez";
        $password="111111111111111";
        $key=time();
        $md5=md5($login.$password.$key);
        
        $url = "https://fm.transfer-to.com/cgi-bin/shop/topup?login=paydez"."&key="
            .$key."&md5=".$md5."&content=".$operator_id."&info_type=operator&action=pricelist";
                
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

        $rechargelogs->setVat($currentvat);

        $rechargelogs->setTax($currenttax);

        $rechargelogs->setUserId($session_user);

        $rechargelogs->setNum($mobilenum);

        $rechargelogs->setOprId($operator_id);

        $rechargelogs->setOprName($operator_name);

        $rechargelogs->setCurrency($currency);

        $rechargelogs->setOriginalCurrency($original_currency);

        $rechargelogs->setConvertedPrice($converted_price);

        $rechargelogs->setOriginalPrice($original_price);

        $rechargelogs->setExtensionCode($extension_code);

        $rechargelogs->setSessionVal($sesion_val);

        $rechargelogs->setCreatedDate();

        $rechargelogs->setStatus('0');

        $rechargelogs->setServiceType($service_type_obj);

        $rechargelogs->setEmailId($email);

        $rechargelogs->setTimeSend(time());

        $rechargelogs->setrechargeType(1);

        if(isset($_POST['contact_id'])){
            $ContactList = $em->getRepository('AppBundle:ContactList')->findOneById($_POST['contact_id']);
            $session = new Session();
            $session_user =  $session->get('user_id');

            $rechargelogs->setContactList($ContactList);
            $rechargelogs->setUserId($session_user);
        }

        $em = $this->getDoctrine()->getManager();

        $em->persist($rechargelogs);

        $em->flush();

        echo "1";
        exit();
    }

    Private function getVatandTax($serviceid,$country){

        $em = $this->getDoctrine()->getManager();

        $query = $em->createQueryBuilder()
        ->select('u.vat,u.tax')
        ->from('AppBundle:Taxes', 'u')
        ->where('u.countryId = :id and u.serviceId = :value')
        ->setParameter('id', $country)
        ->setParameter('value', $serviceid);
        $exists = $query->getQuery()->getResult();

        return $exists;
    }

    /**
    * @Route("/send-remainder/{id}", name="sendremainder")
    */

    public function sendremainder(Request $request ,$id)
    {

        $em = $this->getDoctrine()->getManager();
        $data = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);
        $email      = $data->getemailId();
        $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneById(43);
        $subject = $emailTemplate->gettemplateName();

        if($emailTemplate){

	        $session = new Session();

	        $userid =  $session->get('user_id');

            $userdetails = $em->getRepository('AppBundle:Users')->findOneById($userid);

	        $username1 = $userdetails->getfName();

	        $username2 = $userdetails->getuserName();

            $userSenderdetails = $em->getRepository('AppBundle:Users')->findOneById($userid);
            
            $userSender = $em->getRepository('AppBundle:UserDetails')->findOneByUser($userSenderdetails);
            
	        $userReceipientdetails = $em->getRepository('AppBundle:UserDetails')->findOneByEmail($email);

	        $username1 = $userReceipientdetails->getUser()->getfName();

	    if($data->getServiceType()->getId()=="9"){
	        $username2 = $data->getExtensionCode().''.$data->getNum();
	    }else{
		    $username2 = $data->getAccountNumber();
	    }
	
            $serviceTypeName = $data->getServiceType()->getLabel(); 
	        $senderEmail = $userSender->getEmail();
            $emailTemplate = $em->getRepository('AppBundle:EmailTemplates')->findOneById(43);

	    $subject = $emailTemplate->gettemplateName();
	    $content = $emailTemplate->gettemplateContent();
	    $content = str_replace('{YOUREMAIL}', $email , $content);
	    $content = str_replace('{USEREMAIL}', $senderEmail , $content);
	    $content = str_replace('{SERVICETYPE}', $serviceTypeName , $content);
	    $content = str_replace('{ACCOUNTNUMBER}', $username2 , $content);

	    $content = str_replace('{useraccount}', $username2 , $content);//echo "<pre>";print_r($content);exit;
            $sendEmail = $this->sendMail($subject, $content, $email);
        }

        $this->addFlash(
                'notice',
                'Successfully send remainder'
        );

        return $this->redirectToRoute('sendrequest');
    }


     /**
    * @Route("/cancel-request/{id}", name="cancelrequest")
    */

    public function cancelrequest(Request $request ,$id)
    {

        $em = $this->getDoctrine()->getManager();
        $data = $em->getRepository('AppBundle:RechargeLogs')->findOneById($id);

        if($data){
            $em->remove($data);
            $em->flush();

            $this->addFlash(
                'notice',
                'Successfully deleted the request !'
            );
        }

        return $this->redirectToRoute('sendrequest');
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

    private function generateRandomString($length = 10){

        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }
}   
  
