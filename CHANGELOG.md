# Changelog

## [0.1.6](https://github.com/rapira-rs/sdk-php/compare/http-0.1.5...http-0.1.6) (2026-10-08)


### Bug Fixes

* **http:** leave the client's authority out of SERVER_NAME ([bc71e99](https://github.com/rapira-rs/sdk-php/commit/bc71e99d3050de59c470d55f394b0b8598011099))
* **http:** put loopback in REMOTE_ADDR for a unix peer, as the SAPI does ([#16](https://github.com/rapira-rs/sdk-php/issues/16)) ([bc71e99](https://github.com/rapira-rs/sdk-php/commit/bc71e99d3050de59c470d55f394b0b8598011099))

## [0.1.5](https://github.com/rapira-rs/sdk-php/compare/http-0.1.4...http-0.1.5) (2026-10-06)


### Bug Fixes

* **http:** fill server params the way the Rapira SAPI fills $_SERVER ([423e8d6](https://github.com/rapira-rs/sdk-php/commit/423e8d6193acadafbe0a0804d32ddc086952116f))
* **http:** match the form Content-Type case-insensitively ([e7886cb](https://github.com/rapira-rs/sdk-php/commit/e7886cbab9d8dbd257442df2bbb99e1b7d84fbdb))
* **http:** name uploaded files the way PHP fills $_FILES ([0ab038e](https://github.com/rapira-rs/sdk-php/commit/0ab038e89d505512f3a74b73dd5b2cc601fb7aca))
* **http:** take the query from the request-target as sent ([f737489](https://github.com/rapira-rs/sdk-php/commit/f73748961bda7a9461c373669299c89b54a4cbf7))

## [0.1.4](https://github.com/rapira-rs/sdk-php/compare/http-0.1.3...http-0.1.4) (2026-10-06)


### Bug Fixes

* **http:** parse the Cookie header the way PHP fills $_COOKIE ([f4e7249](https://github.com/rapira-rs/sdk-php/commit/f4e72495a349c92f8059591aa63acee0345b8973))

## [0.1.3](https://github.com/rapira-rs/sdk-php/compare/http-0.1.2...http-0.1.3) (2026-10-06)


### Dependencies

* update dev dependencies ([3741fca](https://github.com/rapira-rs/sdk-php/commit/3741fca741a3a5f7fd40e001e155a575c3e4af88))

## [0.1.2](https://github.com/rapira-rs/sdk-php/compare/http-0.1.1...http-0.1.2) (2026-08-20)


### Documentation

* trim package READMEs and ship a per-package LICENSE.md ([4e90f9f](https://github.com/rapira-rs/sdk-php/commit/4e90f9fc3dcd0859f1103015023f27cbe93a33c6))

## [0.1.1](https://github.com/rapira-rs/sdk-php/compare/http-0.1.0...http-0.1.1) (2026-08-20)


### Features

* **http:** add package README ([743c1e3](https://github.com/rapira-rs/sdk-php/commit/743c1e3b164a8bf814a1b68d470b4d745ca92f00))


### Documentation

* capitalize the Rapira product name ([1fa21fb](https://github.com/rapira-rs/sdk-php/commit/1fa21fbcf220f86bf551eff940b2c9a31f5d5083))
