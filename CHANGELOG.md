# Changelog

## [0.3.0](https://github.com/Larasell-dev/chronicle/compare/v0.2.7...v0.3.0) (2026-11-03)


### ⚠ BREAKING CHANGES

* CHRONICLE_POSTHOG_ENABLED and the chronicle.posthog config are removed. Set CHRONICLE_CHANNEL=stack, or posthog, to send events to PostHog. CHRONICLE_CHANNEL now names a channel in config/chronicle.php, not in config/logging.php. The chronicle log driver and the logging.channels.chronicle, chronicle.file, and posthog channels are removed.
* rename structured errors to structured exceptions
* record() now flattens nested fields and adds the Context fields. Chronicle::flattenContext() is removed.
* ChronicleException and ChronicleException::make() are removed. Catch InvalidSampler or MissingPostHogApiKey instead.
* classes move namespace, e.g. Larasell\Chronicle\Http\Middleware\LogRequests is now Larasell\Chronicle\WideEvents\Requests\Middleware\LogRequests and Larasell\Chronicle\StructuredError is now Larasell\Chronicle\Errors\StructuredError. Chronicle::errorPayload() is removed.
* CHRONICLE_SAMPLING is no longer read. Set 'sampling' in config/chronicle.php instead.

### Features

* add structured versions of the SPL exceptions to throw inline ([7d4fafc](https://github.com/Larasell-dev/chronicle/commit/7d4fafcc1c9094a90273d41ef1e5a719483a24da))
* assign request ids at the start of each request and fall back to the uri for unnamed routes ([70dd527](https://github.com/Larasell-dev/chronicle/commit/70dd52711ba795fcac7b2f761e0f72c6c9ec90fd))
* build every wide event the same way ([67867a3](https://github.com/Larasell-dev/chronicle/commit/67867a38ec8335bf75bd7b3afad787dc6cab3c6b))
* configure chronicle's log channels like config/logging.php ([94db0cc](https://github.com/Larasell-dev/chronicle/commit/94db0cccfa2869e14ca4af3af00347f868db9247))
* install the opentelemetry packages with chronicle ([f0aff78](https://github.com/Larasell-dev/chronicle/commit/f0aff78dbde56022c45d549df1f969d9f5b6ff99))
* set the sampler in the config file only ([ab63275](https://github.com/Larasell-dev/chronicle/commit/ab63275f853a9f29c23336826c5a2cd029c21843))


### Bug Fixes

* require php 8.4 ([97d5d96](https://github.com/Larasell-dev/chronicle/commit/97d5d969cccc6c65bdd048863952b0b90f016371))


### Miscellaneous Chores

* add Amp orb setup script ([4fecb22](https://github.com/Larasell-dev/chronicle/commit/4fecb2278d5b30f9aa7c6cb0f2ae7a1698215bc7))


### Code Refactoring

* organise the package around wide events and structured errors ([fafefd1](https://github.com/Larasell-dev/chronicle/commit/fafefd1b0554e5599230e18319daafc9fb1cbb29))
* rename structured errors to structured exceptions ([402c8e5](https://github.com/Larasell-dev/chronicle/commit/402c8e523f7f88f1b223dc7231f83bf24cb813a8))
* replace ChronicleException with named structured errors ([e4b525c](https://github.com/Larasell-dev/chronicle/commit/e4b525c07c647b09e24d0b3f159c67e8a8081ae3))
