<?php
/**
 * AI Content Marking Test Suite
 * Basic validation tests for the AI content marking implementation
 */

declare(strict_types=1);

// Set up autoloading
require_once __DIR__ . '/../bootstrap.php';

echo "=== AI Content Marking Test Suite ===\n\n";

// Test 1: Check if all required classes can be autoloaded
echo "Test 1: Autoloading Check\n";
$classes = [
    'Contracts\AiProvider',
    'DTOs\AiResponse',
    'Events\AiResponseGenerated',
    'Listeners\LogAiResponse',
    'Services\AiGateway\AiGateway',
    'Services\AiProviders\ClaudeProvider',
    'Services\AiProviders\GeminiProvider',
    'Services\Export\DocxExportService',
    'Services\Export\PdfExportService',
    'Http\Controllers\Api\CopyLogController',
    'Http\Controllers\Api\ExportController',
    'Http\Controllers\Admin\RequestVerificationController',
];

$failed = [];
foreach ($classes as $class) {
    if (!class_exists($class)) {
        $failed[] = $class;
        echo "  ❌ FAILED: $class not found\n";
    } else {
        echo "  ✅ PASSED: $class\n";
    }
}

if (empty($failed)) {
    echo "✅ All classes autoloaded successfully\n\n";
} else {
    echo "❌ Autoloading failed for: " . implode(', ', $failed) . "\n\n";
}

