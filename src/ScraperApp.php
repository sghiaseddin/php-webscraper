<?php
use WebScraper\PageScraper;

require_once __DIR__ . '/UrlRepository.php';
require_once __DIR__ . '/SitemapFetcher.php';
require_once __DIR__ . '/PageScraper.php';

/**
 * ScraperApp
 *
 * Main orchestrator for the scraping workflow. Loads sitemap entries, compares them with
 * the database, and queues updated or new entries for crawling and scraping.
 */
class ScraperApp
{
    private array $config;
    private UrlRepository $urlRepo;
    private PageScraper $pageScraper;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->urlRepo = new UrlRepository($config['db']);
        $this->urlRepo->createTable();
        $this->pageScraper = new PageScraper();
    }

    /**
     * Executes the main scraping workflow:
     *
     * 1. Loads sitemap URLs and extracts URL/date_modified entries.
     * 2. Compares sitemap entries with the database records.
     * 3. Queues URLs that are new or updated for crawling and scraping.
     * 4. Updates the database with new entries and modifications.
     * 5. Triggers crawl and scrape actions on the queued URLs.
     */
    public function run(): void
    {
        $queue = [];

        foreach ($this->config['sources'] as $source) {
            $sitemapUrls = array_keys($source['sitemaps']);
            $sitemapFetcher = new SitemapFetcher($sitemapUrls);
            $sitemapEntries = $sitemapFetcher->fetch(); // from sitemap

            $dbRecords = $this->urlRepo->getAllUrls(); // from database
            $dbMap = [];
            foreach ($dbRecords as $record) {
                $dbMap[$record['url']] = $record['date_modified'];
            }

            foreach ($sitemapEntries as $entry) {
                $url = $entry['url'];
                $modified = $entry['date_modified'];

                // Compare with DB
                if (!isset($dbMap[$url]) || $modified > $dbMap[$url]) {
                    $queue[] = [
                        'url' => $url,
                        'date_modified' => $modified,
                        'selectors' => $this->getAttributesForUrl($entry['sitemap_url'], 'selector'),
                        'exclusion' => $this->getAttributesForUrl($entry['sitemap_url'], 'exclusion'),
                        'table_operation' => $this->getAttributesForUrl($entry['sitemap_url'], 'table_operation'),
                    ];
                }
            }
        }

        $this->urlRepo->update($queue);
        $this->crawlAndScrape($queue);
        $this->aggregateText();
    }

    /**
     * Extracts the matching selectors for a given URL from sitemap definitions.
     */
    private function getAttributesForUrl(string $sitemap_url, string $attribute)
    {
        foreach ($this->config['sources'] as $source) {
            if (isset($source['sitemaps'][$sitemap_url])) {
                return $source['sitemaps'][$sitemap_url][$attribute];
            }
        }
        return [];
    }

    /**
     * Iterates through the queue and calls the scraper for each URL.
     */
    private function crawlAndScrape(array $queue): void
    {
        foreach ($queue as $entry) {
            $url = $entry['url'];
            $selectors = $entry['selectors'];
            $exclusions = (is_array($entry['exclusion']) ? $entry['exclusion'] : []);
            $table_operation = ($entry['table_operation'] == 1 ? TRUE : FALSE);
            $is_crawled = FALSE;
            $is_scraped = FALSE;
            $is_stored = FALSE;

            $content = $this->crawlUrl($url, $selectors);

            if ($content !== null) { // If crawling was successful
                $text = $this->scrapContent($content, $selectors, $exclusions, $table_operation);
                if ($text !== null) { // If scraping was succuessful
                    $is_stored = $this->storeText($url, $text);
                    $is_scraped = TRUE;
                } else {
                    $this->urlRepo->addScrapError($url);
                }
                $is_crawled = TRUE;
            } else {
                $this->urlRepo->addCrawlError($url);
            }
            $this->log($url, implode(', ', $selectors), $entry['date_modified'], $is_crawled, $is_scraped, $is_stored);
        }
    }

    /**
     * Sends an HTTP GET request to the given URL using configured crawler settings.
     *
     * Applies user agent, timeout, and crawl interval (delay between requests). Returns the
     * raw HTML content if the response code is 200, otherwise returns null.
     *
     * @param string $url  The URL to crawl.
     * @return string|null The fetched HTML content or null if the request failed.
     */
    private function crawlUrl(string $url): ?string
    {
        $userAgent = $this->config['crawler']['user_agent'] ?? 'ScraperBot/1.0';
        $timeout = $this->config['crawler']['timeout'] ?? 10; // default 10 seconds
        $interval = $this->config['crawler']['interval'] ?? 1; // default 1 second

        $contextOptions = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: {$userAgent}\r\n",
                'timeout' => $timeout,
                'ignore_errors' => true,
            ]
        ];
        $context = stream_context_create($contextOptions);

        $content = @file_get_contents($url, false, $context);

        // Sleep for crawl interval
        usleep($interval * 1000 * 1000); // Original $interval is in seconds, need micro-seconds

        if ($content === false) {
            return null;
        }

        // Check HTTP response code
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $header, $matches)) { // Get status code
                    $statusCode = (int)$matches[1];
                    if ($statusCode === 200) {
                        return $content;
                    } else {
                        return null;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extracts and returns the main text content from the provided HTML using the given selectors.
     *
     * Uses the PageScraper to extract relevant text from the HTML content. If no usable text is found,
     * returns null.
     *
     * @param string $content   The raw HTML content to be scraped.
     * @param array  $selectors An array of CSS selectors or scraping rules to extract content.
     * @param array  $exclusions An array of CSS selectors or scraping rules to exclude content.
     * @param bool   $table_operation A switch to do specilized function on <table> markups.
     * @return string|null      The extracted text if found, or null if no usable text is found.
     */
    private function scrapContent(string $content, array $selectors, array $exclusions, bool $table_operation): ?string
    {
        $text = $this->pageScraper->scrape($content, $selectors, $exclusions, $table_operation);
        if (!empty($text) && $text !== null) {
            return $text;
        }
        return null;
    }

    /**
     * Stores the extracted text content into a .txt file in the configured storage directory.
     *
     * The file is named based on the sanitized URL. The original URL is also appended at the
     * end of the file for reference. Creates the storage directory if it doesn't exist.
     *
     * @param string $url   The original URL the content came from.
     * @param string $text  The extracted text content to be stored.
     * @return bool|null    True if the content was stored successfully, false otherwise.
     */
    private function storeText(string $url, string $text): ?bool
    {
        $storagePath = $this->config['storage_path'] ? $this->config['storage_path'] . '/text' : __DIR__ . '/storage/text';
        if (!is_dir($storagePath)) {
            if (!mkdir($storagePath, 0777, true) && !is_dir($storagePath)) {
                return false;
            }
        }
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $url);
        $filename = substr($safeName, 0, 200) . '.txt'; // Limit to 200 chars to stay safe
        $filepath = $storagePath. DIRECTORY_SEPARATOR . $filename;

        $result = file_put_contents($filepath, $text . PHP_EOL . PHP_EOL . 'Reference: ' . $url);
        return $result !== false;
    }

    /**
     * Outputs a summary of the crawl and scrape status for a specific URL.
     *
     * Shows which stages were completed (crawled, scraped, stored) and includes
     * the selectors and last modified date. Intended for debugging or monitoring.
     *
     * @param string $url           The URL being processed.
     * @param string $selectors     The CSS selectors used during scraping.
     * @param string $date_modified The last modified date from the sitemap.
     * @param bool   $is_crawled    Indicates if the page was successfully crawled.
     * @param bool   $is_scraped    Indicates if content was successfully scraped.
     * @param bool   $is_stored     Indicates if the scraped content was successfully stored.
     */
    private function log(string $url, string $selectors, string $date_modified, bool $is_crawled, bool $is_scraped, bool $is_stored): void
    {
        // Append the operation result in the log file
        $crawl_status = $is_crawled ? 'crawled' : 'not crowled';
        $scrap_status = $is_scraped ? 'scraped' : 'not scraped';
        $store_status = $is_stored ? 'stored' : 'not stored';
        $timestamp = date('Y-m-d H:i:s');
        $logDir = $this->config['storage_path'] ? $this->config['storage_path'] . '/log' : __DIR__ . '/storage/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'webscraper.log';
        $logEntry = "[{$timestamp}] {$url} | {$selectors} | {$date_modified} | {$crawl_status} | {$scrap_status} | {$store_status}" . PHP_EOL;
        file_put_contents($logFile, $logEntry, FILE_APPEND);
        echo $logEntry;
    }


    /**
     * Aggregates all individual scraped text files into a single file.
     * Also, make divided versions into chunk size
     *
     */
    private function aggregateText():void 
    {
        $storagePath = $this->config['storage_path'] ? $this->config['storage_path'] . '/text' : __DIR__ . '/storage/text';
        $aggregatedFile = $this->config['aggregated_file'] ?? ($storagePath . '/../aggregated.txt');
        $copyrightText = $this->config['copyright_text'] ?? '';

        // Get all .txt files in the storage/text directory
        $files = glob($storagePath . '/*.txt');
        $contents = [];
        foreach ($files as $file) {
            $contents[] = file_get_contents($file);
        }
        // Join with 3 line breaks
        $aggregatedText = $copyrightText . PHP_EOL . PHP_EOL . PHP_EOL;
        if (!empty($contents)) {
            $aggregatedText .= implode(PHP_EOL . PHP_EOL . PHP_EOL, $contents);
        }
        // Write to the aggregated file
        file_put_contents($aggregatedFile, $aggregatedText);

        // Chunk size: interpret config value as kilobytes, fallback to 1000 KB if missing
        $chunkSizeKb = $this->config['aggregated_chunk_size'] ?? 1000;
        $chunkSizeBytes = (int)$chunkSizeKb * 1024;

        $baseDir = dirname($aggregatedFile);
        $baseName = pathinfo($aggregatedFile, PATHINFO_FILENAME);

        $partIndex = 1;
        $currentChunk = $copyrightText . PHP_EOL . PHP_EOL . PHP_EOL;
        foreach ($contents as $piece) {
            // Candidate with this piece appended (with separator)
            $candidate = $currentChunk;
            if (trim($candidate) !== trim($copyrightText)) {
                // if not the very first, ensure separator
                $candidate .= PHP_EOL . PHP_EOL . PHP_EOL . $piece;
            } else {
                $candidate .= $piece;
            }

            if (mb_strlen($candidate, '8bit') > $chunkSizeBytes) {
                // write existing current chunk (without adding this piece)
                $chunkFilename = "{$baseDir}/{$baseName}_part_{$partIndex}.txt";
                file_put_contents($chunkFilename, rtrim($currentChunk) . PHP_EOL);
                $partIndex++;

                // start new chunk with header + this piece
                $currentChunk = $copyrightText . PHP_EOL . PHP_EOL . PHP_EOL . $piece;
            } else {
                // safe to absorb the piece
                $currentChunk = $candidate;
            }
        }

        // flush remaining chunk if non-empty (and not identical to just header)
        $trimmed = trim(str_replace($copyrightText, '', $currentChunk));
        if ($trimmed !== '') {
            $chunkFilename = "{$baseDir}/{$baseName}_part_{$partIndex}.txt";
            file_put_contents($chunkFilename, rtrim($currentChunk) . PHP_EOL);
        }        
    }
}
