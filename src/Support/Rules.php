<?php

declare(strict_types = 1);
namespace Rebing\GraphQL\Support;

use GraphQL\Type\Definition\InputObjectField;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\Type as GraphqlType;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationRuleParser;

class Rules
{
    /** @var array<string,mixed> */
    private $queryArguments;
    /** @var array<string,mixed> */
    private $requestArguments;

    /**
     * @param array<string,mixed> $queryArguments
     * @param array<string,mixed> $requestArguments
     */
    public function __construct(array $queryArguments, array $requestArguments)
    {
        $this->queryArguments = $queryArguments;
        $this->requestArguments = $requestArguments;
    }

    /**
     * @return array<string,mixed>
     */
    public function get(): array
    {
        return $this->getRules($this->queryArguments, null, $this->requestArguments);
    }

    /**
     * @param array<string,mixed>|string|callable $rules
     * @param array<string,mixed> $arguments
     * @return array<string,mixed>|string
     */
    protected function resolveRules($rules, ?string $prefix, array $arguments)
    {
        if (\is_callable($rules)) {
            return $rules($arguments, $this->requestArguments);
        }

        if (is_string($rules)) {
            $rules = [$rules];
        }

        return $this->qualifyFieldPrefixes($rules, $prefix);
    }

    /**
     * @param array<string,mixed> $resolutionArguments
     * @return array<string,mixed>
     */
    protected function inferRulesFromType(GraphqlType $type, string $prefix, array $resolutionArguments): array
    {
        $isList = false;
        $rules = [];

        // make sure we are dealing with the actual type
        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        // if it is an array type, add an array validation component
        if ($type instanceof ListOfType) {
            $type = $type->getWrappedType();

            $isList = true;
        }

        if ($type instanceof NonNull) {
            $type = $type->getWrappedType();
        }

        // if it is an input object type - the only type we care about here...
        if ($type instanceof InputObjectType) {
            // merge in the input type's rules

            if ($isList) {
                if (empty($resolutionArguments)) {
                    return [];
                }

                foreach ($resolutionArguments as $index => $input) {
                    $key = "$prefix.$index";

                    if (null !== $input) {
                        $rules = $rules + $this->getInputTypeRules($type, $key, $input);
                    }
                }

                return $rules;
            }

            $rules = $rules + $this->getInputTypeRules($type, $prefix, $resolutionArguments);
        }

        return $rules;
    }

    /**
     * @param array<string,mixed> $resolutionArguments
     * @return array<string,mixed>
     */
    protected function getInputTypeRules(InputObjectType $input, string $prefix, array $resolutionArguments): array
    {
        return $this->getRules($input->getFields(), $prefix, $resolutionArguments);
    }

    /**
     * Get rules from fields.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $resolutionArguments
     * @return array<string,mixed>
     */
    protected function getRules(array $fields, ?string $prefix, array $resolutionArguments): array
    {
        $rules = [];

        foreach ($fields as $name => $field) {
            $field = $field instanceof InputObjectField ? $field : (object) $field;

            $key = null === $prefix ? $name : "$prefix.$name";

            // get any explicitly set rules
            $fieldRules = $field->config['rules'] ?? $field->rules ?? null;

            if ($fieldRules) {
                $rules[$key] = $this->resolveRules($fieldRules, $prefix, $resolutionArguments);
            }

            if (property_exists($field, 'type') && \array_key_exists($name, $resolutionArguments) && \is_array($resolutionArguments[$name])) {
                $type = $field instanceof InputObjectField
                    ? $field->getType()
                    : $field->type;
                $rules = $rules + $this->inferRulesFromType($type, $key, $resolutionArguments[$name]);
            }
        }

        return $rules;
    }

    /**
     * @param array<string,mixed> $rules
     * @param string|null $prefix
     *
     * @throws \Safe\Exceptions\PcreException
     * @return array<string,mixed>|string
     */
    protected function qualifyFieldPrefixes(array $rules, ?string $prefix): array|string
    {
        // If there is no prefix, we don't need to do anything
        if (!$prefix) {
            return $rules;
        }

        foreach ($rules as $key => $rule) {
            $parsed = ValidationRuleParser::parse($rule);

            $name = $parsed[0];
            $args = $parsed[1];

            if ($name === 'WithReference') {
                $indexes = explode('_', $args[1]);
                array_splice($args, 1, 1);
                foreach ($indexes as $index) {
                    // Skipping over the first index, which is the name
                    $index        = (int)$index + 1;
                    $args[$index] = "$prefix.$args[$index]";
                }

                $parsed = ValidationRuleParser::parse($args);

                $name = $parsed[0];
                $args = $parsed[1];
            }

            // Skip rules that are already prefixed
            if (count($args)) {
                $basePrefix = explode('.', $prefix)[0];
                if (\Safe\preg_match('/^' . preg_quote($basePrefix, '/') . '\.\*\.|^\d+\./', $args[0])) {
                    continue;
                }
            }

            if (in_array($name, [
                'Different',
                'Gt',
                'Gte',
                'Lt',
                'Lte',
                'ProhibitedIf',
                'ProhibitedUnless',
                'RequiredIf',
                'RequiredUnless',
                'Same',
            ])) {
                $args[0] = "$prefix.$args[0]";
            }

            // Rules where all arguments are field references
            if (in_array($name, [
                'Prohibits',
                'RequiredWith',
                'RequiredWithAll',
                'RequiredWithout',
                'RequiredWithoutAll',
            ])) {
                $args = array_map(
                    static fn (string $field): string => "$prefix.$field",
                    $args,
                );
            }

            // Rules where the first argument is a date or a field reference
            if (is_string($args[0] ?? null) && in_array($name, [
                    'After',
                    'AfterOrEqual',
                    'Before',
                    'BeforeOrEqual',
                ])) {
                try {
                    Carbon::parse($args[0]);
                } catch (\Throwable) {
                    $args[0] = "$prefix.$args[0]";
                }
            }

            // Convert back to the Laravel rule definition style rule:arg1,arg2
            $rule = count($args) > 0
                ? $name . ':' . implode(',', $args)
                : $name;

            $rules[$key] = $rule;
        }

        return $rules;
    }
}
