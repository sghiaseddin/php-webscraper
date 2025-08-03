<?php

namespace WebScraper;

use Symfony\Component\DomCrawler\Crawler;

/**
 * PageScraper
 *
 * Extracts readable text content from raw HTML using CSS selectors (jQuery-style).
 * Supports multiple selectors and concatenates their text with double line breaks.
 */
class PageScraper
{
    /**
     * Parses HTML and extracts text using given selectors.
     *
     * @param string $html Full HTML content of a page.
     * @param array  $selectors List of jQuery-style CSS selectors.
     * @param array  $exclusions List of jQuery-style CSS selectors to exclude content.
     * @param bool   $table_operation Switch to call getFormattedTableText() on <table> markups.
     * @return string|null Combined and cleaned text content, or null if none found.
     */
    public function scrape(string $html, array $selectors, array $exclusions = [], bool $tableOperation = true): ?string
    {
        if (empty($selectors)) {
            return null;
        }

        $crawler = new Crawler($html);
        $textChunks = [];

        foreach ($selectors as $selector) {
            try {
                $crawler->filter($selector)->each(function (Crawler $node) use (&$textChunks, $exclusions, $tableOperation) {
                    $node->filter('style')->each(function (Crawler $styleNode) {
                        foreach ($styleNode as $domElement) {
                            $domElement->parentNode->removeChild($domElement);
                        }
                    });
                    $node->filter('script')->each(function (Crawler $scriptNode) {
                        foreach ($scriptNode as $domElement) {
                            $domElement->parentNode->removeChild($domElement);
                        }
                    });

                    // apply configured exclusions
                    foreach ($exclusions as $excludeSelector) {
                        try {
                            $node->filter($excludeSelector)->each(function (Crawler $excluded) {
                                $domElement = $excluded->getNode(0);
                                if ($domElement && $domElement->parentNode) {
                                    $domElement->parentNode->removeChild($domElement);
                                }
                            });
                        } catch (\InvalidArgumentException $e) {
                            // invalid exclusion selector: skip
                        }
                    }

                    // Process tables
                    $tableTexts = [];
                    if ($tableOperation) {
                        $node->filter('table')->each(function (Crawler $tableCrawler) use (&$tableTexts, $node, $exclusions) {
                            $domElement = $tableCrawler->getNode(0);
                            if (!($domElement instanceof \DOMElement)) return;
                    
                            // Skip if parent or self matches any exclusion selector
                            foreach ($exclusions as $excludeSelector) {
                                try {
                                    if ($node->filter($excludeSelector)->reduce(function (Crawler $c) use ($domElement) {
                                        return $c->getNode(0)->isSameNode($domElement) || $c->getNode(0)->contains($domElement);
                                    })->count() > 0) {
                                        return; // excluded
                                    }
                                } catch (\InvalidArgumentException $e) {
                                    continue;
                                }
                            }
                    
                            $formatted = self::getFormattedTableText($domElement);
                            if ($formatted !== null) {
                                $tableTexts[] = trim($formatted);
                            }
                            $domElement->parentNode?->removeChild($domElement);
                        });
                    }
                    
                    $htmlContent = mb_convert_encoding($node->html(), 'HTML-ENTITIES', 'UTF-8');
                    $dom = new \DOMDocument();
                    @$dom->loadHTML('<div>' . $htmlContent . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $root = $dom->documentElement;

                    $text = '';
                    $inlineTags = ['i', 'b', 'strong', 'em', 'a', 'span'];
                    foreach ($root->childNodes as $child) {
                        $text .= PageScraper::getTextWithBreaks($child, $inlineTags);
                    }

                    $cleaned = preg_replace('/\n{2,}/', "\n", trim($text));
                    if (!empty($cleaned)) {
                        $textChunks[] = $cleaned;
                    }

                    // Append table text 
                    foreach ($tableTexts as $tbl) {
                        $textChunks[] = $tbl;
                    }
                });
            } catch (\InvalidArgumentException $e) {
                // Skip invalid selector or empty result
                continue;
            }
        }

        if (empty($textChunks)) {
            return null;
        }

        return implode("\n\n", $textChunks); // two line breaks for LLM-friendliness
    }

    /**
     * Recursively extracts text content with line breaks for block-level elements.
     */
    private static function getTextWithBreaks(\DOMNode $node, array $inlineTags): string
    {
        $text = '';
        if ($node instanceof \DOMText) {
            $text .= rtrim($node->nodeValue);
        } elseif ($node instanceof \DOMElement) {
            $innerText = '';
            foreach ($node->childNodes as $child) {
                $innerText .= self::getTextWithBreaks($child, $inlineTags);
            }
            $tag = strtolower($node->tagName);
            if (!in_array($tag, $inlineTags)) {
                $text .= rtrim($innerText) . "\n";
            } else {
                $text .= $innerText;
            }
        }
        return $text;
    }

    /**
     * Converts an HTML <table> element into an LLM-friendly plain-text representation.
     *
     * Rules:
     *  - Treat the first <tr> as the header row (even if there's no <thead>).
     *  - For each subsequent row, pair header cells with their corresponding cell value as "Header: Value".
     *  - Join pairs with ", " and put each row on its own line.
     *
     * @param \DOMElement $table The <table> element.
     * @return string|null The formatted representation or null if cannot parse.
     */
    public static function getFormattedTableText(\DOMElement $table): ?string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof \DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'])) {
                    $cells[] = trim($cell->textContent);
                }
            }
            if (!empty($cells)) {
                $rows[] = $cells;
            }
        }

        if (count($rows) < 2) {
            return null; // no data rows
        }

        $header = $rows[0];
        $lines = [];
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            $pairs = [];
            for ($j = 0; $j < count($header); $j++) {
                $key = $header[$j];
                $value = $row[$j] ?? '';
                $pairs[] = "{$key}: {$value}";
            }
            $lines[] = implode(', ', $pairs);
        }

        return implode("\n", $lines);
    }
}