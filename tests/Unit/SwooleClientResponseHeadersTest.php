<?php

namespace Tests\Unit;

use Laravel\Octane\Swoole\SwooleClient;
use Mockery;
use Swoole\Http\Response as SwooleResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Swoole keeps one value per header name: a second header() call for the same
 * name replaces the first. Sending each value of a repeated header separately
 * therefore delivered only the last one — a response with ten Link values
 * reached the client with one. Repeated values must go out as one
 * comma-separated field line; cookies keep their own path.
 */
class SwooleClientResponseHeadersTest extends TestCase
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $sent = [];

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function swooleResponse(): SwooleResponse
    {
        $this->sent = [];

        $swoole = Mockery::mock(SwooleResponse::class);
        $swoole->shouldReceive('header')->andReturnUsing(function (string $name, string $value) {
            $this->sent[] = [$name, $value];

            return true;
        });
        $swoole->shouldReceive('status')->andReturnTrue();
        $swoole->shouldReceive('cookie')->andReturnTrue();
        $swoole->shouldReceive('rawcookie')->andReturnTrue();

        return $swoole;
    }

    /**
     * @return list<string>
     */
    private function sentValues(string $name): array
    {
        return array_values(array_map(
            fn (array $header) => $header[1],
            array_filter($this->sent, fn (array $header) => strcasecmp($header[0], $name) === 0),
        ));
    }

    public function test_repeated_header_values_go_out_as_one_comma_separated_line(): void
    {
        $response = new Response('ok');
        $response->headers->set('Link', '</build/app.css>; rel=preload; as=style');
        $response->headers->set('Link', '</llms.txt>; rel="llms-txt"', false);
        $response->headers->set('Link', '</privacy-policy>; rel="privacy-policy"', false);

        (new SwooleClient)->sendResponseHeaders($response, $this->swooleResponse());

        // One header() call, so Swoole has nothing to overwrite.
        $this->assertSame(
            ['</build/app.css>; rel=preload; as=style, </llms.txt>; rel="llms-txt", </privacy-policy>; rel="privacy-policy"'],
            $this->sentValues('Link'),
        );
    }

    public function test_single_value_headers_are_sent_unchanged(): void
    {
        $response = new Response('ok');
        $response->headers->set('X-Single', 'one');
        $response->headers->set('Cache-Control', 'no-cache, private');

        (new SwooleClient)->sendResponseHeaders($response, $this->swooleResponse());

        $this->assertSame(['one'], $this->sentValues('X-Single'));
        $this->assertSame(['no-cache, private'], $this->sentValues('Cache-Control'));
    }

    public function test_cookies_are_never_joined_into_a_header_line(): void
    {
        $response = new Response('ok');
        $response->headers->setCookie(Cookie::create('a', '1'));
        $response->headers->setCookie(Cookie::create('b', '2'));

        $swoole = $this->swooleResponse();
        (new SwooleClient)->sendResponseHeaders($response, $swoole);

        $this->assertSame([], $this->sentValues('Set-Cookie'));
        $swoole->shouldHaveReceived('cookie')->twice();
    }
}
