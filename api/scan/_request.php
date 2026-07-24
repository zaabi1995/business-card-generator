<?php
/**
 * Shared strict request-value checks for native API endpoints.
 */
function scanRequestHasExactTrue(array $body, string $key): bool
{
    return array_key_exists($key, $body) && $body[$key] === true;
}
