<?php

use SendGrid\Mail\From;
use SendGrid\Mail\HtmlContent;
use SendGrid\Mail\PlainTextContent;
use SendGrid\Mail\MailSettings;
use SendGrid\Mail\SandBoxMode;
use SendGrid\Mail\Personalization;
use SendGrid\Mail\To;
use SendGrid\Mail\Mail;
use SendGrid\Mail\Subject;
use SendGrid\Mail\Substitution;
use \Component\CommunicationComponent\App\Http\Controllers\NotificationController;
use \Component\AccountComponent\App\Company;
use \Component\AccountComponent\App\Tenant;
use Component\JobComponent\App\CronStatistics;
use Component\OrdersComponent\App\Subscriptions;
use Component\AccountComponent\App\NotificationsList;
use Component\AccountComponent\App\NotificationsManagement;
use Component\UserComponent\App\User;
use Spatie\ArrayToXml\ArrayToXml;
use Component\ProcurementComponent\App\Exceptions\InvalidResponseException;
use Illuminate\Support\Arr;
use Component\CommunicationComponent\App\Notification;
use \Component\CatalogComponent\App\Http\Controllers\CatalogComponentController;
use \Component\OrdersComponent\App\Http\Controllers\OrderController;
use \Component\CatalogComponent\App\Service;
use \Component\CatalogComponent\App\Addons;
use \Component\AccountComponent\App\Http\Controllers\AccountComponentController;

use Monolog\Logger;
use Monolog\Handler\RotatingFileHandler;
use Illuminate\Http\Request;
use Component\AccountComponent\App\Setting;
use Carbon\Carbon;

use LaravelFCM\Message\OptionsBuilder;
use LaravelFCM\Message\PayloadDataBuilder;
use LaravelFCM\Message\PayloadNotificationBuilder;
use LaravelFCM\Facades\FCM;

use App\Jobs\SendEmail;
use Component\EmailNotificationComponent\App\EmailNotification;
use Illuminate\Support\Str;

/**
 * Create API response
 **/
function createResponseData($code, $success, $message = '', $data = [], $pagination, Illuminate\Http\Request $request, $skip = false ){

    $response = [];
   if(!$skip){
        if(gettype($message) == 'object') {
            $message = collect($message->getMessages())->map(function ($item) {
                return collect($item)->map(function ($item) {
                    $item = preg_replace("/(\.|,)$/", "", $item);
                    $item = ucfirst(str_replace(' id ', ' ID ', $item));
                    $item = preg_replace_callback( '/[.!?].*?\w/', function ($matches) { return strtoupper($matches[0]); }, $item);
                    return preg_replace_callback( '/[[A-Za-z0-9.]+@[A-Za-z0-9.]+/', function ($matches) { return strtolower($matches[0]); }, $item);
                })->toArray();
            });
        }elseif (gettype($message) == 'array'){
            $new_message = (object)[];
            foreach ($message as $index=>$item){
                foreach ($item as $key=>$value){
                    $value = ucfirst(str_replace(' id ', ' ID ', strtolower( preg_replace("/(\.|,)$/","",$value))));
                    $value = preg_replace_callback( '/[.!?].*?\w/', function ($matches) { return strtoupper($matches[0]); }, $value);
                    $new_message->{$index}[] = preg_replace_callback( '/[[A-Za-z0-9.]+@[A-Za-z0-9.]+/', function ($matches) { return strtolower($matches[0]); }, $value);
                }
            }
            $message = $new_message;
        }else{
            if(is_array($message)){
                $messages = $message;
                $message = [];
                foreach($messages as $msg){
                    if(is_array($msg)){
                        $msgs = $msg;
                        foreach($msgs as $m){
                            $message = ucfirst(str_replace(' id ', ' ID ', strtolower( preg_replace("/(\.|,)$/","",$m))));
                            $message = preg_replace_callback( '/[.!?].*?\w/', function ($matches) { return strtoupper($matches[0]); }, $message);
                            $message = preg_replace_callback( '/[[A-Za-z0-9.]+@[A-Za-z0-9.]+/', function ($matches) { return strtolower($matches[0]); }, $message);
                        }
                    }
                    else {
                        $message = ucfirst(str_replace(' id ', ' ID ', strtolower(preg_replace("/(\.|,)$/", "", $msg))));
                        $message = preg_replace_callback('/[.!?].*?\w/', function ($matches) {
                            return strtoupper($matches[0]);
                        }, $message);
                        $message = preg_replace_callback('/[[A-Za-z0-9.]+@[A-Za-z0-9.]+/', function ($matches) {
                            return strtolower($matches[0]);
                        }, $message);
                    }
                }
            }else {
                $message = ucfirst(str_replace(' id ', ' ID ', strtolower(preg_replace("/(\.|,)$/", "", $message))));
                $message = preg_replace_callback('/[.!?].*?\w/', function ($matches) {
                    return strtoupper($matches[0]);
                }, $message);
                $message = preg_replace_callback('/[[A-Za-z0-9.]+@[A-Za-z0-9.]+/', function ($matches) {
                    return strtolower($matches[0]);
                }, $message);
            }
         }
    }
    $response['status_code'] = $code;
    $response['success'] = $success;
    $response['message'] = $message;

    if($pagination){
        $response['pages'] = $pagination;
    }

    $response['data'] = $data;

    return $response;
}

/**
 * Create Grid API response
 **/
function createGridResponseData($code, $success, $message = '',$embedded = [], $entities = [],$pagination, Illuminate\Http\Request $request){

    $response = [];

    $response['status_code'] = $code;
    $response['success'] = $success;
    $response['message'] = $message;

    if($pagination){
        $response['pages'] = $pagination;
    }

    $response['embedded'] = $embedded;
    $response['entities'] = $entities;

    return $response;
}

/**
 * Extract Domain from email
 *
 * @param (string) $email
 * @return (string) $domain
 */
function extractDomain($email)
{

    $domain = strstr($email,'@');
    if ($domain) {
        $domain = str_replace('@', '', $domain);
    }

    return $domain;
}

/**
 * Verifies if given parameter is json, incase of not json then aborts
 *
 * @param (string) $json
 * @return
 */
function isJsonRequestBody($jsonRequestBody)
{
    if (!empty($jsonRequestBody) && empty(json_decode($jsonRequestBody))) { // if received json from raw body is not valid
        return abort(400, 'Invalid json');
    }

}

/**
 * Base 64 encode a string with url encode
 * @param $str
 * @return string
 */
function base64urlEncode($str) {
    return rtrim(strtr(base64_encode($str), '+/', '-_'), '=');
}

/**
 * Base 64 decode a string with url encode
 * @param $str
 * @return string
 */
function base64urlDecode($str) {
    return base64_decode(str_pad(strtr($str, '-_', '+/'), strlen($str) % 4, '=', STR_PAD_RIGHT));
}

/*
 * Check if user have permission
 * @param (string) $permission
 * @param (string) $accessType
 * @return (bool)
 */
