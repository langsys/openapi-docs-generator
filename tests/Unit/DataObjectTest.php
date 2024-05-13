<?php

use Langsys\SwaggerAutoGenerator\Console\Commands\GenerateDataSwagger;

test('it executes the command successfully', function () {
    $this
        ->artisan(\Langsys\SwaggerAutoGenerator\Console\Commands\DataObjectMakeCommand::class, ['--model' => 'App\Models\User'])
        ->assertSuccessful();
});
