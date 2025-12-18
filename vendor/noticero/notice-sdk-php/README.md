# Notice API PHP SDK

Check documentation before:

[API Docs](https://documenter.getpostman.com/view/6644801/2s9YyzbxNU)

Installation:

    composer require noticero/notice-sdk-php

Usage:

    use Notice\SdkPhp\SdkPhp;

    require_once 'vendor/autoload.php';

    $notice = new SdkPhp('YOUR-API-TOKEN');

Send SMS:

Using just number and message:

    $notice->sendSms([
	    'number' => '07XXXXXXXX',
	    'message' => 'Hello, world!'
	]);

Using template_id and variables:

    $notice->sendSms([
	    'number'  =>  '07XXXXXXXX',
	    'template_id'  =>  1,
	    'variables'  => [
		    'order_id'  =>  '123',
		    'total'  =>  '100',
		]
	]);
    
[See available variables to use with templates here](https://documenter.getpostman.com/view/6644801/2s9YyzbxNU#a8bc4226-75fa-4ec3-886d-8e901dca9a05)

Get Incoming SMS List:

    $notice->getIncomeSmsList();

Get Sent SMS List:

    $notice->getSentSmsList();

Get Template List:

    $notice->getTemplates();
