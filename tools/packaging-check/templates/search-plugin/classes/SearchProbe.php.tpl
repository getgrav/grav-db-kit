<?php

declare(strict_types=1);

namespace {{NS}};

use {{KIT}}\PackagingMarker;
use {{YETI}}\YetiSearch;

/**
 * Index one document and search for it through this plugin's YetiSearch copy,
 * and report which kit copy the plugin carries next to it.
 */
final class SearchProbe
{
    /** @return array<string, mixed> */
    public static function run(string $dbPath): array
    {
        // An unprefixed PSR-3 logger, the kind Grav core hands out: the
        // prefixed copy must still accept it, because psr/log is left shared.
        $search = new YetiSearch([
            'storage' => ['path' => $dbPath],
            'search' => ['enable_suggestions' => false, 'cache_ttl' => 0],
        ], new \Psr\Log\NullLogger());

        $indexer = $search->createIndex('docs');
        $indexer->insert([
            'id' => 'doc-1',
            'content' => [
                'title' => '{{SLUG}} packaging note',
                'content' => 'The {{WORD}} lives only in {{SLUG}}.',
            ],
        ]);
        $indexer->insert([
            'id' => 'doc-2',
            'content' => ['title' => 'Unrelated', 'content' => 'Nothing to see here.'],
        ]);
        $indexer->flush();

        $results = $search->search('docs', '{{WORD}}');

        return [
            'plugin' => '{{SLUG}}',
            'yeti_class' => YetiSearch::class,
            'yeti_file' => (new \ReflectionClass(YetiSearch::class))->getFileName(),
            'has_semantic_seam' => interface_exists('{{YETI}}\Contracts\EmbeddingProviderInterface'),
            'hits' => array_map(static fn (array $r): string => (string) $r['id'], $results['results'] ?? []),
            'total' => $results['total'] ?? null,
            'kit' => PackagingMarker::VERSION,
            'kit_file' => (new \ReflectionClass(PackagingMarker::class))->getFileName(),
        ];
    }
}