function can($permission, $accessType) {

    $userPermissions = app('request')->user()->getAllPermissionsFormAllRoles();
    if ($userPermissions->get($permission) != $accessType && $userPermissions->get($permission) != "full_access") {
        return false;
    }

    return true;
}

/**
 * Prepare or Repair criteria (to be used by fetch and export methods)
 *
 * @param array $criteria
 * @return array|mixed
 */
function prepareCriteria ($criteria = []) {
    $defaultCriteria = config('main.criteria');

    if (!empty($criteria)) {
        switch (gettype($criteria)) {
            case 'array':
                $criteria = array_merge($defaultCriteria, (array) $criteria);

                if (!empty($criteria['fs'])){
                    $criteria['fs'] = array_filter($criteria['fs']);
                }

                if (!empty($criteria['disp'])){
                    $criteria['disp'] = array_filter($criteria['disp']);
                }

                if (empty($criteria['r']) || (int) $criteria['r'] <= 0){
                    $criteria['r'] = config('main.criteria.r');
                } else {
                    $criteria['r'] = (int) $criteria['r'];
                }

                if (empty($criteria['o'])){
                    $criteria['o'] = config('main.criteria.o');
                }

                if (isset($criteria['d']) && ((int) $criteria['d'] !== 1 && (int) $criteria['d'] !== 0)){
                    $criteria['d'] = config('main.criteria.d');
                }

                break;
            case 'string':
                $criteria = json_decode($criteria, true);

                if (empty($criteria['o'])){
                    $criteria['o'] = config('main.criteria.o');
                }

                if (empty($criteria['r']) || (int) $criteria['r'] <= 0){
                    $criteria['r'] = config('main.criteria.r');
                } else {
                    $criteria['r'] = (int) $criteria['r'];
                }

                if (isset($criteria['d']) && ((int) $criteria['d'] !== 1 && (int) $criteria['d'] !== 0)){
                    $criteria['d'] = config('main.criteria.d');
                }

                if (!empty($criteria['fs'])) {
                    $criteria['fs'] = array_filter($criteria['fs']);
                } else {
                    $criteria['fs'] = config('main.criteria.fs');
                }

                if (!empty($criteria['disp'])) {
                    $criteria['disp'] = array_filter($criteria['disp']);
                } else {
                    $criteria['disp'] = config('main.criteria.disp');
                }

                break;
            default:
                $criteria = $defaultCriteria;
                break;
        }
    } else {
        $criteria = $defaultCriteria;
    }

    // url-Decoding values of 'fs' key in Criteria recursively
    if (isset($criteria['fs']) && !empty($criteria['fs'])) {
        foreach ($criteria['fs'] as $key => $fs) {
            if (is_array($fs)) {
                foreach ($fs as $i => $f) {
                    $criteria['fs'][$key][$i] = rawurldecode($f);
                }
            } else {
                $criteria['fs'][$key] = rawurldecode($fs);
            }
        }
    }

    return json_decode(json_encode($criteria));
}

/**
 * Function to insert role notification entry/entries in database.
 *
 * @param  array   type(string), account_id(int), role(string), body(string), created_at(datetime), updated_at(datetime)
 * @return array
 */
function createNotificationByRole($notificationData)
{
    $notificationObj = New NotificationController();

    return $notificationObj->createNotificationByRole($notificationData);
}

/**
 * Function to insert role notification entry/entries in database.
 *
 * @param  array   type(string), account_id(int), role(string), body(string), created_at(datetime), updated_at(datetime)
 * @return array
 */
function notifyCompanyUsers($notificationData)
{
    $notificationObj = New NotificationController();

    return $notificationObj->createNotificationForAllUsers($notificationData);
}

/**
 * Function to insert notification entry/entries in database for mgmnt portal admins.
 *
 * @param  array   type(string), account_id(int), permission(string), body(string), created_at(datetime), updated_at(datetime)
 * @return array
 */
function createNotificationForMgmntPortalAdmins($notificationData)
{
    if(!isset($notificationData['type'])) {
        $notificationData['type'] = 'user';
    }

    if(!isset($notificationData['permission'])) {
        $notificationData['permission'] = ['notification_administrator'];
    }

    if(!isset($notificationData['created_at'])) {
        $notificationData['created_at'] = date('Y-m-d H:i:s');
    }

    if(!isset($notificationData['updated_at'])) {
        $notificationData['updated_at'] = date('Y-m-d H:i:s');
    }

    $notificationObj = New NotificationController();

    return $notificationObj->createNotificationForMgmntPortalAdmins($notificationData);
}

/**
 * Function to insert user notification entry/entries in database.
 *
 * @param  array   type(string), user_id(int), account_id(int), body(string), created_at(datetime), updated_at(datetime)
 * @return array
 */
function createNotificationByUserId($notificationData)
{
    if(!isset($notificationData['type'])) {
        $notificationData['type'] = 'user';
    }

    if(!isset($notificationData['created_at'])) {
        $notificationData['created_at'] = date('Y-m-d H:i:s');
    }

    if(!isset($notificationData['updated_at'])) {
        $notificationData['updated_at'] = date('Y-m-d H:i:s');
    }

    $notificationObj = New NotificationController();

    return $notificationObj->createNotificationByUserId($notificationData);
}

/**
 * Function to fetch/get mgmnt portal admin(s) email(s).
 *
 * @param  string role(string)
 * @return array
 */
function getMgmntPortalAdminsEmails($permission, $withName = false)
{
    $notificationObj = New NotificationController();

    return $notificationObj->getMgmntPortalAdminsEmails($permission, $withName);
}

