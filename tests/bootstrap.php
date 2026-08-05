<?php

declare(strict_types=1);

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }
}

require_once dirname(__DIR__) . '/src/Contracts/EventSchemaValidator.php';
require_once dirname(__DIR__) . '/src/Contracts/MetricDefinitionValidator.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Text.php';
require_once dirname(__DIR__) . '/src/Infrastructure/SensitiveValueDetector.php';
require_once dirname(__DIR__) . '/src/Domain/LifecyclePolicy.php';
require_once dirname(__DIR__) . '/src/Domain/PrivacyGateway.php';
