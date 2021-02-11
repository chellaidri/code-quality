<?php

namespace Customer\Controller;

use Customer\Service\TransferOwnership\TransferLoginTrackerLogService;
use Dri\Lib\Cart\Service\PersistentCartService;
use Dri\Lib\Common\Helper\CsrfHelper;
use Dri\Lib\Customer\Filter\LoginFilter;
use Dri\Lib\Customer\Helper\LoginHelper;
use Customer\Service\TransferOwnership\TransferDesignService;
use Customer\Service\TransferOwnership\TransferESDesignFileService;
use Customer\Service\TransferOwnership\TransferFileService;
use Customer\Service\TransferOwnership\TransferMailingListService;
use Dri\Lib\Customer\Service\PartnerService;
use Dri\Lib\Cart\Service\CartService;
use Dri\Lib\Common\Controller\DriAbstractRestfulController;
use Dri\Lib\Newsletter\Model\NewsletterTable;
use Dri\Lib\Customer\Model\CustomerDataTable;
use Firebase\JWT\JWT;
use Zend\View\Model\JsonModel;

/**
 * Class LoginController
 * @package Customer\Controller
 * @author Chellai Fajardo <chellai.f@digitalroominc.com>
 */
class LoginController extends DriAbstractRestfulController
{
    protected $Config;
    protected $oauthConfig;
    protected $discountConfig;
    protected $csrfConfig;
    protected $LoginHelper;
    protected $TransferLoginTrackerLogService;
    protected $TransferFileService;
    protected $TransferDesignService;
    protected $TransferESDesignFileService;
    protected $TransferMailingListService;
    protected $CartService;
    protected $PartnerService;
    protected $PersistentCartService;
    protected $NewsletterTable;
    private $CustomerDataTable;

    private $LoginFilter;
    private $CsrfHelper;


    protected $eventIdentifier = 'Unsecured';

    public function __construct(
        $config,
        $oauthConfig,
        $discountConfig,
        $csrfConfig,
        LoginHelper $loginHelper,
        TransferLoginTrackerLogService $TransferLoginTrackerLogService,
        TransferFileService $TransferFileService,
        TransferDesignService $TransferDesignService,
        TransferESDesignFileService $TransferESDesignFileService,
        TransferMailingListService $TransferMailingListService,
        CartService $cartService,
        PartnerService $partnerService,
        PersistentCartService $persistentCartService,
        NewsletterTable $newsletterTable,
        CustomerDataTable $customerDataTable,
        LoginFilter $loginFilter,
        CsrfHelper $csrfHelper
    ) {
        $this->Config = $config;
        $this->oauthConfig = $oauthConfig;
        $this->discountConfig = $discountConfig;
        $this->csrfConfig = $csrfConfig;
        $this->LoginHelper = $loginHelper;
        $this->TransferLoginTrackerLogService = $TransferLoginTrackerLogService;
        $this->TransferFileService = $TransferFileService;
        $this->TransferDesignService = $TransferDesignService;
        $this->TransferESDesignFileService = $TransferESDesignFileService;
        $this->TransferMailingListService = $TransferMailingListService;
        $this->CartService = $cartService;
        $this->PartnerService = $partnerService;
        $this->PersistentCartService = $persistentCartService;
        $this->NewsletterTable = $newsletterTable;
        $this->CustomerDataTable = $customerDataTable;
        $this->LoginFilter = $loginFilter;
        $this->CsrfHelper = $csrfHelper;
    }

