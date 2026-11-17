<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Tools;

use ClaudeCode\Permissions\PermissionManager;
use ClaudeCode\Tools\ReadFileTool;
use ClaudeCode\Tools\RunCommandTool;
use ClaudeCode\Tools\WriteFileTool;
use PHPUnit\Framework\TestCase;

final class ToolsTest extends TestCase
{
    private string $workspace;
    private PermissionManager $permissions;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_tools_' . uniqid();
        mkdir($this->workspace, 0777, true);
        $this->permissions = new PermissionManager($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workspace);
    }

    public function testWriteAndReadFileWithPagination(): void
    {
        $writer = new WriteFileTool($this->permissions);
        $writeResult = $writer->execute([
            'path' => 'nested/hello.txt',
            'content' => "linha1\nlinha2\nlinha3\nlinha4",
        ]);

        $this->assertFalse($writeResult->isError);
        $this->assertFileExists($this->workspace . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'hello.txt');

        $reader = new ReadFileTool($this->permissions);
        $readResult = $reader->execute([
            'path' => 'nested/hello.txt',
            'start_line' => 2,
            'end_line' => 3,
        ]);

        $this->assertFalse($readResult->isError);
        $this->assertStringContainsString('2: linha2', $readResult->output);
        $this->assertStringContainsString('3: linha3', $readResult->output);
        $this->assertStringContainsString('1 linhas restantes', $readResult->output);
    }

    public function testRunCommandCapturesOutput(): void
    {
        $runner = new RunCommandTool($this->permissions, 10);
        $result = $runner->execute(['command' => 'php -r "echo \'ok-123\';"']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('Exit code: 0', $result->output);
        $this->assertStringContainsString('ok-123', $result->output);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
