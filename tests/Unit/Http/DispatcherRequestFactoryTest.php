<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Http;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use Psr\Http\Message\StreamFactoryInterface;
use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\Http\Tls;
use Rapira\Http\UploadedFile;
use Rapira\InetAddress;
use Rapira\UnixAddress;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Rapira\Sdk\Tests\Support\FailingFileStreamFactory;
use Rapira\Sdk\Tests\Support\StubExchange;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Covers(DispatcherRequestFactory::class)]
final class DispatcherRequestFactoryTest
{
    #[Test]
    public function testMethodUriAndProtocolAreTaken(): void
    {
        $request = $this->create(self::request(
            method: 'PUT',
            uri: 'http://example.com/path',
            protocol: 'HTTP/2',
        ));

        Assert::same($request->getMethod(), 'PUT');
        Assert::same((string) $request->getUri(), 'http://example.com/path');
        Assert::same($request->getProtocolVersion(), '2');
    }

    #[Test]
    public function testHeadersAreCopied(): void
    {
        $request = $this->create(self::request(headers: [
            'Content-Type' => ['text/plain'],
            'X-Foo' => ['a', 'b'],
        ]));

        Assert::same($request->getHeaderLine('Content-Type'), 'text/plain');
        Assert::same($request->getHeader('X-Foo'), ['a', 'b']);
    }

    #[Test]
    public function testQueryParamsAreParsedFromUri(): void
    {
        $request = $this->create(self::request(uri: 'http://host/p?a=1&b[]=2&b[]=3'));

        Assert::same($request->getQueryParams(), ['a' => '1', 'b' => ['2', '3']]);
    }

