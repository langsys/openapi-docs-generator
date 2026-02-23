<?php namespace Langsys\SwaggerAutoGenerator\Tests\Output;
/**  
* @OA\Schema( schema="ExampleData",
*	@OA\Property(property="example",type="string",example="test",),
* ),
*
* @OA\Schema( schema="TestData",
*	@OA\Property(property="id",type="int",example=468,),
*	@OA\Property(property="another_id",type="string",example="368c23fe-ae9c-4052-9f8c-0bb5622cf3ca",),
*	@OA\Property(
*		property="collection",
*		type="array",
*		@OA\Items(type="string", example="collection as array "),
*	),
*	@OA\Property(
*		property="array",
*		type="array",
*		@OA\Items(type="string", example="array"),
*	),
*	@OA\Property(
*		property="grouped_array",
*		type="object",
*		example={"es": "es-cr"},
*	),
*	@OA\Property(
*		property="grouped_collection",
*		type="object",
*		@OA\Property(
*			property="es-cr",
*			allOf={
*				@OA\Schema(ref = "#/components/schemas/ExampleData"),
*			}
*		),
*	),
*	@OA\Property(property="default_string",type="string",default="defaultString",example="A String",),
*	@OA\Property(property="default_int",type="int",default=3,example=0,),
*	@OA\Property(property="default_bool",type="bool",default=true,example=true,),
*	@OA\Property(property="enum",type="enum",default="case1",enum={"case1", "case2"}, example= "case2",),
* ),
*
 */ 
 class Schemas {}