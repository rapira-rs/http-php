<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Http;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Rapira\Http\FormField;
use Rapira\Http\Multipart;
use Rapira\Http\Request;
use Rapira\Http\UploadedFile;
use Rapira\InetAddress;
use Rapira\Tls;
use Rapira\UnixAddress;
use Rapira\Sdk\Http\DispatcherRequestFactory;
use Rapira\Sdk\Tests\Support\FailingFileStreamFactory;
use Rapira\Sdk\Tests\Support\StubExchange;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataSet;
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
    public function testQueryParamsAreParsedFromTarget(): void
    {
        $request = $this->create(self::request(target: '/p?a=1&b[]=2&b[]=3'));

        Assert::same($request->getQueryParams(), ['a' => '1', 'b' => ['2', '3']]);
    }

    #[Test]
    public function testQueryIsTakenFromTargetAsSent(): void
    {
        $request = $this->create(self::request(
            uri: 'http://host/p?e=[1]&a=%zz&c+d=x%20y',
            target: '/p?e=[1]&a=%zz&c+d=x%20y',
        ));

        Assert::same($request->getServerParams()['QUERY_STRING'], 'e=[1]&a=%zz&c+d=x%20y');
        Assert::same($request->getQueryParams(), ['e' => '[1]', 'a' => '%zz', 'c_d' => 'x y']);
    }

    #[Test]
    public function testQueryIsNotTakenFromUri(): void
    {
        $request = $this->create(self::request(uri: 'http://host/p?from=uri', target: '/p'));

        Assert::same($request->getServerParams()['QUERY_STRING'], '');
        Assert::same($request->getQueryParams(), []);
    }

    #[Test]
    public function testCookiesAreParsedFromHeader(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['a=1; b=2;c=3'],
        ]));

        Assert::same($request->getCookieParams(), ['a' => '1', 'b' => '2', 'c' => '3']);
    }

    #[Test]
    public function testCookieValuesAreRawUrlDecoded(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['sid=a%3Ab%3D; space=a%20b; plus=a+b; amp=x&y=1; bad=%zz; enc=%2B'],
        ]));

        Assert::same($request->getCookieParams(), [
            'sid' => 'a:b=',
            'space' => 'a b',
            'plus' => 'a+b',
            'amp' => 'x&y=1',
            'bad' => '%zz',
            'enc' => '+',
        ]);
    }

    #[Test]
    public function testCookieNamesAreNotDecoded(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['n%41me=1; a+b=2'],
        ]));

        Assert::same($request->getCookieParams(), ['n%41me' => '1', 'a+b' => '2']);
    }

    #[Test]
    public function testFirstCookieWinsForRepeatedName(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['a=1; b=2; a=3; a.b=4; a_b=5'],
        ]));

        Assert::same($request->getCookieParams(), ['a' => '1', 'b' => '2', 'a_b' => '4']);
    }

    #[Test]
    public function testBracketedCookieNamesNest(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['arr[]=1; arr[]=2; arr[k]=3; arr[k]=4; x[a.b]=5; s[]=6; s=7'],
        ]));

        Assert::same($request->getCookieParams(), [
            'arr' => [0 => '1', 1 => '2', 'k' => '4'],
            'x' => ['a.b' => '5'],
            's' => ['6'],
        ]);
    }

    #[Test]
    public function testMultipleCookieHeadersAreJoinedWithSemicolon(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['a=1', 'b=2; a=3'],
        ]));

        Assert::same($request->getCookieParams(), ['a' => '1', 'b' => '2']);
        Assert::same($request->getHeaderLine('Cookie'), 'a=1; b=2; a=3');
        Assert::same($request->getServerParams()['HTTP_COOKIE'], 'a=1; b=2; a=3');
    }

    #[Test]
    public function testCookieNamesWithDotsAndSpacesAreMangled(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ["a.b=1; c d=2;  \te=3; f =4"],
        ]));

        Assert::same($request->getCookieParams(), ['a_b' => '1', 'c_d' => '2', 'e' => '3', 'f_' => '4']);
    }

    #[Test]
    public function testCookiePairsWithoutValue(): void
    {
        $request = $this->create(self::request(headers: [
            'Cookie' => ['flag; empty=; =orphan; ; v= 1 '],
        ]));

        Assert::same($request->getCookieParams(), ['flag' => '', 'empty' => '', 'v' => ' 1 ']);
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

        Assert::same($params['REMOTE_ADDR'], '127.0.0.1');
        Assert::same($params['REMOTE_PORT'], 0);
        Assert::same($params['SERVER_ADDR'], '/tmp/server.sock');
        Assert::false(isset($params['SERVER_PORT']));
    }

    #[Test]
    public function testUnnamedUnixPeerIsLoopback(): void
    {
        $request = $this->create(self::request(remote: new UnixAddress(null)));

        Assert::same($request->getServerParams()['REMOTE_ADDR'], '127.0.0.1');
        Assert::same($request->getServerParams()['REMOTE_PORT'], 0);
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
        Assert::same($params['HTTP_CONTENT_TYPE'], 'application/json');
        Assert::same($params['CONTENT_LENGTH'], '12');
        Assert::same($params['HTTP_CONTENT_LENGTH'], '12');
        Assert::same($params['HTTP_X_CUSTOM'], 'v1, v2');
    }

    #[Test]
    public function testHeadersNamedOutsideTokenAlphabetAreNotMirrored(): void
    {
        $request = $this->create(self::request(headers: [
            'x-forwarded-for' => ['1.1.1.1'],
            'X_Forwarded_For' => ['6.6.6.6'],
            'X.Forwarded.For' => ['7.7.7.7'],
            'x~tilde' => ['1'],
        ]));

        $params = $request->getServerParams();

        Assert::same($params['HTTP_X_FORWARDED_FOR'], '1.1.1.1');
        Assert::same(\array_keys(\array_filter(
            $params,
            static fn(string $key): bool => \str_starts_with($key, 'HTTP_'),
            \ARRAY_FILTER_USE_KEY,
        )), ['HTTP_X_FORWARDED_FOR']);
        Assert::same($request->getHeaderLine('X_Forwarded_For'), '6.6.6.6');
    }

    #[Test]
    public function testCgiServerParams(): void
    {
        $request = $this->create(self::request(
            uri: 'http://Example.COM:8080/a/b?x=1',
            target: '/a/b?x=1',
            authority: 'Example.COM:8080',
        ));

        $params = $request->getServerParams();

        Assert::same($params['GATEWAY_INTERFACE'], 'CGI/1.1');
        Assert::same($params['SERVER_SOFTWARE'], 'Rapira');
        Assert::same($params['REQUEST_SCHEME'], 'http');
        Assert::false(isset($params['SERVER_NAME']));
        Assert::same($params['HTTP_HOST'], 'Example.COM:8080');
        Assert::same($params['DOCUMENT_URI'], '/a/b');
        Assert::same($params['QUERY_STRING'], 'x=1');
    }

    #[Test]
    public function testIpv6AuthorityReachesTheHostHeaderOnly(): void
    {
        $request = $this->create(self::request(uri: 'http://[::1]:8080/', authority: '[::1]:8080'));

        Assert::same($request->getServerParams()['HTTP_HOST'], '[::1]:8080');
        Assert::false(isset($request->getServerParams()['SERVER_NAME']));
    }

    #[Test]
    public function testHttpsSchemeComesFromTheListener(): void
    {
        $params = $this->create(self::request(uri: 'https://example.com/'))->getServerParams();

        Assert::same($params['REQUEST_SCHEME'], 'https');
        Assert::same($params['HTTPS'], 'on');
    }

    #[Test]
    public function testScriptParamsAreAbsent(): void
    {
        $saved = $_SERVER['SCRIPT_FILENAME'] ?? null;
        $_SERVER['SCRIPT_FILENAME'] = '/srv/worker.php';
        try {
            $params = $this->create(self::request())->getServerParams();
        } finally {
            $_SERVER['SCRIPT_FILENAME'] = $saved;
        }

        foreach (['SCRIPT_FILENAME', 'SCRIPT_NAME', 'PHP_SELF', 'DOCUMENT_ROOT'] as $key) {
            Assert::false(\array_key_exists($key, $params));
        }
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
    #[DataSet(['Application/X-WWW-Form-Urlencoded'], 'mixed case')]
    #[DataSet(['application/x-www-form-urlencoded; charset=UTF-8'], 'parameter')]
    #[DataSet(['application/x-www-form-urlencoded,text/plain'], 'comma')]
    #[DataSet(['application/x-www-form-urlencoded charset'], 'space')]
    public function testFormContentTypeMatchesTheWayPhpDoes(string $contentType): void
    {
        $request = $this->create(self::request(
            method: 'POST',
            headers: ['Content-Type' => [$contentType]],
            body: 'a=1',
        ));

        Assert::same($request->getParsedBody(), ['a' => '1']);
    }

    #[Test]
    public function testFormContentTypeWithSuffixIsNotParsed(): void
    {
        $request = $this->create(self::request(
            method: 'POST',
            headers: ['Content-Type' => ['application/x-www-form-urlencodedx']],
            body: 'a=1',
        ));

        Assert::null($request->getParsedBody());
    }

    #[Test]
    #[DataSet(['POST'])]
    #[DataSet(['PUT'])]
    #[DataSet(['PATCH'])]
    #[DataSet(['DELETE'])]
    #[DataSet(['GET'])]
    #[DataSet(['QUERY'])]
    #[DataSet(['post'], 'lowercase')]
    #[DataSet(['PROPFIND'], 'extension method')]
    public function testFormBodyIsParsedWhateverTheMethod(string $method): void
    {
        $form = $this->create(self::request(
            method: $method,
            headers: ['Content-Type' => ['application/x-www-form-urlencoded']],
            body: 'a=1',
        ));
        $multipart = $this->create(self::request(method: $method, body: self::multipartWithFile()));

        Assert::same($form->getParsedBody(), ['a' => '1']);
        Assert::same((string) $form->getBody(), 'a=1');
        Assert::same($multipart->getParsedBody(), ['name' => 'John']);
        Assert::same(\array_keys($multipart->getUploadedFiles()), ['avatar']);
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
    public function testMultipartFileNamesAreMangledLikeTextFields(): void
    {
        $multipart = new Multipart(
            fields: [],
            files: [
                self::file('a.b', 'dot'),
                self::file('c d', 'space'),
                self::file('g.h[i.j]', 'nested'),
                self::file('x[y][]', 'first'),
                self::file('x[y][]', 'second'),
                self::file('dup', 'earlier'),
                self::file('dup', 'later'),
            ],
        );

        $files = $this->create(self::request(method: 'POST', body: $multipart))->getUploadedFiles();

        Assert::same(self::clientFilenames($files), [
            'a_b' => 'dot',
            'c_d' => 'space',
            'g_h' => ['i.j' => 'nested'],
            'x' => ['y' => ['first', 'second']],
            'dup' => 'later',
        ]);
    }

    #[Test]
    #[DataSet(['u[v'], 'unclosed bracket')]
    #[DataSet(['n]o'], 'stray closing bracket')]
    #[DataSet(['p[q]r]'], 'text after a bracket')]
    #[DataSet(['z[a]b[c]'], 'text between brackets')]
    #[DataSet(['a[[b]]'], 'bracket inside a bracket')]
    #[DataSet(['[m]'], 'no base name')]
    public function testMultipartFileWithMalformedNameIsDropped(string $name): void
    {
        $multipart = new Multipart(fields: [], files: [self::file($name, 'bad'), self::file('ok', 'good')]);

        $files = $this->create(self::request(method: 'POST', body: $multipart))->getUploadedFiles();

        Assert::same(self::clientFilenames($files), ['ok' => 'good']);
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

    private static function file(string $name, string $clientFilename): UploadedFile
    {
        return new UploadedFile($name, $clientFilename, 'text/plain', [], self::fixture('image'), 463);
    }

    /**
     * @param array<array-key, mixed> $files
     * @return array<array-key, mixed>
     */
    private static function clientFilenames(array $files): array
    {
        return \array_map(
            static fn(mixed $file): mixed => $file instanceof UploadedFileInterface
                ? $file->getClientFilename()
                : self::clientFilenames((array) $file),
            $files,
        );
    }

    private static function multipartWithFile(): Multipart
    {
        return new Multipart(
            fields: [new FormField('name', 'John', [])],
            files: [new UploadedFile('avatar', 'face.jpg', 'image/jpeg', [], self::fixture('image'), 463)],
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
