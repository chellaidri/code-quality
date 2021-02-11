<?php

namespace Customer\Controller;

use Customer\Service\TransferOwnership\TransferDesignService;
use Customer\Service\TransferOwnership\TransferFileService;
use Customer\Service\TransferOwnership\TransferMailingListService;
use Customer\Service\TransferOwnership\TransferRegisterTrackerLogService;
use Dri\Lib\Common\Helper\CsrfHelper;
use Dri\Lib\Customer\Filter\RegisterFilter;
use Dri\Lib\Customer\Helper\RegisterHelper;
use Dri\Lib\Customer\Service\CustomerUtmService;
use Dri\Lib\Marketing\Service\CustomerAdManagerService;
use Dri\Lib\Customer\Service\PartnerService;
use Dri\Lib\Cart\Service\CartService;
use Dri\Lib\Newsletter\Service\NewsletterService;
use Dri\Messaging\Service\MessagingService;
use Dri\Lib\Common\Controller\DriAbstractRestfulController;
use Zend\View\Model\JsonModel;

/**
 * Class RegisterController
 * @package Dri\Lib\Customer\Controller
 * @author Chellai Fajardo <chellai.f@digitalroominc.com>
 */
class RegisterController extends DriAbstractRestfulController
{
    protected $RegisterHelper;
    protected $Config;
    protected $TransferRegisterTrackerLogService;
    protected $TransferFileService;
    protected $TransferDesignService;
    protected $TransferMailingListService;
    protected $CartService;
    protected $PartnerService;
    protected $MessagingService;
    protected $NewsletterService;
    protected $CustomerUtmService;
    protected $CustomerAdManagerService;
    protected $RegisterFilter;
    protected $CsrfHelper;
    protected $eventIdentifier = 'Unsecured';


    public function __construct(
        $config,
        RegisterHelper $RegisterHelper,
        TransferRegisterTrackerLogService $TransferRegisterTrackerLogService,
        TransferFileService $TransferFileService,
        TransferDesignService $TransferDesignService,
        TransferMailingListService $TransferMailingListService,
        CartService $cartService,
        PartnerService $partnerService,
        MessagingService $messagingService,
        NewsletterService $NewsletterService,
        CustomerUtmService $customerUtmService,
        CustomerAdManagerService $customerAdManagerService,
        RegisterFilter $registerFilter,
        CsrfHelper $csrfHelper
    ) {
        $this->Config = $config;
        $this->RegisterHelper = $RegisterHelper;
        $this->TransferRegisterTrackerLogService = $TransferRegisterTrackerLogService;
        $this->TransferFileService = $TransferFileService;
        $this->TransferDesignService = $TransferDesignService;
        $this->TransferMailingListService = $TransferMailingListService;
        $this->CartService = $cartService;
        $this->PartnerService = $partnerService;
        $this->MessagingService = $messagingService;
        $this->NewsletterService = $NewsletterService;
        $this->CustomerUtmService = $customerUtmService;
        $this->CustomerAdManagerService = $customerAdManagerService;
        $this->RegisterFilter = $registerFilter;
        $this->CsrfHelper = $csrfHelper;
    }

    public function create($data)
    {
        $this->RegisterFilter->setRequiredFields($this->Config['customer']['registration_required_fields']);
        $this->RegisterFilter->setInputFilter($data);
        $this->RegisterFilter->setData($data);
        $referer = $this->getRequest()->getHeader('Referer')->uri()->getPath();

        if (!$this->RegisterFilter->isValid()) {
            return $this->createResponse(412, $this->RegisterFilter->getMessages());
        }

        $formName = !empty($data['form_name']) ? trim(strip_tags($data['form_name'])) : '';
        if(!in_array($referer, $this->Config['csrf']['skip_csrf_validation'])) {
            if (!$this->CsrfHelper->verifyCsrfToken($this->getRequest(), $formName)) {
                return $this->createResponse(
                    412,
                    array('csrf' => array('This session has expired. Please refresh and try again'))
                );
            }
        }

        if (!empty($data['reseller_customer_id'])
            && !$this->PartnerService->isValidPartner($data['reseller_customer_id'])) {
            $error = $this->PartnerService->getError();
            return $this->createResponse($error['status_code'], $error['error']);
        }

        $response = $this->RegisterHelper->register($this->RegisterFilter->getValues());
        if (!$response['success']) {
            return $this->createResponse(
                $response['status_code'],
                $response['error_description']
            );
        }

        $customerInfo = $response['customer_info'];
        unset($response['customer_info']);

        // get customer id
        $customerId = $customerInfo['customer_id'];

        // get visitor id
        $visitorId = $data['vid'];

        // get session id
        $sessionId = $data['sessionId'];

        // get website code
        $websiteCode = isset($this->Config['app_config']['website_code']) ? $this->Config['app_config']['website_code'] : null;

        // do transfer ownership
        if (!empty($visitorId)) {
            $this->TransferRegisterTrackerLogService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode,
                $sessionId
            );

            $this->TransferFileService->transferOwnership($customerId, $visitorId, $websiteCode);

            $this->TransferDesignService->transferOwnership($customerId, $visitorId, $websiteCode);

            $this->TransferMailingListService->transferOwnership($customerId, $visitorId, $websiteCode);
        }

        // assign cart to customer
        if (!empty($data['cart_id'])) {
            $this->CartService->assignCartToCustomer($data['cart_id'], $customerInfo);
        }

        if (!empty($data['ffr_cart_id'])) {
            $this->CartService->assignCartToCustomer($data['ffr_cart_id'], $customerInfo);
        }

        // set customer profile
        if (!empty($data['reseller_customer_id'])) {
            $customerInfo = $this->PartnerService->setRegisteredCustomerProfile(
                $data['reseller_customer_id'],
                $customerInfo
            );

            if (!empty($customerInfo['show_private_gallery_flag'])
                && $customerInfo['show_private_gallery_flag'] == 'y') {
                $response['customer_data'] = array(
                    'private_gallery' => true,
                );
            }
        }

        // store customer ad manager
        if (!empty($this->Config['customer']['enable_customer_admanager'])) {
            if (!empty($_COOKIE[$this->Config['admanager']['storage_key']])) {
                $admData = $_COOKIE[$this->Config['admanager']['storage_key']];
                $this->CustomerAdManagerService->saveAdManagerData($customerId, json_decode($admData));
            }
        }

        // store customer utm
        if (!empty($this->Config['customer']['enable_customer_utm'])) {
            // will change utm_data to configurable
            if (!empty($_COOKIE[$this->Config['customer']['utm_data']['storage_key']])) {
                $utmData = $_COOKIE[$this->Config['customer']['utm_data']['storage_key']];
                $this->CustomerUtmService->saveCustomerUtm(json_decode($utmData), $customerId);
            }
        }

        //subscribe to newsletter
        $data['newsletter_event_source_code'] = 'RF';
        $data['website_code'] = $websiteCode;
        $data['company_code'] = $this->Config['app_config']['company_code'];
        $this->NewsletterService->subscribeNewsletter($data);

        // send welcome email
        $this->MessagingService->setMessagingTag('WELCOME');
        $this->MessagingService->send($customerId);

        $expire = time() + (10 * 365 * 24 * 60 * 60);
        setcookie($this->Config['oauth2']['refresh_token_name'], $response['refresh_token'], $expire, "/",
            $this->Config['app_config']['domain'], true, true);

        return new JsonModel($response);
    }
}
