<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Plugin;

use Magento\Customer\Model\Session;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface as HttpResponseInterface;
use Magento\ReCaptchaUi\Model\RequestHandlerInterface;
use Magetu\Turnstile\Plugin\KeepFormInputOnReject;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * After a rejected captcha the visitor lands back on the form, so what they typed is saved for the
 * form to read back. Only the contact and registration forms have a read-back; the password fields
 * are never saved. A passed check does no work at all.
 */
class KeepFormInputOnRejectTest extends TestCase
{
    /**
     * @var ActionFlag&MockObject
     */
    private $actionFlag;

    /**
     * @var DataPersistorInterface&MockObject
     */
    private $dataPersistor;

    /**
     * @var Session&MockObject
     */
    private $session;

    /**
     * @var KeepFormInputOnReject
     */
    private KeepFormInputOnReject $plugin;

    protected function setUp(): void
    {
        $this->actionFlag = $this->getMockBuilder(ActionFlag::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $this->dataPersistor = $this->getMockBuilder(DataPersistorInterface::class)->getMock();
        // setCustomerFormData is a magic method, reached through SessionManager::__call
        $this->session = $this->getMockBuilder(Session::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['__call'])
            ->getMock();

        $this->plugin = new KeepFormInputOnReject($this->actionFlag, $this->dataPersistor, $this->session);
    }

    private function subject(): RequestHandlerInterface
    {
        return $this->getMockBuilder(RequestHandlerInterface::class)->getMock();
    }

    /**
     * @param mixed $flag
     */
    private function flagReturns($flag): void
    {
        $this->actionFlag->method('get')
            ->with('', ActionInterface::FLAG_NO_DISPATCH)
            ->willReturn($flag);
    }

    /**
     * @return RequestInterface&MockObject
     */
    private function requestWithParams(array $params)
    {
        $request = $this->getMockBuilder(RequestInterface::class)
            ->onlyMethods(['getParams'])
            ->getMockForAbstractClass();
        $request->method('getParams')->willReturn($params);

        return $request;
    }

    private function callPlugin(string $key, RequestInterface $request)
    {
        return $this->plugin->afterExecute(
            $this->subject(),
            null,
            $key,
            $request,
            $this->getMockBuilder(HttpResponseInterface::class)->getMock(),
            'https://example.invalid/back'
        );
    }

    public function testContactRejectSavesTheTypedFieldsUnderContactUsAndNothingElse(): void
    {
        $this->flagReturns(true);
        $request = $this->requestWithParams([
            'name' => 'Pat Example',
            'email' => 'a@example.invalid',
            'telephone' => '555-0100',
            'comment' => 'Hello',
            'form_key' => 'abc',
            'g-recaptcha-response' => 'token',
        ]);

        $this->dataPersistor->expects(self::once())
            ->method('set')
            ->with(
                'contact_us',
                [
                    'name' => 'Pat Example',
                    'email' => 'a@example.invalid',
                    'telephone' => '555-0100',
                    'comment' => 'Hello',
                ]
            );
        $this->session->expects(self::never())->method('__call');

        $this->callPlugin('contact', $request);
    }

    public function testRegisterRejectSavesTheTypedFieldsWithoutPasswordsOnTheCustomerSession(): void
    {
        $this->flagReturns(true);
        $request = $this->requestWithParams([
            'firstname' => 'Pat',
            'lastname' => 'Example',
            'email' => 'a@example.invalid',
            'password' => 'not-a-real-password',
            'password_confirmation' => 'not-a-real-password',
            'is_subscribed' => '1',
            'form_key' => 'abc',
            'g-recaptcha-response' => 'token',
        ]);

        $this->session->expects(self::once())
            ->method('__call')
            ->with(
                'setCustomerFormData',
                [[
                    'firstname' => 'Pat',
                    'lastname' => 'Example',
                    'email' => 'a@example.invalid',
                    'is_subscribed' => '1',
                ]]
            );
        $this->dataPersistor->expects(self::never())->method('set');

        $this->callPlugin('customer_create', $request);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function savedFormKeys(): array
    {
        return ['contact' => ['contact'], 'customer_create' => ['customer_create']];
    }

    /**
     * @dataProvider savedFormKeys
     */
    public function testPassedCheckDoesNoWorkAndReadsNoParams(string $key): void
    {
        foreach ([false, null] as $flag) {
            $actionFlag = $this->getMockBuilder(ActionFlag::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['get'])
                ->getMock();
            $actionFlag->method('get')->with('', ActionInterface::FLAG_NO_DISPATCH)->willReturn($flag);
            $dataPersistor = $this->getMockBuilder(DataPersistorInterface::class)->getMock();
            $dataPersistor->expects(self::never())->method('set');
            $session = $this->getMockBuilder(Session::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['__call'])
                ->getMock();
            $session->expects(self::never())->method('__call');
            $request = $this->getMockBuilder(RequestInterface::class)
                ->onlyMethods(['getParams'])
                ->getMockForAbstractClass();
            $request->expects(self::never())->method('getParams');

            $plugin = new KeepFormInputOnReject($actionFlag, $dataPersistor, $session);
            $plugin->afterExecute(
                $this->subject(),
                null,
                $key,
                $request,
                $this->getMockBuilder(HttpResponseInterface::class)->getMock(),
                'https://example.invalid/back'
            );
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsavedFormKeys(): array
    {
        $keys = [
            'product_review',
            'sendfriend',
            'customer_login',
            'coupon_code',
            'corporate_order',
            'customer_forgot_password',
            'newsletter',
        ];

        return \array_combine($keys, \array_map(static fn (string $key): array => [$key], $keys));
    }

    /**
     * @dataProvider unsavedFormKeys
     */
    public function testOtherFormsRejectedWriteNothing(string $key): void
    {
        $this->flagReturns(true);
        $this->dataPersistor->expects(self::never())->method('set');
        $this->session->expects(self::never())->method('__call');

        $this->callPlugin($key, $this->requestWithParams(['email' => 'a@example.invalid', 'form_key' => 'abc']));
    }

    public function testReturnsNullBecauseExecuteIsVoid(): void
    {
        $this->flagReturns(true);

        self::assertNull($this->callPlugin('contact', $this->requestWithParams(['name' => 'Pat Example'])));
    }
}
