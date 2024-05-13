<?php

namespace Langsys\SwaggerAutoGenerator\Functions;

use App\Models\Locale;

class CustomFunctions
{
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
