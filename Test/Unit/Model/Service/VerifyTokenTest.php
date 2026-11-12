<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Test\Unit\Model\Service;

use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\Framework\Validation\ValidationResult;
use Magento\Framework\Validation\ValidationResultFactory;
use Magento\ReCaptchaValidationApi\Model\ErrorMessagesProvider;
use Magetu\Turnstile\Model\Service\VerifyToken;
use Magetu\Turnstile\Model\ValidationConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The security core of the module: every row of the response policy, and the two invariants that
 * turn a wrong return value into a fatal rather than a rejected captcha.
 */
class VerifyTokenTest extends TestCase
{
    private const SECRET = 'sekrit-do-not-log';
    private const TOKEN = 'tok-do-not-log';

    /** @var ClientInterface&MockObject */
    private $client;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /**
     * @var VerifyToken
     */
    private VerifyToken $verifyToken;

    /** @var array<int, array{level: string, message: string}> */
    private array $logCalls = [];

    protected function setUp(): void
    {
        $this->client = $this->getMockBuilder(ClientInterface::class)->getMock();

        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($this->client);

        // Mirror native ErrorMessagesProvider: registered code -> label, unknown code -> the code.
        $errorMessages = $this->createMock(ErrorMessagesProvider::class);
        $errorMessages->method('getErrorMessage')
            ->willReturnCallback(static fn (string $code): string => 'label:' . $code);

        $resultFactory = $this->createMock(ValidationResultFactory::class);
        $resultFactory->method('create')
            ->willReturnCallback(static fn (array $data): ValidationResult => new ValidationResult($data['errors']));

        $this->logger = $this->getMockBuilder(LoggerInterface::class)->getMock();
        foreach (['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'] as $level) {
            $this->logger->method($level)->willReturnCallback(
                function (string $message) use ($level): void {
                    $this->logCalls[] = ['level' => $level, 'message' => $message];
                }
            );
        }
        $this->logger->method('log')->willReturnCallback(
            function ($level, string $message): void {
                $this->logCalls[] = ['level' => (string)$level, 'message' => $message];
            }
        );

        $this->verifyToken = new VerifyToken($clientFactory, $errorMessages, $resultFactory, $this->logger);
    }

    /**
     * @param string[] $allowedHostnames
     */
    private function config(
        string $remoteIp = '',
        bool $validateAction = true,
        array $allowedHostnames = ['example.com'],
        string $expectedAction = 'customer_login'
    ): ValidationConfig {
        return new ValidationConfig(
            self::SECRET,
            $remoteIp,
            'unused failure message',
            $validateAction,
            $allowedHostnames,
            $expectedAction
        );
    }

    private function respondWith(string $body, int $status = 200): void
    {
        $this->client->method('getStatus')->willReturn($status);
        $this->client->method('getBody')->willReturn($body);
    }

    private function successBody(string $hostname = 'example.com', string $action = 'customer_login'): string
    {
        return (string)json_encode(['success' => true, 'hostname' => $hostname, 'action' => $action]);
    }

    public function testPostsTheSecretAndTokenAndNothingElseWhenSendRemoteIpIsDisabled(): void
    {
        $captured = null;
        $this->client->method('post')->willReturnCallback(
            function (string $url, $params) use (&$captured): void {
                $captured = ['url' => $url, 'params' => $params];
            }
        );
        $this->respondWith($this->successBody());

        $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertSame(VerifyToken::SITEVERIFY_URL, $captured['url']);
        self::assertSame(['secret' => self::SECRET, 'response' => self::TOKEN], $captured['params']);
        self::assertArrayNotHasKey('remoteip', $captured['params']);
    }

    public function testIncludesRemoteipInThePostBodyWhenSendRemoteIpIsEnabled(): void
    {
        $captured = null;
        $this->client->method('post')->willReturnCallback(
            function (string $url, $params) use (&$captured): void {
                $captured = $params;
            }
        );
        $this->respondWith($this->successBody());

        $this->verifyToken->execute(self::TOKEN, $this->config('203.0.113.7'));

        self::assertSame('203.0.113.7', $captured['remoteip']);
    }

