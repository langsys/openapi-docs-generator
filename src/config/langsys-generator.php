<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Output Dir Path
    |--------------------------------------------------------------------------
    |
    | This value is the path of the directory where the generated swagger file
    | will be saved.
    |
    */
    'output_dir' => base_path('app/Swagger'),

    /*
       |--------------------------------------------------------------------------
       | Faker Attribute Mapper
       |--------------------------------------------------------------------------
       |
       | This value is the array of the faker attribute mapper. If you have a custom
       | attribute in your data object, you can map it to a faker function here.
    */

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

    /*
     * This value is the array of the pagination fields. If you have a custom attribute for pagination in your data object, you can map it here.
     * It must follow the format of the pagination fields. (name, description, content, type)
     */
    'pagination_fields' => [
        [
            'name' => 'status',
            'description' => 'Response status',
            'content' => true,
            'type' => 'bool'
        ],
        [
            'name' => 'page',
            'description' => 'Current page number',
            'content' => 1,
            'type' => 'int'
        ],
        [
            'name' => 'records_per_page',
            'description' => 'Number of records per page',
            'content' => 8,
            'type' => 'int'
        ],
        [
            'name' => 'page_count',
            'description' => 'Number of pages',
            'content' => 5,
            'type' => 'int'
        ],
        [
            'name' => 'total_records',
            'description' => 'Total number of items',
            'content' => 40,
            'type' => 'int'
        ],
    ],
];
