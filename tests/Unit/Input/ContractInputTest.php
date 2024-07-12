<?php

declare(strict_types = 1);

namespace Rebing\GraphQL\Tests\Unit\Input;

use Rebing\GraphQL\Tests\TestCase;

class ContractInputTest extends TestCase
{
    /**
     * Ref https://github.com/rebing/graphql-laravel/issues/930
     */
    public function testInputValidationFields(): void
    {
        $query = <<<'GRAQPHQL'
mutation {
    userUpdate(
        contract: {
            start: null
            end: 2
        }
    )
}
GRAQPHQL;

        $result = $this->httpGraphql($query, [
            'expectErrors' => true,
        ]);

        $expected = [
            'errors' => [
                [
                    'message'    => 'validation',
                    'extensions' => [
                        'category'   => 'validation',
                        'validation' => [
                            'contract.start' => [
                                'The contract.start field must be less than 1 characters.',
                            ],
                            'contract.end'   => [
                                'The contract.end field must be greater than contract.start.',
                            ],
                        ],
                    ],
                    'locations'  => [
                        [
                            'line'   => 2,
                            'column' => 5,
                        ],
                    ],
                    'path'       => [
                        'userUpdate',
                    ],
                ],
            ],
        ];
        self::assertEquals($expected, $result);
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('graphql.schemas.default', [
            'mutation' => [
                UserUpdateMutation::class,
            ],
            'types' => [
                ContractInput::class,
                UserInput::class,
            ],
        ]);
    }
}