function covertStringToDate($dateString){

    // dd($dateString);
    $case = $dateString;

    if(strpos($dateString,'daybefore') != FALSE){
        $case = 'daybefore';
    }
    if(strpos($dateString,'dayfromnow') != FALSE){
        $case = 'dayfromnow';
    }
    if(strpos($dateString,'weekbefore') != FALSE){
        $case = 'weekbefore';
    }
    if(strpos($dateString,'weekfromnow') != FALSE){
        $case = 'weekfromnow';
    }
    if(strpos($dateString,'monthbefore') != FALSE){
        $case = 'monthbefore';
    }
    if(strpos($dateString,'monthfromnow') != FALSE){
        $case = 'monthfromnow';
    }
    if(strpos($dateString,'yearbefore') != FALSE){
        $case = 'yearbefore';
    }
    if(strpos($dateString,'yearfromnow') != FALSE){
        $case = 'yearfromnow';
    }
    switch ($case){
        case "today":
            return date('Y-m-d');
            break;
        case "yesterday":
            return date('Y-m-d',strtotime("-1 days"));
            break;
        case "tomorrow":
            return date('Y-m-d',strtotime("+1 days"));
            break;
        case 'daybefore':
            $days = strstr($dateString, 'daybefore',1);
            return date('Y-m-d',strtotime("-".$days." days"));
            break;
        case 'dayfromnow':
            $days = strstr($dateString, 'dayfromnow',1);
            return date('Y-m-d',strtotime("+".$days." days"));
            break;
        case "startofthismonth":
            return date('Y-m-d',strtotime("first day of this month"));
            break;
        case "endtofthismonth":
            return date('Y-m-d',strtotime("last day of this month"));
            break;
        case "startoflastmonth":
            return date('Y-m-d',strtotime("first day of last month"));
            break;
        case "endtoflastmonth":
            return date('Y-m-d',strtotime("last day of last month"));
            break;
        case "startofnextmonth":
            return date('Y-m-d',strtotime("first day of next month"));
            break;
        case "endtofnextmonth":
            return date('Y-m-d',strtotime("last day of next month"));
            break;
        case "monthbefore": // here to write logic
            $month = strstr($dateString, 'monthbefore',1);
            return date('Y-m-d',strtotime("-".$month." months"));
            break;
        case "monthfromnow": // here to write logic
            $month = strstr($dateString, 'monthfromnow',1);
            return date('Y-m-d',strtotime("+".$month." months"));
            break;
        case 'weekbefore':
            $week = strstr($dateString, 'weekbefore',1);
            return date('Y-m-d',strtotime("-".$week." week"));
            break;
        case 'weekfromnow':
            $week = strstr($dateString, 'weekfromnow',1);
            return date('Y-m-d',strtotime("+".$week." week"));
            break;
        case 'yearfromnow':
            $year = strstr($dateString, 'yearfromnow',1);
            return date('Y-m-d',strtotime("+".$year." year"));
            break;
        case 'yearbefore':
            $year = strstr($dateString, 'yearbefore',1);
            return date('Y-m-d',strtotime("-".$year." year"));
            break;
        case "startofthisweek":
            return date('Y-m-d',$ts = strtotime('This Monday', time()));
            break;
        case "endtofthisweek":
            return date('Y-m-d',strtotime('This Friday', time()));
            break;
        case "startoflastweek":
            return date('Y-m-d',strtotime('Last Monday', time()));
            break;
        case "endtoflastweek":
            return date('Y-m-d',strtotime('Last Friday', time()));
            break;
        case "startofnextweek":
            return date('Y-m-d',strtotime('Next Monday', time()));
            break;
        case "endtofnextweek":
            return date('Y-m-d',strtotime('Next Friday', time()));
            break;
        case "startofnextquarter":
            return getQuarter((new DateTime())->modify('+3 Months'))['start'];
        case "startofthisquarter":
            return getQuarter((new DateTime())->modify('This Months'))['start'];
        case "startoflastquarter":
            return getQuarter((new DateTime())->modify('-3 Months'))['start'];
            break;
        case "endofnextquarter":
            return getQuarter((new DateTime())->modify('+3 Months'))['end'];
        case "endofthisquarter":
            return getQuarter((new DateTime())->modify('This Months'))['end'];
        case "endoflastquarter":
            return getQuarter((new DateTime())->modify('-3 Months'))['end'];
            break;
        case "priorbusinessday":
            return priorBussinessDate();
            break;
        case "nextbusinessday":
            return nextBussinessDate();
            break;
        default:
            return $dateString;
    }

}

function priorBussinessDate(){
    // (0 for Sunday, 6 for Saturday)
    $currentWeekDay = date( "w" );

    if ($currentWeekDay == 1)
    {
        $lastWorkingDay = date("d", strtotime("-3 day"));
    }
    else if ($currentWeekDay == 0)
    {
        $lastWorkingDay = date("d", strtotime("-2 day"));
    }
    else
    {
        $lastWorkingDay = date("d", strtotime("-1 day"));
    }
    return $lastWorkingDay;
}

function nextBussinessDate(){
    // (0 for Sunday, 6 for Saturday)
    $currentWeekDay = date( "w" );
    if ($currentWeekDay == 5)
    {
        $nextWorkingDay = date("d", strtotime("3 day"));
    }
    else if ($currentWeekDay == 6)
    {
        $nextWorkingDay = date("d", strtotime("2 day"));
    }
    else
    {
        $nextWorkingDay = date("d", strtotime("1 day"));
    }
    return $nextWorkingDay;
}

function getQuarter(\DateTime $DateTime) {
    $y = $DateTime->format('Y');
    $m = $DateTime->format('m');
    switch($m) {
        case $m >= 1 && $m <= 3:
            $start = '01/01/'.$y;
            $end = (new DateTime('03/1/'.$y))->modify('Last day of this month')->format('m/d/Y');
            break;
        case $m >= 4 && $m <= 6:
            $start = '04/01/'.$y;
            $end = (new DateTime('06/1/'.$y))->modify('Last day of this month')->format('m/d/Y');
            break;
        case $m >= 7 && $m <= 9:
            $start = '07/01/'.$y;
            $end = (new DateTime('09/1/'.$y))->modify('Last day of this month')->format('m/d/Y');
            break;
        case $m >= 10 && $m <= 12:
            $start = '10/01/'.$y;
            $end = (new DateTime('12/1/'.$y))->modify('Last day of this month')->format('m/d/Y');
            break;
    }
    $start = date('Y-m-d',strtotime($start));
    $end = date('Y-m-d',strtotime($end));
    return array("start"=>$start,"end"=>$end);
}

function getAbsClassName($object) {
    $classNameWithNamespace = get_class($object);

    if(substr($classNameWithNamespace, strrpos($classNameWithNamespace, '\\')+1) == "SendEmailCCMUserController") {
        return "Send Emails";
    }

    return substr($classNameWithNamespace, strrpos($classNameWithNamespace, '\\')+1);
}

/**
 * Get System details by company id
 * @param (integer) $id
 * return (array)
 */
function getSystemFields( $id , $providerId = null, $tenantId = null)
{
    $data = [];
    $addressOfUse = [];
    //get company details with address of use
    $item = Company::where('id', $id)->with('organizationDomain', 'users', 'addresses', 'addresses.state')->first()->toArray();
    $companyTenantQuery = Tenant::where('company_id', $id)->where('provider_id', $providerId)->orderBy('id', 'desc');
    if($tenantId !== null)
        $companyTenantQuery->where('id', $tenantId);
    $companyTenant = $companyTenantQuery->first();
    if ($item) {
        //check address and assigned
        if (isset($item['addresses']))
        {
            $item['addresses'] = collect($item['addresses'])->keyBy('type');
            $addressOfUse = $item['addresses']->toArray();
            unset($addressOfUse['id']);
        }

        //check user and assigned
        if (isset($item['users']))
        {
            $companyUser = $item['users'];
        }

        //check domain and assigned
        if (isset($item['organization_domain']))
        {
            $companyDomain = $item['organization_domain'];
        }

        if(isset($companyTenant)){
            $data['tenant'] = $companyTenant;
        }

        //get company detail without address
        $item = Collect($item);
        $data['company'] = $item->except('addresses', 'organization_domain', 'users')->toArray();
        $data['addresses'] = $addressOfUse;
        $data['users'] = $companyUser;
        $data['domain'] = $companyDomain;
    }
    return $data;
}

