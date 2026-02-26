<?php

use Langsys\OpenApiDocsGenerator\Console\Commands\DtoMakeCommand;

test('dto make command requires --model option', function () {
    $this
        ->artisan(DtoMakeCommand::class)
        ->assertFailed();
});

test('dto make command fails for non-existent model', function () {
    $this
        ->artisan(DtoMakeCommand::class, ['--model' => 'App\Models\NonExistent'])
        ->assertFailed();
});
