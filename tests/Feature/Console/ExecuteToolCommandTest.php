<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Laravel\Boost\Mcp\ToolRegistry;
use Laravel\Boost\Mcp\Tools\DatabaseConnections;
use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Boost\Mcp\Tools\ReadLogEntries;
use Symfony\Component\Console\Command\Command;
use Tests\Fixtures\ThrowingTool;

beforeEach(function (): void {
    ToolRegistry::clearCache();
    config()->set('app.name', 'TestApp');
    config()->set('boost.mcp.tools.exclude', []);
});

afterEach(function (): void {
    config()->set('boost.mcp.tools.exclude', []);
    ToolRegistry::clearCache();
});

it('exits with error when the tool class is not in the registry', function (): void {
    $this->artisan('boost:execute-tool', [
        'tool' => 'App\\Fake\\NonExistentTool',
        'arguments' => base64_encode('{}'),
    ])->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Tool not registered or not allowed');
});

it('exits with error when the tool is in the exclude config', function (): void {
    config()->set('boost.mcp.tools.exclude', [DatabaseConnections::class]);
    ToolRegistry::clearCache();

    $this->artisan('boost:execute-tool', [
        'tool' => DatabaseConnections::class,
        'arguments' => base64_encode('{}'),
    ])->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Tool not registered or not allowed');
});

it('exits with error when decoded arguments contain invalid JSON', function (): void {
    $this->artisan('boost:execute-tool', [
        'tool' => DatabaseConnections::class,
        'arguments' => base64_encode('{not valid json'),
    ])->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Invalid arguments format');
});

it('outputs JSON with isError false on successful tool execution', function (): void {
    ob_start();
    $exitCode = Artisan::call('boost:execute-tool', [
        'tool' => DatabaseConnections::class,
        'arguments' => base64_encode('{}'),
    ]);
    $rawOutput = ob_get_clean();

    $json = json_decode($rawOutput, true);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($json)->toHaveKeys(['isError', 'content'])
        ->and($json['isError'])->toBeFalse();
});

it('outputs JSON with isError true when the tool returns an error response', function (): void {
    ob_start();
    Artisan::call('boost:execute-tool', [
        'tool' => DatabaseQuery::class,
        'arguments' => base64_encode(json_encode(['query' => 'DELETE FROM users'])),
    ]);
    $rawOutput = ob_get_clean();

    $json = json_decode($rawOutput, true);

    expect($json['isError'])->toBeTrue();
});

it('catches tool exceptions and outputs error JSON with failure exit code', function (): void {
    config()->set('boost.mcp.tools.include', [ThrowingTool::class]);
    ToolRegistry::clearCache();

    $this->artisan('boost:execute-tool', [
        'tool' => ThrowingTool::class,
        'arguments' => base64_encode('{}'),
    ])->assertExitCode(Command::FAILURE)
        ->expectsOutputToContain('Intentional test exception');
});

it('outputs valid JSON when tool content contains invalid UTF-8', function (): void {
    $logPath = storage_path('logs/invalid-utf8-'.uniqid().'.log');

    if (! is_dir(dirname($logPath))) {
        mkdir(dirname($logPath), 0755, true);
    }

    file_put_contents($logPath, "[2026-01-01 10:00:00] local.ERROR: bad binding \xE9\xFF here\n[2026-01-01 10:01:00] local.INFO: latest entry\n");
    config()->set('logging.default', 'single');
    config()->set('logging.channels.single', ['driver' => 'single', 'path' => $logPath]);

    ob_start();
    $exitCode = Artisan::call('boost:execute-tool', [
        'tool' => ReadLogEntries::class,
        'arguments' => base64_encode(json_encode(['entries' => 2])),
    ]);
    $rawOutput = ob_get_clean();

    unlink($logPath);

    $json = json_decode($rawOutput, true);

    expect($exitCode)->toBe(Command::SUCCESS)
        ->and($json['isError'])->toBeFalse()
        ->and($json['content'][0]['text'])->toContain('latest entry')
        ->and($json['content'][0]['text'])->toContain("\u{FFFD}");
});