// Test 2: Check if AI configuration can be loaded
echo "Test 2: AI Configuration Check\n";
try {
    $configPath = __DIR__ . '/../config/ai.php';
    if (file_exists($configPath)) {
        $config = require $configPath;
        if (isset($config['policy_version'])) {
            echo "  ✅ PASSED: AI config loaded, policy version: " . $config['policy_version'] . "\n";
        } else {
            echo "  ❌ FAILED: policy_version not found in config\n";
        }
        if (isset($config['providers'])) {
            echo "  ✅ PASSED: Providers configured: " . implode(', ', array_keys($config['providers'])) . "\n";
        } else {
            echo "  ❌ FAILED: providers not found in config\n";
        }
    } else {
        echo "  ⚠️  WARNING: config/ai.php not found\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 3: Check DTO instantiation
echo "Test 3: DTO Instantiation Check\n";
try {
    use DTOs\AiResponse;
    
    $response = new AiResponse(
        content: 'Test AI response',
        requestId: 'test_req_123',
        provider: 'claude',
        model: 'claude-3-opus-20240229',
        providerRequestId: 'msg_123',
        watermarkType: 'synthid',
        timestamp: new DateTimeImmutable()
    );
    
    if ($response->content === 'Test AI response') {
        echo "  ✅ PASSED: AiResponse DTO created successfully\n";
    }
    if ($response->hasWatermark()) {
        echo "  ✅ PASSED: Watermark detection works\n";
    }
    if ($response->getFormattedTimestamp() !== '') {
        echo "  ✅ PASSED: Timestamp formatting works\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Check AiResponse toArray method
echo "Test 4: DTO toArray Method Check\n";
try {
    use DTOs\AiResponse;
    
    $response = new AiResponse(
        content: 'Test content',
        requestId: 'req_456',
        provider: 'gemini',
        model: 'gemini-1.5-pro'
    );
    
    $array = $response->toArray();
    if (isset($array['content']) && isset($array['request_id'])) {
        echo "  ✅ PASSED: toArray() method works\n";
    } else {
        echo "  ❌ FAILED: toArray() missing expected keys\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Check AiGateway provider list
echo "Test 5: AiGateway Provider List Check\n";
try {
    use Services\AiGateway\AiGateway;
    
    $gateway = new AiGateway();
    $providers = $gateway->getSupportedProviders();
    
    if (in_array('claude', $providers) && in_array('gemini', $providers)) {
        echo "  ✅ PASSED: AiGateway has expected providers\n";
    } else {
        echo "  ❌ FAILED: AiGateway missing expected providers\n";
    }
    
    if ($gateway->providerSupportsWatermark('claude')) {
        echo "  ✅ PASSED: Claude watermark support detected\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: Check Export Services can be instantiated
echo "Test 6: Export Services Check\n";
try {
    use Services\Export\DocxExportService;
    use Services\Export\PdfExportService;
    
    $docxExporter = new DocxExportService();
    echo "  ✅ PASSED: DocxExportService instantiated\n";
    
    $pdfExporter = new PdfExportService();
    echo "  ✅ PASSED: PdfExportService instantiated\n";
    
    // Test export with simple content
    $testContent = "This is a test AI response";
    $docxOutput = $docxExporter->export($testContent, 'test_req_789', 'claude', 'claude-3-opus');
    if (!empty($docxOutput)) {
        echo "  ✅ PASSED: DOCX export generated output\n";
    }
    
    $pdfOutput = $pdfExporter->export($testContent, 'test_req_789', 'claude', 'claude-3-opus');
    if (!empty($pdfOutput)) {
        echo "  ✅ PASSED: PDF export generated output\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 7: Check Event and Listener
echo "Test 7: Event and Listener Check\n";
try {
    use Events\AiResponseGenerated;
    use DTOs\AiResponse;
    
    $response = new AiResponse(
        content: 'Event test content',
        requestId: 'event_req_123',
        provider: 'claude',
        model: 'claude-3-opus'
    );
    
    $event = new AiResponseGenerated(
        response: $response,
        organizationId: 1,
        userId: 1,
        conversationId: 1,
        prompt: 'Test prompt'
    );
    
    if ($event->response->requestId === 'event_req_123') {
        echo "  ✅ PASSED: AiResponseGenerated event created\n";
    }
    
    use Listeners\LogAiResponse;
    $listener = new LogAiResponse();
    echo "  ✅ PASSED: LogAiResponse listener instantiated\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Check Controllers can be instantiated
echo "Test 8: Controller Check\n";
try {
    use Http\Controllers\Api\CopyLogController;
    use Http\Controllers\Api\ExportController;
    use Http\Controllers\Admin\RequestVerificationController;
    
    $copyLogController = new CopyLogController();
    echo "  ✅ PASSED: CopyLogController instantiated\n";
    
    $exportController = new ExportController();
    echo "  ✅ PASSED: ExportController instantiated\n";
    
    $verificationController = new RequestVerificationController();
    echo "  ✅ PASSED: RequestVerificationController instantiated\n";
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 9: Check database migration SQL syntax
echo "Test 9: Database Migration Check\n";
try {
    $migrationPath = __DIR__ . '/../database/migrations/2026_08_20_000000_add_ai_content_marking.php';
    if (file_exists($migrationPath)) {
        $migrationContent = file_get_contents($migrationPath);
        if (strpos($migrationContent, 'CREATE TABLE IF NOT EXISTS ai_requests') !== false) {
            echo "  ✅ PASSED: ai_requests table creation found\n";
        }
        if (strpos($migrationContent, 'CREATE TABLE IF NOT EXISTS ai_response_contents') !== false) {
            echo "  ✅ PASSED: ai_response_contents table creation found\n";
        }
        if (strpos($migrationContent, 'CREATE TABLE IF NOT EXISTS audit_events') !== false) {
            echo "  ✅ PASSED: audit_events table creation found\n";
        }
    } else {
        echo "  ❌ FAILED: Migration file not found\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 10: Check documentation files
echo "Test 10: Documentation Check\n";
$docs = [
    '/../docs/adr/ADR-001-AI-Content-Marking-Strategy.md',
    '/../docs/compliance/AI-Content-Marking-Compliance-Notat.md'
];

foreach ($docs as $doc) {
    $fullPath = __DIR__ . $doc;
    if (file_exists($fullPath)) {
        echo "  ✅ PASSED: " . basename($doc) . " exists\n";
    } else {
        echo "  ❌ FAILED: " . basename($doc) . " not found\n";
    }
}
echo "\n";

echo "=== Test Suite Complete ===\n";
echo "Note: Some tests may fail if dependencies (PhpWord, Dompdf) are not installed.\n";
echo "This is expected in a fresh installation.\n";
