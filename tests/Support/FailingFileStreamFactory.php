<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Support;

use HttpSoft\Message\StreamFactory;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A {@see StreamFactoryInterface} whose file-based creation always fails, used to exercise the
 * "temporary uploaded file is unavailable" fallback. Every call is recorded so a test can assert
 * how the factory was invoked (the Testo replacement for a mock's `expects()->with()`).
 */
final class FailingFileStreamFactory implements StreamFactoryInterface
{
    /** @var list<string> Contents passed to {@see createStream()}. */
    public array $createStreamCalls = [];

    /** @var list<string> Filenames passed to {@see createStreamFromFile()}. */
    public array $createStreamFromFileCalls = [];

    public function __construct(
        private readonly StreamFactoryInterface $inner = new StreamFactory(),
    ) {}

    public function createStream(string $content = ''): StreamInterface
    {
        $this->createStreamCalls[] = $content;
        return $this->inner->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        $this->createStreamFromFileCalls[] = $filename;
        throw new \RuntimeException('Temporary file is unavailable.');
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return $this->inner->createStreamFromResource($resource);
    }
}
