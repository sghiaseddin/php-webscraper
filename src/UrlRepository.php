<?php

/**
 * UrlRepository
 *
 * Handles all database operations for managing URLs extracted from sitemaps,
 * including creation, updating, and error tracking.
 */
class UrlRepository
{
    /**
     * UrlRepository constructor.
     *
     * @param array $dbConfig Database configuration with host, user, pass, name, charset.
     */
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $dsn = "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset={$dbConfig['charset']}";
        $this->pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Creates the 'url' table in the database if it does not already exist.
     */
    public function createTable(): void
    {
        $sql = <<<SQL
        CREATE TABLE IF NOT EXISTS `url` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            `url` TEXT NOT NULL UNIQUE,
            `date_modified` DATETIME NOT NULL,
            `crawl_error` INT DEFAULT 0,
            `scraped_error` INT DEFAULT 0,
            `status` ENUM('enabled', 'disabled', 'auto_disabled') DEFAULT 'enabled'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        SQL;

        $this->pdo->exec($sql);
    }

    /**
     * Retrieves all URLs from the 'url' table.
     *
     * @return array List of all URL records.
     */
    public function getAllUrls(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM url");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Inserts or updates a batch of URLs with their last modified date.
     * Resets crawl_error and scraped_error to 0 on new insert.
     *
     * @param array $urlData Array of ['url' => string, 'date_modified' => string] records.
     */
    public function update(array $urlData): void
    {
        $sql = <<<SQL
        INSERT INTO url (url, date_modified, crawl_error, scraped_error, status)
        VALUES (:url, :date_modified, 0, 0, 'enabled')
        ON DUPLICATE KEY UPDATE date_modified = VALUES(date_modified)
        SQL;

        $stmt = $this->pdo->prepare($sql);

        foreach ($urlData as $entry) {
            $date_modified = $entry['date_modified'];
            if ($date_modified === null) {
                $date_modified = date('Y-m-d H:i:s');
            }
            $stmt->execute([
                ':url' => $entry['url'],
                ':date_modified' => $date_modified,
            ]);
        }
    }

    /**
     * Increments the crawl_error counter for a given URL.
     *
     * @param string $url The URL that had a crawl error.
     */
    public function addCrawlError(string $url): void
    {
        $sql = "UPDATE url SET crawl_error = crawl_error + 1 WHERE url = :url";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':url' => $url]);
    }

    /**
     * Increments the scraped_error counter for a given URL.
     *
     * @param string $url The URL that had a scrape error.
     */
    public function addScrapError(string $url): void
    {
        $sql = "UPDATE url SET scraped_error = scraped_error + 1 WHERE url = :url";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':url' => $url]);
    }
}