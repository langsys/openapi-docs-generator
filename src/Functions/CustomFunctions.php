<?php

namespace Langsys\SwaggerAutoGenerator\Functions;

use App\Models\Locale;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CustomFunctions
{

    public function id(string $type): string|int
    {
        return $type === 'int' ? random_int(1, 1000) : Str::uuid();
    }

    public function locale(): string
    {
        $locales = Locale::all();

        return $locales->get(random_int(1, $locales->count()))->code;
    }

    public function date(string $type): string|int
    {
        $date = Carbon::now()->format('Y-m-d H:i:s');
        return $type === 'int' ? strtotime($date) : $date;
    }
}
