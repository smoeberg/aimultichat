<?php
/**
 * Role and Capability Test Suite
 * Tests for the role-based access control system
 */

declare(strict_types=1);

// Set up autoloading
require_once __DIR__ . '/../../bootstrap.php';

echo "=== Role and Capability Test Suite ===\n\n";

// Test 1: Check if all models can be autoloaded
echo "Test 1: Model Autoloading Check\n";
$models = [
    'Models\Role',
    'Models\Capability',
    'Models\Organization',
    'Models\OrganizationMember',
];

$failed = [];
foreach ($models as $model) {
    if (!class_exists($model)) {
        $failed[] = $model;
        echo "  ❌ FAILED: $model not found\n";
    } else {
        echo "  ✅ PASSED: $model\n";
    }
}

if (empty($failed)) {
    echo "✅ All models autoloaded successfully\n\n";
} else {
    echo "❌ Autoloading failed for: " . implode(', ', $failed) . "\n\n";
}

// Test 2: Check if traits and helpers can be autoloaded
echo "Test 2: Traits and Helpers Check\n";
$classes = [
    'Traits\HasCapabilities',
    'Http\Middleware\CheckCapability',
    'Http\Middleware\CheckCapabilityApi',
    'Http\Controllers\OrganizationController',
    'Http\Controllers\Admin\InvitationController',
    'Http\Controllers\Auth\InvitationController',
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

// Test 3: Test Role model
echo "Test 3: Role Model Check\n";
try {
    use Models\Role;
    
    // This will fail if tables don't exist, but we can test the model structure
    $role = new Role();
    $role->id = 1;
    $role->code = 'owner';
    $role->name = 'Ejer';
    $role->is_system = true;
    $role->created_at = date('Y-m-d H:i:s');
    $role->updated_at = date('Y-m-d H:i:s');
    
    if ($role->code === 'owner') {
        echo "  ✅ PASSED: Role model can be instantiated\n";
    }
    
    // Test toArray
    $array = $role->toArray();
    if (isset($array['code']) && $array['code'] === 'owner') {
        echo "  ✅ PASSED: Role toArray() method works\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 4: Test Capability model
echo "Test 4: Capability Model Check\n";
try {
    use Models\Capability;
    
    $cap = new Capability();
    $cap->id = 1;
    $cap->code = 'chat.use';
    $cap->description = 'Må bruge chat';
    $cap->created_at = date('Y-m-d H:i:s');
    $cap->updated_at = date('Y-m-d H:i:s');
    
    if ($cap->code === 'chat.use') {
        echo "  ✅ PASSED: Capability model can be instantiated\n";
    }
    
    $array = $cap->toArray();
    if (isset($array['code']) && $array['code'] === 'chat.use') {
        echo "  ✅ PASSED: Capability toArray() method works\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 5: Test Organization model
echo "Test 5: Organization Model Check\n";
try {
    use Models\Organization;
    
    $org = new Organization();
    $org->id = 1;
    $org->name = 'Test Organization';
    $org->owner_id = 1;
    $org->created_at = date('Y-m-d H:i:s');
    $org->updated_at = date('Y-m-d H:i:s');
    
    if ($org->name === 'Test Organization') {
        echo "  ✅ PASSED: Organization model can be instantiated\n";
    }
    
    $array = $org->toArray();
    if (isset($array['name']) && $array['name'] === 'Test Organization') {
        echo "  ✅ PASSED: Organization toArray() method works\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 6: Test OrganizationMember model
echo "Test 6: OrganizationMember Model Check\n";
try {
    use Models\OrganizationMember;
    
    $member = new OrganizationMember();
    $member->id = 1;
    $member->organization_id = 1;
    $member->user_id = 1;
    $member->role_id = 1;
    $member->created_at = date('Y-m-d H:i:s');
    $member->updated_at = date('Y-m-d H:i:s');
    
    if ($member->organization_id === 1) {
        echo "  ✅ PASSED: OrganizationMember model can be instantiated\n";
    }
    
    $array = $member->toArray();
    if (isset($array['organization_id']) && $array['organization_id'] === 1) {
        echo "  ✅ PASSED: OrganizationMember toArray() method works\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 7: Test User model extensions
echo "Test 7: User Model Extensions Check\n";
try {
    use Models\User;
    
    // Check if new methods exist
    $user = new User();
    $user->id = 1;
    $user->name = 'Test User';
    $user->username = 'test';
    $user->role = 'user';
    $user->enabled = true;
    $user->sessionToken = null;
    
    // Check if methods exist
    if (method_exists($user, 'organizationMembers')) {
        echo "  ✅ PASSED: User has organizationMembers() method\n";
    }
    if (method_exists($user, 'organizations')) {
        echo "  ✅ PASSED: User has organizations() method\n";
    }
    if (method_exists($user, 'getCurrentOrganizationRole')) {
        echo "  ✅ PASSED: User has getCurrentOrganizationRole() method\n";
    }
    if (method_exists($user, 'getCurrentOrganizationId')) {
        echo "  ✅ PASSED: User has getCurrentOrganizationId() method\n";
    }
    if (method_exists($user, 'hasCapability')) {
        echo "  ✅ PASSED: User has hasCapability() method\n";
    }
    if (method_exists($user, 'hasAnyCapability')) {
        echo "  ✅ PASSED: User has hasAnyCapability() method\n";
    }
    if (method_exists($user, 'hasAllCapabilities')) {
        echo "  ✅ PASSED: User has hasAllCapabilities() method\n";
    }
    if (method_exists($user, 'hasRole')) {
        echo "  ✅ PASSED: User has hasRole() method\n";
    }
    if (method_exists($user, 'hasAnyRole')) {
        echo "  ✅ PASSED: User has hasAnyRole() method\n";
    }
    if (method_exists($user, 'getCapabilities')) {
        echo "  ✅ PASSED: User has getCapabilities() method\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 8: Test Trait
echo "Test 8: HasCapabilities Trait Check\n";
try {
    use Traits\HasCapabilities;
    
    // Create a test class that uses the trait
    $testClass = new class {
        use HasCapabilities;
        
        public function testCan() {
            return $this->can('chat.use');
        }
    };
    
    if (method_exists($testClass, 'can')) {
        echo "  ✅ PASSED: HasCapabilities trait has can() method\n";
    }
    if (method_exists($testClass, 'canOrFail')) {
        echo "  ✅ PASSED: HasCapabilities trait has canOrFail() method\n";
    }
    if (method_exists($testClass, 'canOrJson')) {
        echo "  ✅ PASSED: HasCapabilities trait has canOrJson() method\n";
    }
    if (method_exists($testClass, 'isRole')) {
        echo "  ✅ PASSED: HasCapabilities trait has isRole() method\n";
    }
    if (method_exists($testClass, 'isRoleOrFail')) {
        echo "  ✅ PASSED: HasCapabilities trait has isRoleOrFail() method\n";
    }
} catch (Throwable $e) {
    echo "  ❌ FAILED: " . $e->getMessage() . "\n";
}
echo "\n";

// Test 9: Check database migration files
echo "Test 9: Database Migration Files Check\n";
$migrations = [
    '/../../database/migrations/2026_08_21_000000_create_roles_and_capabilities_tables.php',
];

foreach ($migrations as $migration) {
    $fullPath = __DIR__ . $migration;
    if (file_exists($fullPath)) {
        echo "  ✅ PASSED: " . basename($migration) . " exists\n";
        
        // Check for table creation
        $content = file_get_contents($fullPath);
        if (strpos($content, 'CREATE TABLE IF NOT EXISTS roles') !== false) {
            echo "    ✅ roles table creation found\n";
        }
        if (strpos($content, 'CREATE TABLE IF NOT EXISTS capabilities') !== false) {
            echo "    ✅ capabilities table creation found\n";
        }
        if (strpos($content, 'CREATE TABLE IF NOT EXISTS role_capabilities') !== false) {
            echo "    ✅ role_capabilities table creation found\n";
        }
        if (strpos($content, 'CREATE TABLE IF NOT EXISTS organization_members') !== false) {
            echo "    ✅ organization_members table creation found\n";
        }
        if (strpos($content, 'CREATE TABLE IF NOT EXISTS invitations') !== false) {
            echo "    ✅ invitations table creation found\n";
        }
    } else {
        echo "  ❌ FAILED: " . basename($migration) . " not found\n";
    }
}
echo "\n";

// Test 10: Check seeder file
echo "Test 10: Seeder File Check\n";
$seederPath = __DIR__ . '/../../database/seeders/RoleAndCapabilitySeeder.php';
if (file_exists($seederPath)) {
    echo "  ✅ PASSED: RoleAndCapabilitySeeder.php exists\n";
    
    $content = file_get_contents($seederPath);
    if (strpos($content, "'owner'") !== false) {
        echo "    ✅ owner role found\n";
    }
    if (strpos($content, "'admin'") !== false) {
        echo "    ✅ admin role found\n";
    }
    if (strpos($content, "'chat.use'") !== false) {
        echo "    ✅ chat.use capability found\n";
    }
    if (strpos($content, "'finance.accounts'") !== false) {
        echo "    ✅ finance.accounts capability found\n";
    }
} else {
    echo "  ❌ FAILED: RoleAndCapabilitySeeder.php not found\n";
}
echo "\n";

// Test 11: Check helper functions
echo "Test 11: Helper Functions Check\n";
$helperPath = __DIR__ . '/../../src/Helpers/RoleHelper.php';
if (file_exists($helperPath)) {
    echo "  ✅ PASSED: RoleHelper.php exists\n";
    
    $content = file_get_contents($helperPath);
    if (strpos($content, 'function can(') !== false) {
        echo "    ✅ can() function found\n";
    }
    if (strpos($content, 'function canOrFail(') !== false) {
        echo "    ✅ canOrFail() function found\n";
    }
    if (strpos($content, 'function currentRole(') !== false) {
        echo "    ✅ currentRole() function found\n";
    }
    if (strpos($content, 'function isRole(') !== false) {
        echo "    ✅ isRole() function found\n";
    }
    if (strpos($content, 'function isAnyRole(') !== false) {
        echo "    ✅ isAnyRole() function found\n";
    }
} else {
    echo "  ❌ FAILED: RoleHelper.php not found\n";
}
echo "\n";

// Test 12: Check controller files
echo "Test 12: Controller Files Check\n";
$controllers = [
    '/../../src/Http/Controllers/OrganizationController.php',
    '/../../src/Http/Controllers/Admin/InvitationController.php',
    '/../../src/Http/Controllers/Auth/InvitationController.php',
];

foreach ($controllers as $controller) {
    $fullPath = __DIR__ . $controller;
    if (file_exists($fullPath)) {
        echo "  ✅ PASSED: " . basename($controller) . " exists\n";
    } else {
        echo "  ❌ FAILED: " . basename($controller) . " not found\n";
    }
}
echo "\n";

echo "=== Test Suite Complete ===\n";
echo "Note: Some tests may fail if database tables don't exist yet.\n";
echo "Run the migration first: php database/migrations/2026_08_21_000000_create_roles_and_capabilities_tables.php\n";
echo "Then run the seeder: php database/seeders/RoleAndCapabilitySeeder.php\n";
