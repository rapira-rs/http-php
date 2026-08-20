<div align="center">

# rapira/http

</div>

<br />

> [!IMPORTANT]
> ## 🪞 This is a read-only mirror.
>
> Active development lives in [**rapira-rs/sdk-php**](https://github.com/rapira-rs/sdk-php) under `packages/http/`. This repository is **automatically synchronized** from there on every release.
>
> File issues and pull requests in the [main monorepo](https://github.com/rapira-rs/sdk-php/issues), not here.

## About

Builds a PSR-7 `ServerRequestInterface` from the shape [Rapira](https://rapira.rs/) hands your worker, with one factory per run mode:

- **`SapiRequestFactory`** — hydrates the request from the PHP superglobals (`$_SERVER`, `$_GET`, `$_POST`, `$_FILES`), the shape Rapira exposes in **classic** and **worker** modes.
- **`DispatcherRequestFactory`** — hydrates it from the [`Rapira\Http\Exchange`](https://github.com/rapira-rs/contract-php) the host delivers in **dispatcher** mode, without touching the superglobals.

Both take PSR-17 factories, so you keep your project's own PSR-7 implementation.

## Install

```bash
composer require rapira/http
```

[![PHP](https://img.shields.io/packagist/php-v/rapira/http.svg?style=flat-square&logo=php)](https://packagist.org/packages/rapira/http)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/rapira/http.svg?style=flat-square&logo=packagist)](https://packagist.org/packages/rapira/http)
[![License](https://img.shields.io/packagist/l/rapira/http.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/rapira/http.svg?style=flat-square)](https://packagist.org/packages/rapira/http/stats)

## Usage

Classic / worker mode — from the SAPI environment:

```php
use Rapira\Sdk\Http\SapiRequestFactory;

$factory = new SapiRequestFactory(
    $serverRequestFactory, // Psr\Http\Message\ServerRequestFactoryInterface
    $uriFactory,           // Psr\Http\Message\UriFactoryInterface
    $uploadedFileFactory,  // Psr\Http\Message\UploadedFileFactoryInterface
    $streamFactory,        // Psr\Http\Message\StreamFactoryInterface
);

$request = $factory->create();
```

Dispatcher mode — from the exchange the host delivers:

```php
use Rapira\Sdk\Http\DispatcherRequestFactory;

$factory = new DispatcherRequestFactory(
    $serverRequestFactory,
    $uploadedFileFactory,
    $streamFactory,
);

$request = $factory->create($exchange); // Rapira\Http\Exchange
```
