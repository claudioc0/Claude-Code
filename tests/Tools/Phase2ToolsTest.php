<?php

declare(strict_types=1);

namespace ClaudeCode\Tests\Tools;

use ClaudeCode\Permissions\PermissionManager;
use ClaudeCode\Tools\EditFileTool;
use ClaudeCode\Tools\GlobTool;
use ClaudeCode\Tools\GrepSearchTool;
use ClaudeCode\Ui\DiffRenderer;
use PHPUnit\Framework\TestCase;

final class Phase2ToolsTest extends TestCase
{
    private string $workspace;
    private PermissionManager $permissions;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc_p2_' . uniqid();
        mkdir($this->workspace . DIRECTORY_SEPARATOR . 'src', 0777, true);
        mkdir($this->workspace . DIRECTORY_SEPARATOR . 'vendor', 0777, true);

        file_put_contents($this->workspace . DIRECTORY_SEPARATOR . '.gitignore', "vendor/\n*.log\n");
        file_put_contents($this->workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.php', "<?php\nfunction helloWorld() {\n    return 'hi';\n}\n");
        file_put_contents($this->workspace . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'Ignored.php', "<?php\nfunction helloWorld() {}\n");
        file_put_contents($this->workspace . DIRECTORY_SEPARATOR . 'debug.log', "helloWorld\n");

        $this->permissions = new PermissionManager($this->workspace);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workspace);
    }

    public function testGlobRespectsGitIgnore(): void
    {
        $glob = new GlobTool($this->permissions);
        $result = $glob->execute(['pattern' => '**/*.php']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src/App.php', $result->output);
        $this->assertStringNotContainsString('vendor/Ignored.php', $result->output);
    }

    public function testGrepSearchFindsMatchesAndSkipsIgnored(): void
    {
        $grep = new GrepSearchTool($this->permissions);
        $result = $grep->execute(['query' => 'helloWorld']);

        $this->assertFalse($result->isError);
        $this->assertStringContainsString('src/App.php:2:', $result->output);
        $this->assertStringNotContainsString('vendor/Ignored.php', $result->output);
        $this->assertStringNotContainsString('debug.log', $result->output);
    }

    public function testEditFileValidatesUniquenessAndReplaces(): void
    {
        $edit = new EditFileTool($this->permissions);

        $missing = $edit->execute([
            'path' => 'src/App.php',
            'old_string' => 'notFound',
            'new_string' => 'x',
        ]);
        $this->assertTrue($missing->isError);

        $ok = $edit->execute([
            'path' => 'src/App.php',
            'old_string' => "return 'hi';",
            'new_string' => "return 'hello';",
        ]);
        $this->assertFalse($ok->isError);
        $this->assertStringContainsString(
            "return 'hello';",
            (string) file_get_contents($this->workspace . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'App.php')
        );
    }

    public function testDiffRendererOutputsColoredAndPlainDiff(): void
    {
        $plain = new DiffRenderer(false);
        $diff = $plain->renderEditDiff('src/App.php', "old line", "new line");

        $this->assertStringContainsString('--- a/src/App.php', $diff);
        $this->assertStringContainsString('+++ b/src/App.php', $diff);
        $this->assertStringContainsString('- old line', $diff);
        $this->assertStringContainsString('+ new line', $diff);

        $colored = new DiffRenderer(true);
        $this->assertStringContainsString("\033[31m- old line\033[0m", $colored->renderEditDiff('src/App.php', 'old line', 'new line'));
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
