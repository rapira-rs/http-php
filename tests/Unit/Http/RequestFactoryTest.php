<?php

declare(strict_types=1);

namespace Rapira\Sdk\Tests\Unit\Http;

use HttpSoft\Message\ServerRequestFactory;
use HttpSoft\Message\StreamFactory;
use HttpSoft\Message\UploadedFileFactory;
use HttpSoft\Message\UriFactory;
use Rapira\Sdk\Http\SapiRequestFactory;
use Rapira\Sdk\Tests\Support\FailingFileStreamFactory;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeClass;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Covers(SapiRequestFactory::class)]
final class RequestFactoryTest
{
    public static array|false $getAllHeadersResult = false;
    private array $globalServer = [];
    private array $globalPost = [];
    private array $globalFiles = [];

    public static function getAllHeadersStubResult(): array|false
    {
        return self::$getAllHeadersResult;
    }

    public static function ipv6AuthorityDataProvider(): array
    {
        return [
            'withoutPort' => [
                '[::1]',
                'http://[::1]',
            ],
            'withPort' => [
                '[::1]:8080',
                'http://[::1]:8080',
            ],
        ];
    }

    public static function bodyDataProvider(): array
    {
        return [
            'string' => ['content', 'content'],
            'null' => ['', null],
        ];
    }

