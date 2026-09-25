<?php

namespace Langsys\OpenApiDocsGenerator\Tests\ErrorOperationFixtures;

use Langsys\OpenApiDocsGenerator\Generators\Attributes\Example;
use Langsys\OpenApiDocsGenerator\Tests\ErrorFixtures\ApiError;

/** Its only detail is an array, so its example lives on the items. */
class AccountOwnsOrganizationsError extends ApiError
{
    public const CODE = 'account_owns_organizations';
    public const MESSAGE = 'Transfer or delete every organization you own before deleting your account.';
    public const STATUS = 422;

    public function __construct(
        /** @var string[] */
        #[Example('5b0e9c1f')]
        public array $owned_organization_ids,
    ) {
    }
}
