<?php

use Illuminate\Support\Collection;
use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Trace\TracerProviderInterface;

uses(\Keepsuit\LaravelOpenTelemetry\Tests\TestCase::class)->in(__DIR__);

/**
 * @return Collection<array-key,\OpenTelemetry\SDK\Trace\ImmutableSpan>
 */
function getRecordedSpans(): Collection
{
    $tracerProvider = Globals::tracerProvider();
    assert($tracerProvider instanceof TracerProviderInterface);
    $tracerProvider->forceFlush();

    $exporter = app(\OpenTelemetry\SDK\Trace\SpanExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter);

    return collect($exporter->getSpans());
}

function withRootSpan(Closure $callback): mixed
{
    $rootSpan = \Keepsuit\LaravelOpenTelemetry\Facades\Tracer::newSpan('root')->start();
    $rootScope = $rootSpan->activate();

    $result = $callback();

    $rootScope->detach();
    $rootSpan->end();

    return $result;
}

/**
 * @return Collection<array-key,\OpenTelemetry\API\Logs\LogRecord>
 */
function getRecordedLogs(): Collection
{
    $loggerProvider = Globals::loggerProvider();
    assert($loggerProvider instanceof \OpenTelemetry\SDK\Logs\LoggerProvider);
    $loggerProvider->forceFlush();

    $exporter = app(\OpenTelemetry\SDK\Logs\LogRecordExporterInterface::class);
    assert($exporter instanceof \OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter);

    return collect($exporter->getStorage()->getArrayCopy());
}
