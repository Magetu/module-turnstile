<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Plugin;

use Magento\Customer\Model\Session;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\ReCaptchaUi\Model\RequestHandlerInterface;

/**
 * Keeps what the visitor typed when the captcha check fails. The core handler only adds an error
 * message, sets the no-dispatch flag and redirects back, so every typed value is lost.
 *
 * Two forms have a stock read-back and are covered: the contact form (data persistor key
 * `contact_us`) and customer registration (customer session form data, without the password
 * fields). Other forms are left alone: most have no read-back, and the review controller would use
 * saved review data in place of the visitor's next submit.
 *
 * A passed check leaves the no-dispatch flag unset, and the plugin returns before it reads the request.
 */
class KeepFormInputOnReject
{
    private const KEY_CONTACT = 'contact';
    private const KEY_CUSTOMER_CREATE = 'customer_create';
    private const CONTACT_PERSISTOR_KEY = 'contact_us';
    private const FIELD_FORM_KEY = 'form_key';
    private const FIELD_PASSWORD = 'password';
    private const FIELD_PASSWORD_CONFIRMATION = 'password_confirmation';

    /**
     * @var ActionFlag
     */
    private ActionFlag $actionFlag;

    /**
     * @var DataPersistorInterface
     */
    private DataPersistorInterface $dataPersistor;

    /**
     * @var Session
     */
    private Session $customerSession;

    /**
     * @param ActionFlag $actionFlag
     * @param DataPersistorInterface $dataPersistor
     * @param Session $customerSession
     */
    public function __construct(
        ActionFlag $actionFlag,
        DataPersistorInterface $dataPersistor,
        Session $customerSession
    ) {
        $this->actionFlag = $actionFlag;
        $this->dataPersistor = $dataPersistor;
        $this->customerSession = $customerSession;
    }

    /**
     * Save the typed values for the covered forms after a rejected check.
     *
     * @param RequestHandlerInterface $subject
     * @param mixed $result
     * @param string $key The captcha id, e.g. `contact`
     * @param RequestInterface $request
     * @param HttpResponseInterface $response
     * @param string $redirectOnFailureUrl
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(
        RequestHandlerInterface $subject,
        $result,
        string $key,
        RequestInterface $request,
        HttpResponseInterface $response,
        string $redirectOnFailureUrl
    ): void {
        if (!$this->actionFlag->get('', ActionInterface::FLAG_NO_DISPATCH)) {
            return;
        }
        if ($key !== self::KEY_CONTACT && $key !== self::KEY_CUSTOMER_CREATE) {
            return;
        }

        $data = $request->getParams();
        unset($data[self::FIELD_FORM_KEY], $data[SwapWidgetTemplate::RESPONSE_FIELD_NAME]);

        if ($key === self::KEY_CONTACT) {
            $this->dataPersistor->set(self::CONTACT_PERSISTOR_KEY, $data);
            return;
        }

        unset($data[self::FIELD_PASSWORD], $data[self::FIELD_PASSWORD_CONFIRMATION]);
        $this->customerSession->setCustomerFormData($data);
    }
}
