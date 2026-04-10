<?php
declare(strict_types=1);

require_once __DIR__ . '/LoginTest.php';
require_once __DIR__ . '/RbacTest.php';
require_once __DIR__ . '/BitacoraTest.php';

$allResults = array_merge(
    runLoginTests(),
    runRbacTests(),
    runBitacoraTests()
);

$passed = 0;
$failed = 0;

foreach ($allResults as $result) {
    if ($result['ok']) {
        $passed++;
        echo '[OK] ' . $result['name'] . PHP_EOL;
        continue;
    }

    $failed++;
    echo '[FAIL] ' . $result['name'] . ' -> ' . $result['error'] . PHP_EOL;
}

echo PHP_EOL;
echo 'Total: ' . count($allResults) . ' | Exitosas: ' . $passed . ' | Fallidas: ' . $failed . PHP_EOL;

exit($failed > 0 ? 1 : 0);
