<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class PublicPrivateSeparationTest extends TestCase
{
    public function test_private_sensitive_files_are_outside_public_directory(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $publicDir = $baseDir . DIRECTORY_SEPARATOR . 'public';

        // 1. Archivo .env NUNCA debe estar en public/
        $this->assertFileDoesNotExist($publicDir . DIRECTORY_SEPARATOR . '.env');
        $this->assertFileDoesNotExist($publicDir . DIRECTORY_SEPARATOR . '.env.example');

        // 2. Storage NUNCA debe estar expuesto directamente en public/ sin symlink controlado
        $this->assertDirectoryDoesNotExist($publicDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs');
        $this->assertDirectoryDoesNotExist($publicDir . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'framework');

        // 3. Credenciales de Google Sheets o Service Account NUNCA en public/
        $this->assertFileDoesNotExist($publicDir . DIRECTORY_SEPARATOR . 'google-service-account.json');
    }

    public function test_htaccess_in_public_directory_disables_indexes_and_protects_access(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $htaccessPath = $baseDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '.htaccess';

        $this->assertFileExists($htaccessPath);
        $content = file_get_contents($htaccessPath);

        // Desactivación estricta de listado de directorios
        $this->assertStringContainsString('Options -Indexes', $content);

        // Reescritura hacia index.php
        $this->assertStringContainsString('RewriteRule ^ index.php [L]', $content);

        // Cabeceras de seguridad
        $this->assertStringContainsString('X-Content-Type-Options', $content);
        $this->assertStringContainsString('X-Frame-Options', $content);
    }

    public function test_public_index_php_resolves_app_without_hardcoding_server_paths(): void
    {
        $baseDir = dirname(__DIR__, 2);
        $indexPath = $baseDir . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';

        $this->assertFileExists($indexPath);
        $content = file_get_contents($indexPath);

        // Debe soportar tanto la ruta de cPanel separada (crm-app) como la ruta local estándar
        $this->assertStringContainsString('crm-app/vendor/autoload.php', $content);
        $this->assertStringContainsString('../vendor/autoload.php', $content);
    }
}
