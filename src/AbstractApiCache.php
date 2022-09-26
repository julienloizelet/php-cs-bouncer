<?php

declare(strict_types=1);

namespace CrowdSecBouncer;

use CrowdSecBouncer\Fixes\Memcached\TagAwareAdapter as MemcachedTagAwareAdapter;
use DateTime;
use ErrorException;
use Exception;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\MemcachedAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\PruneableInterface;

/**
 * The cache mechanism to store every decision from LAPI. Symfony Cache component powered.
 *
 * @author    CrowdSec team
 *
 * @see      https://crowdsec.net CrowdSec Official Website
 *
 * @copyright Copyright (c) 2020+ CrowdSec
 * @license   MIT License
 */
class AbstractApiCache
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var ApiClient
     */
    protected $apiClient;

    /** @var TagAwareAdapterInterface */
    protected $adapter;

    /** @var array  */
    protected $configs;

    /** @var bool */
    protected $streamMode;

    /**
     * @var int
     */
    protected $cacheExpirationForCleanIp;

    /**
     * @var int
     */
    protected $cacheExpirationForBadIp;

    /**
     * @var int
     */
    protected $cacheExpirationForCaptcha;

    /**
     * @var int
     */
    protected $cacheExpirationForGeo;

    /** @var bool */
    protected $warmedUp;

    /**
     * @var string
     */
    protected $fallbackRemediation;

    /**
     * @var array|null
     */
    protected $geolocConfig;

    /**
     * @var array
     */
    private $cacheKeys = [];

    /**
     * @var array|null
     */
    private $scopes;

    public const CACHE_SEP = '_';

    /**
     * @param array $configs
     * @param LoggerInterface $logger
     * @throws BouncerException
     * @throws CacheException
     * @throws InvalidArgumentException
     * @throws ErrorException
     */
    public function __construct(
        array $configs,
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
        $this->configs = $configs;
        $this->apiClient = new ApiClient($this->configs, $logger);
        $this->configureAdapter();
        $this->streamMode = $configs['stream_mode'] ?? false;
        $this->cacheExpirationForCleanIp =
            $configs['clean_ip_cache_duration'] ?? Constants::CACHE_EXPIRATION_FOR_CLEAN_IP;
        $this->cacheExpirationForBadIp = $configs['bad_ip_cache_duration'] ?? Constants::CACHE_EXPIRATION_FOR_BAD_IP;
        $this->cacheExpirationForCaptcha =
            $configs['captcha_cache_duration'] ?? Constants::CACHE_EXPIRATION_FOR_CAPTCHA;
        $this->cacheExpirationForGeo = $configs['geolocation_cache_duration'] ?? Constants::CACHE_EXPIRATION_FOR_GEO;
        $this->fallbackRemediation = $configs['fallback_remediation'] ?? Constants::REMEDIATION_BYPASS;
        $this->geolocConfig = $configs['geolocation'] ?? [];

        $cacheConfigItem = $this->adapter->getItem('cacheConfig');
        $cacheConfig = $cacheConfigItem->get();
        $this->warmedUp = (\is_array($cacheConfig) && isset($cacheConfig['warmed_up'])
                           && true === $cacheConfig['warmed_up']);
    }

    /**
     * @return void
     * @throws BouncerException
     * @throws CacheException
     * @throws ErrorException
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    private function configureAdapter(): void
    {
        $cacheSystem = $this->configs['cache_system'] ?? Constants::CACHE_SYSTEM_PHPFS;
        switch ($cacheSystem) {
            case Constants::CACHE_SYSTEM_PHPFS:
                $this->adapter = new TagAwareAdapter(
                    new PhpFilesAdapter('', 0, $this->configs['fs_cache_path'])
                );
                break;

            case Constants::CACHE_SYSTEM_MEMCACHED:
                $memcachedDsn = $this->configs['memcached_dsn'];
                if (empty($memcachedDsn)) {
                    throw new BouncerException('The selected cache technology is Memcached.' .
                                               ' Please set a Memcached DSN or select another cache technology.');
                }

                $this->adapter = new MemcachedTagAwareAdapter(
                    new MemcachedAdapter(MemcachedAdapter::createConnection($memcachedDsn))
                );
                break;

            case Constants::CACHE_SYSTEM_REDIS:
                $redisDsn = $this->configs['redis_dsn'];
                if (empty($redisDsn)) {
                    throw new BouncerException('The selected cache technology is Redis.' .
                                               ' Please set a Redis DSN or select another cache technology.');
                }

                try {
                    $this->adapter = new RedisTagAwareAdapter((RedisAdapter::createConnection($redisDsn)));
                } catch (Exception $e) {
                    throw new BouncerException('Error when connecting to Redis.' .
                                               ' Please fix the Redis DSN or select another cache technology.');
                }
                break;

            default:
                throw new BouncerException("Unknown selected cache technology: $cacheSystem");
        }
    }

    /**
     * @return array
     */
    public function getScopes(): array
    {
        if (null === $this->scopes) {
            $finalScopes = [Constants::SCOPE_IP, Constants::SCOPE_RANGE];
            if (!empty($this->geolocConfig['enabled'])) {
                $finalScopes[] = Constants::SCOPE_COUNTRY;
            }
            $this->scopes = $finalScopes;
        }

        return $this->scopes;
    }

    /**
     * Add remediation to a Symfony Cache Item identified by a cache key.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws \Psr\Cache\CacheException
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function addRemediationToCacheItem(string $cacheKey, string $type, int $expiration, int $decisionId): string
    {
        $item = $this->adapter->getItem(base64_encode($cacheKey));

        // Merge with existing remediations (if any).
        $remediations = $item->isHit() ? $item->get() : [];

        $index = array_search(Constants::REMEDIATION_BYPASS, array_column($remediations, 0));
        if (false !== $index) {
            $this->logger->debug('', [
                'type' => 'IP_CLEAN_TO_BAD',
                'cache_key' => $cacheKey,
                'old_remediation' => Constants::REMEDIATION_BYPASS,
            ]);
            unset($remediations[$index]);
        }

        $remediations[] = [
            $type,
            $expiration,
            $decisionId,
        ]; // erase previous decision with the same id

        // Build the item lifetime in cache and sort remediations by priority
        $maxLifetime = max(array_column($remediations, 1));
        $prioritizedRemediations = Remediation::sortRemediationByPriority($remediations);

        $item->set($prioritizedRemediations);
        $item->expiresAt(new DateTime('@' . $maxLifetime));
        $item->tag(Constants::CACHE_TAG_REM);

        // Save the cache without committing it to the cache system.
        // Useful to improve performance when updating the cache.
        if (!$this->adapter->saveDeferred($item)) {
            throw new BouncerException("cache#$cacheKey: Unable to save this deferred item in cache: " .
                                       "$type for $expiration sec, (decision $decisionId)");
        }

        return $prioritizedRemediations[0][0];
    }

    /**
     * Remove a decision from a Symfony Cache Item identified by a cache key.
     *
     * @throws InvalidArgumentException
     * @throws Exception
     * @throws \Psr\Cache\CacheException
     *
     * @SuppressWarnings(PHPMD.StaticAccess)
     */
    public function removeDecisionFromRemediationItem(string $cacheKey, int $decisionId): bool
    {
        $item = $this->adapter->getItem(base64_encode($cacheKey));
        $remediations = $item->get();

        $index = false;
        if ($remediations) {
            $index = array_search($decisionId, array_column($remediations, 2));
        }

        // If decision was not found for this cache item early return.
        if (false === $index) {
            return false;
        }
        unset($remediations[$index]);

        if (!$remediations) {
            $this->logger->debug('', [
                'type' => 'CACHE_ITEM_REMOVED',
                'cache_key' => $cacheKey,
            ]);
            $this->adapter->delete(base64_encode($cacheKey));

            return true;
        }
        // Build the item lifetime in cache and sort remediations by priority
        $maxLifetime = max(array_column($remediations, 1));
        $cacheContent = Remediation::sortRemediationByPriority($remediations);
        $item->expiresAt(new DateTime('@' . $maxLifetime));
        $item->set($cacheContent);
        $item->tag(Constants::CACHE_TAG_REM);

        // Save the cache without committing it to the cache system.
        // Useful to improve performance when updating the cache.
        if (!$this->adapter->saveDeferred($item)) {
            throw new BouncerException("cache#$cacheKey: Unable to save item");
        }
        $this->logger->debug('', [
            'type' => 'DECISION_REMOVED',
            'decision' => $decisionId,
            'cache_key' => $cacheKey,
        ]);

        return true;
    }

    /**
     * Parse "duration" entries returned from API to a number of seconds.
     * @throws BouncerException
     */
    private static function parseDurationToSeconds(string $duration): int
    {
        $re = '/(-?)(?:(?:(\d+)h)?(\d+)m)?(\d+).\d+(m?)s/m';
        preg_match($re, $duration, $matches);
        if (!\count($matches)) {
            throw new BouncerException("Unable to parse the following duration: ${$duration}.");
        }
        $seconds = 0;
        if (isset($matches[2])) {
            $seconds += ((int)$matches[2]) * 3600; // hours
        }
        if (isset($matches[3])) {
            $seconds += ((int)$matches[3]) * 60; // minutes
        }
        if (isset($matches[4])) {
            $seconds += ((int)$matches[4]); // seconds
        }
        if ('m' === ($matches[5])) { // units in milliseconds
            $seconds *= 0.001;
        }
        if ('-' === ($matches[1])) { // negative
            $seconds *= -1;
        }

        return (int)round($seconds);
    }

    /**
     * Format a remediation item of a cache item.
     * This format use a minimal amount of data allowing less cache data consumption.
     * @throws BouncerException
     */
    public function formatRemediationFromDecision(?array $decision): array
    {
        if (!$decision) {
            $duration = time() + $this->cacheExpirationForCleanIp;
            if ($this->streamMode) {
                /**
                 * In stream mode we consider a clean IP forever... until the next resync.
                 * in this case, forever is 10 years as PHP_INT_MAX will cause trouble with the Memcached Adapter
                 * (int to float unwanted conversion)
                 * */
                $duration = 315360000;
            }

            return [Constants::REMEDIATION_BYPASS, $duration, 0];
        }

        $duration = self::parseDurationToSeconds($decision['duration']);

        // Don't set a max duration in stream mode to avoid bugs. Only the stream update has to change the cache state.
        if (!$this->streamMode) {
            $duration = min($this->cacheExpirationForBadIp, $duration);
        }

        return [
            $decision['type'],  // ex: ban, captcha
            time() + $duration, // expiration timestamp
            $decision['id'],
        ];
    }

    /**
     * @throws InvalidArgumentException
     */
    public function defferUpdateCacheConfig(array $config): void
    {
        $cacheConfigItem = $this->adapter->getItem('cacheConfig');
        $cacheConfig = $cacheConfigItem->isHit() ? $cacheConfigItem->get() : [];
        $cacheConfig = array_replace_recursive($cacheConfig, $config);
        $cacheConfigItem->set($cacheConfig);
        $this->adapter->saveDeferred($cacheConfigItem);
    }

    /**
     * Update the cached remediation of the specified cacheKey from these new decisions.
     *
     * @throws InvalidArgumentException|\Psr\Cache\CacheException
     * @throws BouncerException
     *
     * @SuppressWarnings(PHPMD.ElseExpression)
     */
    public function saveRemediationsForCacheKey(array $decisions, string $cacheKey): string
    {
        $remediationResult = Constants::REMEDIATION_BYPASS;
        if (\count($decisions)) {
            foreach ($decisions as $decision) {
                if (!\in_array($decision['type'], Constants::ORDERED_REMEDIATIONS)) {
                    $this->logger->warning('', [
                            'type' => 'UNKNOWN_REMEDIATION',
                            'unknown' => $decision['type'],
                            'fallback' => $this->fallbackRemediation]);
                    $decision['type'] = $this->fallbackRemediation;
                }
                $remediation = $this->formatRemediationFromDecision($decision);
                $type = $remediation[0];
                $exp = $remediation[1];
                $id = $remediation[2];
                $remediationResult = $this->addRemediationToCacheItem($cacheKey, $type, $exp, $id);
            }
        } else {
            $remediation = $this->formatRemediationFromDecision(null);
            $type = $remediation[0];
            $exp = $remediation[1];
            $id = $remediation[2];
            $remediationResult = $this->addRemediationToCacheItem($cacheKey, $type, $exp, $id);
        }
        $this->commit();

        return $remediationResult;
    }

    /**
     * Cache key convention.
     *
     * @param string $scope
     * @param string $value
     * @return string
     * @throws BouncerException
     */
    public function getCacheKey(string $scope, string $value): string
    {
        if (!isset($this->cacheKeys[$scope][$value])) {
            switch ($scope) {
                case Constants::SCOPE_RANGE:
                    $this->cacheKeys[$scope][$value] = Constants::SCOPE_IP . self::CACHE_SEP . $value;
                    break;
                case Constants::SCOPE_IP:
                case Constants::CACHE_TAG_GEO . self::CACHE_SEP . Constants::SCOPE_IP:
                case Constants::CACHE_TAG_CAPTCHA . self::CACHE_SEP . Constants::SCOPE_IP:
                case Constants::SCOPE_COUNTRY:
                    $this->cacheKeys[$scope][$value] = $scope . self::CACHE_SEP . $value;
                    break;
                default:
                    throw new BouncerException('Unknown scope:' . $scope);
            }
        }

        return $this->cacheKeys[$scope][$value];
    }

    /**
     * @throws InvalidArgumentException|BouncerException
     */
    public function clear(): bool
    {
        $this->setCustomErrorHandler();
        try {
            $cleared = $this->adapter->clear();
        } finally {
            $this->unsetCustomErrorHandler();
        }
        $this->warmedUp = false;
        $this->defferUpdateCacheConfig(['warmed_up' => $this->warmedUp]);
        $this->commit();
        $this->logger->info('', ['type' => 'CACHE_CLEARED']);

        return $cleared;
    }

    /**
     * This method is called when nothing has been found in cache for the requested value/cacheScope pair (IP,country).
     * In live mode is enabled, calls the API for decisions concerning the specified IP
     * In stream mode, as we consider cache is the single source of truth, the value is considered clean.
     * Finally, the result is stored in caches for further calls.
     *
     * @throws InvalidArgumentException
     * @throws Exception|\Psr\Cache\CacheException
     */
    protected function miss(string $value, string $cacheScope): string
    {
        $decisions = [];
        $cacheKey = $this->getCacheKey($cacheScope, $value);
        if (!$this->streamMode) {
            if (Constants::SCOPE_IP === $cacheScope) {
                $this->logger->debug('', ['type' => 'DIRECT_API_CALL', 'ip' => $value]);
                $decisions = $this->apiClient->getFilteredDecisions(['ip' => $value]);
            } elseif (Constants::SCOPE_COUNTRY === $cacheScope) {
                $this->logger->debug('', ['type' => 'DIRECT_API_CALL', 'country' => $value]);
                $decisions = $this->apiClient->getFilteredDecisions([
                    'scope' => Constants::SCOPE_COUNTRY,
                    'value' => $value,
                ]);
            }
        }

        return $this->saveRemediationsForCacheKey($decisions, $cacheKey);
    }

    /**
     * Used in both mode (stream and rupture).
     * This method formats the cached item as a remediation.
     * It returns the highest remediation level found.
     *
     * @throws InvalidArgumentException
     */
    protected function hit(string $ip): string
    {
        $remediations = $this->adapter->getItem(base64_encode($ip))->get();

        // We apply array values first because keys are ids.
        $firstRemediation = array_values($remediations)[0];

        /** @var string */
        return $firstRemediation[0];
    }

    /**
     * Prune the cache (only when using PHP File System cache).
     * @throws BouncerException
     */
    public function prune(): bool
    {
        if ($this->adapter instanceof PruneableInterface) {
            $pruned = $this->adapter->prune();
            $this->logger->debug('', ['type' => 'CACHE_PRUNED']);

            return $pruned;
        }

        throw new BouncerException('Cache Adapter' . \get_class($this->adapter) . ' is not prunable.');
    }

    /**
     * When Memcached connection fail, it throws an unhandled warning.
     * To catch this warning as a clean exception we have to temporarily change the error handler.
     * @throws BouncerException
     */
    protected function setCustomErrorHandler(): void
    {
        if ($this->adapter instanceof MemcachedTagAwareAdapter) {
            set_error_handler(function ($errno, $errstr) {
                $message = "Error when connecting to Memcached. (Error level: $errno)" .
                           "Please fix the Memcached DSN or select another cache technology." .
                           "Original message was: $errstr";
                throw new BouncerException($message);
            });
        }
    }

    /**
     * When the selected cache adapter is MemcachedAdapter, revert to the previous error handler.
     * */
    protected function unsetCustomErrorHandler(): void
    {
        if ($this->adapter instanceof MemcachedTagAwareAdapter) {
            restore_error_handler();
        }
    }

    /**
     * Wrap the cacheAdapter to catch warnings.
     *
     * @throws BouncerException if the connection was not successful
     * */
    protected function commit(): bool
    {
        $this->setCustomErrorHandler();
        try {
            $result = $this->adapter->commit();
        } finally {
            $this->unsetCustomErrorHandler();
        }

        return $result;
    }

    /**
     * Test the connection to the cache system (Redis or Memcached).
     *
     * @throws BouncerException|InvalidArgumentException if the connection was not successful
     * */
    public function testConnection(): void
    {
        $this->setCustomErrorHandler();
        try {
            $this->adapter->getItem(' ');
        } finally {
            $this->unsetCustomErrorHandler();
        }
    }

    /**
     * Retrieve raw cache item for some IP and cache tag.
     *
     * @return array|mixed
     *
     * @throws InvalidArgumentException|BouncerException
     */
    private function getIpCachedVariables(string $cacheTag, string $ip)
    {
        $cacheKey = $this->getCacheKey($cacheTag . self::CACHE_SEP . Constants::SCOPE_IP, $ip);
        $cachedVariables = [];
        if ($this->adapter->hasItem(base64_encode($cacheKey))) {
            $cachedVariables = $this->adapter->getItem(base64_encode($cacheKey))->get();
        }

        return $cachedVariables;
    }

    /**
     * Retrieved prepared cached variables associated to an Ip
     * Set null if not already in cache.
     *
     * @param string $cacheTag
     * @param array $names
     * @param string $ip
     *
     * @return array
     * @throws InvalidArgumentException|BouncerException
     */
    public function getIpVariables(string $cacheTag, array $names, string $ip): array
    {
        $cachedVariables = $this->getIpCachedVariables($cacheTag, $ip);
        $variables = [];
        foreach ($names as $name) {
            $variables[$name] = null;
            if (isset($cachedVariables[$name])) {
                $variables[$name] = $cachedVariables[$name];
            }
        }

        return $variables;
    }

    /**
     * Store variables in cache for some IP and cache tag.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     * @throws \Psr\Cache\CacheException
     * @throws Exception
     */
    public function setIpVariables(string $cacheTag, array $pairs, string $ip)
    {
        $cacheKey = $this->getCacheKey($cacheTag . self::CACHE_SEP . Constants::SCOPE_IP, $ip);
        $cachedVariables = $this->getIpCachedVariables($cacheTag, $ip);
        foreach ($pairs as $name => $value) {
            $cachedVariables[$name] = $value;
        }
        $this->saveCacheItem($cacheTag, $cacheKey, $cachedVariables);
    }

    /**
     * Unset cached variables for some IP and cache tag.
     *
     * @param string $cacheTag
     * @param array $pairs
     * @param string $ip
     * @return void
     * @throws InvalidArgumentException
     * @throws \Psr\Cache\CacheException|BouncerException
     */
    public function unsetIpVariables(string $cacheTag, array $pairs, string $ip)
    {
        $cacheKey = $this->getCacheKey($cacheTag . self::CACHE_SEP . Constants::SCOPE_IP, $ip);
        $cachedVariables = $this->getIpCachedVariables($cacheTag, $ip);
        foreach ($pairs as $name => $value) {
            unset($cachedVariables[$name]);
        }
        $this->saveCacheItem($cacheTag, $cacheKey, $cachedVariables);
    }

    /**
     * @param string $cacheTag
     * @param string $cacheKey
     * @param mixed $cachedVariables
     * @return void
     * @throws InvalidArgumentException
     * @throws \Psr\Cache\CacheException
     */
    protected function saveCacheItem(string $cacheTag, string $cacheKey, $cachedVariables): void
    {
        $duration = (Constants::CACHE_TAG_CAPTCHA === $cacheTag)
            ? $this->cacheExpirationForCaptcha : $this->cacheExpirationForGeo;
        $item = $this->adapter->getItem(base64_encode($cacheKey));
        $item->set($cachedVariables);
        $item->expiresAt(new DateTime("+$duration seconds"));
        $item->tag($cacheTag);
        $this->adapter->save($item);
    }


    public function getClient(): ApiClient
    {
        return $this->apiClient;
    }

    public function getAdapter(): TagAwareAdapterInterface
    {
        return $this->adapter;
    }
}
