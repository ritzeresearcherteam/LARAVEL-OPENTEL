<?php

use GuzzleHttp\Server\Server;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Keepsuit\LaravelOpenTelemetry\Facades\Tracer;
use Keepsuit\LaravelOpenTelemetry\Instrumentation\HttpClientInstrumentation;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

it('injects propagation headers to Http client request', function () {
    $http = Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    $root = Tracer::newSpan('root')->start();
    $scope = $root->activate();

    Http::withTrace()->get(Server::$url);

    $traceId = Tracer::traceId();

    $scope->detach();
    $root->end();

    $spans = getRecordedSpans();

    $httpSpan = Arr::get($spans, count($spans) - 2);

    $request = Http::recorded()->first()[0];
    assert($request instanceof \Illuminate\Http\Client\Request);

    expect($request)
        ->header('traceparent')->toBe([sprintf('00-%s-%s-01', $traceId, $httpSpan->getSpanId())]);
});

it('create http client span', function () {
    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    Http::withTrace()->get(Server::$url);

    $httpSpan = getRecordedSpans()->last();

    expect($httpSpan)
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getName()->toBe('HTTP GET')
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_UNSET)
        ->getAttributes()->toMatchArray([
            'url.full' => 'http://127.0.0.1/',
            'url.path' => '/',
            'url.query' => '',
            'http.request.method' => 'GET',
            'http.request.body.size' => '0',
            'url.scheme' => 'http',
            'server.address' => '127.0.0.1',
            'server.port' => 8126,
            'http.response.status_code' => 200,
        ]);
});

it('set span status to error on 4xx and 5xx status code', function () {
    Http::fake([
        '*' => Http::response('', 500, ['Content-Length' => 0]),
    ]);

    Http::withTrace()->get(Server::$url);

    $httpSpan = getRecordedSpans()->last();

    expect($httpSpan)
        ->getKind()->toBe(SpanKind::KIND_CLIENT)
        ->getName()->toBe('HTTP GET')
        ->getStatus()->getCode()->toBe(StatusCode::STATUS_ERROR)
        ->getAttributes()->toMatchArray([
            'http.response.status_code' => 500,
        ]);
});

it('trace allowed request headers', function () {
    app()->make(HttpClientInstrumentation::class)->register([
        'allowed_headers' => [
            'x-foo',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    Http::withHeaders([
        'x-foo' => 'bar',
        'x-bar' => 'baz',
    ])->withTrace()->get(Server::$url);

    $span = getRecordedSpans()[0];

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['bar'],
        ])
        ->not->toHaveKey('http.request.header.x-bar');
});

it('trace allowed response headers', function () {
    app()->make(HttpClientInstrumentation::class)->register([
        'allowed_headers' => [
            'content-type',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0, 'Content-Type' => 'text/html; charset=UTF-8']),
    ]);

    Http::withTrace()->get(Server::$url);

    $span = getRecordedSpans()[0];

    expect($span->getAttributes())
        ->toMatchArray([
            'http.response.header.content-type' => ['text/html; charset=UTF-8'],
        ])
        ->not->toHaveKey('http.response.header.date');
});

it('trace sensitive headers with hidden value', function () {
    app()->make(HttpClientInstrumentation::class)->register([
        'allowed_headers' => [
            'x-foo',
        ],
        'sensitive_headers' => [
            'x-foo',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0]),
    ]);

    Http::withHeaders(['x-foo' => 'bar'])->withTrace()->get(Server::$url);

    $span = getRecordedSpans()[0];

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.x-foo' => ['*****'],
        ]);
});

it('mark some headers as sensitive by default', function () {
    app()->make(HttpClientInstrumentation::class)->register([
        'allowed_headers' => [
            'authorization',
            'cookie',
            'set-cookie',
        ],
    ]);

    Http::fake([
        '*' => Http::response('', 200, ['Content-Length' => 0, 'Set-Cookie' => 'cookie']),
    ]);

    Http::withHeaders([
        'authorization' => 'Bearer token',
        'cookie' => 'cookie',
    ])->withTrace()->get(Server::$url);

    $span = getRecordedSpans()[0];

    expect($span->getAttributes())
        ->toMatchArray([
            'http.request.header.authorization' => ['*****'],
            'http.request.header.cookie' => ['*****'],
            'http.response.header.set-cookie' => ['*****'],
        ]);
});
