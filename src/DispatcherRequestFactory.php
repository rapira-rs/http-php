<?php

declare(strict_types=1);

namespace Rapira\Sdk\Http;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Rapira\Http\Exchange;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\Http\UploadedFile;
use Rapira\InetAddress;

/**
 * Creates a PSR-7 server request in Rapira dispatcher (worker) mode, from the {@see Exchange} the
 * {@see \Rapira\Http\HttpDispatcher} hands the worker.
 *
 * The counterpart of {@see SapiRequestFactory}: instead of reading the PHP superglobals it hydrates the
 * request from {@see Request}, the shape the rapira host delivers.
 */
final readonly class DispatcherRequestFactory
{
    public function __construct(
        private ServerRequestFactoryInterface $serverRequestFactory,
        private UploadedFileFactoryInterface $uploadedFileFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    public function create(Exchange $exchange): ServerRequestInterface
    {
        $source = $exchange->getRequest();

        $request = $this->serverRequestFactory->createServerRequest(
            $source->method,
            $source->uri,
            $this->createServerParams($source),
        );

        // Protocol arrives as `HTTP/1.1`, `HTTP/2`, `HTTP/3`; PSR-7 wants the version alone.
        $request = $request->withProtocolVersion(\str_replace('HTTP/', '', $source->protocol));

        foreach ($source->headers as $name => $values) {
            $request = $request->withHeader($name, $values);
        }

        $request = $request
            ->withQueryParams($this->parseQuery($request->getUri()->getQuery()))
            ->withCookieParams($this->parseCookies($source->headers));

        return $this->populateBody($request, $source);
    }

    /**
     * @return array<string, mixed>
     */
    private function createServerParams(Request $request): array
    {
        $params = [
            'REQUEST_METHOD' => $request->method,
            'REQUEST_URI' => $request->target,
            'SERVER_PROTOCOL' => $request->protocol,
            'REQUEST_TIME' => (int) $request->receivedAt,
            'REQUEST_TIME_FLOAT' => $request->receivedAt,
        ];

        if ($request->authority !== null) {
            $params['HTTP_HOST'] = $request->authority;
        }

        if ($request->tls !== null) {
            $params['HTTPS'] = 'on';
        }

        if ($request->remote instanceof InetAddress) {
            $params['REMOTE_ADDR'] = $request->remote->ip;
            $params['REMOTE_PORT'] = $request->remote->port;
        } elseif ($request->remote->path !== null) {
            $params['REMOTE_ADDR'] = $request->remote->path;
        }

        if ($request->server instanceof InetAddress) {
            $params['SERVER_ADDR'] = $request->server->ip;
            $params['SERVER_PORT'] = $request->server->port;
        } elseif ($request->server->path !== null) {
            $params['SERVER_ADDR'] = $request->server->path;
        }

        // Mirror the headers into the `HTTP_*` / `CONTENT_*` slots SAPI-oriented code still reads.
        foreach ($request->headers as $name => $values) {
            $key = \strtoupper(\str_replace('-', '_', $name));
            if ($key !== 'CONTENT_TYPE' && $key !== 'CONTENT_LENGTH') {
                $key = 'HTTP_' . $key;
            }
            $params[$key] = \implode(', ', $values);
        }

        return $params;
    }

    private function populateBody(ServerRequestInterface $request, Request $source): ServerRequestInterface
    {
        if ($source->body instanceof Multipart) {
            return $request
                ->withBody($this->streamFactory->createStream())
                ->withParsedBody($this->parseFields($source->body))
                ->withUploadedFiles($this->createUploadedFiles($source->body));
        }

        $request = $request->withBody($this->streamFactory->createStream($source->body));

        $contentType = $this->headerLine($source->headers, 'content-type');
        if (\preg_match('~^application/x-www-form-urlencoded(?:$| |;)~', $contentType) === 1) {
            $request = $request->withParsedBody($this->parseQuery($source->body));
        }

        return $request;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseFields(Multipart $multipart): array
    {
        // Re-encode as a query string so PHP's own parser rebuilds the nested `name[key]` structure.
        $pairs = [];
        foreach ($multipart->fields as $field) {
            $pairs[] = \urlencode($field->name) . '=' . \urlencode($field->value);
        }

        \parse_str(\implode('&', $pairs), $result);

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function createUploadedFiles(Multipart $multipart): array
    {
        $files = [];
        foreach ($multipart->files as $file) {
            $this->addNested($files, $file->name, $this->createUploadedFile($file));
        }

        return $files;
    }

    private function createUploadedFile(UploadedFile $file): UploadedFileInterface
    {
        try {
            $stream = $this->streamFactory->createStreamFromFile($file->tmpPath);
        } catch (\RuntimeException) {
            $stream = $this->streamFactory->createStream();
        }

        return $this->uploadedFileFactory->createUploadedFile(
            $stream,
            $file->size,
            $file->clientFilename === '' ? \UPLOAD_ERR_NO_FILE : \UPLOAD_ERR_OK,
            $file->clientFilename,
            $file->clientMediaType,
        );
    }

    /**
     * Inserts a value into a nested array following PHP's `name[key][]` bracket notation.
     *
     * @param array<array-key, mixed> $target
     */
    private function addNested(array &$target, string $name, mixed $value): void
    {
        if (\preg_match('/^([^\[]+)((?:\[[^\]]*])*)$/', $name, $matches) !== 1) {
            $target[$name] = $value;
            return;
        }

        $keys = [$matches[1]];
        if ($matches[2] !== '') {
            \preg_match_all('/\[([^\]]*)]/', $matches[2], $bracketed);
            foreach ($bracketed[1] as $key) {
                $keys[] = $key;
            }
        }

        $this->insert($target, $keys, $value);
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<string> $keys
     *
     * @psalm-suppress MixedArrayAssignment, MixedArgument
     */
    private function insert(array &$target, array $keys, mixed $value): void
    {
        $key = \array_shift($keys);
        if ($key === null) {
            return;
        }

        if ($key === '') {
            $target[] = $keys === [] ? $value : [];
            if ($keys !== []) {
                /** @var array-key $last */
                $last = \array_key_last($target);
                $child = &$target[$last];
                $this->insert($child, $keys, $value);
            }
            return;
        }

        if ($keys === []) {
            $target[$key] = $value;
            return;
        }

        if (!isset($target[$key]) || !\is_array($target[$key])) {
            $target[$key] = [];
        }
        $this->insert($target[$key], $keys, $value);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function parseQuery(string $query): array
    {
        \parse_str($query, $result);

        return $result;
    }

    /**
     * @param array<non-empty-string, list<string>> $headers
     *
     * @return array<string, string>
     */
    private function parseCookies(array $headers): array
    {
        $cookies = [];
        foreach (\explode(';', $this->headerLine($headers, 'cookie')) as $pair) {
            $parts = \explode('=', $pair, 2);
            if (\count($parts) !== 2) {
                continue;
            }
            $cookies[\trim($parts[0])] = \trim($parts[1]);
        }

        return $cookies;
    }

    /**
     * Case-insensitive header lookup returning the comma-joined value.
     *
     * @param array<non-empty-string, list<string>> $headers
     */
    private function headerLine(array $headers, string $name): string
    {
        foreach ($headers as $key => $values) {
            if (\strtolower($key) === $name) {
                return \implode(', ', $values);
            }
        }

        return '';
    }
}
