<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Support;

use Rapira\Http\Exchange;
use Rapira\Http\Request;

/**
 * A minimal {@see Exchange} that only carries a preset {@see Request}, for testing request-building.
 *
 * The response-writing verbs are never exercised by a request factory, so they throw instead of
 * pretending to answer.
 */
final readonly class StubExchange implements Exchange
{
    public function __construct(
        private Request $request,
    ) {}

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function isFinalized(): bool
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function isCancelled(): bool
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function writeHead(int $status, array $headers = []): void
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function writeBody(string $content, bool $eos = true): void
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function sendFile(string $path, int $offset = 0, ?int $length = null, bool $eos = true): void
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function writeTrailers(array $trailers): void
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function flush(): void
    {
        throw new \BadMethodCallException('Not supported by the stub.');
    }

    public function __destruct() {}
}
