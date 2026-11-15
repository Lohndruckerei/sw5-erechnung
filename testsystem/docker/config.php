<?php
/*
 * config.php des Testsystems. Wird über die config.php der Shop-Kopie gelegt (Bind-Mount),
 * die Original-Datei bleibt unverändert.
 */
return [
    'db' => [
        'host' => 'db',
        'port' => '3306',
        'username' => 'shopware',
        'password' => 'shopware',
        'dbname' => 'shopware',
    ],
    // Alle Mails gehen an Mailpit, unabhängig von der Mail-Einstellung im Backend.
    // (Verschlüsselung/Auth werden beim Import zusätzlich in der Datenbank zurückgesetzt.)
    'mail' => [
        'type' => 'smtp',
        'host' => 'mailpit',
        'port' => 1025,
        'username' => '',
        'password' => '',
    ],
    'front' => [
        'showException' => true,
        'throwExceptions' => false,
    ],
    'phpsettings' => [
        'display_errors' => 1,
        'error_reporting' => E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED & ~E_NOTICE & ~E_WARNING,
    ],
    'httpcache' => ['enabled' => false],
    'es' => ['enabled' => false],
    // Medien lokal statt CDN/S3 des Live-Shops
    'cdn' => [
        'backend' => 'local',
        'strategy' => 'md5',
        'adapters' => [
            'local' => [
                'type' => 'local',
                'mediaUrl' => '',
                'permissions' => [
                    'file' => ['public' => 0666 & ~umask(), 'private' => 0600 & ~umask()],
                    'dir' => ['public' => 0777 & ~umask(), 'private' => 0700 & ~umask()],
                ],
                'path' => realpath(__DIR__ . '/'),
            ],
        ],
    ],
];
