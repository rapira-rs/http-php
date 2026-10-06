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
        // PHP reads the query from the request-target as sent, not from a URI re-encoded on the way.
        $query = \explode('?', $source->target, 2)[1] ?? '';

        $request = $this->serverRequestFactory->createServerRequest(
            $source->method,
            $source->uri,
            $this->createServerParams($source, $query),
        );

        // Protocol arrives as `HTTP/1.1`, `HTTP/2`, `HTTP/3`; PSR-7 wants the version alone.
        $request = $request->withProtocolVersion(\str_replace('HTTP/', '', $source->protocol));

        foreach ($source->headers as $name => $values) {
            // HTTP/2 may split cookies across several fields; a comma-joined line would fuse two pairs.
            $request = $request->withHeader(
                $name,
                \strtolower($name) === 'cookie' ? \implode('; ', $values) : $values,
            );
        }

        $request = $request
            ->withQueryParams($this->parseQuery($query))
            ->withCookieParams($this->parseCookies($this->headerLine($source->headers, 'cookie', '; ')));

        return $this->populateBody($request, $source);
    }

    /**
     * PHP drops an upload whose name has an unclosed bracket or text after a `]` instead of repairing
     * it as it does for a text field: `a[b`, `a]`, `a[b]c` and `a[[b]]` never reach `$_FILES`.
     *
     * @psalm-pure
     */
    private static function isUploadName(string $name): bool
    {
        $depth = 0;
        for ($i = 0, $length = \strlen($name); $i < $length; $i++) {
            if ($name[$i] === '[') {
                $depth++;
            } elseif ($name[$i] === ']') {
                $depth--;
                if ($i + 1 < $length && $name[$i + 1] !== '[') {
                    return false;
                }
            }
            if ($depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * @return array<string, mixed>
     *
     * @psalm-capabilities read-props
     */
    private function createServerParams(Request $request, string $query): array
    {
        $https = $request->tls !== null || \str_starts_with($request->uri, 'https:');

        $params = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'Rapira',
            'SERVER_PROTOCOL' => $request->protocol,
            'REQUEST_METHOD' => $request->method,
            'REQUEST_SCHEME' => $https ? 'https' : 'http',
            'REQUEST_URI' => $request->target,
            'DOCUMENT_URI' => \explode('?', $request->target, 2)[0],
            'QUERY_STRING' => $query,
            'REQUEST_TIME' => (int) $request->receivedAt,
            'REQUEST_TIME_FLOAT' => $request->receivedAt,
        ];

        $host = \parse_url($request->uri, \PHP_URL_HOST);
        if (\is_string($host) && $host !== '') {
            $params['SERVER_NAME'] = \trim($host, '[]');
        }

        if ($request->authority !== null) {
            $params['HTTP_HOST'] = $request->authority;
        }

        if ($https) {
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

        foreach ($request->headers as $name => $values) {
            // In worker mode the host drops a field named outside `[A-Za-z0-9-]` before filling
            // `$_SERVER`: `X_Forwarded_For` or `X.Forwarded.For` would pose as `HTTP_X_FORWARDED_FOR`.
            if (\preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1) {
                continue;
            }

            $key = \strtoupper(\str_replace('-', '_', $name));
            $value = \implode($key === 'COOKIE' ? '; ' : ', ', $values);
            $params['HTTP_' . $key] = $value;
            if ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    private function populateBody(ServerRequestInterface $request, Request $source): ServerRequestInterface
    {
        // A form is read by its `Content-Type` whatever the method, as the host reads any body by its
        // framing; PHP's `$_POST` is filled for `POST` alone, which would leave `PUT` or `QUERY` forms raw.
        if ($source->body instanceof Multipart) {
            return $request
                ->withBody($this->streamFactory->createStream())
                ->withParsedBody($this->parseFields($source->body))
                ->withUploadedFiles($this->createUploadedFiles($source->body));
        }

        $request = $request->withBody($this->streamFactory->createStream($source->body));

        // PHP compares the media type case-insensitively, cut at the first `;`, `,` or space.
        $contentType = $this->headerLine($source->headers, 'content-type');
        if (\preg_match('~^application/x-www-form-urlencoded(?:$|[;, ])~i', $contentType) === 1) {
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
        // Same trick as the fields: `parse_str()` mangles and nests each name into a tree of indexes,
        // and every index is then swapped for its file.
        $pairs = [];
        $files = [];
        foreach ($multipart->files as $index => $file) {
            if (!self::isUploadName($file->name)) {
                continue;
            }
            $pairs[] = \rawurlencode($file->name) . '=' . $index;
            $files[$index] = $file;
        }

        $tree = $this->parseQuery(\implode('&', $pairs));
        \array_walk_recursive($tree, function (mixed &$value) use ($files): void {
            $value = $this->createUploadedFile($files[(int) $value]);
        });

        return $tree;
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
     * @return array<array-key, mixed>
     */
    private function parseQuery(string $query): array
    {
        \parse_str($query, $result);

        return $result;
    }

    /**
     * Parses a `Cookie` header the way PHP fills `$_COOKIE`: `;`-separated pairs with leading
     * whitespace dropped, names taken literally and then mangled (`.` and space become `_`, brackets
     * nest), values raw-URL-decoded (`+` stays `+`), an empty value for a pair without `=`, and the
     * first value kept for a repeated plain name.
     *
     * @return array<array-key, mixed>
     */
    private function parseCookies(string $header): array
    {
        $pairs = [];
        $seen = [];
        foreach (\explode(';', $header) as $pair) {
            [$name, $value] = \explode('=', \ltrim($pair, " \t\n\r\v\f"), 2) + [1 => ''];
            if ($name === '') {
                continue;
            }

            // Run the name through `parse_str()` alone to learn the key PHP would register it under.
            \parse_str(\rawurlencode($name), $probe);
            $key = \array_key_first($probe);
            if ($key === null) {
                continue;
            }

            // PHP drops a repeated plain name, but a bracketed one still nests into the existing key.
            if (!\is_array($probe[$key]) && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            // Re-encoded so `parse_str()` takes `+` and `&` literally instead of as its own syntax.
            $pairs[] = \rawurlencode($name) . '=' . \rawurlencode(\rawurldecode($value));
        }

        return $this->parseQuery(\implode('&', $pairs));
    }

    /**
     * Case-insensitive header lookup returning the values joined with the separator.
     *
     * @param array<non-empty-string, list<string>> $headers
     *
     * @psalm-pure
     */
    private function headerLine(array $headers, string $name, string $separator = ', '): string
    {
        foreach ($headers as $key => $values) {
            if (\strtolower($key) === $name) {
                return \implode($separator, $values);
            }
        }

        return '';
    }
}
