<?php
/*
    Crawler

    Crawls URLs in WordPressSite, saving them to StaticSite


Modified to working with XAMPP localhost Wordpress on Windows, this will trying to retry resource path with "/" instead "\" so there's no longer have 404 when crawling files, which broke the whole site down by have missing css,js,etc..

*/

namespace WP2Static;

use WP2StaticGuzzleHttp\Client;
use WP2StaticGuzzleHttp\Psr7\Request;
use WP2StaticGuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use WP2StaticGuzzleHttp\Exception\RequestException;
use WP2StaticGuzzleHttp\Exception\TooManyRedirectsException;
use WP2StaticGuzzleHttp\Pool;

define( 'WP2STATIC_REDIRECT_CODES', [ 301, 302, 303, 307, 308 ] );

class Crawler {

    /**
     * @var Client
     */
    private $client;
    /**
     * @var string
     */
    private $site_path;

    /**
     * @var integer
     */
    private $crawled = 0;

    /**
     * @var integer
     */
    private $cache_hits = 0;

    /**
     * Crawler constructor
     */
    public function __construct() {
        $this->site_path = rtrim( SiteInfo::getURL( 'site' ), '/' );

        $port_override = apply_filters(
            'wp2static_curl_port',
            null
        );

        $base_uri = $this->site_path;

        if ( $port_override ) {
            $base_uri = "{$base_uri}:{$port_override}";
        }

        $opts = [
            'base_uri' => $base_uri,
            'verify' => false,
            'http_errors' => false,
            'allow_redirects' => [
                'max' => 2,
                // required to get effective_url
                'track_redirects' => true,
            ],
            'connect_timeout'  => 0,
            'timeout' => 600,
            'headers' => [
                'User-Agent' => apply_filters(
                    'wp2static_curl_user_agent',
                    'WP2Static.com',
                ),
            ],
        ];

        $auth_user = CoreOptions::getValue( 'basicAuthUser' );

        if ( $auth_user ) {
            $auth_password = CoreOptions::getValue( 'basicAuthPassword' );

            if ( $auth_password ) {
                WsLog::l( 'Using basic auth credentials to crawl' );
                $opts['auth'] = [ $auth_user, $auth_password ];
            }
        }

        $this->client = new Client( $opts );
    }

    public static function wp2staticCrawl( string $static_site_path, string $crawler_slug ) : void {
        if ( 'wp2static' === $crawler_slug ) {
            $crawler = new Crawler();
            $crawler->crawlSite( $static_site_path );
        }
    }

