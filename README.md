# Laravel Langsys Swagger

THIS PACKAGE IS ON ALPHA, README IS FAR FROM COMPLETE. DO NOT USE IT YET.

This package is an extension for Laravel Data by Spatie and L5-Swagger by Darkaonline. It allows you to generate Swagger documentation and DTOs for your API based on the Laravel Data package.

## Installation

You can install the package via composer:

```bash
composer require langsys/data-swagger
```

## Usage

You can publish the configuration file with:

```bash
php artisan vendor:publish --provider="Langsys\SwaggerAutoGenerator\DataSwaggerServiceProvider" --tag="config"
```

You can customize the configuration file to fit your needs. The configuration file is located at `config/langsys-generator.php`.

```php
return [
    'paths' => [
        'data_objects' => app_path('DataObjects'),
        'swagger_docs' => app_path('Swagger/Schemas.php')
    ],
];
```

Modify the `paths` array to point to the directory where your DataObjects and Langsys Schemas should be generated.

You can generate the Langsys Schemas by running the following command:

```bash
php artisan data-swagger:generate
```

This will generate Swagger Schemas based on the configuration file.

To Generate the Data Objects, you can run the following command:

```bash
php artisan data-swagger:dto --model="App\Models\User"
```

This will generate a DataObject for the User model.

```php
<?php

declare(strict_types=1);

namespace App\DataObjects;

use Spatie\LaravelData\Data;

/** @typescript */
final class UserData extends Data
{
    public function __construct(
        public string $id,       
        public string $firstname,
        public string $lastname,
        public string $email,       
        public ?string $email_verified_at,
        public string $password,       
        public ?string $remember_token,        
        public ?string $created_at,
        public ?string $updated_at,      
    ) {
    }
}
```

## Extended Usage

If you have custom fields in your Schemas, that do not exist in Laravel Default Helpers or Faker Functions, you can add your own custom functions to the `config/langsys-generator.php` file.

```php
    'faker_attribute_mapper' => [
        'address_1' => 'streetAddress',
        'address_2' => 'buildingNumber',
        'zip' => 'postcode',
        '_at' => 'date',
        '_url' => 'url',
        'locale' => 'locale',
        'phone' => 'phoneNumber',
        '_id' => 'id'
    ],

    //These are examples of custom functions that can be used in the data object, you can add more functions here, or remove them if you don't need them.
    'custom_functions' => [
        'id' => [\Langsys\SwaggerAutoGenerator\Functions\CustomFunctions::class,'id'],
        'locale' => [\Langsys\SwaggerAutoGenerator\Functions\CustomFunctions::class,'locale'],
        'date' => [\Langsys\SwaggerAutoGenerator\Functions\CustomFunctions::class,'date'],
    ],
```
In the above example, we have added a custom function `locale` that generates a random locale string.
Here is an example of how the custom function looks like:

```php
<?php

namespace Langsys\SwaggerAutoGenerator\Functions;

use App\Models\Locale;

class CustomFunctions
{  
    public function locale(): string
    {
        $locales = Locale::all();

        return $locales->get(random_int(1, $locales->count()))->code;
    }
}
```

## Testing

```bash
composer test
```
