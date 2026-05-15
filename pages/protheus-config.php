<?php

declare(strict_types=1);

$feedback = null;
$feedbackClass = 'ok';
$settings = $app['protheusSettingsRepository']->getSettings();
$driverAvailable = $app['protheusConnectionService']->isDriverAvailable();
$driversLabel = $app['protheusConnectionService']->availableDrivers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $formType = (string) ($_POST['form_type'] ?? '');

        if ($formType === 'protheus_save') {
            $app['protheusSettingsRepository']->saveSettings([
                'host' => (string) ($_POST['host'] ?? ''),
                'database_name' => (string) ($_POST['database_name'] ?? ''),
                'port' => (string) ($_POST['port'] ?? '1433'),
                'username' => (string) ($_POST['username'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
            ]);
            $settings = $app['protheusSettingsRepository']->getSettings();
            $feedback = 'Configuracao Protheus salva.';
        }

        if ($formType === 'protheus_test') {
            $usePosted = trim((string) ($_POST['host'] ?? '')) !== '';
            $testSettings = $usePosted ? [
                'host' => (string) ($_POST['host'] ?? ''),
                'database_name' => (string) ($_POST['database_name'] ?? ''),
                'port' => (string) ($_POST['port'] ?? '1433'),
                'username' => (string) ($_POST['username'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
            ] : $settings;

            if ($usePosted && ($testSettings['password'] ?? '') === '' && is_array($settings)) {
                $testSettings['password'] = (string) ($settings['password'] ?? '');
            }

            $feedback = $app['protheusConnectionService']->testConnection(
                is_array($testSettings) ? $testSettings : null
            );
        }
    } catch (Throwable $exception) {
        $feedback = 'Erro: ' . $exception->getMessage();
        $feedbackClass = 'err';
    }
}

$host = (string) ($settings['host'] ?? '');
$databaseName = (string) ($settings['database_name'] ?? '');
$port = (string) ($settings['port'] ?? '1433');
$username = (string) ($settings['username'] ?? '');
?>
<section class="card">
    <h1>Config Protheus</h1>
    <p>Conexao com o banco SQL Server do TOTVS Protheus (driver PHP: <code><?= htmlspecialchars($driversLabel) ?></code>).</p>

    <?php if (!$driverAvailable): ?>
        <p class="msg err">Instale <strong>pdo_sqlsrv</strong> (Windows) ou <strong>pdo_dblib</strong> no PHP para habilitar a integracao.</p>
    <?php endif; ?>

    <?php if ($feedback !== null): ?>
        <p class="msg <?= htmlspecialchars($feedbackClass) ?>"><?= htmlspecialchars($feedback) ?></p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="form_type" value="protheus_save">
        <label>Host</label>
        <input type="text" name="host" value="<?= htmlspecialchars($host) ?>" placeholder="192.168.0.10 ou servidor\instancia" required>

        <label>Banco</label>
        <input type="text" name="database_name" value="<?= htmlspecialchars($databaseName) ?>" placeholder="Nome do database" required>

        <label>Porta</label>
        <input type="number" name="port" value="<?= htmlspecialchars($port) ?>" min="1" max="65535" required>

        <label>Usuario</label>
        <input type="text" name="username" value="<?= htmlspecialchars($username) ?>" autocomplete="username" required>

        <label>Senha</label>
        <input type="password" name="password" value="" autocomplete="current-password" placeholder="<?= $settings ? 'Deixe em branco para manter a senha salva' : 'Obrigatoria na primeira vez' ?>">

        <button type="submit"<?= $driverAvailable ? '' : ' disabled' ?>>Salvar configuracao</button>
    </form>

    <form method="post" style="margin-top:8px;">
        <input type="hidden" name="form_type" value="protheus_test">
        <input type="hidden" name="host" value="<?= htmlspecialchars($host) ?>">
        <input type="hidden" name="database_name" value="<?= htmlspecialchars($databaseName) ?>">
        <input type="hidden" name="port" value="<?= htmlspecialchars($port) ?>">
        <input type="hidden" name="username" value="<?= htmlspecialchars($username) ?>">
        <button type="submit"<?= $driverAvailable && $settings ? '' : ' disabled' ?>>Testar conexao (config salva)</button>
    </form>
</section>
