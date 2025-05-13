<?php

namespace ClarionApp\LlmClient;

class GenerateToolFunction
{
    public static function generateFunction($name, $description, $parameters)
    {
        return [
            "type" => "function",
            "function" => [
                "name" => $name,
                "description" => $description,
                "parameters" => $parameters        
            ]
        ];
    }

    public static function generateParameters($properties, $required = [])
    {
        return [
            "type" => "object",
            "properties" => $properties,
            "required" => $required
        ];
    }
}