function json_error(){
    switch (json_last_error()) {
        case JSON_ERROR_NONE:
            echo ' - No errors';
            break;
        case JSON_ERROR_DEPTH:
            echo ' - Maximum stack depth exceeded';
            break;
        case JSON_ERROR_STATE_MISMATCH:
            echo ' - Underflow or the modes mismatch';
            break;
        case JSON_ERROR_CTRL_CHAR:
            echo ' - Unexpected control character found';
            break;
        case JSON_ERROR_SYNTAX:
            echo ' - Syntax error, malformed JSON';
            break;
        case JSON_ERROR_UTF8:
            echo ' - Malformed UTF-8 characters, possibly incorrectly encoded';
            break;
        default:
            echo ' - Unknown error';
            break;
    }
}

function jsonClean($json){
    $str_response = mb_convert_encoding($json, 'utf-8', 'auto');

    for ($i = 0; $i <= 31; ++$i) {
        $str_response = str_replace(chr($i), "", $str_response);
    }
    $str_response = str_replace(chr(127), "", $str_response);

    if (0 === strpos(bin2hex($str_response), 'efbbbf')) {
        $str_response = substr($str_response, 3);
    }

    return $str_response;
}

//function attrite($source, $data){
//    if(is_array($source)){
//        $final = [];
//        foreach ($source as $key => $value) {
//            $final[$key] = attrite($source[$key], $data[$key]);
//        }
//        return $final;
//    }
//    return $data;
//}



//function requestDataMapper($source, $data, $output_type){
//    foreach ($source as $key => $value) {
//        $source[$key] = attrite($source[$key], $data[$key]);
//    }
//
//    if($output_type == 'json'){
//        return json_encode($source);
//    }
//    else {
//        return ArrayToXml::convert($source);
//    }
//}

/**
 * Create User Specific Logs
 *
 * @param $method
 * @param $endPoint
 * @param $data
 * @param $headers
 * @param Request $request
 */
function setApiLog($method, $endPoint, $data, $headers, Request $request)
{
    $auth = $request->user();

    $user_id = !empty($auth->id) ? $auth->id : 0;
    $first_name = !empty($auth->first_name) ? $auth->first_name : 'System';
    $last_name = !empty($auth->last_name) ? $auth->last_name : '';

    $data['Request_headers'] = $headers;

    $handler = new RotatingFileHandler(storage_path() . '/logs/' . $user_id . '.log', 0, Logger::INFO, true, 0664);
    $logger = new Logger($first_name . " " . $last_name);

    $handler->setFilenameFormat('{date}_{filename}', 'Y_m_d');
    $logger->pushHandler($handler);
    $array = [$method . ' - ' . $endPoint, json_encode($data)];

    $logger->addError('API-Response', $array);
}

// set data as per send grid template required.
function asJSON($data)
{
    $json = json_encode($data);
    $json = preg_replace('/(["\]}])([,:])(["\[{])/', '$1$2 $3', $json);
    return $json;
}


    /**
     * @param $data
     * @param $value
     * @return mixed
     */
    function handleDefaultValue($data, $value)
    {
        $defualt_text = '';
        if ( false !== strpos( $value, '[' ) ) {
            $defualt_text = ltrim(rtrim(strstr($value, '[', 0), ']'), '[');
            $value = strstr($value, '[', 1);
        }

        try
        {
            $final_value = arr::get($data, $value, $value).$defualt_text;
        }
        catch(Exception $e) {
            $final_value = $value;
        }

        return $final_value;
    }

    function attrite($request, $data, $is_response = false)
    {
        if (is_array($request))
        {
            foreach ($request as $key => $value)
            {
                if (is_array($value))
                {
                    $request[$key] = attrite($value, $data);
                }
                else
                {
                    $translated = handleDefaultValue($data, $value);
                    if ($is_response && $translated == $value)
                    {
                        // Raise error -
                        throw new InvalidResponseException('Invalid Response Exception.');
                    }
                    $request[$key] = $translated;
                }
            }
        }

        return $request;
    }

    function setEmailTemplate($emailBody, $companyId, $providerId = null)
    {
        $systemFields = getSystemFields($companyId , $providerId);

        // get all values that are wrapped in {}
        preg_match_all('/\B{[a-zA-Z0-9._\s]+}\B/', $emailBody, $matches);
        $foundKeys  = $matches[0];

        foreach ($foundKeys as $val)
        {
            $key = preg_replace('~[{}]~', '', $val);
            $searched = attrite([$key], $systemFields);
            $searched = $searched[0];

            if($searched != $key)
            {
                $emailBody = preg_replace('{' . $val . '}', $searched, $emailBody);
            }
        }

        return $emailBody;
    }

if (! function_exists('saveEmailNotification')){
    function saveEmailNotification($params){
        $to_emails = filterEmails(optional(optional($params)['to'])['email']);
        $tos = [];
        if(is_array($to_emails) && count($to_emails) > 0) {
            foreach ($to_emails as $to_email) {
                $user = User::where([['email', '=', $to_email], ['portal_type', '=', 'customer']])->first();
                if (NULL !== $user && null !== optional($user)->company_id){
                    $email_obj = new \StdClass();
                    $email_obj->email = $to_email;
                    $email_obj->company_id = optional($user)->company_id;
                    $tos[] = $email_obj;
                }
            }
        }
        if(count($tos) > 0){
            foreach($tos as $to){
                $maxLimit = 9;
                $count = intval((new EmailNotification())->where('to', $to->email)->count());
                if($count > $maxLimit){
                    $limit = $count - $maxLimit;
                    $email_notifications = (new EmailNotification())->select('id')->where('to', $to->email)->orderBy('id', 'asc')->offset(0)->limit($limit)->get();
                    $ids_need_to_delete = $email_notifications->pluck('id');
                    (new EmailNotification())->whereIn('id', $ids_need_to_delete)->delete();
                }
                $email_notification = new EmailNotification();
                $email_notification->company_id = $to->company_id;
                $email_notification->date = Carbon::now()->format('Y-m-d');
                $email_notification->params = $params;
                $email_notification->template_id = optional(optional($params)['template'])['id'];
                $email_notification->time = Carbon::now()->format('H:i:s');
                $email_notification->to = $to->email;
                $email_notification->type = getOnlySubject((isset($params['subject']['title'])) ?  $params['subject']['title'] : "");
                $email_notification->save();
            }
        }
    }
}

if (! function_exists('getAllUsers')){
    function getAllUsers(){
        return User::select('email', 'first_name', 'last_name')->get();
    }
}

if (! function_exists('getUserFullNameByEmail')){
    function getUserFullNameByEmail($users, $email){
        $user = optional($users)->filter(function($user) use ($email) {
            return $user->email == $email;
        })->first();
        if($user !== null && is_object($user))
            return $user->first_name . ' ' . $user->last_name;
        return '';
    }
}

/**
 * Generic method to Send Email Through Configured Email Service Provider
 *
 * @param $params
 * @return bool|string
 */