    public function create($data)
    {
        $persistentLogin = !empty($data['persistent_login']) ? $data['persistent_login'] : 'y';
        $formName = !empty($data['form_name']) ? trim(strip_tags($data['form_name'])) : '';
        $referer = $this->getRequest()->getHeader('Referer')->uri()->getPath();

        if(!in_array($referer, $this->csrfConfig['skip_csrf_validation'])) {
            if (!$this->CsrfHelper->verifyCsrfToken($this->getRequest(), $formName)) {
                return $this->createResponse(
                    412,
                    array('csrf' => array('This session has expired. Please refresh and try again'))
                );
            }
        }

        // validate login
        $this->LoginFilter->setData($data);

        if (!$this->LoginFilter->isValid()) {
            return $this->createResponse(
                412,
                $this->LoginFilter->getMessages()
            );
        }

        $response = $this->LoginHelper->login($this->LoginFilter->getValues());
        if (!$response['success']) {
            if ($response['error'] == 'reseller_not_approved') {
                return $this->createResponse(
                    $response['status_code'],
                    array($response['error'] => array($response['error_description']))
                );
            } else {
                return $this->createResponse(
                    $response['status_code'],
                    $response['error_description']
                );
            }
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
        $websiteCode = isset($this->Config['website_code']) ? $this->Config['website_code'] : null;

        // do transfer ownership
        /*if (!empty($visitorId)) {
            $this->TransferLoginTrackerLogService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode,
                $sessionId
            );
        }

        if (!empty($visitorId)) {
            $this->TransferFileService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode
            );
        }

        if (!empty($visitorId)) {
            $this->TransferDesignService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode
            );
        }

        if (!empty($visitorId)) {
            $this->TransferMailingListService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode
            );
        }

        if (isset($this->Config['esodt_service']) && $this->Config['esodt_service'] && !empty($visitorId)) {
            $this->TransferESDesignFileService->transferOwnership(
                $customerId,
                $visitorId,
                $websiteCode
            );
        }*/

        // persistent po cart
        if (empty($data['cart_id'])) {
            $persistedCart = $this->PersistentCartService->persistCart($websiteCode, $visitorId,
                $customerId, 'j');
            if ($persistedCart) {
                $response['cart_id'] = $persistedCart['cart']->cart_id;
                $response['cart_count'] = $persistedCart['cart_count'];
            }
        }

        // Persistent ffr cart
        if (empty($data['ffr_cart_id'])) {
            $persistedFfrCart = $this->PersistentCartService->persistCart($websiteCode, $visitorId,
                $customerId, 'p');
            if ($persistedFfrCart) {
                $response['ffr_cart_id'] = $persistedFfrCart['cart']->cart_id;
                $response['ffr_cart_count'] = $persistedFfrCart['cart_count'];
            }
        }

        // assign cart to customer
        if (!empty($data['cart_id'])) {
            $this->CartService->assignCartToCustomer(
                $data['cart_id'],
                $customerInfo
            );
        }

        if (!empty($data['ffr_cart_id'])) {
            $this->CartService->assignCartToCustomer(
                $data['ffr_cart_id'],
                $customerInfo
            );
        }

        // set customer profile to customer cache if exists
        $customerInfo = $this->PartnerService->setLoggedInCustomerProfile($customerInfo);

        if (!empty($customerInfo['show_delivery_via_van_flag'])
            && $customerInfo['show_delivery_via_van_flag'] == 'y') {
            $response['customer_data'] = array(
                'show_delivery_via_van' => true,
            );
        }

        // get unsubscribe flag
        $newsletterDetails = $this->NewsletterTable->getNewsletterDetailsByCustomerId(
            $customerInfo['customer_id'],
            $customerInfo['company_code'],
            $customerInfo['email']);

        if (!empty($newsletterDetails)) {
            $response['customer_data']['unsubscribed_flag'] = $newsletterDetails->unsubscribed_flag;
        }

        if ($this->discountConfig['enable_partner_coupon']) {
            // get customer coupon id
            $customerDataResult = $this->CustomerDataTable->getCustomerData($customerInfo['customer_id']);
            if (!empty($customerDataResult)) {
                $customerData = $customerDataResult->getArrayCopy();
                $response['customer_data']['customer_coupon_id'] = $customerData['coupon_id'];
            }
        } else {
            if ($customerInfo['trade_flag'] == 'y' && $customerInfo['verified_trade_flag'] == 'y'
                && !empty($this->discountConfig['customer_coupons']['trade'])) {
                $response['customer_data']['customer_coupon_code'] = $this->discountConfig['customer_coupons']['trade'];
            } else if ($customerInfo['npo_flag'] == 'y' && $customerInfo['verified_npo_flag'] == 'y'
                && !empty($this->discountConfig['customer_coupons']['npo'])) {
                $response['customer_data']['customer_coupon_code'] = $this->discountConfig['customer_coupons']['npo'];
            }
        }

        $expire = $persistentLogin == 'y' ? time() + (10 * 365 * 24 * 60 * 60) : 0;
        setcookie($this->oauthConfig['refresh_token_name'], $response['refresh_token'], $expire, "/",
            $this->Config['domain'], true, true);

        return new JsonModel($response);

    }
}
