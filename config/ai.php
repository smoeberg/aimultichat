<?php
/**
 * AI Configuration
 * Settings for AI providers, content marking, and compliance
 */

return [
    // AI Content Marking Policy Version
    // Increment this when making changes to the marking strategy
    'policy_version' => '1.0.0',

    // AI Providers Configuration
    'providers' => [
        'claude' => [
            'api_key' => null, // Will be loaded from secrets
            'model' => 'claude-3-opus-20240229',
            'endpoint' => 'https://api.anthropic.com/v1/messages',
            'supports_watermark' => true,
            'watermark_type' => 'synthid',
        ],
        'gemini' => [
            'api_key' => null, // Will be loaded from secrets
            'model' => 'gemini-1.5-pro',
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
            'supports_watermark' => true,
            'watermark_type' => 'synthid',
        ],
    ],

    // Content Marking Settings
    'content_marking' => [
        // Enable/disable UI badge
        'show_badge' => true,
        
        // Enable/disable copy protection (disclaimer prefix)
        'copy_protection' => true,
        
        // Enable/disable export marking
        'export_marking' => true,
        
        // Enable/disable audit logging
        'audit_logging' => true,
    ],

    // Audit Settings
    'audit' => [
        // Retention period in days (3 years for AI Act compliance)
        'retention_days' => 1095,
        
        // Log copy events
        'log_copy_events' => true,
        
        // Log export events
        'log_export_events' => true,
    ],

    // Export Settings
    'export' => [
        // Default export format
        'default_format' => 'pdf',
        
        // Available export formats
        'available_formats' => ['pdf', 'docx'],
    ],
];