    #[Test]
    public function testCookiesAreParsedFromHeader(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['a=1; b=2; malformed; c=3'],
        ]));

        Assert::same($request->getCookieParams(), ['a' => '1', 'b' => '2', 'c' => '3']);
    }

    #[Test]
    public function testServerParamsFromInetAddresses(): void
    {
        $request = $this->create(self::request(
            target: '/path?x=1',
            authority: 'example.com',
            protocol: 'HTTP/2',
            remote: new InetAddress('1.2.3.4', 5678),
            server: new InetAddress('9.9.9.9', 80),
            tls: new Tls('TLSv1.3', 'TLS_AES_256_GCM_SHA384', null, null, null, null, null),
            receivedAt: 1_700_000_000.5,
        ));

        $params = $request->getServerParams();

        Assert::same($params['REQUEST_METHOD'], 'GET');
        Assert::same($params['REQUEST_URI'], '/path?x=1');
        Assert::same($params['SERVER_PROTOCOL'], 'HTTP/2');
        Assert::same($params['HTTP_HOST'], 'example.com');
        Assert::same($params['HTTPS'], 'on');
        Assert::same($params['REMOTE_ADDR'], '1.2.3.4');
        Assert::same($params['REMOTE_PORT'], 5678);
        Assert::same($params['SERVER_ADDR'], '9.9.9.9');
        Assert::same($params['SERVER_PORT'], 80);
        Assert::same($params['REQUEST_TIME'], 1_700_000_000);
        Assert::same($params['REQUEST_TIME_FLOAT'], 1_700_000_000.5);
    }

    #[Test]
    public function testServerParamsFromUnixAddresses(): void
    {
        $request = $this->create(self::request(
            remote: new UnixAddress('/tmp/remote.sock'),
            server: new UnixAddress('/tmp/server.sock'),
        ));

        $params = $request->getServerParams();

        Assert::same($params['REMOTE_ADDR'], '/tmp/remote.sock');
        Assert::same($params['SERVER_ADDR'], '/tmp/server.sock');
        Assert::false(isset($params['REMOTE_PORT']));
        Assert::false(isset($params['SERVER_PORT']));
    }

    #[Test]
    public function testUnnamedUnixPeerContributesNoAddress(): void
    {
        $request = $this->create(self::request(remote: new UnixAddress(null)));

        Assert::false(isset($request->getServerParams()['REMOTE_ADDR']));
    }

    #[Test]
    public function testPlaintextListenerHasNoHttpsParam(): void
    {
        $request = $this->create(self::request(tls: null));

        Assert::false(isset($request->getServerParams()['HTTPS']));
    }

    #[Test]
    public function testHeadersAreMirroredIntoServerParams(): void
    {
        $request = $this->create(self::request(headers: [
            'Content-Type' => ['application/json'],
            'Content-Length' => ['12'],
            'X-Custom' => ['v1', 'v2'],
        ]));

        $params = $request->getServerParams();

        Assert::same($params['CONTENT_TYPE'], 'application/json');
        Assert::same($params['CONTENT_LENGTH'], '12');
        Assert::same($params['HTTP_X_CUSTOM'], 'v1, v2');
    }

    #[Test]
    public function testFormUrlEncodedBodyIsParsed(): void
    {
        $request = $this->create(self::request(
            method: 'POST',
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            body: 'a=1&b[]=2&b[]=3',
        ));

        Assert::same($request->getParsedBody(), ['a' => '1', 'b' => ['2', '3']]);
        Assert::same((string) $request->getBody(), 'a=1&b[]=2&b[]=3');
    }

    #[Test]
    public function testNonFormBodyIsNotParsed(): void
    {
        $request = $this->create(self::request(
            method: 'POST',
            headers: ['Content-Type' => ['application/json']],
            body: '{"a":1}',
        ));

        Assert::null($request->getParsedBody());
        Assert::same((string) $request->getBody(), '{"a":1}');
    }

    #[Test]
    public function testMultipartFieldsAndFile(): void
    {
        $multipart = new Multipart(
            fields: [
                new FormField('name', 'John', []),
                new FormField('items[]', 'a', []),
                new FormField('items[]', 'b', []),
                new FormField('user[email]', 'x@y', []),
            ],
            files: [
                new UploadedFile('avatar', 'face.jpg', 'image/jpeg', [], self::fixture('image'), 463),
            ],
        );

        $request = $this->create(self::request(method: 'POST', body: $multipart));

        Assert::same($request->getParsedBody(), [
            'name' => 'John',
            'items' => ['a', 'b'],
            'user' => ['email' => 'x@y'],
        ]);

        $avatar = $request->getUploadedFiles()['avatar'];
        Assert::same($avatar->getClientFilename(), 'face.jpg');
        Assert::same($avatar->getClientMediaType(), 'image/jpeg');
        Assert::same($avatar->getSize(), 463);
        Assert::same($avatar->getError(), \UPLOAD_ERR_OK);

        // A multipart body is parsed away, so the message body itself is empty.
        Assert::same((string) $request->getBody(), '');
    }

    #[Test]
    public function testMultipartNestedFileNames(): void
    {
        $multipart = new Multipart(
            fields: [],
            files: [
                new UploadedFile('docs[]', 'a.txt', 'text/plain', [], self::fixture('image'), 1),
                new UploadedFile('docs[]', 'b.txt', 'text/plain', [], self::fixture('image2'), 2),
            ],
        );

        $request = $this->create(self::request(method: 'POST', body: $multipart));

        $docs = $request->getUploadedFiles()['docs'];
        Assert::same($docs[0]->getClientFilename(), 'a.txt');
        Assert::same($docs[1]->getClientFilename(), 'b.txt');
    }

    #[Test]
    public function testMultipartFileWithEmptyClientFilenameIsReportedAsNoFile(): void
    {
        $multipart = new Multipart(
            fields: [],
            files: [
                new UploadedFile('optional', '', null, [], self::fixture('image'), 0),
            ],
        );

        $request = $this->create(self::request(method: 'POST', body: $multipart));

        Assert::same($request->getUploadedFiles()['optional']->getError(), \UPLOAD_ERR_NO_FILE);
    }

    #[Test]
    public function testUploadedFileFallsBackToEmptyStreamWhenTemporaryFileIsUnavailable(): void
    {
        $streamFactory = new FailingFileStreamFactory();
        $multipart = new Multipart(
            fields: [],
            files: [
                new UploadedFile('avatar', 'face.jpg', 'image/jpeg', [], '/non-existent-file', 42),
            ],
        );

        $request = $this
            ->factory($streamFactory)
            ->create(new StubExchange(self::request(method: 'POST', body: $multipart)));

        $avatar = $request->getUploadedFiles()['avatar'];
        Assert::same($avatar->getClientFilename(), 'face.jpg');
        Assert::same((string) $avatar->getStream(), '');
        Assert::same($streamFactory->createStreamFromFileCalls, ['/non-existent-file']);
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $uri
     * @param non-empty-string $target
     * @param non-empty-string $protocol
     * @param array<non-empty-string, list<string>> $headers
     */
    private static function request(
        string $method = 'GET',
        string $uri = 'http://localhost/',
        string $target = '/',
        ?string $authority = null,
        string $protocol = 'HTTP/1.1',
        array $headers = [],
        string|Multipart $body = '',
        InetAddress|UnixAddress $remote = new InetAddress('127.0.0.1', 40000),
        InetAddress|UnixAddress $server = new InetAddress('127.0.0.1', 8080),
        ?Tls $tls = null,
        float $receivedAt = 0.0,
    ): Request {
        return new Request(
            $method,
            $uri,
            $target,
            $authority,
            $protocol,
            $headers,
            $body,
            $remote,
            $server,
            $tls,
            $receivedAt,
        );
    }

    /**
     * Absolute path to an uploaded-file fixture under `tests/Fixtures/uploads`.
     */
    private static function fixture(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixtures/uploads/' . $name;
    }

    private function create(Request $request): \Psr\Http\Message\ServerRequestInterface
    {
        return $this->factory(new StreamFactory())->create(new StubExchange($request));
    }

    private function factory(StreamFactoryInterface $streamFactory): DispatcherRequestFactory
    {
        return new DispatcherRequestFactory(
            new ServerRequestFactory(),
            new UploadedFileFactory(),
            $streamFactory,
        );
    }
}
