<?php
// @see ../../docs/USER_GUIDE.md for possible settings details
use CrowdSecBouncer\Constants;

$crowdSecStandaloneBouncerConfig = [
    /** The bouncer api key to access LAPI.
     *
     * Key generated by the cscli (CrowdSec cli) command like "cscli bouncers add bouncer-php-library"
     */
    'api_key' => 'YOUR_BOUNCER_API_KEY',

    /** Define the URL to your LAPI server, default to http://localhost:8080.
     *
     * If you have installed the CrowdSec agent on your server, it should be "http://localhost:8080"
     */
    'api_url'=> Constants::DEFAULT_LAPI_URL,

    // In seconds. The timeout when calling LAPI. Must be greater or equal than 1. Defaults to 1 sec.
    'api_timeout'=> 1,

    // HTTP user agent used to call LAPI. Default to this library name/current version.
    'api_user_agent'=> 'CrowdSec PHP Library/x.x.x',

    // true to enable verbose debug log.
    'debug_mode' => false,

    /** Absolute path to store log files.
     *
     * Important note: be sur this path won't be publicly accessible
     */
    'log_directory_path' => __DIR__.'/.logs',

    // true to stop the process and display errors if any.
    'display_errors' => false,

    /** Only for test or debug purpose. Default to empty.
     *
     * If not empty, it will be used for all remediation and geolocation processes.
     */
    'forced_test_ip' => '',

    /** Select from 'bouncing_disabled', 'normal_bouncing' or 'flex_bouncing'.
     *
     * Choose if you want to apply CrowdSec directives (Normal bouncing) or be more permissive (Flex bouncing).
     * With the `Flex mode`, it is impossible to accidentally block access to your site to people who don’t
     * deserve it. This mode makes it possible to never ban an IP but only to offer a Captcha, in the worst-case
     * scenario.
     */
    'bouncing_level' => Constants::BOUNCING_LEVEL_NORMAL,

    /** Select from 'bypass' (minimum remediation), 'captcha' or 'ban' (maximum remediation).
     * Default to 'captcha'.
     *
     * Handle unknown remediations as.
     */
    'fallback_remediation'=> Constants::REMEDIATION_CAPTCHA,

    /** Select from 'bypass' (minimum remediation),'captcha' or 'ban' (maximum remediation).
     * Default to 'ban'.
     *
     * Cap the remediation to the selected one.
     */
    'max_remediation_level'=> Constants::REMEDIATION_BAN,

    /** If you use a CDN, a reverse proxy or a load balancer, set an array of IPs.
     *
     * For other IPs, the bouncer will not trust the X-Forwarded-For header.
     */
    'trust_ip_forward_array' => [],

    /**
     * array of URIs that will not be bounced
     */
    'excluded_uris' => ['/favicon.ico'],

    // Select from 'phpfs' (File system cache), 'redis' or 'memcached'.
    'cache_system' => Constants::CACHE_SYSTEM_PHPFS,

    /** Will be used only if you choose File system as cache_system
     *
     * Important note: be sur this path won't be publicly accessible
     */
    'fs_cache_path' => __DIR__.'/.cache',

    // Will be used only if you choose Redis cache as cache_system
    'redis_dsn' => 'redis://localhost:6379',

    // Will be used only if you choose Memcached as cache_system
    'memcached_dsn' => 'memcached://localhost:11211',

    // Set the duration we keep in cache the fact that an IP is clean. In seconds. Defaults to 5.
    'clean_ip_cache_duration'=> Constants::CACHE_EXPIRATION_FOR_CLEAN_IP,

    // Set the duration we keep in cache the fact that an IP is bad. In seconds. Defaults to 20.
    'bad_ip_cache_duration'=> Constants::CACHE_EXPIRATION_FOR_BAD_IP,

    // Set the duration we keep in cache the captcha flow variables for an IP. In seconds. Defaults to 86400.
    'captcha_cache_duration'=> Constants::CACHE_EXPIRATION_FOR_CAPTCHA,

    // Set the duration we keep in cache a geolocation result for an IP . In seconds. Defaults to 86400.
    'geolocation_cache_duration'=> Constants::CACHE_EXPIRATION_FOR_GEO,

    /** true to enable stream mode, false to enable the live mode. Default to false.
     *
     * By default, the `live mode` is enabled. The first time a stranger connects to your website, this mode
     * means that the IP will be checked directly by the CrowdSec API. The rest of your user’s browsing will be
     * even more transparent thanks to the fully customizable cache system.
     *
     * But you can also activate the `stream mode`. This mode allows you to constantly feed the bouncer with the
     * malicious IP list via a background task (CRON), making it to be even faster when checking the IP of your
     * visitors. Besides, if your site has a lot of unique visitors at the same time, this will not influence the
     * traffic to the API of your CrowdSec instance.
     */
    'stream_mode'=> false,

    // Settings for geolocation remediation (i.e. country based remediation).
    'geolocation' => [
        // true to enable remediation based on country. Default to false.
        'enabled' => false,
        // Geolocation system. Only 'maxmind' is available for the moment. Default to 'maxmind'
        'type' => Constants::GEOLOCATION_TYPE_MAXMIND,
        /** true to store the geolocalized country in session. Default to true.
         *
         * Setting true will avoid multiple call to the geolocalized system (e.g. maxmind database)
         */
        'save_result' => true,
        // MaxMind settings
        'maxmind' => [
            /**Select from 'country' or 'city'. Default to 'country'
             *
             * These are the two available MaxMind database types.
             */
            'database_type' => Constants::MAXMIND_COUNTRY,
            // Absolute path to the MaxMind database (mmdb file).
            'database_path' => '/some/path/GeoLite2-Country.mmdb',
        ]
    ],

    //true to hide CrowdSec mentions on ban and captcha walls.
    'hide_mentions' => false,

    // Settings for ban and captcha walls
    'theme_color_text_primary' => 'black',
    'theme_color_text_secondary' => '#AAA',
    'theme_color_text_button' => 'white',
    'theme_color_text_error_message' => '#b90000',
    'theme_color_background_page' => '#eee',
    'theme_color_background_container' => 'white',
    'theme_color_background_button' => '#626365',
    'theme_color_background_button_hover' => '#333',
    'theme_custom_css' => '',
    // Settings for captcha wall
    'theme_text_captcha_wall_tab_title' => 'Oops..',
    'theme_text_captcha_wall_title' => 'Hmm, sorry but...',
    'theme_text_captcha_wall_subtitle' => 'Please complete the security check.',
    'theme_text_captcha_wall_refresh_image_link' => 'refresh image',
    'theme_text_captcha_wall_captcha_placeholder' => 'Type here...',
    'theme_text_captcha_wall_send_button' => 'CONTINUE',
    'theme_text_captcha_wall_error_message' => 'Please try again.',
    'theme_text_captcha_wall_footer' => '',
    // Settings for ban wall
    'theme_text_ban_wall_tab_title' => 'Oops..',
    'theme_text_ban_wall_title' => '🤭 Oh!',
    'theme_text_ban_wall_subtitle' => 'This page is protected against cyber attacks and your IP has been banned by our system.',
    'theme_text_ban_wall_footer' => '',
];
