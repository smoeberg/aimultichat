<?php
// database/seeders/RoleAndCapabilitySeeder.php

namespace Database\Seeders;

use Core\Database;

/**
 * Seeder for roles and capabilities
 * Can be run via CLI or included in setup
 */
class RoleAndCapabilitySeeder
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getConnection();
    }

    public function run()
    {
        echo "Seeding roles and capabilities...\n";

        // ===== ROLLER =====
        $roles = [
            ['code' => 'owner', 'name' => 'Ejer', 'is_system' => true],
            ['code' => 'admin', 'name' => 'Administrator', 'is_system' => true],
            ['code' => 'finance', 'name' => 'Økonomi', 'is_system' => true],
            ['code' => 'sales', 'name' => 'Salg', 'is_system' => true],
            ['code' => 'ops', 'name' => 'Drift', 'is_system' => true],
            ['code' => 'member', 'name' => 'Medarbejder', 'is_system' => true],
        ];

        foreach ($roles as $role) {
            $this->db->exec(
                "INSERT INTO roles (code, name, is_system) 
                 VALUES ('{$role['code']}', '{$role['name']}', {$role['is_system']}) 
                 ON DUPLICATE KEY UPDATE name = VALUES(name), is_system = VALUES(is_system)"
            );
            echo "  Role: {$role['code']} ({$role['name']})\n";
        }

        // ===== CAPABILITIES =====
        $capabilities = [
            ['code' => 'chat.use', 'description' => 'Må bruge chat'],
            ['code' => 'knowledge.read_assigned', 'description' => 'Må læse tildelte vidensmapper'],
            ['code' => 'knowledge.read_all', 'description' => 'Må læse alle organisationens mapper'],
            ['code' => 'knowledge.manage', 'description' => 'Må oprette/redigere mapper og dokumenter'],
            ['code' => 'sales.customers_own', 'description' => 'Må se egne kunder + deres køb/ordrer'],
            ['code' => 'sales.customers_all', 'description' => 'Må se alle kunder (salgsoverblik)'],
            ['code' => 'finance.debtors', 'description' => 'Debitorliste, åbne poster, "hvem skylder"'],
            ['code' => 'finance.customer_balance_own', 'description' => 'Saldo på egen kunde (begrænset)'],
            ['code' => 'finance.accounts', 'description' => 'Balance, resultat, regnskab'],
            ['code' => 'finance.reports', 'description' => 'Økonomirapporter / eksport'],
            ['code' => 'admin.users', 'description' => 'Brugere, invitationer, roller'],
            ['code' => 'admin.integrations', 'description' => 'Koble ERP, tokens, connectors'],
        ];

        foreach ($capabilities as $cap) {
            $desc = $this->db->quote($cap['description']);
            $this->db->exec(
                "INSERT INTO capabilities (code, description) 
                 VALUES ('{$cap['code']}', {$desc}) 
                 ON DUPLICATE KEY UPDATE description = VALUES(description)"
            );
            echo "  Capability: {$cap['code']}\n";
        }

        // ===== ROLE-CAPABILITY MATRIX =====
        // Clear existing
        $this->db->exec("TRUNCATE TABLE role_capabilities");

        // Get role IDs
        $roleIds = [];
        $result = $this->db->query("SELECT id, code FROM roles");
        while ($row = $result->fetch()) {
            $roleIds[$row['code']] = (int)$row['id'];
        }

        // Get capability IDs
        $capIds = [];
        $result = $this->db->query("SELECT id, code FROM capabilities");
        while ($row = $result->fetch()) {
            $capIds[$row['code']] = (int)$row['id'];
        }

        // Definition: [role_code => [capability_code, ...]]
        $matrix = [
            'owner' => [
                'chat.use',
                'knowledge.read_assigned',
                'knowledge.read_all',
                'knowledge.manage',
                'sales.customers_own',
                'sales.customers_all',
                'finance.customer_balance_own',
                'finance.debtors',
                'finance.accounts',
                'finance.reports',
                'admin.users',
                'admin.integrations',
            ],
            'admin' => [
                'chat.use',
                'knowledge.read_assigned',
                'knowledge.read_all',
                'knowledge.manage',
                'admin.users',
                'admin.integrations',
            ],
            'finance' => [
                'chat.use',
                'knowledge.read_assigned',
                'sales.customers_own',
                'sales.customers_all',
                'finance.customer_balance_own',
                'finance.debtors',
                'finance.accounts',
                'finance.reports',
            ],
            'sales' => [
                'chat.use',
                'knowledge.read_assigned',
                'sales.customers_own',
                'finance.customer_balance_own',
            ],
            'ops' => [
                'chat.use',
                'knowledge.read_assigned',
                'sales.customers_own',
            ],
            'member' => [
                'chat.use',
                'knowledge.read_assigned',
            ],
        ];

        // Insert
        foreach ($matrix as $roleCode => $capabilityCodes) {
            $roleId = $roleIds[$roleCode] ?? null;
            if (!$roleId) continue;

            foreach ($capabilityCodes as $capCode) {
                $capId = $capIds[$capCode] ?? null;
                if (!$capId) continue;

                $this->db->exec(
                    "INSERT INTO role_capabilities (role_id, capability_id) 
                     VALUES ({$roleId}, {$capId}) 
                     ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), capability_id = VALUES(capability_id)"
                );
            }
        }

        echo "Role-capability matrix seeded successfully.\n";
        echo "Seeder completed!\n";
    }
}

// Check if this is being run directly
if (php_sapi_name() === 'cli') {
    require_once __DIR__ . '/../../bootstrap.php';
    (new RoleAndCapabilitySeeder())->run();
}
