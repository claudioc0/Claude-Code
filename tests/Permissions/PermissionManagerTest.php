<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Permissions;

use ClaudeCode\Permissions\PermissionManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PermissionManagerTest extends TestCase
{
    public function testResolvesRelativePathInsideWorkspace(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_perm_' . uniqid();
        mkdir($root, 0777, true);

        try {
            $pm = new PermissionManager($root);
            $resolved = $pm->resolveWorkspacePath('src/file.php');
            $this->assertStringStartsWith(realpath($root) ?: $root, $resolved);
            $this->assertStringEndsWith('src' . DIRECTORY_SEPARATOR . 'file.php', $resolved);
        } finally {
            rmdir($root);
        }
    }

    public function testBlocksPathTraversalOutsideWorkspace(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_perm_' . uniqid();
        mkdir($root, 0777, true);

        try {
            $pm = new PermissionManager($root);
            $this->expectException(InvalidArgumentException::class);
            $pm->resolveWorkspacePath('../../etc/passwd');
        } finally {
            rmdir($root);
        }
    }

    public function testAuthorizationModes(): void
    {
        $pm = new PermissionManager(sys_get_temp_dir(), PermissionManager::MODE_ASK);

        // Read-only is always allowed
        $this->assertTrue($pm->authorize('read_file', [], false, fn () => false));

        // Ask mode delegates to callback
        $this->assertFalse($pm->authorize('write_file', [], true, fn () => false));
        $this->assertTrue($pm->authorize('write_file', [], true, fn () => true));

        // Auto-edit approves write_file automatically, still asks for run_command
        $pm->setMode(PermissionManager::MODE_AUTO_EDIT);
        $this->assertTrue($pm->authorize('write_file', [], true, fn () => false));
        $this->assertFalse($pm->authorize('run_command', [], true, fn () => false));

        // Plan mode blocks all mutating actions
        $pm->setMode(PermissionManager::MODE_PLAN);
        $this->assertFalse($pm->authorize('write_file', [], true, fn () => true));
        $this->assertFalse($pm->authorize('run_command', [], true, fn () => true));
    }
}
