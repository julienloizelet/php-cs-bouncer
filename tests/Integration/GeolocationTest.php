<?php

declare(strict_types=1);
namespace CrowdSecBouncer\Tests\Integration;

use CrowdSecBouncer\ApiCache;
use CrowdSecBouncer\ApiClient;
use CrowdSecBouncer\Bouncer;
use CrowdSecBouncer\Constants;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

final class GeolocationTest extends TestCase
{
    /** @var WatcherClient */
    private $watcherClient;

    /** @var LoggerInterface */
    private $logger;
    /** @var bool  */
    private $useCurl;

    protected function setUp(): void
    {
        $this->logger = TestHelpers::createLogger();

        $this->useCurl = (bool) getenv('USE_CURL');
        $this->watcherClient = new WatcherClient($this->logger, ['use_curl' => $this->useCurl]);
    }

    public function maxmindConfigProvider(): array
    {
        return TestHelpers::maxmindConfigProvider();
    }

    private function handleMaxMindConfig(array $maxmindConfig): array
    {
        // Check if MaxMind database exist
        if (!file_exists($maxmindConfig['database_path'])) {
            $this->fail('There must be a MaxMind Database here: '.$maxmindConfig['database_path']);
        }

        return [
            'save_result' => false,
            'enabled' => true,
            'type' => 'maxmind',
            'maxmind' => [
                'database_type' => $maxmindConfig['database_type'],
                'database_path' => $maxmindConfig['database_path'],
            ],
        ];
    }

    /**
     * @group integration
     * @covers       \Bouncer
     * @dataProvider maxmindConfigProvider
     * @group ignore_
     *
     * @throws \Symfony\Component\Cache\Exception\CacheException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function testCanVerifyIpAndCountryWithMaxmindInLiveMode(array $maxmindConfig): void
    {
        // Init context
        $this->watcherClient->setInitialState();

        // Init bouncer
        $geolocationConfig = $this->handleMaxMindConfig($maxmindConfig);
        $bouncerConfigs = [
            'api_key' => TestHelpers::getBouncerKey(),
            'api_url' => TestHelpers::getLapiUrl(),
            'geolocation' => $geolocationConfig,
            'use_curl' => $this->useCurl,
            'api_user_agent' => 'Unit test/'.Constants::BASE_USER_AGENT,
            'cache_system' => Constants::CACHE_SYSTEM_PHPFS,
            'fs_cache_path' => TestHelpers::PHP_FILES_CACHE_ADAPTER_DIR
        ];

        $bouncer = new Bouncer($bouncerConfigs, $this->logger);

        $cacheAdapter = $bouncer->getCacheAdapter();
        $cacheAdapter->clear();

        $this->assertEquals(
            'captcha',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'Get decisions for a clean IP but bad country (captcha)'
        );

        $this->assertEquals(
            'bypass',
            $bouncer->getRemediationForIp(TestHelpers::IP_FRANCE),
            'Get decisions for a clean IP and clean country'
        );

        // Disable Geolocation feature
        $geolocationConfig['enabled'] = false;
        $bouncerConfigs['geolocation'] = $geolocationConfig;
        $bouncer = new Bouncer($bouncerConfigs, $this->logger);
        $cacheAdapter->clear();

        $this->assertEquals(
            'bypass',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'Get decisions for a clean IP and bad country but with geolocation disabled'
        );

        // Enable again geolocation and change testing conditions
        $this->watcherClient->setSecondState();
        $geolocationConfig['enabled'] = true;
        $bouncerConfigs['geolocation'] = $geolocationConfig;
        $bouncer = new Bouncer($bouncerConfigs, $this->logger);
        $cacheAdapter->clear();

        $this->assertEquals(
            'ban',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'Get decisions for a bad IP (ban) and bad country (captcha)'
        );

        $this->assertEquals(
            'ban',
            $bouncer->getRemediationForIp(TestHelpers::IP_FRANCE),
            'Get decisions for a bad IP (ban) and clean country'
        );
    }

    /**
     * @group integration
     * @covers       \Bouncer
     * @dataProvider maxmindConfigProvider
     * @group ignore_
     *
     * @throws \Symfony\Component\Cache\Exception\CacheException|\Psr\Cache\InvalidArgumentException
     */
    public function testCanVerifyIpAndCountryWithMaxmindInStreamMode(array $maxmindConfig): void
    {
        // Init context
        $this->watcherClient->setInitialState();
        // Init bouncer
        $geolocationConfig = $this->handleMaxMindConfig($maxmindConfig);
        $bouncerConfigs = [
            'api_key' => TestHelpers::getBouncerKey(),
            'api_url' => TestHelpers::getLapiUrl(),
            'stream_mode' => true,
            'geolocation' => $geolocationConfig,
            'use_curl' => $this->useCurl,
            'api_user_agent' => 'Unit test/'.Constants::BASE_USER_AGENT,
            'cache_system' => Constants::CACHE_SYSTEM_PHPFS,
            'fs_cache_path' => TestHelpers::PHP_FILES_CACHE_ADAPTER_DIR
        ];

        $bouncer = new Bouncer($bouncerConfigs, $this->logger);
        $cacheAdapter = $bouncer->getCacheAdapter();
        $cacheAdapter->clear();

        // Warm BlockList cache up
        $bouncer->refreshBlocklistCache();

        $this->logger->debug('', ['message' => 'Refresh the cache just after the warm up. Nothing should append.']);
        $bouncer->refreshBlocklistCache();

        $this->assertEquals(
            'captcha',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'Should captcha a clean IP coming from a bad country (captcha)'
        );

        // Add and remove decision
        $this->watcherClient->setSecondState();

        $this->assertEquals(
            'captcha',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'Should still captcha a bad IP (ban) coming from a bad country (captcha) as cache has not been refreshed'
        );

        // Pull updates
        $bouncer->refreshBlocklistCache();

        $this->assertEquals(
            'ban',
            $bouncer->getRemediationForIp(TestHelpers::IP_JAPAN),
            'The new decision should now be added, so the previously captcha IP should now be ban'
        );
    }
}