    public static function hostParsingDataProvider(): array
    {
        return [
            'host' => [
                [
                    'HTTP_HOST' => 'test',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'hostWithPort' => [
                [
                    'HTTP_HOST' => 'test:88',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => 88,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv4' => [
                [
                    'HTTP_HOST' => '127.0.0.1',
                    'REQUEST_METHOD' => 'GET',
                    'HTTPS' => true,
                ],
                [
                    'method' => 'GET',
                    'host' => '127.0.0.1',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'https',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv4WithPort' => [
                [
                    'HTTP_HOST' => '127.0.0.1:443',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '127.0.0.1',
                    'port' => 443,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv6' => [
                [
                    'HTTP_HOST' => '[::1]',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '[::1]',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'ipv6WithPort' => [
                [
                    'HTTP_HOST' => '[::1]:443',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '[::1]',
                    'port' => 443,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'serverName' => [
                [
                    'SERVER_NAME' => 'test',
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'hostAndServerName' => [
                [
                    'SERVER_NAME' => 'override',
                    'HTTP_HOST' => 'test',
                    'REQUEST_METHOD' => 'GET',
                    'SERVER_PORT' => 81,
                ],
                [
                    'method' => 'GET',
                    'host' => 'test',
                    'port' => 81,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'none' => [
                [
                    'REQUEST_METHOD' => 'GET',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'path' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'REQUEST_URI' => '/path/to/folder?param=1',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '/path/to/folder',
                    'query' => '',
                ],
            ],
            'query' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'QUERY_STRING' => 'path/to/folder?param=1',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => 'path/to/folder?param=1',
                ],
            ],
            'protocol' => [
                [
                    'REQUEST_METHOD' => 'GET',
                    'SERVER_PROTOCOL' => 'HTTP/1.0',
                ],
                [
                    'method' => 'GET',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.0',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'post' => [
                [
                    'REQUEST_METHOD' => 'POST',
                ],
                [
                    'method' => 'POST',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'delete' => [
                [
                    'REQUEST_METHOD' => 'DELETE',
                ],
                [
                    'method' => 'DELETE',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'put' => [
                [
                    'REQUEST_METHOD' => 'PUT',
                ],
                [
                    'method' => 'PUT',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'http',
                    'path' => '',
                    'query' => '',
                ],
            ],
            'https' => [
                [
                    'REQUEST_METHOD' => 'PUT',
                    'HTTPS' => 'on',
                ],
                [
                    'method' => 'PUT',
                    'host' => '',
                    'port' => null,
                    'protocol' => '1.1',
                    'scheme' => 'https',
                    'path' => '',
                    'query' => '',
                ],
            ],
        ];
    }

    public static function dataPostInParsedBody(): array
    {
        return [
            [
                ['name' => 'test'],
                'application/x-www-form-urlencoded',
            ],
            [
                ['name' => 'test'],
                'multipart/form-data',
            ],
        ];
    }

    #[Test]
    public function testUploadedFiles(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'test',
            'REQUEST_METHOD' => 'GET',
        ];
        $_FILES = [
            'file1' => [
                'name' => $firstFileName = 'facepalm.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => self::fixture('image'),
                'error' => '0',
                'size' => '463',
            ],
            'file2' => [
                'name' => [$secondFileName = 'facepalm2.jpg', $thirdFileName = 'facepalm3.jpg'],
                'type' => ['image/jpeg', 'image/jpeg'],
                'tmp_name' => [self::fixture('image2'), self::fixture('image3')],
                'error' => ['0', '0'],
                'size' => ['778', '1415'],
            ],
        ];

        $serverRequest = $this->createRequestFactory()->create();

        $firstUploadedFile = $serverRequest->getUploadedFiles()['file1'];
        Assert::same($firstUploadedFile->getClientFilename(), $firstFileName);

        $secondUploadedFile = $serverRequest->getUploadedFiles()['file2'][0];
        Assert::same($secondUploadedFile->getClientFilename(), $secondFileName);

        $thirdUploadedFile = $serverRequest->getUploadedFiles()['file2'][1];
        Assert::same($thirdUploadedFile->getClientFilename(), $thirdFileName);
    }

    #[Test]
    public function testUploadedFilesFallbackToEmptyStreamWhenTemporaryFileIsUnavailable(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'test',
            'REQUEST_METHOD' => 'GET',
        ];
        $_FILES = [
            'file1' => [
                'name' => 'facepalm.jpg',
                'type' => 'image/jpeg',
                'tmp_name' => '/non-existent-file',
                'error' => '0',
                'size' => '463',
            ],
        ];

        $streamFactory = new FailingFileStreamFactory();

        $requestFactory = new SapiRequestFactory(
            new ServerRequestFactory(),
            new UriFactory(),
            new UploadedFileFactory(),
            $streamFactory,
        );

        $serverRequest = $requestFactory->create();

        $uploadedFile = $serverRequest->getUploadedFiles()['file1'];
        Assert::same($uploadedFile->getClientFilename(), 'facepalm.jpg');
        Assert::same($uploadedFile->getClientMediaType(), 'image/jpeg');
        Assert::same($uploadedFile->getSize(), 463);
        Assert::same($uploadedFile->getError(), 0);
        Assert::same((string) $uploadedFile->getStream(), '');

        Assert::same($streamFactory->createStreamFromFileCalls, ['/non-existent-file']);
        Assert::same($streamFactory->createStreamCalls, ['']);
    }

    #[Test]
    public function testHeadersParsing(): void
    {
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'CONTENT_TYPE' => 'text/plain',
            'REQUEST_METHOD' => 'GET',
            'REDIRECT_STATUS' => '200',
            'REDIRECT_HTTP_HOST' => 'example.org',
            'REDIRECT_HTTP_CONNECTION' => 'keep-alive',
        ];

        $expected = [
            'Host' => ['example.com'],
            'Content-Type' => ['text/plain'],
            'Connection' => ['keep-alive'],
        ];

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getHeaders(), $expected);
    }

    #[Test]
    public function testHeadersParsingFallsBackWhenGetAllHeadersReturnsFalse(): void
    {
        self::$getAllHeadersResult = false;
        $_SERVER = [
            'HTTP_HOST' => 'example.com',
            'CONTENT_TYPE' => 'text/plain',
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getHeaders(), [
            'Host' => ['example.com'],
            'Content-Type' => ['text/plain'],
        ]);
    }

    #[Test]
    public function testHeadersAreTakenFromGetAllHeadersWhenAvailable(): void
    {
        self::$getAllHeadersResult = [
            'X-Test' => 'header-value',
            'X-Another' => 'another-value',
        ];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getHeader('X-Test'), ['header-value']);
        Assert::same($request->getHeader('X-Another'), ['another-value']);
    }

    #[DataProvider('ipv6AuthorityDataProvider')]
    #[Test]
    public function testIpv6HostIsFormattedAsUriAuthority(string $hostHeader, string $expectedUri): void
    {
        $_SERVER = [
            'HTTP_HOST' => $hostHeader,
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        Assert::same((string) $request->getUri(), $expectedUri);
    }

    #[Test]
    public function testInvalidMethodException(): void
    {
        $_SERVER = [];

        $requestFactory = $this->createRequestFactory();

        Expect::exception(\RuntimeException::class)->withMessage('Unable to determine HTTP request method.');
        $requestFactory->create();
    }

    #[DataProvider('bodyDataProvider')]
    #[Test]
    public function testBody(string $expected, ?string $body): void
    {
        $_SERVER = ['REQUEST_METHOD' => 'GET'];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create($this->createResource($body));

        Assert::same((string) $request->getBody(), $expected);
    }

    #[DataProvider('hostParsingDataProvider')]
    #[Test]
    public function testHostParsingFromParameters(array $serverParams, array $expectParams): void
    {
        $_SERVER = $serverParams;

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getUri()->getHost(), $expectParams['host']);
        Assert::same($request->getUri()->getPort(), $expectParams['port']);
        Assert::same($request->getMethod(), $expectParams['method']);
        Assert::same($request->getProtocolVersion(), $expectParams['protocol']);
        Assert::same($request->getUri()->getScheme(), $expectParams['scheme']);
        Assert::same($request->getUri()->getPath(), $expectParams['path']);
        Assert::same($request->getUri()->getQuery(), $expectParams['query']);
    }

    #[DataProvider('hostParsingDataProvider')]
    #[Test]
    public function testHostParsingFromGlobals(array $serverParams, array $expectParams): void
    {
        $_SERVER = $serverParams;

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getUri()->getHost(), $expectParams['host']);
        Assert::same($request->getUri()->getPort(), $expectParams['port']);
        Assert::same($request->getMethod(), $expectParams['method']);
        Assert::same($request->getProtocolVersion(), $expectParams['protocol']);
        Assert::same($request->getUri()->getScheme(), $expectParams['scheme']);
        Assert::same($request->getUri()->getPath(), $expectParams['path']);
        Assert::same($request->getUri()->getQuery(), $expectParams['query']);
    }

    #[DataProvider('dataPostInParsedBody')]
    #[Test]
    public function testPostInParsedBody(array $post, string $contentType): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => $contentType,
        ];
        $_POST = $post;

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create();

        Assert::same($request->getParsedBody(), $post);
    }

    #[Test]
    public function testPostBodyIsNotParsedForPrefixedFormUrlEncodedContentType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'xapplication/x-www-form-urlencoded',
        ];
        $_POST = ['name' => 'test'];

        $request = $this->createRequestFactory()->create();

        Assert::null($request->getParsedBody());
    }

    #[Test]
    public function testPostBodyIsNotParsedForPrefixedMultipartContentType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'xmultipart/form-data',
        ];
        $_POST = ['name' => 'test'];

        $request = $this->createRequestFactory()->create();

        Assert::null($request->getParsedBody());
    }

    #[Test]
    public function testNotParseUJsonWithoutBody(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/json',
        ];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create(false);

        Assert::null($request->getParsedBody());
    }

    #[Test]
    public function testNotParseUnknownType(): void
    {
        $_SERVER = [
            'REQUEST_METHOD' => 'POST',
            'CONTENT_TYPE' => 'application/unknown',
        ];

        $requestFactory = $this->createRequestFactory();
        $request = $requestFactory->create($this->createResource('hello'));

        Assert::null($request->getParsedBody());
    }

    #[Test]
    public function testMalformedHostWithLeadingNewlineIsNotParsedAsHostPortPair(): void
    {
        $_SERVER = [
            'HTTP_HOST' => "\nexample.com:8080",
            'REQUEST_METHOD' => 'GET',
        ];

        $request = $this->createRequestFactory()->create();

        Assert::same($request->getUri()->getHost(), "\nexample.com:8080");
        Assert::null($request->getUri()->getPort());
    }

    #[BeforeClass]
    public static function setUpBeforeClass(): void
    {
        if (!\function_exists('getallheaders')) {
            eval(<<<'PHP_WRAP'
            namespace {
                function getallheaders(): array|false
                {
                    return \Rapira\Sdk\Tests\Unit\Http\RequestFactoryTest::getAllHeadersStubResult();
                }
            }
            PHP_WRAP);
        }
    }

    #[BeforeTest]
    protected function setUp(): void
    {
        $this->globalServer = $_SERVER;
        $this->globalPost = $_POST;
        $this->globalFiles = $_FILES;
        self::$getAllHeadersResult = false;
    }

    #[AfterTest]
    protected function tearDown(): void
    {
        $_SERVER = $this->globalServer;
        $_POST = $this->globalPost;
        $_FILES = $this->globalFiles;
    }

    /**
     * Absolute path to an uploaded-file fixture under `tests/Fixtures/uploads`.
     */
    private static function fixture(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixtures/uploads/' . $name;
    }

    private function createRequestFactory(): SapiRequestFactory
    {
        return new SapiRequestFactory(
            new ServerRequestFactory(),
            new UriFactory(),
            new UploadedFileFactory(),
            new StreamFactory(),
        );
    }

    /**
     * @return false|resource
     */
    private function createResource(?string $value)
    {
        return $value === null
            ? false
            : \fopen('data://text/plain,' . $value, 'rb');
    }
}
