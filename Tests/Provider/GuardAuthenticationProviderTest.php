<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Guard\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\AuthenticationExpiredException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Guard\AuthenticatorInterface;
use Symfony\Component\Security\Guard\Provider\GuardAuthenticationProvider;
use Symfony\Component\Security\Guard\Token\GuardTokenInterface;
use Symfony\Component\Security\Guard\Token\PostAuthenticationGuardToken;
use Symfony\Component\Security\Guard\Token\PreAuthenticationGuardToken;

/**
 * @author Ryan Weaver <weaverryan@gmail.com>
 *
 * @group legacy
 */
class GuardAuthenticationProviderTest extends TestCase
{
    private $userProvider;
    private $userChecker;
    private $preAuthenticationToken;

    public function testAuthenticate()
    {
        $providerKey = 'my_cool_firewall';

        $authenticatorA = $this->createMock(AuthenticatorInterface::class);
        $authenticatorB = $this->createMock(AuthenticatorInterface::class);
        $authenticatorC = $this->createMock(AuthenticatorInterface::class);
        $authenticators = [$authenticatorA, $authenticatorB, $authenticatorC];

        // called 2 times - for authenticator A and B (stops on B because of match)
        $this->preAuthenticationToken->expects($this->exactly(2))
            ->method('getGuardProviderKey')
            // it will return the "1" index, which will match authenticatorB
            ->willReturn('my_cool_firewall_1');

        $enteredCredentials = [
            'username' => '_weaverryan_test_user',
            'password' => 'guard_auth_ftw',
        ];
        $this->preAuthenticationToken->expects($this->atLeastOnce())
            ->method('getCredentials')
            ->willReturn($enteredCredentials);

        // authenticators A and C are never called
        $authenticatorA->expects($this->never())
            ->method('getUser');
        $authenticatorC->expects($this->never())
            ->method('getUser');

        $mockedUser = $this->createMock(UserInterface::class);
        $authenticatorB->expects($this->once())
            ->method('getUser')
            ->with($enteredCredentials, $this->userProvider)
            ->willReturn($mockedUser);
        // checkCredentials is called
        $authenticatorB->expects($this->once())
            ->method('checkCredentials')
            ->with($enteredCredentials, $mockedUser)
            // authentication works!
            ->willReturn(true);
        $authedToken = $this->createMock(GuardTokenInterface::class);
        $authenticatorB->expects($this->once())
            ->method('createAuthenticatedToken')
            ->with($mockedUser, $providerKey)
            ->willReturn($authedToken);

        // user checker should be called
        $this->userChecker->expects($this->once())
            ->method('checkPreAuth')
            ->with($mockedUser);
        $this->userChecker->expects($this->once())
            ->method('checkPostAuth')
            ->with($mockedUser);

        $provider = new GuardAuthenticationProvider($authenticators, $this->userProvider, $providerKey, $this->userChecker);
        $actualAuthedToken = $provider->authenticate($this->preAuthenticationToken);
        $this->assertSame($authedToken, $actualAuthedToken);
    }

    public function testCheckCredentialsReturningFalseFailsAuthentication()
    {
        $this->expectException(BadCredentialsException::class);
        $providerKey = 'my_uncool_firewall';

        $authenticator = $this->createMock(AuthenticatorInterface::class);

        // make sure the authenticator is used
        $this->preAuthenticationToken->expects($this->any())
            ->method('getGuardProviderKey')
            // the 0 index, to match the only authenticator
            ->willReturn('my_uncool_firewall_0');

        $this->preAuthenticationToken->expects($this->atLeastOnce())
            ->method('getCredentials')
            ->willReturn('non-null-value');

        $mockedUser = $this->createMock(UserInterface::class);
        $authenticator->expects($this->once())
            ->method('getUser')
            ->willReturn($mockedUser);
        // checkCredentials is called
        $authenticator->expects($this->once())
            ->method('checkCredentials')
            // authentication fails :(
            ->willReturn(false);

        $provider = new GuardAuthenticationProvider([$authenticator], $this->userProvider, $providerKey, $this->userChecker);
        $provider->authenticate($this->preAuthenticationToken);
    }

    public function testGuardWithNoLongerAuthenticatedTriggersLogout()
    {
        $this->expectException(AuthenticationExpiredException::class);
        $providerKey = 'my_firewall_abc';

        // create a token and mark it as NOT authenticated anymore
        // this mimics what would happen if a user "changed" between request
        $mockedUser = $this->createMock(UserInterface::class);
        $token = new PostAuthenticationGuardToken($mockedUser, $providerKey, ['ROLE_USER']);
        $token->setAuthenticated(false);

        $provider = new GuardAuthenticationProvider([], $this->userProvider, $providerKey, $this->userChecker);
        $provider->authenticate($token);
    }

    public function testSupportsChecksGuardAuthenticatorsTokenOrigin()
    {
        $authenticatorA = $this->createMock(AuthenticatorInterface::class);
        $authenticatorB = $this->createMock(AuthenticatorInterface::class);
        $authenticators = [$authenticatorA, $authenticatorB];

        $mockedUser = $this->createMock(UserInterface::class);
        $provider = new GuardAuthenticationProvider($authenticators, $this->userProvider, 'first_firewall', $this->userChecker);

        $token = new PreAuthenticationGuardToken($mockedUser, 'first_firewall_1');
        $supports = $provider->supports($token);
        $this->assertTrue($supports);

        $token = new PreAuthenticationGuardToken($mockedUser, 'second_firewall_0');
        $supports = $provider->supports($token);
        $this->assertFalse($supports);
    }

    public function testAuthenticateFailsOnNonOriginatingToken()
    {
        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessageMatches('/second_firewall_0/');
        $authenticatorA = $this->createMock(AuthenticatorInterface::class);
        $authenticators = [$authenticatorA];

        $mockedUser = $this->createMock(UserInterface::class);
        $provider = new GuardAuthenticationProvider($authenticators, $this->userProvider, 'first_firewall', $this->userChecker);

        $token = new PreAuthenticationGuardToken($mockedUser, 'second_firewall_0');
        $provider->authenticate($token);
    }

    protected function setUp(): void
    {
        $this->userProvider = $this->createMock(UserProviderInterface::class);
        $this->userChecker = $this->createMock(UserCheckerInterface::class);
        $this->preAuthenticationToken = $this->createMock(PreAuthenticationGuardToken::class);
    }

    protected function tearDown(): void
    {
        $this->userProvider = null;
        $this->userChecker = null;
        $this->preAuthenticationToken = null;
    }
}