    public function testSetsBothAConnectTimeoutAndATotalTimeout(): void
    {
        $options = [];
        $this->client->method('setOptions')->willReturnCallback(
            function (array $opts) use (&$options): void {
                $options = $opts;
            }
        );
        $this->respondWith($this->successBody());

        $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertSame(VerifyToken::CONNECT_TIMEOUT_SECONDS, $options[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame(VerifyToken::TOTAL_TIMEOUT_SECONDS, $options[CURLOPT_TIMEOUT]);
    }

    public function testFailsClosedWhenTheTokenIsEmpty(): void
    {
        $this->client->expects(self::never())->method('post');

        $result = $this->verifyToken->execute('', $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_MISSING_INPUT, $result->getErrors());
    }

    /**
     * Pins the `?? false` default on the `success` key, which nothing else reached: every other
     * fixture in this class sends `success` explicitly, either true or false, so flipping the
     * default to `?? true` left the whole suite green while turning a body with no verdict at all
     * into a pass. A 200 with valid JSON and no `success` key is exactly what a captive portal, an
     * error page rendered as JSON, or a proxy that rewrites the body produces, so the default is
     * the thing standing between that and a validated request.
     */
    public function testFailsClosedWhenSiteverifyReturnsJsonWithNoSuccessKeyAtAll(): void
    {
        $this->respondWith((string)json_encode(['hostname' => 'example.com', 'action' => 'customer_login']));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse(
            $result->isValid(),
            'A siteverify body carrying no success key must be rejected, not treated as a pass.'
        );
        self::assertArrayHasKey(VerifyToken::CODE_UNKNOWN_ERROR, $result->getErrors());
    }

    /**
     * The sibling of the test above: an explicit `success: false` with no error-codes list must also
     * reject, and must do so under the same code, so that neither the missing key nor the empty
     * error list can be read as a verdict.
     */
    public function testFailsClosedWhenSuccessIsFalseAndNoErrorCodesAreSupplied(): void
    {
        $this->respondWith((string)json_encode(['success' => false]));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_UNKNOWN_ERROR, $result->getErrors());
    }

    /**
     * `success` must be the boolean true and nothing that merely looks like it. A string "true", or
     * 1, is what a lenient JSON producer emits, and a loose comparison would accept both.
     */
    public function testFailsClosedWhenSuccessIsTruthyButNotTheBooleanTrue(): void
    {
        $this->respondWith((string)json_encode(
            ['success' => 'true', 'hostname' => 'example.com', 'action' => 'customer_login']
        ));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse(
            $result->isValid(),
            'Only the boolean true is a pass; a truthy string must not validate a request.'
        );
    }

    public function testFailsClosedWhenTheHttpClientThrowsAConnectionException(): void
    {
        $this->client->method('post')->willThrowException(new \RuntimeException('connection refused'));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_CONNECTION_FAILED, $result->getErrors());
    }

