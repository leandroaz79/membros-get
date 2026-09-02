<?php

namespace Tests\Feature;

use Tests\TestCase;

class BlockSensitivePathsTest extends TestCase
{
    public function test_blocks_env_path(): void
    {
        $this->get('/.env')->assertForbidden();
    }

    public function test_blocks_env_backup_path(): void
    {
        $this->get('/.env.backup')->assertForbidden();
    }

    public function test_blocks_vendor_path(): void
    {
        $this->get('/vendor/autoload.php')->assertForbidden();
    }

    public function test_blocks_config_path(): void
    {
        $this->get('/config/app.php')->assertForbidden();
    }

    public function test_does_not_block_normal_routes(): void
    {
        $this->get('/login')->assertRedirect();
    }
}