if (! function_exists('sendEmail')){
    function sendEmail($params){
        try{
            if(optional(optional($params)['to'])['email'] !== null && optional(optional($params)['to'])['email'] !== ''){
                $params['to']['email'] = filterEmailString($params['to']['email']);
            }
            if(!isset($params['resend']))
                saveEmailNotification($params);
            if (env('APP_ENV') != 'testing') {
                // if environment is not testing then send email else skip the send email process
                $to_email = (isset($params['to']['email'])) ? $params['to']['email'] : "";
                $to_email = filterEmails($to_email);
                $emails_optout = [];
                if (isset($params['user_email'])) {
                    foreach (filterEmails($params['user_email']) as $email) {
                        $params['user_email'] = $email;
                        if(isset($params['module_name']) && isset($params['event_name']) && isset($params['notification_type'])
                            && !isNotificationsEnabled($params)){
                            // No Email required to send
                            $emails_optout[] =  $email;
                        }
                    }
                    $to_email = array_diff($to_email, array_unique($emails_optout));
                }
                $fails = [];
                if (!empty($to_email)) {
                    if(config('main.debug') === true && (strtolower(trim(config('main.env'))) === 'local' || strtolower(trim(config('main.env'))) === 'development')){
                        $to_email = array_filter($to_email);
                    }else{
                        $to_email = array_filter(array_merge($to_email, [config('main.default_email')]));
                    }
                    $to_email = array_map('trim', $to_email);
                    $to_email = array_filter(array_unique($to_email));
                    $sendGridMail = new Mail();
                    $users = getAllUsers();

                    // these are two test variables please comment both when in live mode
                    $to_email = filterEmails(config('main.test_email_to'));
                    $params['cc'] = [];

                    foreach ($to_email as $email) {
                        $sendGridMail->addTo(trim($email), getUserFullNameByEmail($users, trim($email)));
                    }
                    $subject = (isset($params['subject']['title'])) ?  $params['subject']['title'] : "";
                    $from_name = (isset($params['from']['name'])) ? $params['from']['name'] : "";
                    $from_email = (isset($params['from']['email'])) ? $params['from']['email'] : config('main.from_email');
                    $template_id = (isset($params['template']['id'])) ? $params['template']['id'] : "";
                    $params['template_data']['year'] = \Carbon\Carbon::now()->format('Y');
                    if(!empty($params['auth_full_name']))
                        $params['template_data']['auth_full_name'] = $params['auth_full_name'];
                    if(!empty(config('main.app_full_name_r')))
                        $params['template_data']['app_full_name_r'] = config('main.app_full_name_r');
                    if(!empty(config('main.app_full_name')))
                        $params['template_data']['app_full_name'] = config('main.app_full_name');
                    if(!empty(config('main.app_short_name')))
                        $params['template_data']['app_short_name'] = config('main.app_short_name');
                    if(!empty(config('main.app_short_name_r')))
                        $params['template_data']['app_short_name_r'] = config('main.app_short_name_r');
                    if(!empty(config('main.email_header_logo_path')))
                        $params['template_data']['email_header_logo_path'] = config('main.email_header_logo_path');
                    if(!empty($subject) && $subject !== "")
                        $params['template_data']['only_subject'] = getOnlySubject($subject);
                    else
                        $params['template_data']['only_subject'] = "";
                    $params['template_data']['subject'] = $subject;

                    //E-66 subject and only subject will same
                    if($params['template']['id'] == config('email_templates.customer_multi_factor_authentication.template_id')) {
                        $params['template_data']['only_subject'] = $subject;
                    }
                    
                    foreach ($params['template_data'] as $key => $val){
                        $sendGridMail->addDynamicTemplateData(
                            new Substitution($key, $val)
                        );
                    }
                    $sendGridMail->setFrom($from_email, $from_name);
                    $sendGridMail->setSubject($subject);
                    $sendGridMail->setTemplateId($template_id);
                    if(isset($params['cc']) && is_array($params['cc']) && count($params['cc']) > 0){
                        foreach($params['cc'] as $cc){
                            foreach($cc as $ccEmail => $ccName){
                                $sendGridMail->addCc($ccEmail, $ccName);
                            }
                        }
                    }
                    $sendGrid = new \SendGrid(config('main.sendgrid.V3_api_key'));
                    $sendGrid->send($sendGridMail);
                }
            }
        }catch (Exception $e) {
            $fails[] = 'Caught exception: '.  $e->getMessage();
        }
        if (!empty($fails)) {
            \Log::info("Email Sending Fails: " . json_encode($fails));
            return false;
        }
        return true;
    }
}

/**
 * Generic method to Send Email With Attachment(s) Through Configured Email Service Provider
 *
 * @param array $params
 * @return bool|string
 */

/*
 * Sample Data for Params Array
 * $params = array(
                'files' => array( array('path' => $filePath, 'name' => $fileName) ),
                'to' => array('email' => $email),
                'subject' => array('title' => $subject),
                'from' => array('name' => $fromName, 'email' => $fromEmail),
                'template' => array('id' => $templateId),
                'template_data' => array( '{{username}}' => 'username'));
 *
 */
