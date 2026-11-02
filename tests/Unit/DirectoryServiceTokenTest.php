<?php

namespace Tests\Unit;

use App\Http\Middleware\DirectoryTransport;
use App\Http\Middleware\ValidateServiceToken;
use App\Services\Directory\DirectoryPolicy;
use App\Services\OAuthTokenService;
use Firebase\JWT\JWT;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/** No Laravel application, providers, dotenv, DB, cache, filesystem writes or service calls. */
final class DirectoryServiceTokenTest extends TestCase
{
    private const KEY = 'synthetic-unit-only-directory-signing-key-not-a-credential';

    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container;
        $container->instance('config', new Repository(['oauth' => [
            'issuer' => 'synthetic-issuer', 'audience' => 'synthetic-audience', 'signing_key' => self::KEY,
        ]]));
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public static function tokens(): iterable
    {
        yield 'valid' => ['valid', true];
        yield 'missing exp' => ['no_exp', false];
        yield 'expired' => ['expired', false];
        yield 'wrong signature' => ['signature', false];
        yield 'wrong issuer' => ['issuer', false];
        yield 'wrong audience' => ['audience', false];
        yield 'null exp' => ['null_exp', false];
        yield 'non integer exp' => ['string_exp', false];
        yield 'malformed token' => ['malformed', false];
    }

    private function request(string $case): Request
    {
        $claims = ['iss' => 'synthetic-issuer', 'aud' => 'synthetic-audience', 'sub' => 'synthetic-service',
            'scope' => DirectoryPolicy::READ, 'iat' => time() - 1, 'exp' => time() + 300];
        if ($case === 'no_exp') { unset($claims['exp']); }
        if ($case === 'expired') { $claims['exp'] = time() - 60; }
        if ($case === 'null_exp') { $claims['exp'] = null; }
        if ($case === 'string_exp') { $claims['exp'] = (string) (time() + 300); }
        if ($case === 'issuer') { $claims['iss'] = 'other-synthetic-issuer'; }
        if ($case === 'audience') { $claims['aud'] = 'other-synthetic-audience'; }
        $token = $case === 'malformed' ? 'not-a-jwt' : JWT::encode($claims,
            $case === 'signature' ? 'other-synthetic-unit-key-with-no-real-use' : self::KEY, 'HS256');

        return Request::create('/api/v1/identity/directory/batch', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token]);
    }

    #[DataProvider('tokens')]
    public function test_directory_profile_uses_real_signature_validation_and_requires_expiration(string $case, bool $accepted): void
    {
        $request = $this->request($case);
        $called = false;
        try {
            $response = (new ValidateServiceToken(new OAuthTokenService))->handle($request, function ($request) use (&$called) {
                $called = true;
                self::assertSame('synthetic-service', $request->attributes->get('oauth_client_id'));
                self::assertSame(DirectoryPolicy::READ, $request->attributes->get('oauth_scope'));

                return new Response('', 204);
            }, DirectoryPolicy::READ, 'directory');
            self::assertTrue($accepted);
            self::assertSame(204, $response->getStatusCode());
            self::assertTrue($called);
        } catch (UnauthorizedHttpException $error) {
            self::assertFalse($accepted);
            self::assertFalse($called);
            $response = DirectoryTransport::failure($request, $error);
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
            self::assertStringNotContainsString(self::KEY, $response->getContent());
            self::assertStringNotContainsString('data', $response->getContent());
        }
    }

    public function test_other_consumers_do_not_silently_opt_into_the_directory_profile(): void
    {
        $response = (new ValidateServiceToken(new OAuthTokenService))->handle($this->request('no_exp'), fn () => new Response('', 204));
        self::assertSame(204, $response->getStatusCode());
    }
}
