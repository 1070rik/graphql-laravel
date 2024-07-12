<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Tests\Unit\Input;

use GraphQL\Type\Definition\Type;
use Rebing\GraphQL\Support\InputType;

class ContractInput extends InputType
{
    /** @var array<string,mixed> */
    protected $attributes = [
        'name' => 'ContractInput',
    ];

    public function fields(): array
    {
        return [
            'start' => [
                'type'  => Type::int(),
                'rules' => ['lt:end'],
            ],
            'end'   => [
                'type'  => Type::int(),
                'rules' => ['gt:start'],
            ],
        ];
    }
}