if (!function_exists('sendEmailWithAttachment')) {
    function sendEmailWithAttachment($params){
        try {
            if(optional(optional($params)['to'])['email'] !== null && optional(optional($params)['to'])['email'] !== ''){
                $params['to']['email'] = filterEmailString($params['to']['email']);
            }
            $to_email = (isset($params['to']['email'])) ? $params['to']['email'] : "";
            // for comma separated emails
            $to_email_array = array_filter(explode(",", $to_email));
            $to_email = (count($to_email_array) > 1) ? $to_email_array : [$to_email];
            // removed empty indexes
            $to_email = array_filter($to_email);
            $fails = [];
            if (!empty($to_email)) {
                if(config('main.debug') === true && (strtolower(trim(config('main.env'))) === 'local' || strtolower(trim(config('main.env'))) === 'development')){
                    $to_email = array_filter($to_email);
                }else{
                    $to_email = array_filter(array_merge($to_email, [config('main.default_email')]));
                }
                $to_email = array_map('trim', $to_email);
                $to_email = array_filter(array_unique($to_email));
                $sendGridMail = new Mail();
                $users = getAllUsers();

                // these are two test variables please comment both when in live mode
                $to_email = filterEmails(config('main.test_email_to'));
                $params['cc'] = [];

                foreach ($to_email as $email) {
                    $sendGridMail->addTo(trim($email), getUserFullNameByEmail($users, trim($email)));
                }
                $subject = (isset($params['subject']['title'])) ? $params['subject']['title'] : "";
                $from_name = (isset($params['from']['name'])) ? $params['from']['name'] : "";
                $from_email = (isset($params['from']['email'])) ? $params['from']['email'] : config('main.from_email');
                $template_id = (isset($params['template']['id'])) ? $params['template']['id'] : "";
                $params['template_data']['year'] = \Carbon\Carbon::now()->format('Y');
                if (!empty($params['auth_full_name']))
                    $params['template_data']['auth_full_name'] = $params['auth_full_name'];
                if(!empty(config('main.app_full_name_r')))
                    $params['template_data']['app_full_name_r'] = config('main.app_full_name_r');
                if(!empty(config('main.app_full_name')))
                    $params['template_data']['app_full_name'] = config('main.app_full_name');
                if(!empty(config('main.app_short_name')))
                    $params['template_data']['app_short_name'] = config('main.app_short_name');
                if(!empty(config('main.app_short_name_r')))
                    $params['template_data']['app_short_name_r'] = config('main.app_short_name_r');
                if(!empty(config('main.email_header_logo_path')))
                    $params['template_data']['email_header_logo_path'] = config('main.email_header_logo_path');
                if(!empty($subject) && $subject !== "")
                    $params['template_data']['only_subject'] = getOnlySubject($subject);
                else
                    $params['template_data']['only_subject'] = "";
                $params['template_data']['subject'] = $subject;
                foreach ($params['template_data'] as $key => $val) {
                    $sendGridMail->addDynamicTemplateData(
                        new Substitution($key, $val)
                    );
                }
                // attaching files
                foreach ($params['files'] as $file) {
                    $file_encoded = base64_encode(file_get_contents($file['path']));
                    $sendGridMail->addAttachment(
                        $file_encoded,
                        "application/pdf",
                        $file['name'],
                        "attachment"
                    );
                }
                $sendGridMail->setFrom($from_email, $from_name);
                $sendGridMail->setSubject($subject);
                $sendGridMail->setTemplateId($template_id);
                if(isset($params['cc']) && is_array($params['cc']) && count($params['cc']) > 0){
                    foreach($params['cc'] as $cc){
                        foreach($cc as $ccEmail => $ccName){
                            $sendGridMail->addCc($ccEmail, $ccName);
                        }
                    }
                }
                $sendGrid = new \SendGrid(config('main.sendgrid.V3_api_key'));
                $sendGrid->send($sendGridMail);
            }
        } catch (Exception $e) {
            $fails[] = 'Caught exception: '.  $e->getMessage();
        }
        if (!empty($mail_fails))
            $fails[] = $mail_fails;
        if (!empty($fails)) {
            \Log::info("Email Sending Fails: " . json_encode($fails));
            return false;
        }
        return true;
    }

    /**
     * Method to insert cron jobs logs into database
     *
     * @param array $data
     * @return bool|string
     */

    if (! function_exists('logCronJobs')){
        function logCronJobs($data){

            // Update or insert cron logs in db
            CronStatistics::updateOrCreate(['cron_name' => $data['cron_name']], $data);

            return true;
        }
    }
}

if (! function_exists('canLog')){
    function canLog(){
        if(true === config('main.debug') && ('local' === strtolower(trim(config('main.env'))) || 'development' === strtolower(trim(config('main.env')))))
            return true;
        return false;
    }
}

if (! function_exists('logInfo')){
    function logInfo($data = null){
        if(null !== $data && '' !== $data){
            if(is_array($data))
                $data = json_encode($data);
        }
        \Log::info($data);
    }
}

if (! function_exists('setOrderState'))
{
    function setOrderState($status = NULL){
        $state = false;
        if(strtolower($status) === strtolower('Fulfilled'))
            $state = 'Ready';
        if(strtolower($status) === strtolower('Pending'))
            $state = 'System Processing';
        if(strtolower($status) === strtolower('Cancelled'))
            $state = 'Ready';
        \Log::info("*setOrderState* : (status: '".$status."') state => '".$state."'");
        return $state;
    }
}

if (! function_exists('setOrderLineItemState'))
{
    function setOrderLineItemState($status = NULL){
        $state = false;
        if(strtolower($status) === strtolower('Fulfilled'))
            $state = 'Ready';
        \Log::info("*setOrderLineItemState* : (status: '".$status."') state => '".$state."'");
        return $state;
    }
}

if (! function_exists('setTenantState'))
{
    function setTenantState($status = NULL, $integration_type = NULL){
        $state = false;
        if(strtolower($status) === strtolower('Pending') && strtolower($integration_type) === strtolower('automate'))
            $state = 'System Processing';
        else if(strtolower($status) === strtolower('Pending') && strtolower($integration_type) === strtolower('manual'))
            $state = 'Waiting for Input';
        else if(strtolower($status) === strtolower('Active') && strtolower($integration_type) === strtolower('automate'))
            $state = 'Ready';
        else if(strtolower($status) === strtolower('Active') && strtolower($integration_type) === strtolower('manual'))
            $state = 'Ready';
        \Log::info("*setTenantState* : (status: '".$status."', integration_type: '".$integration_type."') state => '".$state."'");
        return $state;
    }
}

if (! function_exists('setSubscriptionState'))
{
    function setSubscriptionState($status = NULL, $integration_method = NULL){

        $forReadyStateStatuses = [1,2,3,4,5,6];

        $state = false;

        // for status (active, terminated, expired, cancelled, freeze) and integration may be manual or auto
        if(in_array($status, $forReadyStateStatuses))
            $state = 'Ready';

        // pending/auto
        else if($status === 0 && (strtolower($integration_method) === strtolower('Automatic') || strtolower($integration_method) === strtolower('automate')))
            $state = 'System Processing';
        // pending/manual
        else if($status === 0 && strtolower($integration_method) === strtolower('manual'))
            $state = 'Waiting for Input';
/*
        // suspended/manual
        else if($status === 2 && strtolower($integration_method) === strtolower('manual'))
            $state = 'Suspended';
        // suspended/auto
        else if($status === 2 && (strtolower($integration_method) === strtolower('Automatic') || strtolower($integration_method) === strtolower('automate')))
            $state = 'Ready';
*/
        /*
        // currently this never be checked because it is already in array of ready state so can be removed from here
        // freeze/manual
        else if($status === 6 && strtolower($integration_method) === strtolower('manual'))
            $state = 'Ready'; // this need to be Freezed but in sheet it is ready
        // freeze/auto
        else if($status === 6 && (strtolower($integration_method) === strtolower('Automatic') || strtolower($integration_method) === strtolower('automate')))
            $state = 'Ready';
        */

        \Log::info("*setSubscriptionState* : (status: '".$status."', integration_method: '".$integration_method."') state => '".$state."'");

        return $state;
    }
}

/*
 * Returns array of alerts for specific status id
 */
