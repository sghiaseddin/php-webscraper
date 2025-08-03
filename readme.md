# PHP WebScraper

This is a lightweight, configurable web scraper built with PHP and powered by [Symfony DomCrawler](https://symfony.com/doc/current/components/dom_crawler.html) and [CSSSelector](https://symfony.com/doc/current/components/css_selector.html). It can crawl multiple sitemaps, extract content based on CSS selectors, and store the results as plain text files.

Version: 1.0.1
Tag: Database Documentation

## Features

- **Sitemap-based crawling**: Supports multiple sitemap sources per domain, including standard XML sitemaps and HTML-rendered ones.
- **Change detection**: Only re-crawls and re-scrapes URLs when their `lastmod`/`modified_date` changes compared to stored database records.
- **Per-sitemap selectors**: Multiple CSS (jQuery-style) selectors per sitemap to extract the main content blocks.
- **Exclusions**: Configurable exclusion selectors to strip noise before extraction.
- **Structured table handling**: Optional transformation of `<table>` content into LLM-friendly text. The first row is treated as header; each following row is output as `Header: Value, ...` lines.
- **Clean text extraction**:
  - Inline tags (`<b>`, `<i>`, `<strong>`, `<em>`, etc.) preserved without introducing unnecessary line breaks.
  - Block-level elements introduce controlled line breaks; consecutive breaks are collapsed.
  - Inline styles/scripts are removed from extraction.
- **UTF-8 safe**: Handles international content properly and outputs in UTF-8.
- **Aggregation and chunking**:
  - Aggregates all individual scraped `.txt` files into one master file.
  - Prepends a configurable copyright.
  - Splits the aggregated output into size-limited chunks (in bytes) to avoid overly large files (e.g., for downstream ingestion limits).
- **Robust DB-backed queue**:
  - URL table with `url`, `date_modified`, `crawl_error`, `scraped_error`, and `status` (`enabled`, `disabled`, `auto_disabled`).
  - Supports incrementing error counters and upserting updated URLs.
- **Configurable crawler parameters**: Timeout, interval between requests, and custom User-Agent string.
- **Modular OOP design**: Clear separation between components — fetching, DB, scraping, orchestration.
- **Command-line runnable**: Designed to be driven by `run.php` and suitable for cron scheduling.

## Requirements

- PHP 8.2+
- Composer
- DOM and MBString PHP extensions

## Code Base Structure

```
webscraper/
├── config/
│   ├── config.json          # Your environment-specific configuration (git ignored)
│   └── config-sample.json   # Template for configurations
├── src/
│   ├── PageScraper.php      # Scrapper logic by symfony libraries
│   ├── ScraperApp.php       # Main logic
│   ├── SitemapFetcher.php   # Fetch urls out of each sitemap
│   └── UrlRepository.php    # Database operations to store and get urls
├── run.php                  # CLI entry point
├── composer.json
├── composer.lock
└── vendor/                  # Composer-managed libraries
```

## Components

- UrlRepository: Handles DB interactions, including upserting sitemap entries, incrementing crawl/scrape error counters, and retrieving all tracked URLs.
- SitemapFetcher: Fetches and parses sitemap URLs (XML or HTML-rendered), normalizes lastmod into Y-m-d H:i:s, and attaches source sitemap URL to each entry.
- PageScraper: Extracts cleaned text from HTML fragments given inclusion selectors, honors exclusions, and optionally processes <table> elements into flattened, contextual key-value lines.
- ScraperApp: Orchestrator that:
1. Loads sitemap entries.
2. Compares them with DB state.
3. Queues new/updated URLs.
4. Crawls and scrapes each item.
5. Stores output and updates DB.
6. Aggregates individual text files into chunked master output.

## Installation

```bash
git clone https://github.com/sghiaseddin/php-webscraper.git
cd webscraper
composer install
cp config/config-sample.json config/config.json
```

You must create a MySQL database and user, then supply those credentials in config/config.json. Example using the MySQL CLI:

```sql
-- Log into MySQL as admin, then run:
CREATE DATABASE webscraper CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'scraper_user'@'localhost' IDENTIFIED BY 'strong_password';
GRANT ALL PRIVILEGES ON webscraper.* TO 'scraper_user'@'localhost';
FLUSH PRIVILEGES;
```

Then, edit config/config.json with your actual database details, domains, selectors, and storage paths.

## Usage

From the project root, run the following or set a cron job to the same:
```bash
php run.php
```

The scraper will:

1. Read config from config/config.json
2. Crawl sitemaps
3. Extract and store text content
4. Log results in log/webscraper.log

## Output

- Individual scraped .txt files named by sanitized URL, placed under storage_path.
- Aggregated file(s): full aggregation plus split chunk files (aggregated_part_1.txt, etc.) respecting size limits.

# Error Handling

- crawl_error and scraped_error counters are incremented per URL when respective steps fail.
- Status field can be used to disable problematic URLs (disabled/auto_disabled).


## Dependencies

Installed via Composer:
- symfony/dom-crawler
- symfony/css-selector
- masterminds/html5
- symfony/polyfill-*

You can review full versions and hashes in composer.lock.
