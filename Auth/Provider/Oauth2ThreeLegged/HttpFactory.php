<?php

declare(strict_types=1);

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\HandlerStack;
use kamermans\OAuth2\GrantType\AuthorizationCode;
use kamermans\OAuth2\GrantType\RefreshToken;
use kamermans\OAuth2\OAuth2Middleware;
use MauticPlugin\IntegrationsBundle\Auth\Provider\AuthConfigInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\AuthCredentialsInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\AuthProviderInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\CredentialsSignerInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\TokenPersistenceInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\ConfigAccess\TokenSignerInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CodeInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\RedirectUriInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\CredentialsInterface;
use MauticPlugin\IntegrationsBundle\Auth\Provider\Oauth2ThreeLegged\Credentials\ScopeInterface;
use MauticPlugin\IntegrationsBundle\Exception\PluginNotConfiguredException;

/**
 * Factory for building HTTP clients that will sign the requests with Oauth2 headers.
 * Based on Guzzle OAuth 2.0 Subscriber - kamermans/guzzle-oauth2-subscriber package
 * @see https://github.com/kamermans/guzzle-oauth2-subscriber
 */
class HttpFactory implements AuthProviderInterface
{
    public const NAME = 'oauth2_three_legged';

    /**
     * @var CredentialsInterface
     */
    private $credentials;

    /**
     * @var CredentialsSignerInterface|TokenPersistenceInterface|TokenSignerInterface
     */
    private $config;

    /**
     * @var Client
     */
    private $reAuthClient;

    /**
     * Cache of initialized clients.
     *
     * @var Client[]
     */
    private $initializedClients = [];

    /**
     * @return string
     */
    public function getAuthType(): string
    {
        return self::NAME;
    }

    /**
     * @param AuthCredentialsInterface|CredentialsInterface                                                 $credentials
     * @param CredentialsSignerInterface|TokenPersistenceInterface|TokenSignerInterface|AuthConfigInterface $config
     *
     * @return ClientInterface
     * @throws PluginNotConfiguredException
     */
    public function getClient(AuthCredentialsInterface $credentials, ?AuthConfigInterface $config = null): ClientInterface
    {
        if (!$this->credentialsAreConfigured($credentials)) {
            throw new PluginNotConfiguredException('Missing credentials');
        }

        // Return cached initialized client if there is one.
        if (!empty($this->initializedClients[$credentials->getClientId()])) {
            return $this->initializedClients[$credentials->getClientId()];
        }

        $this->credentials = $credentials;
        $this->config      = $config;

        $this->initializedClients[$credentials->getClientId()] = new Client(
            [
                'handler' => $this->getStackHandler(),
                'auth'    => 'oauth',
            ]
        );

        return $this->initializedClients[$credentials->getClientId()];
    }

    /**
     * @param CredentialsInterface $credentials
     *
     * @return bool
     */
    protected function credentialsAreConfigured(CredentialsInterface $credentials): bool
    {
        if (empty($credentials->getAuthorizationUrl())) {
            return false;
        }

        if (empty($credentials->getTokenUrl())) {
            return false;
        }

        if (empty($credentials->getClientId())) {
            return false;
        }

        if (empty($credentials->getClientSecret())) {
            return false;
        }

        return true;
    }

    /**
     * @return HandlerStack
     */
    private function getStackHandler(): HandlerStack
    {
        $reAuthConfig          = $this->getReAuthConfig();
        $grantType             = new AuthorizationCode($this->getReAuthClient(), $reAuthConfig);
        $refreshTokenGrantType = new RefreshToken($this->getReAuthClient(), $reAuthConfig);
        $middleware            = new OAuth2Middleware($grantType, $refreshTokenGrantType);

        $this->configureMiddleware($middleware);

        $stack = HandlerStack::create();
        $stack->push($middleware);

        return $stack;
    }

    /**
     * @return ClientInterface
     */
    private function getReAuthClient(): ClientInterface
    {
        if ($this->reAuthClient) {
            return $this->reAuthClient;
        }

        $this->reAuthClient = new Client(
            [
                'base_uri' => $this->credentials->getTokenUrl(),
            ]
        );

        return $this->reAuthClient;
    }

    /**
     * @return array
     */
    private function getReAuthConfig(): array
    {
        $config = [
            'client_id'     => $this->credentials->getClientId(),
            'client_secret' => $this->credentials->getClientSecret(),
            'code'          => 'code',
        ];

        if ($this->credentials instanceof ScopeInterface) {
            $config['scope']  = $this->credentials->getScope();
        }

        if ($this->credentials instanceof RedirectUriInterface) {
            $config['redirect_uri']  = $this->credentials->getRedirectUri();
        }

        if ($this->credentials instanceof CodeInterface) {
            $config['code'] = $this->credentials->getCode();
        }

        return $config;
    }

    /**
     * @param OAuth2Middleware $oauth
     */
    private function configureMiddleware(OAuth2Middleware $oauth): void
    {
        if (!$this->config) {
            return;
        }

        if ($this->config instanceof CredentialsSignerInterface) {
            $oauth->setClientCredentialsSigner($this->config->getCredentialsSigner());
        }

        if ($this->config instanceof TokenPersistenceInterface) {
            $oauth->setTokenPersistence($this->config->getTokenPersistence());
        }

        if ($this->config instanceof TokenSignerInterface) {
            $oauth->setAccessTokenSigner($this->config->getTokenSigner());
        }
    }
}