if (! function_exists('getChangeType'))
{
    function getChangeTypes($status = NULL){

        switch ($status){
            case ($status == Subscriptions::STATUS_SUSPENDED):
                $changeType['email'] = "Suspension";
                $changeType['system_alert'] = "Suspension";
                break;
            case ($status == Subscriptions::STATUS_TERMINATED):
                $changeType['email'] = "Termination";
                $changeType['system_alert'] = "Termination";
                break;
            case ($status == Subscriptions::STATUS_CANCELLED):
                $changeType['email'] = "Cancellation";
                $changeType['system_alert'] = "Cancellation";
                break;
            case ($status == Subscriptions::STATUS_FREEZE):
                $changeType['email'] = "Freeze";
                $changeType['system_alert'] = "Freeze";
                break;
            default:
                $changeType['email'] = "";
                $changeType['system_alert'] = "";
        }

        return $changeType;
    }
}
    /**
     * Compute the start and end date of some fixed o relative quarter in a specific year.
     * @param mixed $quarter  Integer from 1 to 4 or relative string value:
     *                        'this', 'current', 'previous', 'first' or 'last'.
     *                        'this' is equivalent to 'current'. Any other value
     *                        will be ignored and instead current quarter will be used.
     *                        Default value 'current'. Particulary, 'previous' value
     *                        only make sense with current year so if you use it with
     *                        other year like: get_dates_of_quarter('previous', 1990)
     *                        the year will be ignored and instead the current year
     *                        will be used.
     * @param int $year       Year of the quarter. Any wrong value will be ignored and
     *                        instead the current year will be used.
     *                        Default value null (current year).
     * @param string $format  String to format returned dates
     * @return array          Array with two elements (keys): start and end date.
     */
    if (! function_exists('get_dates_of_quarter'))
    {
        function get_dates_of_quarter($quarter = 'current', $year = null, $format = 'Y-m-d H:i:s')
        {
            if ( !is_int($year) ) {
                $year = (new DateTime)->format('Y');
            }
            $current_quarter = ceil((new DateTime)->format('n') / 3);
            switch (  strtolower($quarter) ) {
                case 'this':
                case 'current':
                    $quarter = ceil((new DateTime)->format('n') / 3);
                    break;

                case 'previous':
                    $year = (new DateTime)->format('Y');
                    if ($current_quarter == 1) {
                        $quarter = 4;
                        $year--;
                    } else {
                        $quarter =  $current_quarter - 1;
                    }
                    break;

                case 'first':
                    $quarter = 1;
                    break;

                case 'last':
                    $quarter = 4;
                    break;

                default:
                    $quarter = (!is_int($quarter) || $quarter < 1 || $quarter > 4) ? $current_quarter : $quarter;
                    break;
            }
            if ( $quarter === 'this' ) {
                $quarter = ceil((new DateTime)->format('n') / 3);
            }
            $start = new DateTime($year.'-'.(3*$quarter-2).'-1 00:00:00');
            $end = new DateTime($year.'-'.(3*$quarter).'-'.($quarter == 1 || $quarter == 4 ? 31 : 30) .' 23:59:59');

            return array(
                'start' => $format ? $start->format($format) : $start,
                'end' => $format ? $end->format($format) : $end,
            );
        }
    }

    if (! function_exists('isSubscriptionActive'))
    {
        function isSubscriptionActive($subscription_id = null)
        {
            $subscription_id = intval($subscription_id);
            if($subscription_id !== null && $subscription_id > 0){
                $subscription = Subscriptions::find($subscription_id);
                if(intval(optional($subscription)->status) === 1)
                    return true;
            }
            return false;
        }
    }

    if (! function_exists('updateVerificationExpiryFromSettings'))
    {
        function updateVerificationExpiryFromSettings()
        {
            $email_code_expiry = Setting::where([
                'key'               => 'email_code_expiry',
                'portal_type'       => 'customer',
            ])->select('key', 'value', 'portal_type')->first();
            if(optional($email_code_expiry)->value){
                if(intval(optional($email_code_expiry)->value) > 0)
                    return Carbon::now()->addHours(optional($email_code_expiry)->value);
            }
            return Carbon::now()->addHours(config('main.email_code_expiry'));
        }
    }