    /**
     * Crawls URLs in WordPressSite, saving them to StaticSite
     */
    public function crawlSite(string $static_site_path): void {
        WsLog::l('Starting to crawl detected URLs.');

        $site_host = parse_url($this->site_path, PHP_URL_HOST);
        $site_port = parse_url($this->site_path, PHP_URL_PORT);
        $site_host = $site_port ? $site_host . ":$site_port" : $site_host;
        $site_urls = ["http://$site_host", "https://$site_host"];

        $use_crawl_cache = CoreOptions::getValue('useCrawlCaching');

        WsLog::l(($use_crawl_cache ? 'Using' : 'Not using') . ' CrawlCache.');

        $crawlable_paths = CrawlQueue::getCrawlablePaths();
        $urls = [];

        foreach ($crawlable_paths as $root_relative_path) {
            $absolute_uri = new URL($this->site_path . $root_relative_path);
            $urls[] = [
                'url' => $absolute_uri->get(),
                'path' => $root_relative_path,
            ];
        }

        $requests = function ($urls) {
            foreach ($urls as $url) {
                yield new Request('GET', $url['url']);
            }
        };

        $concurrency = intval(CoreOptions::getValue('crawlConcurrency'));

        $pool = new Pool(
            $this->client,
            $requests($urls),
            [
                'concurrency' => $concurrency,
                'fulfilled' => function (Response $response, $index) use ($urls, $use_crawl_cache, $site_urls) {
                    $root_relative_path = $urls[$index]['path'];
                    $crawled_contents = (string) $response->getBody();
                    $status_code = $response->getStatusCode();

                    $is_cacheable = true;
                    if ($status_code === 404) {
                        // Replace backslashes with forward slashes in the URL
                        $fixed_url = str_replace('\\', '/', $urls[$index]['url']);
                        
                        WsLog::l("Retrying with fixed URL: $fixed_url");

                        // Retry the request with the fixed URL
                        $retry_response = $this->client->get($fixed_url);

                        // Use the retry response
                        $crawled_contents = (string) $retry_response->getBody();
                        $status_code = $retry_response->getStatusCode();

                        // You can log or handle the retry here as needed

                        // Continue processing with the original response
                        // ...

                    } elseif (in_array($status_code, WP2STATIC_REDIRECT_CODES)) {
                        $crawled_contents = null;
                    }

                    $redirect_to = null;

                    if (in_array($status_code, WP2STATIC_REDIRECT_CODES)) {
                        $effective_url = $urls[$index]['url'];

                        $redirect_history = $response->getHeaderLine('X-Guzzle-Redirect-History');

                        if ($redirect_history) {
                            $redirects = explode(', ', $redirect_history);
                            $effective_url = end($redirects);
                        }

                        $redirect_to = (string) str_replace($site_urls, '', $effective_url);
                        $page_hash = md5($status_code . $redirect_to);
                    } elseif (!is_null($crawled_contents)) {
                        $page_hash = md5($crawled_contents);
                    } else {
                        $page_hash = md5((string) $status_code);
                    }

                    $write_contents = true;

                    if ($use_crawl_cache) {
                        if (CrawlCache::getUrl($root_relative_path, $page_hash)) {
                            $this->cache_hits++;
                            $write_contents = false;
                        }
                    }

                    $this->crawled++;

                    if ($crawled_contents && $write_contents) {
                        $static_path = self::transformPath($root_relative_path);
                        StaticSite::add($static_path, $crawled_contents);
                    }

                    if ($is_cacheable) {
                        CrawlCache::addUrl(
                            $root_relative_path,
                            $page_hash,
                            $status_code,
                            $redirect_to
                        );
                    }

                    if ($this->crawled % 300 === 0) {
                        $notice = "Crawling progress: $this->crawled crawled," .
                            " $this->cache_hits skipped (cached).";
                        WsLog::l($notice);
                    }
                },
                'rejected' => function (RequestException $reason, $index) use ($urls) {
                    $root_relative_path = $urls[$index]['path'];
                    WsLog::l('Failed ' . $root_relative_path);
                },
            ]
        );

        $promise = $pool->promise();

        $promise->wait();

        WsLog::l(
            "Crawling complete. $this->crawled crawled, $this->cache_hits skipped (cached)."
        );

        $args = [
            'staticSitePath' => $static_site_path,
            'crawled' => $this->crawled,
            'cache_hits' => $this->cache_hits,
        ];

        do_action('wp2static_crawling_complete', $args);
    }

    /**
     * Transform a root-relative path to a static site path.
     *
     * This lets us encapsulate the logic for path transformation in a single
     * place and use it in multiple places.
     *
     * TODO Should this actually be in `StaticSite`?
     */
    public static function transformPath(string $root_relative_path ) : string {
        // do some magic here - naive: if URL ends in /, save to /index.html
        // TODO: will need love for example, XML files
        // check content type, serve .xml/rss, etc instead
        if ( mb_substr( $root_relative_path, -1 ) === '/' ) {
            return $root_relative_path . 'index.html';
        }
        return $root_relative_path;
    }

    /**
     * @deprecated
     *
     * Crawls a string of full URL within WordPressSite
     *
     * @return ResponseInterface|null response object
     */
    public function crawlURL( string $url ) : ?ResponseInterface {
        WsLog::w( 'WP2Static Crawler::crawlURL is deprecated.' );

        $headers = [];
        $response = null;

        $auth_user = CoreOptions::getValue( 'basicAuthUser' );

        if ( $auth_user ) {
            $auth_password = CoreOptions::getValue( 'basicAuthPassword' );

            if ( $auth_password ) {
                $headers['auth'] = [ $auth_user, $auth_password ];
            }
        }

        $request = new Request( 'GET', $url, $headers );

        try {
            $response = $this->client->send( $request );
        } catch ( TooManyRedirectsException $e ) {
            WsLog::l( "Too many redirects from $url" );
        }

        return $response;
    }
}
