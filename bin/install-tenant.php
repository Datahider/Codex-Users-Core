#!/usr/bin/env php
<?php

declare(strict_types=1);

const USAGE = 'Usage: php bin/install-tenant.php /path/to/tenant.ini';

if (($argv[1] ?? null) === '--help' && count($argv) === 2) {
    fwrite(STDOUT, USAGE . PHP_EOL);
    exit(0);
}

$validate_only = ($argv[1] ?? null) === '--validate';
$config_path = $validate_only ? ($argv[2] ?? null) : ($argv[1] ?? null);
$expected_count = $validate_only ? 3 : 2;

if (count($argv) !== $expected_count || !is_string($config_path) || $config_path === '') {
    fwrite(STDERR, USAGE . PHP_EOL);
    exit(1);
}

try {
    $input = loadInput($config_path);

    if ($validate_only) {
        fwrite(STDOUT, "Tenant installer config: OK\n");
        exit(0);
    }

    installTenant($input);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Tenant installation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @return array{username:string,telegram_chat_id:string,transcription_api_key:string}
 */
function loadInput(string $config_path): array
{
    if (!is_file($config_path) || !is_readable($config_path)) {
        throw new RuntimeException('INI file must exist and be readable');
    }

    if (fileowner($config_path) !== posix_geteuid()) {
        throw new RuntimeException('INI file must belong to the current user');
    }

    if ((fileperms($config_path) & 0777) !== 0600) {
        throw new RuntimeException('INI file permissions must be exactly 0600');
    }

    $input = parse_ini_file($config_path, false, INI_SCANNER_RAW);
    if (!is_array($input)) {
        throw new RuntimeException('Cannot parse INI file');
    }

    $expected_keys = ['telegram_chat_id', 'transcription_api_key', 'username'];
    $actual_keys = array_keys($input);
    sort($actual_keys);
    if ($actual_keys !== $expected_keys) {
        throw new RuntimeException('INI file must contain only username, telegram_chat_id and transcription_api_key');
    }

    $username = trim((string) $input['username']);
    $telegram_chat_id = trim((string) $input['telegram_chat_id']);
    $transcription_api_key = trim((string) $input['transcription_api_key']);

    if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username) !== 1) {
        throw new RuntimeException('Invalid username');
    }
    if (preg_match('/^-?[1-9][0-9]*$/', $telegram_chat_id) !== 1) {
        throw new RuntimeException('Invalid Telegram chat ID');
    }
    if ($transcription_api_key === '') {
        throw new RuntimeException('Transcription API key cannot be empty');
    }

    return [
        'username' => $username,
        'telegram_chat_id' => $telegram_chat_id,
        'transcription_api_key' => $transcription_api_key,
    ];
}

/**
 * @param array{username:string,telegram_chat_id:string,transcription_api_key:string} $input
 */
function installTenant(array $input): void
{
    $project_root = dirname(__DIR__);
    $family_root = dirname($project_root);
    $router_deploy = loadDeployConfig($family_root . '/Router/deploy.env');
    $telegram_deploy = loadDeployConfig($family_root . '/Transport-Telegram/deploy.env');

    $username = $input['username'];
    $home = '/home/' . $username;
    $core_dir = $home . '/Codex-Users-Core';
    $core_token = bin2hex(random_bytes(24));

    run(['sudo', '/var/tmp/codex-limited-sudo/create-user', $username]);

    $user_script = buildUserInstallScript(
        $username,
        $home,
        $core_dir,
        $core_token,
        $input['transcription_api_key']
    );
    run(['sudo', '/var/tmp/codex-limited-sudo/become-user', $username], $user_script);

    provisionRouter($router_deploy, $username, $core_token);
    provisionTelegram($telegram_deploy, $username, $input['telegram_chat_id']);

    $service = 'codex-core@' . $username . '.service';
    run(['sudo', '-n', 'systemctl', 'enable', '--now', $service]);
    run(['sudo', '-n', 'systemctl', 'restart', $service]);
    $status = trim(run(['systemctl', 'is-active', $service]));
    if ($status !== 'active') {
        throw new RuntimeException("Service $service is not active");
    }

    fwrite(STDOUT, "Tenant installed: $username\n");
    fwrite(STDOUT, "Telegram chat ID: {$input['telegram_chat_id']}\n");
    fwrite(STDOUT, "Service: $service ($status)\n");
}

/**
 * @return array<string, string>
 */
function loadDeployConfig(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException("Deploy config is not readable: $path");
    }

    $config = parse_ini_file($path, false, INI_SCANNER_RAW);
    if (!is_array($config)) {
        throw new RuntimeException("Cannot parse deploy config: $path");
    }

    return array_map(static fn (mixed $value): string => (string) $value, $config);
}

