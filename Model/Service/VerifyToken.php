<?php
/**
 * Copyright © Magetu. All rights reserved.
 * See LICENSE.md for license details.
 */

declare(strict_types=1);

namespace Magetu\Turnstile\Model\Service;

use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\Validation\ValidationResult;
use Magento\Framework\Validation\ValidationResultFactory;
use Magento\ReCaptchaValidationApi\Model\ErrorMessagesProvider;
use Magetu\Turnstile\Model\ValidationConfig;
use Psr\Log\LoggerInterface;

/**
 * Verifies a Turnstile token against Cloudflare's siteverify endpoint.
 *
 * Consumers do not call this directly: Plugin\ValidateTurnstile invokes it whenever the resolved
 * config is a {@see ValidationConfig}. Three properties here are load-bearing:
 *
 * 1. **Fails closed on every failure mode**, transport failures included, with no fail-open switch,
 *    a toggle would be flipped during the first incident and never flipped back. The equivalent kill
 *    switch is per-surface: point that surface's `type_for` select at another captcha type.
 * 2. **Validates `hostname` and `action`, not just `success`.** Without the hostname check, a token
 *    minted on any other site sharing the sitekey is accepted; without the action check, a token
 *    minted for one form replays on another.
 * 3. **Errors are keyed by error code, never a positional list.** Native
 *    `RequestHandler::processError()` iterates `as $code => $text` and passes the key into
 *    `isValidationError(string $code)` in a `strict_types` file: a list yields integer keys and a
 *    TypeError instead of a captcha error.
 */
class VerifyToken
{
    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * Cloudflare's reference implementation allows 10s; halved because a hanging siteverify holds
     * one PHP process per guarded POST, and a provider outage would otherwise exhaust the pool.
     *
     * @var int
     */
    public const CONNECT_TIMEOUT_SECONDS = 2;

    public const TOTAL_TIMEOUT_SECONDS = 5;

    /**
     * User-caused codes: the visitor sees the configurable "please try again" message instead of a
     * generic technical failure. Prefixed `turnstile-` because Cloudflare mirrors Google's siteverify
     * vocabulary and the classification provider is shared by every installed captcha type, so raw
     * literals here would reclassify GOOGLE reCAPTCHA failures store-wide and drop their log line.
     *
     * @var string
     */
    public const CODE_MISSING_INPUT = 'turnstile-missing-input-response';

    public const CODE_INVALID_INPUT = 'turnstile-invalid-input-response';

    public const CODE_TIMEOUT_OR_DUPLICATE = 'turnstile-timeout-or-duplicate';

    /**
     * Cloudflare's code => the prefixed code we emit instead.
     *
     * Only codes whose classification must differ from the shared default appear here. Everything
     * else already has a native label and is already treated as technical. This is therefore the
     * complete set of codes the module registers anywhere; `etc/di.xml` is derived from it.
     *
     * @var array<string, string>
     */
    public const USER_CAUSED_CODE_MAP = [
        'missing-input-response' => self::CODE_MISSING_INPUT,
        'invalid-input-response' => self::CODE_INVALID_INPUT,
        'timeout-or-duplicate' => self::CODE_TIMEOUT_OR_DUPLICATE,
    ];

    /**
     * Technical codes we synthesise. These reuse the identifiers native already labels in
     * `ErrorMessagesProvider` and already treats as technical, so we register nothing for them.
     *
     * @var string
     */
    public const CODE_CONNECTION_FAILED = 'connection-failed';

    public const CODE_BAD_RESPONSE = 'bad-response';

    public const CODE_INVALID_JSON = 'invalid-json';

    public const CODE_HOSTNAME_MISMATCH = 'hostname-mismatch';

    public const CODE_ACTION_MISMATCH = 'action-mismatch';

    public const CODE_UNKNOWN_ERROR = 'unknown-error';

