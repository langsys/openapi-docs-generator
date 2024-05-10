<?php

test('it executes the command successfully', function () {
    $this
        ->artisan(\Langsys\SwaggerAutoGenerator\Console\Commands\GenerateDataSwagger::class)
        ->assertSuccessful();
});
