<?php

declare(strict_types=1);

/*
 * Verifies the Microsoft Graph side end-to-end without involving MCP or Claude:
 *   1. Obtain an app-only token
 *   2. Read the Agent Knowledge root folder
 *   3. Build the folder index (recursive)
 *   4. Optionally run a search and read one document
 *
 * Usage:
 *   php bin/check-graph.php
 *   php bin/check-graph.php "brand guidelines"
 *
 * Prints no secrets or tokens.
 */

use Adx\MarketingMcp\Kernel;

require dirname(__DIR__) . '/vendor/autoload.php';

$step = static function (string $label, callable $fn) {
    echo str_pad($label . ' ', 48, '.') . ' ';
    $t = microtime(true);
    try {
        $result = $fn();
        printf("OK (%d ms)\n", (int) ((microtime(true) - $t) * 1000));

        return $result;
    } catch (Throwable $e) {
        echo "FAILED\n  " . get_class($e) . ': ' . $e->getMessage() . "\n";
        exit(1);
    }
};

$kernel = $step('Load configuration', static fn () => Kernel::boot(dirname(__DIR__)));
$repo = $kernel->repository();

$root = $step('Authenticate + read Agent Knowledge root', static fn () => $repo->root());
echo "  Root folder: {$root->name}\n";

$children = $step('List top-level contents', static fn () => $repo->listFolder());
foreach (array_slice($children, 0, 15) as $c) {
    echo '  ' . ($c->isFolder ? '[folder] ' : '[file]   ') . $c->name . "\n";
}

$query = $argv[1] ?? null;
if (null !== $query) {
    $hits = $step("Search \"{$query}\"", static fn () => $repo->search($query, 5));
    foreach ($hits as $h) {
        echo "  {$h['item']->path}  (" . implode(', ', $h['matchedOn']) . ")\n";
    }

    if ([] !== $hits) {
        $result = $step('Read first result via get_document', static fn () => $kernel->tools()->getDocument($hits[0]['item']->id));
        $text = $result->content[0]->text ?? '';
        echo '  ' . str_replace("\n", "\n  ", mb_substr($text, 0, 600)) . "\n  ...\n";
    }
}

echo "\nGraph connectivity looks good.\n";