    public function testFailsClosedWhenTheResponseStatusIsNot2xx(): void
    {
        $this->respondWith($this->successBody(), 503);

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_BAD_RESPONSE, $result->getErrors());
    }

    public function testFailsClosedWhenTheResponseBodyIsNotValidJson(): void
    {
        $this->respondWith('<html>502 Bad Gateway</html>');

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_INVALID_JSON, $result->getErrors());
    }

    public function testFailsClosedWhenSuccessIsFalseAndReturnsTheCloudflareErrorCodes(): void
    {
        $this->respondWith((string)json_encode([
            'success' => false,
            'error-codes' => ['invalid-input-secret', 'bad-request'],
        ]));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey('invalid-input-secret', $result->getErrors());
        self::assertArrayHasKey('bad-request', $result->getErrors());
    }

    public function testFailsClosedWithAnUnknownErrorCodeWhenSuccessIsFalseAndNoCodesAreGiven(): void
    {
        $this->respondWith((string)json_encode(['success' => false]));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_UNKNOWN_ERROR, $result->getErrors());
    }

    public function testFailsClosedWhenTheResponseHostnameIsNotInTheAllowedHostnames(): void
    {
        $this->respondWith($this->successBody('attacker.example.net'));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_HOSTNAME_MISMATCH, $result->getErrors());
    }

    public function testMatchesTheHostnameCaseInsensitively(): void
    {
        $this->respondWith($this->successBody('EXAMPLE.COM'));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertTrue($result->isValid());
    }

    public function testFailsClosedWhenTheResponseActionDoesNotMatchTheExpectedAction(): void
    {
        $this->respondWith($this->successBody('example.com', 'newsletter'));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertFalse($result->isValid());
        self::assertArrayHasKey(VerifyToken::CODE_ACTION_MISMATCH, $result->getErrors());
    }

    public function testSkipsActionValidationWhenValidateActionIsDisabledOnTheConfig(): void
    {
        $this->respondWith($this->successBody('example.com', 'a-different-action'));

        $result = $this->verifyToken->execute(self::TOKEN, $this->config('', false));

        self::assertTrue($result->isValid());
    }

    public function testPassesWhenSuccessIsTrueAndHostnameAndActionBothMatch(): void
    {
        $this->respondWith($this->successBody());

        $result = $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertTrue($result->isValid());
        self::assertSame([], $result->getErrors());
    }

    public function testNeverPassesTheSecretOrTheTokenToTheLogger(): void
    {
        $this->respondWith((string)json_encode(['success' => false, 'error-codes' => ['invalid-input-secret']]));
        $this->verifyToken->execute(self::TOKEN, $this->config('203.0.113.7'));

        $this->client->method('post')->willThrowException(new \RuntimeException(self::SECRET . ' leaked'));
        $this->verifyToken->execute(self::TOKEN, $this->config());

        self::assertNotEmpty($this->logCalls, 'the service must log technical failures, or this test is vacuous');
        foreach ($this->logCalls as $call) {
            self::assertStringNotContainsString(self::SECRET, $call['message']);
            self::assertStringNotContainsString(self::TOKEN, $call['message']);
        }
    }

    public function testReturnsErrorsKeyedByErrorCodeNeverAsAPositionalList(): void
    {
        $this->respondWith((string)json_encode([
            'success' => false,
            'error-codes' => ['invalid-input-secret', 'bad-request'],
        ]));

        $errors = $this->verifyToken->execute(self::TOKEN, $this->config())->getErrors();

        self::assertNotEmpty($errors);
        foreach (array_keys($errors) as $key) {
            self::assertIsString(
                $key,
                'RequestHandler::processError passes this key into isValidationError(string) under '
                . 'strict_types; an int key is a TypeError on the storefront form path, not a captcha error.'
            );
        }
    }

    public function testResolvesEveryErrorMessageThroughTheNativeErrorMessagesProvider(): void
    {
        $this->respondWith((string)json_encode(['success' => false, 'error-codes' => ['bad-request']]));

        $errors = $this->verifyToken->execute(self::TOKEN, $this->config())->getErrors();

        self::assertSame('label:bad-request', $errors['bad-request']);
    }

    public function testRemapsOnlyTheUserCausedCloudflareCodesToTheirTurnstilePrefixedForm(): void
    {
        $this->respondWith((string)json_encode([
            'success' => false,
            'error-codes' => ['invalid-input-response', 'timeout-or-duplicate', 'bad-request'],
        ]));

        $errors = $this->verifyToken->execute(self::TOKEN, $this->config())->getErrors();

        self::assertArrayHasKey(VerifyToken::CODE_INVALID_INPUT, $errors);
        self::assertArrayHasKey(VerifyToken::CODE_TIMEOUT_OR_DUPLICATE, $errors);
        self::assertArrayNotHasKey('invalid-input-response', $errors);
        self::assertArrayNotHasKey('timeout-or-duplicate', $errors);
        self::assertArrayHasKey('bad-request', $errors, 'technical codes pass through unremapped');
    }

    public function testEveryRemappedCodeIsTurnstilePrefixed(): void
    {
        self::assertNotEmpty(VerifyToken::USER_CAUSED_CODE_MAP);
        foreach (VerifyToken::USER_CAUSED_CODE_MAP as $cloudflareCode => $ourCode) {
            self::assertStringStartsWith('turnstile-', $ourCode);
            self::assertStringStartsNotWith('turnstile-', $cloudflareCode);
        }
    }
}
