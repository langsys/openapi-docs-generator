<?php

use Langsys\SwaggerAutoGenerator\Console\Commands\GenerateDataSwagger;
use Langsys\SwaggerAutoGenerator\Generators\Swagger\SwaggerSchemaGenerator;

test('SwaggerSchemaGenerator generates annotations for test Data objects', function () {
    // Determine the package root directory
    $packageRoot = dirname(__DIR__, 2); // Go up two levels from the test file

    $testDataPath = $packageRoot . '/tests/Data';
    $testOutputPath = $packageRoot . '/tests/Output/Schemas.php';
    $namespace = 'Langsys\SwaggerAutoGenerator\Tests\Output';

    // Ensure the output directory exists
    if (!is_dir(dirname($testOutputPath))) {
        mkdir(dirname($testOutputPath), 0777, true);
    }

    $generator = new SwaggerSchemaGenerator($testDataPath, $testOutputPath, $namespace);
    $generator->swaggerAnnotationsFromDataObjects();
});