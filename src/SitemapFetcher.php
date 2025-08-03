<?php

/**
 * SitemapFetcher
 *
 * Fetches and parses sitemap pages (HTML format) to extract URLs and their
 * last modified dates. Designed to work with HTML-rendered sitemaps from plugins
 * like Rank Math.
 */
class SitemapFetcher
{
    private array $sitemaps;

    public function __construct(array $sitemaps)
    {
        $this->sitemaps = $sitemaps; // list of sitemap URLs
    }

    /**
     * Fetches and parses all sitemaps.
     *
     * @return array List of ['url' => string, 'date_modified' => string|null]
     */
    public function fetch(): array
    {
        $results = [];

        foreach ($this->sitemaps as $sitemapUrl) {
            $xmlContent = @file_get_contents($sitemapUrl);

            if (!$xmlContent) {
                continue; // skip unreachable sitemap
            }

            $xml = @simplexml_load_string($xmlContent);
            if ($xml === false) {
                continue; // skip invalid XML
            }

            foreach ($xml->url as $urlNode) {
                $loc = (string) $urlNode->loc ?? '';
                $lastmodRaw = (string) $urlNode->lastmod ?? '';

                if ($loc) {
                    $date = self::normalizeDate($lastmodRaw);
                    $results[] = [
                        'url' => $loc,
                        'date_modified' => $date,
                        'sitemap_url' => $sitemapUrl,
                    ];
                }
            }
        }

        return $results;
    }

    /**
     * Normalize date to `Y-m-d H:i:s` using ISO 8601 (ATOM) format, or return null if invalid.
     */
    private static function normalizeDate(string $raw): ?string
    {
        $raw = trim($raw);
        if (!$raw || $raw == '') return date("Y-m-d H:i:s");
        $dt = \DateTime::createFromFormat(\DateTime::ATOM, $raw);
        return $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    }
}