function buildUserInstallScript(
    string $username,
    string $home,
    string $core_dir,
    string $core_token,
    string $transcription_api_key
): string {
    $config = "<?php\n\n"
        . "return [\n"
        . "    'codex' => [\n"
        . "        'bin' => 'codex',\n"
        . "        'cwd' => " . var_export($home, true) . ",\n"
        . "        'extra_args' => [\n"
        . "            '--skip-git-repo-check',\n"
        . "            '--dangerously-bypass-approvals-and-sandbox',\n"
        . "            '--json',\n"
        . "        ],\n"
        . "    ],\n"
        . "    'router' => [\n"
        . "        'base_url' => 'https://cdx-router.botmeister.ru',\n"
        . "        'core_token' => " . var_export($core_token, true) . ",\n"
        . "    ],\n"
        . "    'transcription' => [\n"
        . "        'api_key' => " . var_export($transcription_api_key, true) . ",\n"
        . "        'model' => 'gpt-transcribe',\n"
        . "    ],\n"
        . "    'storage' => [\n"
        . "        'root' => " . var_export($home . '/var/codex-users-core', true) . ",\n"
        . "    ],\n"
        . "];\n";

    $delimiter = '__CODEX_TENANT_CONFIG_' . bin2hex(random_bytes(8));
    if (str_contains($config, $delimiter)) {
        throw new RuntimeException('Generated config delimiter collision');
    }

    return "set -euo pipefail\n"
        . "git clone https://github.com/Datahider/Codex-Users-Core.git " . shellQuote($core_dir) . "\n"
        . "cd " . shellQuote($core_dir) . "\n"
        . "composer install --no-dev --prefer-dist --no-interaction\n"
        . "mkdir -p " . shellQuote($home . '/.codex-users-core') . "\n"
        . "umask 077\n"
        . "cat > " . shellQuote($home . '/.codex-users-core/config.php') . " <<'$delimiter'\n"
        . $config
        . "$delimiter\n"
        . "php -l " . shellQuote($home . '/.codex-users-core/config.php') . "\n";
}

/** @param array<string, string> $deploy */
function provisionRouter(array $deploy, string $username, string $core_token): void
{
    $host = requireDeployValue($deploy, 'ROUTER_DEPLOY_HOST');
    $port = requireDeployValue($deploy, 'ROUTER_DEPLOY_PORT');
    $user = requireDeployValue($deploy, 'ROUTER_V1_USER');
    $dir = requireDeployValue($deploy, 'ROUTER_V1_DIR');

    $script = '<?php ' .
        'require "vendor/autoload.php"; ' .
        '$config=require "etc/config.php"; ' .
        '$runtime=new CodexMultitenant\\Router\\RouterRuntime($config); ' .
        '$runtime->bootstrap(); ' .
        'try {$tenant=new CodexMultitenant\\Router\\Data\\Tenant(["tenant"=>' . var_export($username, true) . ']);} ' .
        'catch (Exception $e) {if ((int)$e->getCode()!==-10002) throw $e; ' .
        '$tenant=new CodexMultitenant\\Router\\Data\\Tenant(); $tenant->tenant=' . var_export($username, true) . ';} ' .
        '$tenant->core_token_sha256=hash("sha256",' . var_export($core_token, true) . '); ' .
        '$tenant->write(); echo "Router tenant: ".$tenant->tenant.PHP_EOL;';

    run(sshCommand($port, "$user@$host", "cd " . shellQuote($dir) . ' && php'), $script);
}

/** @param array<string, string> $deploy */
function provisionTelegram(array $deploy, string $username, string $telegram_chat_id): void
{
    $host = requireDeployValue($deploy, 'TELEGRAM_TRANSPORT_DEPLOY_HOST');
    $port = requireDeployValue($deploy, 'TELEGRAM_TRANSPORT_DEPLOY_PORT');
    $user = requireDeployValue($deploy, 'TELEGRAM_TRANSPORT_DEPLOY_USER');
    $dir = requireDeployValue($deploy, 'TELEGRAM_TRANSPORT_REMOTE_APP_DIR');

    $script = '<?php ' .
        'require "vendor/autoload.php"; ' .
        '$config=require "etc/config.php"; $db=$config["db"]; ' .
        'losthost\\DB\\DB::connect($db["host"],$db["user"],$db["pass"],$db["name"],$db["prefix"]); ' .
        'try {$binding=new CodexMultitenant\\TransportTelegram\\Data\\ChatTenantBinding(["chat_id"=>' . var_export($telegram_chat_id, true) . ']);} ' .
        'catch (Exception $e) {if ((int)$e->getCode()!==-10002) throw $e; ' .
        '$binding=new CodexMultitenant\\TransportTelegram\\Data\\ChatTenantBinding(); ' .
        '$binding->chat_id=' . var_export($telegram_chat_id, true) . ';} ' .
        '$binding->tenant=' . var_export($username, true) . '; $binding->write(); ' .
        'echo "Telegram binding: ".$binding->chat_id." -> ".$binding->tenant.PHP_EOL;';

    run(sshCommand($port, "$user@$host", "cd " . shellQuote($dir) . ' && php'), $script);
}

/** @return list<string> */
function sshCommand(string $port, string $remote, string $remote_command): array
{
    return [
        'ssh',
        '-p', $port,
        '-o', 'StrictHostKeyChecking=no',
        '-o', 'UserKnownHostsFile=/dev/null',
        $remote,
        $remote_command,
    ];
}

/** @param array<string, string> $config */
function requireDeployValue(array $config, string $key): string
{
    $value = trim($config[$key] ?? '');
    if ($value === '') {
        throw new RuntimeException("Missing deploy value: $key");
    }

    return $value;
}

/** @param list<string> $command */
function run(array $command, ?string $stdin = null): string
{
    $pipes = [];
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start command: ' . $command[0]);
    }

    fwrite($pipes[0], $stdin ?? '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit_code = proc_close($process);

    if ($stdout !== false && $stdout !== '') {
        fwrite(STDOUT, $stdout);
    }
    if ($stderr !== false && $stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    if ($exit_code !== 0) {
        throw new RuntimeException('Command failed: ' . $command[0] . " (exit $exit_code)");
    }

    return $stdout === false ? '' : $stdout;
}

function shellQuote(string $value): string
{
    return "'" . str_replace("'", "'\\''", $value) . "'";
}
