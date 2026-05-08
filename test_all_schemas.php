<?php
require '/home/app/vendor/autoload.php';

use Mcp\Capability\Discovery\SchemaGenerator;
use Mcp\Capability\Discovery\DocBlockParser;

$docBlockParser = new DocBlockParser();
$schemaGen = new SchemaGenerator($docBlockParser);

$toolDir = '/home/app/src/Mcp/Tool';
$files = glob($toolDir . '/*.php');
$hasIssues = false;

foreach ($files as $file) {
    $className = 'App\Mcp\Tool\' . pathinfo($file, PATHINFO_FILENAME);
    
    if (!class_exists($className)) continue;
    
    $toolClass = new ReflectionClass($className);
    
    // Find the invoke method
    $method = null;
    if ($toolClass->hasMethod('__invoke')) {
        $method = $toolClass->getMethod('__invoke');
    } elseif ($toolClass->hasMethod('handle')) {
        $method = $toolClass->getMethod('handle');
    }
    
    if (!$method) continue;
    
    // Skip if not a public method
    if (!$method->isPublic()) continue;
    
    $toolName = $className;
    
    try {
        $schema = $schemaGen->generate($method);
        
        // Check for array properties without items
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $propName => $prop) {
                if ($prop['type'] === 'array' && !isset($prop['items'])) {
                    echo ❌ $toolName.$propName: array without itemsn;
                    $hasIssues = true;
                }
            }
        }
    } catch (Exception $e) {
        echo ⚠️ $toolName:  . $e->getMessage() . n;
    }
}

if (!$hasIssues) {
    echo ✅ All schemas look good!n;
}