    /**
     * @param ClientFactory $clientFactory Yields a fresh HTTP client per verification; sharing one
     *        would carry cURL state between unrelated requests.
     * @param ErrorMessagesProvider $errorMessagesProvider Resolves a code to its human label, the
     *        same way the native validator does.
     * @param ValidationResultFactory $validationResultFactory Builds the native result object.
     * @param LoggerInterface $logger Receives technical failures only, and never the secret or token.
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly ErrorMessagesProvider $errorMessagesProvider,
        private readonly ValidationResultFactory $validationResultFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Verify a token, failing closed on anything short of an unambiguous success.
     *
     * @param string $token The `g-recaptcha-response` value the widget produced.
     * @param ValidationConfig $config
     * @return ValidationResult Empty errors means the token is good.
     */
    public function execute(string $token, ValidationConfig $config): ValidationResult
    {
        if ($token === '') {
            return $this->reject([self::CODE_MISSING_INPUT], $config);
        }

        $body = ['secret' => $config->getPrivateKey(), 'response' => $token];
        if ($config->getRemoteIp() !== '') {
            $body['remoteip'] = $config->getRemoteIp();
        }

        $client = $this->clientFactory->create();
        $client->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT_SECONDS,
        ]);

        try {
            $client->post(self::SITEVERIFY_URL, $body);
        } catch (\Throwable $e) {
            // The message is redacted rather than dropped: some HTTP clients echo the request body
            // into their exception text, which would put the secret in the log we are writing.
            $this->logTechnical(self::CODE_CONNECTION_FAILED, sprintf(
                '%s: %s',
                get_class($e),
                $this->redact($e->getMessage(), $config->getPrivateKey(), $token)
            ));

            return $this->reject([self::CODE_CONNECTION_FAILED], $config);
        }

        $status = (int)$client->getStatus();
        if ($status < 200 || $status > 299) {
            $this->logTechnical(self::CODE_BAD_RESPONSE, 'HTTP status ' . $status);

            return $this->reject([self::CODE_BAD_RESPONSE], $config);
        }

        $decoded = json_decode((string)$client->getBody(), true);
        if (!is_array($decoded)) {
            $this->logTechnical(self::CODE_INVALID_JSON, 'siteverify returned a non-JSON body');

            return $this->reject([self::CODE_INVALID_JSON], $config);
        }

        if (($decoded['success'] ?? false) !== true) {
            $codes = array_values(array_filter(
                (array)($decoded['error-codes'] ?? []),
                static fn ($code): bool => is_string($code) && $code !== ''
            ));

            return $this->reject($codes === [] ? [self::CODE_UNKNOWN_ERROR] : $codes, $config);
        }

        $allowed = $config->getAllowedHostnames();
        $hostname = strtolower((string)($decoded['hostname'] ?? ''));
        if ($allowed !== [] && !in_array($hostname, $allowed, true)) {
            // Either a misconfigured allowlist or a token minted on another site sharing the sitekey.
            $this->logTechnical(self::CODE_HOSTNAME_MISMATCH, sprintf(
                'siteverify reported hostname "%s", which is not in the configured allowlist',
                $hostname
            ));

            return $this->reject([self::CODE_HOSTNAME_MISMATCH], $config);
        }

        $expectedAction = $config->getExpectedAction();
        if ($config->getValidateAction() && $expectedAction !== '') {
            $action = (string)($decoded['action'] ?? '');
            if ($action !== $expectedAction) {
                // A token minted for a different form. Without this check it would replay here.
                $this->logTechnical(self::CODE_ACTION_MISMATCH, sprintf(
                    'siteverify reported action "%s" where "%s" was expected',
                    $action,
                    $expectedAction
                ));

                return $this->reject([self::CODE_ACTION_MISMATCH], $config);
            }
        }

        return $this->validationResultFactory->create(['errors' => []]);
    }

    /**
     * Build the errored result, remapping only the codes whose classification must differ.
     *
     * @param string[] $codes
     * @param ValidationConfig $config
     * @return ValidationResult
     */
    private function reject(array $codes, ValidationConfig $config): ValidationResult
    {
        $errors = [];
        foreach ($codes as $code) {
            $emitted = self::USER_CAUSED_CODE_MAP[$code] ?? $code;
            // Keyed by CODE, never appended positionally. See the class docblock.
            $errors[$emitted] = $this->errorMessagesProvider->getErrorMessage($emitted);
        }

        return $this->validationResultFactory->create(['errors' => $errors]);
    }

    /**
     * Log a technical failure.
     *
     * User-caused rejections are deliberately not logged: they are routine and would drown the signal.
     *
     * @param string $code
     * @param string $detail Must already be free of the secret and the token.
     * @return void
     */
    private function logTechnical(string $code, string $detail): void
    {
        $this->logger->error(sprintf('Turnstile verification failed [%s]: %s', $code, $detail));
    }

    /**
     * Strip the secret and the token out of text that is about to be logged.
     *
     * @param string $message
     * @param string $secret
     * @param string $token
     * @return string
     */
    private function redact(string $message, string $secret, string $token): string
    {
        $needles = array_values(array_filter([$secret, $token], static fn (string $v): bool => $v !== ''));

        return $needles === [] ? $message : str_replace($needles, '***', $message);
    }
}
