<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\I18n;

use App\Infrastructure\I18n\LocaleResolver;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\SiteURI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\Mock\MockSession;
use CodeIgniter\Session\Handlers\ArrayHandler;
use Config\App;
use Config\Services;

final class LocaleResolverTest extends CIUnitTestCase
{
    public function test_default_locale_is_used_when_nothing_matches(): void
    {
        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');

        $this->assertSame('en', $resolver->resolve($this->makeRequest('/path')));
    }

    public function test_query_parameter_wins_over_header(): void
    {
        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');

        $request = $this->makeRequest('/path?locale=pt-BR', headers: ['Accept-Language' => 'en;q=0.9']);

        $this->assertSame('pt-br', $resolver->resolve($request));
    }

    public function test_accept_language_picks_supported_locale(): void
    {
        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');

        $request = $this->makeRequest('/x', headers: ['Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.5']);

        $this->assertSame('pt-br', $resolver->resolve($request));
    }

    public function test_accept_language_falls_back_to_default_for_unsupported(): void
    {
        $resolver = new LocaleResolver(['en'], 'en');

        $request = $this->makeRequest('/x', headers: ['Accept-Language' => 'pt-BR']);

        $this->assertSame('en', $resolver->resolve($request));
    }

    public function test_unknown_query_locale_is_discarded(): void
    {
        $resolver = new LocaleResolver(['en'], 'en');

        $request = $this->makeRequest('/x?locale=de-DE', headers: ['Accept-Language' => 'fr']);
        $this->assertSame('en', $resolver->resolve($request));
    }

    public function test_session_locale_wins_over_query_and_header(): void
    {
        $this->mockSession();
        session()->set('locale', 'pt-br');

        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');

        $request = $this->makeRequest('/x?locale=en', headers: ['Accept-Language' => 'en']);
        $this->assertSame('pt-br', $resolver->resolve($request));
    }

    public function test_persist_choice_writes_to_session_for_supported_locale(): void
    {
        $this->mockSession();
        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');

        $this->assertTrue($resolver->persistChoice('pt-BR'));
        $this->assertSame('pt-br', session()->get('locale'));
    }

    public function test_persist_choice_rejects_unsupported(): void
    {
        $this->mockSession();
        $resolver = new LocaleResolver(['en'], 'en');

        $this->assertFalse($resolver->persistChoice('xx-XX'));
        $this->assertNull(session()->get('locale'));
    }

    public function test_supported_returns_configured_list(): void
    {
        $resolver = new LocaleResolver(['en', 'pt-br'], 'en');
        $this->assertSame(['en', 'pt-br'], $resolver->supported());
        $this->assertSame('en', $resolver->default());
    }

    /**
     * @param array<string, string> $headers
     */
    private function makeRequest(string $path, array $headers = []): IncomingRequest
    {
        $config = new App();
        $uri = new SiteURI($config);

        $parts = explode('?', $path, 2);
        $uri->setPath($parts[0]);
        if (isset($parts[1])) {
            $uri->setQuery($parts[1]);
            Services::injectMock('uri', $uri);
        }

        $request = new IncomingRequest($config, $uri, '', new UserAgent());
        $request->setMethod('GET');
        foreach ($headers as $name => $value) {
            $request->setHeader($name, $value);
        }
        if (isset($parts[1])) {
            parse_str($parts[1], $get);
            $request->setGlobal('get', $get);
        }
        return $request;
    }
}
