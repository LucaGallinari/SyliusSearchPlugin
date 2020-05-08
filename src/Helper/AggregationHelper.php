<?php

declare(strict_types=1);

namespace MonsieurBiz\SyliusSearchPlugin\Helper;

class AggregationHelper
{
    const MAX_AGGREGATED_ATTRIBUTES_INFO = 100;
    const MAX_AGGREGATED_TAXON_INFO = 500;

    /**
     * Build sort array to add in query
     *
     * @param string $field
     * @return array
     */
    public static function buildAggregation(string $field): array
    {
        return [
            'filter' => [
                'bool' => [
                    'must' => [
                        [
                            'term' => ['attributes.code' => $field]
                        ]
                    ]
                ]
            ],
            'aggs' => [
                'values' => [
                    'terms' => ['field' => 'attributes.value.keyword']
                ]
            ]
        ];
    }

    /**
     * Build sort array to add in query
     *
     * @param array $filters
     * @return array
     */
    public static function buildAggregations(array $filters): array
    {
        if (empty($filters)) {
            return [];
        }

        $aggregations = [];
        foreach ($filters as $field) {
            $aggregations[$field] = self::buildAggregation($field);
        }

        return [
            'filters' => [
                'nested' => ['path' => 'attributes'],
                'aggs' => $aggregations
            ],
            // Get attributes info to be able to retrieve the attribute name from code
            'attributes' => [
                'nested' => ['path' => 'attributes'],
                'aggs' => [
                    'codes' => [
                        'terms' => ['field' => 'attributes.code', 'size' => self::MAX_AGGREGATED_ATTRIBUTES_INFO] // Retrieve all attributes info
                        ,
                        'aggs' => [
                            'names' => [
                                'terms' => ['field' => 'attributes.name.keyword']
                            ],
                        ]
                    ]
                ]
            ],
            // Get taxon info to be able to retrieve the attribute name from code
            'taxons' => [
                'nested' => ['path' => 'taxon'],
                'aggs' => [
                    'codes' => [
                        'terms' => ['field' => 'taxon.code', 'size' => self::MAX_AGGREGATED_TAXON_INFO], // Retrieve all attributes info
                        'aggs' => [
                            'levels' => [
                                'terms' => ['field' => 'taxon.level'],
                                'aggs' => [
                                    'names' => [
                                        'terms' => ['field' => 'taxon.name']
                                    ],
                                ]
                            ],
                        ]
                    ]
                ]
            ],
        ];
    }
}
