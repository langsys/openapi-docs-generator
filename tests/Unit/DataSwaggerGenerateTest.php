<?php

use Langsys\SwaggerAutoGenerator\Console\Commands\GenerateDataSwagger;

test('it executes the command successfully', function () {
    $this
        ->artisan(GenerateDataSwagger::class)
        ->assertSuccessful();
});

test('It has custom functions and runs them', function () {
    //Asseert the config file has the custom functions
    $this
        ->assertArrayHasKey('custom_functions', config('langsys-generator'));
});
