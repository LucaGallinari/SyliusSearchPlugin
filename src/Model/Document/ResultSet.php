<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSearchPlugin\Model\Document;

use JoliCode\Elastically\ResultSet as ElasticallyResultSet;
use JoliCode\Elastically\Result;
use MonsieurBiz\SyliusSearchPlugin\Adapter\ResultSetAdapter;
use MonsieurBiz\SyliusSearchPlugin\generated\Model\Taxon;
use Pagerfanta\Pagerfanta;
use Sylius\Component\Core\Model\TaxonInterface;

class ResultSet
{
    /** @var Result[] */
    private $results = [];

    /** @var int */
    private $totalHits;

    /** @var int */
    private $maxItems;

    /** @var int */
    private $page;

    /** @var Filter[] */
    private $filters = [];

    /** @var Pagerfanta */
    private $pager;

    /**
     * SearchResults constructor.
     * @param int $maxItems
     * @param int $page
     * @param ElasticallyResultSet|null $resultSet
     * @param TaxonInterface|null $taxon
     */
    public function __construct(int $maxItems, int $page, ?ElasticallyResultSet $resultSet = null, ?TaxonInterface $taxon = null)
    {
        $this->maxItems = $maxItems;
        $this->page = $page;

        // Empty result set
        if ($resultSet === null) {
            $this->totalHits = 0;
            $this->results = [];
            $this->filters = [];
        } else {
            /** @var Result $result */
            foreach ($resultSet as $result) {
                $this->results[] = $result->getModel();
            }
            $this->totalHits = $resultSet->getTotalHits();
            $this->initFilters($resultSet, $taxon);
        }

        $this->initPager();
    }

    /**
     * Init pager with Pager Fanta
     */
    private function initPager()
    {
        $adapter = new ResultSetAdapter($this);
        $this->pager = new Pagerfanta($adapter);
        $this->pager->setMaxPerPage($this->maxItems);
        $this->pager->setCurrentPage($this->page);
    }

    /**
     * Init filters array depending on result aggregations
     *
     * @param ElasticallyResultSet $resultSet
     * @param TaxonInterface|null $taxon
     */
    private function initFilters(ElasticallyResultSet $resultSet, ?TaxonInterface $taxon = null)
    {
        $aggregations = $resultSet->getAggregations();

        // Retrieve filters labels in aggregations
        $attributes = [];
        $attributeAggregations = $aggregations['attributes'];
        unset($attributeAggregations['doc_count']);
        $attributeCodeBuckets = $attributeAggregations['codes']['buckets'] ?? [];
        foreach ($attributeCodeBuckets as $attributeCodeBucket) {
            $attributeCode = $attributeCodeBucket['key'];
            $attributeNameBuckets = $attributeCodeBucket['names']['buckets'] ?? [];
            foreach ($attributeNameBuckets as $attributeNameBucket) {
                $attributeName = $attributeNameBucket['key'];
                $attributes[$attributeCode] = $attributeName;
                break;
            }
        }

        // Retrieve filters values in aggregations
        $filterAggregations = $aggregations['filters'];
        unset($filterAggregations['doc_count']);
        foreach ($filterAggregations as $field => $aggregation) {
            if ($aggregation['doc_count'] === 0) {
                continue;
            }
            $filter = new Filter($attributes[$field] ?? $field, $aggregation['doc_count']);
            $buckets = $aggregation['values']['buckets'] ?? [];
            foreach ($buckets as $bucket) {
                if (isset($bucket['key']) && isset($bucket['doc_count'])) {
                    $filter->addValue($bucket['key'], $bucket['doc_count']);
                }
            }
            $this->filters[] = $filter;
        }
        $this->sortFilters();

        $this->addTaxonFilter($aggregations, $taxon);
    }

    /**
     * @return Result[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * @return Filter[]
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * @return int
     */
    public function getTotalHits(): int
    {
        return $this->totalHits;
    }

    /**
     * @return Pagerfanta
     */
    public function getPager(): Pagerfanta
    {
        return $this->pager;
    }

    /**
     * Sort filters
     */
    protected function sortFilters()
    {
        usort($this->filters, function($filter1, $filter2) {
            /** @var Filter $filter1 */
            /** @var Filter $filter2 */

            // If same count we display the filters with more values before
            if ($filter1->getCount() === $filter2->getCount()) {
                return count($filter2->getValues()) > count($filter1->getValues());
            }

            return $filter2->getCount() > $filter1->getCount();
        } );
    }

    /**
     * Add taxon filter depending on aggregations
     *
     * @param array $aggregations
     * @param TaxonInterface|null $taxon
     */
    protected function addTaxonFilter(array $aggregations, ?TaxonInterface $taxon)
    {
        $taxonAggregation = $aggregations['taxons'] ?? null;
        if ($taxonAggregation && $taxonAggregation['doc_count'] > 0) {

            // Get current taxon level to retrieve only greater levels, in search we will take only the first level
            $currentTaxonLevel = $taxon ? $taxon->getLevel() : 0;

            // Get children taxon if we have current taxon
            $childrenTaxon = [];
            if ($taxon) {
                foreach ($taxon->getChildren() as $child) {
                    $childrenTaxon[$child->getCode()] = $child->getLevel();
                }
            }

            $filter = new Filter('monsieurbiz_searchplugin.filters.taxon_filter', $taxonAggregation['doc_count']);

            // Get taxon code in aggregation
            $taxonCodeBuckets = $taxonAggregation['codes']['buckets'] ?? [];
            foreach ($taxonCodeBuckets as $taxonCodeBucket) {
                if ($taxonCodeBucket['doc_count'] === 0) {
                    continue;
                }
                $taxonCode = $taxonCodeBucket['key'];
                $taxonName = null;

                // Get taxon level in aggregation
                $taxonLevelBuckets = $taxonCodeBucket['levels']['buckets'] ?? [];
                foreach ($taxonLevelBuckets as $taxonLevelBucket) {
                    $level = $taxonLevelBucket['key'];
                    if ($level === ($currentTaxonLevel + 1) && isset($childrenTaxon[$taxonCode])) {
                        dump($level);
                        // Get taxon name in aggregation
                        $taxonNameBuckets = $taxonLevelBucket['names']['buckets'] ?? [];
                        foreach ($taxonNameBuckets as $taxonNameBucket) {
                            $taxonName = $taxonNameBucket['key'];
                            $filter->addValue($taxonName ?? $taxonCode, $taxonCodeBucket['doc_count']);
                            break 2;
                        }
                    }
                }
            }

            // Put taxon filter in first if contains value
            if (count($filter->getValues())) {
                array_unshift($this->filters , $filter);
            }
        }
    }
}
