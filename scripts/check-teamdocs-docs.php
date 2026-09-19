<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$documents = [
    'cost-tracking-rules.md',
    'leantime-zh-guide.md',
    'leantime-zh-glossary.md',
];
$resourceRoot = $root.'/app/Plugins/TeamDocs/Resources/docs';

$failed = false;
foreach ($documents as $document) {
    $source = $root.'/docs/'.$document;
    $target = $resourceRoot.'/'.$document;

    if (! is_file($source) || ! is_file($target)) {
        fwrite(STDERR, "TeamDocs document missing: {$document}\n");
        $failed = true;
        continue;
    }

    $sourceHash = hash_file('sha256', $source);
    $targetHash = hash_file('sha256', $target);
    if ($sourceHash === false || $targetHash === false || ! hash_equals($sourceHash, $targetHash)) {
        fwrite(STDERR, "TeamDocs document out of sync: {$document}\n");
        $failed = true;
        continue;
    }

    echo "TeamDocs document in sync: {$document}\n";
}

exit($failed ? 1 : 0);