if (! function_exists('getSupportEmails'))
{
    /**
     * Get Support (Sales Ops or Ticketing System) Emails
     * @return array
     */
    function getSupportEmails():array
    {
        $supportEmails = [];

        $salesOps = Setting::where([
            ['key', 'sales_ops_email'],
            ['portal_type', 'customer'],
        ])->first();

        if ($salesOps) {
            $supportEmails = explode(',',collect($salesOps)->get('value'));
        }

        return $supportEmails;
    }
}

    if(!function_exists('isNotificationsEnabled')){

        /*
         * Function to check whether email/notification should send or not
         *
         * @params array user_email,module_name,event_name,notification_type
         * @return bool true/false
        */
        function isNotificationsEnabled($params){

            $notificationsManagementObj = NotificationsList::select('nm.*')
                ->join('notifications_management as nm','notifications_list.id','=','nm.notification_list_id')
                ->join(env('DB_SENSITIVE_DATABASE').'.users as u','u.id','=','nm.user_id')
                ->where('u.email',$params['user_email'])
                ->where('notifications_list.module',$params['module_name'])
                ->where('notifications_list.event',$params['event_name'])
                ->first();

            if($notificationsManagementObj){
                $response = $notificationsManagementObj->{$params['notification_type']};

            }else{ // As by default all the notifications are enabled for users
                $response = true;
            }

            return $response;
        }
    }

    if (! function_exists('getOnlySubject'))
    {
        function getOnlySubject($subject)
        {
            $subject = trim($subject);
            if($subject !== ""){
                $subject = trim(str_replace(config('main.app_full_name_r'), '', $subject));
                $subject = trim(str_replace(config('main.app_full_name'), '', $subject));
                $subject = trim(str_replace(config('main.app_short_name'), '', $subject));
                $subject = trim(str_replace(config('main.registered'), '', $subject));
                $subject = str_replace('–', '', $subject);
                $subject = trim(trim(trim(trim($subject), '-'), '_'));
                $subject = preg_replace('~(?<!\S)-|-(?!\S)~', '', $subject);
            }
            return $subject;
        }
    }

    if (! function_exists('filterEmails')){
        function filterEmails($email_string) {
            $delimiters = array(",", ";");
            $email_string_ready = str_replace($delimiters, $delimiters[0], $email_string);
            return array_unique(array_filter(array_map('trim', explode($delimiters[0], $email_string_ready))));
        }
    }

    if (! function_exists('filterEmailString')){
        function filterEmailString($email_string) {
            $delimiter = ';';
            if(strpos($email_string, ','))
                $delimiter = ',';
            return implode($delimiter, filterEmails($email_string));
        }
    }

    /**
    * Function to Push Notification on Mobile devices
    * @param  array $notificationData
    */
    function pushMobileNotification($notificationData)
    {
        try {
            $optionBuilder = new OptionsBuilder();
            $optionBuilder->setTimeToLive(60*20);

            $notificationBadge =  Notification::select('user_id')->where('user_id', $notificationData['user_id'])->where('read', 0)->groupBy('body','user_id','created_at')->get();
            $notificationData['badge'] = $notificationBadge->count();

            $notificationBuilder = new PayloadNotificationBuilder(env('APP_FULL_NAME'));
            $notificationBuilder->setBody(strip_tags($notificationData['body']))->setSound('default')->setBadge($notificationData['badge']);

            $notification = [
                'body' => strip_tags($notificationData['body']),
                'link' => $notificationData['link'],
                'title' => env('APP_FULL_NAME'),
                'badge' => $notificationData['badge']
            ];

            $dataBuilder = new PayloadDataBuilder();
            $dataBuilder->addData($notification);

            $option = $optionBuilder->build();
            $notification = $notificationBuilder->build();
            $data = $dataBuilder->build();

            $userMobileDevice = \Component\AccountComponent\App\UserMobileDevice::where('user_id', $notificationData['user_id'])->pluck('notification_token')->toArray();

            if($userMobileDevice){
                $downstreamResponse = FCM::sendTo($userMobileDevice, $option, $notification, $data);
                // When debugged in future any developer then uncomment below lines
//                 \Log::info(json_encode($downstreamResponse->numberSuccess()));
//                 \Log::info(json_encode($downstreamResponse->numberFailure()));
//                 \Log::info(json_encode($downstreamResponse->numberModification()));
            }
        } catch (\Throwable $e) {
            \Log::info($e->getMessage());
            \Log::info($e->getTraceAsString());
            \Log::info($e->getLine());
        }


    }

    /**
     * Function to get all company admins
     * @param  account_id
     */
    function getCompanyAdmins($account_id)
    {
        $accountAdmins = User::whereHas('roles', function ($q) {
            $q->where('name', 'admin');
        })->where('company_id', $account_id)->get(['id', 'email']);

        return $accountAdmins;
    }

    function getPriceCall(Request $request, $skuId, $quantities = [])
    {
        $oCatalogComponentController = new CatalogComponentController();
        $request->merge(['skus' => (is_array($skuId)) ? $skuId : [$skuId]]);
        $request->merge(['quantities' => $quantities]);

        // get data from database in E1 failure case.
        $request->merge(['withDatabaseCall' => true]);

        $response = $oCatalogComponentController->getPriceCall($request);
        return $response;
    }

    function getE1Tax(Request $request, $itemsRequest)
    {
        $oOrderController = new OrderController();
        $request->merge(['itemsRequest' => $itemsRequest]);

        $response = $oOrderController->getE1Tax($request);
        return $response;
    }

    /**
    * Remove double strings
    * @param (string) $str
    * @return (string) $str
    */
    function removeDoubleSpace($str)
    {
        $str = str_replace('  ', ' ', $str);
        if (strpos($str, '  ') !== false) {
            return removeDoubleSpace($str);
        }

        return $str;
    }

    function cleanProviderCustomMessage($provider_custom_message = null){
        if($provider_custom_message != null && $provider_custom_message != ''){
            $provider_custom_message = trim($provider_custom_message);
            $pattern = "/<p[^>]*>[\s|&nbsp;|\<br\>|]*<\/p>$/";
            while(preg_match($pattern, $provider_custom_message, $matched)){
                $provider_custom_message = trim($provider_custom_message);
                $provider_custom_message = trim(preg_replace($pattern, '', $provider_custom_message));
            }
            return '<b style="text-decoration:underline">Provider Information</b><br><span>' . $provider_custom_message . '</span>';
        }
        return '';
    }

    /**
     * Get data from Riversand MSSQL
     * @param (string)$tableName
     * @return array
     */
    function getDataFromRiverSand($tableName)
    {
        //set default respone
        $result = [ 'status' => 1, 'data' => [], 'message' => 'success'];
        try{
            //get data of request table from RiverSand
            $result['data'] = DB::connection(env('DB_RIVERSAND_CONNECTION'))->table(env('DB_RIVERSAND_DATABASE').'.dbo.'.$tableName)->get();
        } catch (\Illuminate\Database\QueryException $qe) {
            $result['status'] = 0;
            $result['message'] = $qe->getMessage();
        } catch (\Throwable $t) {
            $result['status'] = 0;
            $result['message'] = $t->getMessage();
        } catch (\Exception $e) {
            $result['status'] = 0;
            $result['message'] =$e->getMessage();
        }

        return $result;
    }

    /**
     * Dispatch emails to queue
     *
     * @param $params
     * @return void
     */
    function sendEmailWithQueue($params)
    {
        dispatch(new SendEmail($params));
    }

    /**
     * Validate SKUS with webstatus 6
     *
     * @param (int)$serviceId
     * @param (bool)$checkSubscription
     * @param (int)$companyId
     * @return bool
     */
    function validateServiceWebStatus(int $serviceId, int $companyId = 0, bool $checkSubscription = false)
    {
        $valid = true;
        $serviceItem = Service::find($serviceId);
        if ($serviceItem->webstatus == config('CatalogComponent.web_status_six')) {

            if ($checkSubscription) {
                //check if sku has parents
                $addonItems = Addons::where('AddOnSKUID', $serviceItem->skuid)->get();
                if ($addonItems->count() > 0) {
                    $valid = false;
                    $addonItems->each(function ($item) use ($companyId, &$valid) {

                        $parentService = Service::with(['subscription' => function ($query) use ($companyId) {
                            //fetch loggedin user account's subscription
                            $query->where('company_id', $companyId);
                            //fetch if status is not cancelled
                            $query->where('status', '!=', 5);
                        }])->where('skuid', $item->SKUID)->first();

                        if (count($parentService->subscription) > 0) {
                            $valid = true;
                            return false; //break out
                        }

                    });
                } else {
                    $valid = false;
                }
            } else {
                $valid = false;
            }
        }

        return $valid;
    }


    /**
         * ESG Api Authentication
         *
         * @params (array) $param
         * @return (GuzzleClient) $response
         */
    function esgApiAuthentication(Request $request)
    {
        $accountObj = New AccountComponentController();

        return $accountObj->esgApiAuthentication($request);
    }

    /**
         * ESG Api fetch ProductId
         *
         * @params (array) $param
         * @return (GuzzleClient) $response
         */
        function esgProductId($param,Request $request)
        {
            $accountObj = New AccountComponentController();
    
            return $accountObj->esgProductId($param ,$request);
        }

        /**
         * ESG Api fetch Price
         *
         * @params (array) $param
         * @return (GuzzleClient) $response
         */
        function esgPrice($param,Request $request)
        {
            $accountObj = New AccountComponentController();
    
            return $accountObj->esgPrice($param , $request);
        }
        /*
         * Auto generated Random GUID function
         * Retrun GUID number
         */
        if (!function_exists('generateRandomGUID')) {
            function generateRandomGUID($trim = true)
            {
                return (string) Str::uuid();
            }
        }

    if (!function_exists('getException')) {
        function getException($e = null)
        {
            if($e !== null)
                return ' < ' . $e->getLine(). ' : ' . $e->getFile() . ' : ' . $e->getMessage() . ' > ';
            return '';
        }
    }


    /**
     * Convert minutes to days
     * @param (string) $startDate
     * @param (int) $minutes
     */
    if (!function_exists('convertMinutesToDays')) {
        function convertMinutesToDays($startDate, $minutes = null)
        {
            $endDate = Carbon::parse($startDate)->addMinutes($minutes);
            $days = Carbon::now()->diffInDays($endDate, false) ;
            return $days > 0 ? $days : 0;
        }
    }