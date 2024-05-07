<?php

namespace Langsys\SwaggerAutoGenerator\Generators\Swagger\Traits;

use App\Models\Locale;

trait HasUserFunctions {

    public function id(string $type): string|int
    {
        return $type === 'int' ? random_int(1, 1000) : $this->faker->uuid();
    }

    public function locale(): string
    {
        $locales = Locale::all();

        return $locales->get(random_int(1, $locales->count()))->code;
    }

    public function date(string $type): string|int
    {
        $date = $this->faker->dateTimeThisYear()->format('Ymd H:i:s');
        return $type === 'int' ? strtotime($date) : $date;
    }

}
