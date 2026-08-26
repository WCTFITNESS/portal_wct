<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PortalUserRepository;

class PortalAuthService
{
    public const SESSION_USER_ID = 'portal_user_id';

    /** @var array<string, array{label: string, pages: list<string>}>|null */
    private static ?array $modulesCache = null;

    public function __construct(
        private PortalUserRepository $users,
        private MailService $mail,
        private string $baseUrl = '/'
    ) {
    }

    public function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $this->users->ensureTable();
        $this->seedDefaultAdminIfEmpty();
    }

    /**
     * @return array<string, array{label: string, pages: list<string>}>
     */
    public static function modulesCatalog(): array
    {
        if (self::$modulesCache !== null) {
            return self::$modulesCache;
        }

        self::$modulesCache = [
            'organizacao' => [
                'label' => 'Organização',
                'pages' => ['tasks', 'rastreamento-ssw'],
            ],
            'mercado_livre' => [
                'label' => 'Mercado Livre',
                'pages' => [
                    'ml-dashboard', 'api-config', 'orders', 'ml-catalogos',
                    'ml-campanhas', 'ml-campanhas-pendentes', 'ml-campanhas-ativas',
                    'ml-anuncios-inativos', 'ml-ads-report', 'ml-redimensionar',
                    'message-template', 'manual-send',
                ],
            ],
            'lexos' => [
                'label' => 'Lexos',
                'pages' => [
                    'dashboard', 'monitor-pedidos', 'lexos-transportadoras',
                    'lexos-diagnostico-expedicao', 'lexos-hub-connect',
                ],
            ],
            'mercado_pago' => [
                'label' => 'Mercado Pago',
                'pages' => ['repasse-mp'],
            ],
            'protheus' => [
                'label' => 'Protheus',
                'pages' => [
                    'protheus-config', 'protheus-monitor-romaneio', 'protheus-monitor-pedidos',
                    'protheus-monitor-nfe', 'protheus-consulta-edi', 'protheus-ler-edi',
                    'protheus-monitor-pedidos-erro', 'protheus-consulta-sql',
                ],
            ],
            'integracao' => [
                'label' => 'Integração',
                'pages' => ['tracking-reprocess', 'find-cep'],
            ],
            'wct_code' => [
                'label' => 'WCT CODE',
                'pages' => [
                    'wct-code-dashboard', 'wct-code-campanhas', 'wct-code-campanhas-pendentes',
                    'wct-code-campanhas-ativas', 'wct-code-inactive-ads', 'wct-code-anuncios',
                    'wct-code-images', 'wct-code-frete',
                ],
            ],
            'configuracoes' => [
                'label' => 'Configurações',
                'pages' => ['config-usuarios'],
            ],
        ];

        return self::$modulesCache;
    }

    public static function moduleForPage(string $page): ?string
    {
        foreach (self::modulesCatalog() as $key => $module) {
            if (in_array($page, $module['pages'], true)) {
                return $key;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function publicPages(): array
    {
        return ['login', 'forgot-password', 'reset-password', 'health', 'logout'];
    }

    public function currentUser(): ?array
    {
        $id = (int) ($_SESSION[self::SESSION_USER_ID] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $user = $this->users->findById($id);
        if ($user === null || empty($user['is_active'])) {
            unset($_SESSION[self::SESSION_USER_ID]);

            return null;
        }

        return $user;
    }

    public function isLoggedIn(): bool
    {
        return $this->currentUser() !== null;
    }

    public function attemptLogin(string $email, string $password): bool
    {
        $user = $this->users->findByEmail($email);
        if ($user === null || empty($user['is_active'])) {
            return false;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_ID] = (int) $user['id'];

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::SESSION_USER_ID]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function canAccessPage(?array $user, string $page): bool
    {
        if (in_array($page, self::publicPages(), true)) {
            return true;
        }
        if ($user === null) {
            return false;
        }
        if (!empty($user['is_admin'])) {
            return true;
        }

        $module = self::moduleForPage($page);
        if ($module === null) {
            // Páginas sem módulo mapeado: só admin (segurança)
            return false;
        }

        $allowed = is_array($user['modules'] ?? null) ? $user['modules'] : [];

        return in_array($module, $allowed, true);
    }

    /**
     * @param array<string, list<array<string, mixed>>> $menuSections
     * @return array<string, list<array<string, mixed>>>
     */
    public function filterMenuSections(array $menuSections, ?array $user): array
    {
        if ($user === null) {
            return [];
        }
        if (!empty($user['is_admin'])) {
            return $menuSections;
        }

        $allowedModules = is_array($user['modules'] ?? null) ? $user['modules'] : [];
        $filtered = [];
        foreach ($menuSections as $sectionTitle => $items) {
            $moduleKey = $this->moduleKeyBySectionLabel((string) $sectionTitle);
            if ($moduleKey === null || !in_array($moduleKey, $allowedModules, true)) {
                continue;
            }
            $filtered[$sectionTitle] = $items;
        }

        return $filtered;
    }

    public function firstAllowedPage(?array $user): string
    {
        if ($user === null) {
            return 'login';
        }
        if (!empty($user['is_admin'])) {
            return 'dashboard';
        }

        $modules = is_array($user['modules'] ?? null) ? $user['modules'] : [];
        foreach (self::modulesCatalog() as $key => $module) {
            if (!in_array($key, $modules, true)) {
                continue;
            }
            $pages = $module['pages'] ?? [];
            if ($pages !== []) {
                return (string) $pages[0];
            }
        }

        return 'login';
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function requestPasswordReset(string $email): array
    {
        $generic = 'Se o e-mail existir em nossa base, enviaremos instruções para redefinir a senha.';
        $user = $this->users->findByEmail($email);
        if ($user === null || empty($user['is_active'])) {
            return ['ok' => true, 'message' => $generic];
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = (new \DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');
        $this->users->setResetToken((int) $user['id'], $hash, $expires);

        $resetUrl = portal_wct_absolute_url(
            $this->baseUrl,
            'index.php?page=reset-password&token=' . urlencode($token)
        );

        $name = (string) ($user['name'] ?? 'usuário');
        $text = "Olá {$name},\n\n"
            . "Recebemos um pedido para redefinir sua senha no Portal WCT.\n"
            . "Abra o link abaixo (válido por 1 hora):\n\n"
            . "{$resetUrl}\n\n"
            . "Se você não pediu isso, ignore este e-mail.\n";
        $html = '<p>Olá <strong>' . htmlspecialchars($name) . '</strong>,</p>'
            . '<p>Recebemos um pedido para redefinir sua senha no Portal WCT.</p>'
            . '<p><a href="' . htmlspecialchars($resetUrl) . '">Redefinir senha</a></p>'
            . '<p>O link vale por 1 hora. Se você não pediu isso, ignore este e-mail.</p>';

        $sent = $this->mail->send((string) $user['email'], 'Portal WCT — redefinir senha', $text, $html);
        if (!$sent['ok']) {
            return [
                'ok' => false,
                'message' => 'Não foi possível enviar o e-mail: ' . ($sent['error'] ?: 'erro desconhecido'),
            ];
        }

        return ['ok' => true, 'message' => $generic];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function resetPasswordWithToken(string $token, string $newPassword): array
    {
        $token = trim($token);
        if ($token === '' || strlen($newPassword) < 4) {
            return ['ok' => false, 'message' => 'Informe o token e uma senha com pelo menos 4 caracteres.'];
        }

        $user = $this->users->findByResetTokenHash(hash('sha256', $token));
        if ($user === null) {
            return ['ok' => false, 'message' => 'Link inválido ou já utilizado.'];
        }

        $expires = trim((string) ($user['reset_expires_at'] ?? ''));
        if ($expires === '' || strtotime($expires) < time()) {
            return ['ok' => false, 'message' => 'Este link de redefinição expirou. Solicite outro.'];
        }

        $this->users->updatePassword((int) $user['id'], password_hash($newPassword, PASSWORD_DEFAULT));

        return ['ok' => true, 'message' => 'Senha atualizada. Você já pode entrar.'];
    }

    /**
     * @param list<string> $modules
     * @return array{ok: bool, message: string, id?: int}
     */
    public function createUser(
        string $name,
        string $email,
        string $password,
        bool $isAdmin,
        bool $isActive,
        array $modules
    ): array {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Informe nome e e-mail válidos.'];
        }
        if (strlen($password) < 4) {
            return ['ok' => false, 'message' => 'A senha deve ter pelo menos 4 caracteres.'];
        }
        if ($this->users->findByEmail($email) !== null) {
            return ['ok' => false, 'message' => 'Já existe um usuário com este e-mail.'];
        }

        $modules = $this->sanitizeModules($modules, $isAdmin);
        $id = $this->users->create(
            $name,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $isAdmin,
            $isActive,
            $modules
        );

        return ['ok' => true, 'message' => 'Usuário criado.', 'id' => $id];
    }

    /**
     * @param list<string> $modules
     * @return array{ok: bool, message: string}
     */
    public function updateUser(
        int $id,
        string $name,
        string $email,
        bool $isAdmin,
        bool $isActive,
        array $modules,
        string $newPassword = ''
    ): array {
        $existing = $this->users->findById($id);
        if ($existing === null) {
            return ['ok' => false, 'message' => 'Usuário não encontrado.'];
        }

        $name = trim($name);
        $email = strtolower(trim($email));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'Informe nome e e-mail válidos.'];
        }

        $other = $this->users->findByEmail($email);
        if ($other !== null && (int) $other['id'] !== $id) {
            return ['ok' => false, 'message' => 'Já existe um usuário com este e-mail.'];
        }

        if ($newPassword !== '' && strlen($newPassword) < 4) {
            return ['ok' => false, 'message' => 'A nova senha deve ter pelo menos 4 caracteres.'];
        }

        $modules = $this->sanitizeModules($modules, $isAdmin);
        $hash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : null;
        $this->users->update($id, $name, $email, $isAdmin, $isActive, $modules, $hash);

        return ['ok' => true, 'message' => 'Usuário atualizado.'];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function deleteUser(int $id, ?array $actor): array
    {
        if ($actor !== null && (int) ($actor['id'] ?? 0) === $id) {
            return ['ok' => false, 'message' => 'Você não pode excluir o próprio usuário logado.'];
        }
        if ($this->users->findById($id) === null) {
            return ['ok' => false, 'message' => 'Usuário não encontrado.'];
        }
        $this->users->delete($id);

        return ['ok' => true, 'message' => 'Usuário removido.'];
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        return $this->users->listUsers();
    }

    public function findUser(int $id): ?array
    {
        return $this->users->findById($id);
    }

    private function seedDefaultAdminIfEmpty(): void
    {
        if ($this->users->countUsers() > 0) {
            return;
        }

        $allModules = array_keys(self::modulesCatalog());
        $this->users->create(
            'Soluções WCT',
            'solucoes@wct.com.br',
            password_hash('teste', PASSWORD_DEFAULT),
            true,
            true,
            $allModules
        );
    }

    /**
     * @param list<string> $modules
     * @return list<string>
     */
    private function sanitizeModules(array $modules, bool $isAdmin): array
    {
        if ($isAdmin) {
            return array_keys(self::modulesCatalog());
        }

        $valid = array_keys(self::modulesCatalog());
        $out = [];
        foreach ($modules as $mod) {
            $key = trim((string) $mod);
            if (in_array($key, $valid, true)) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    private function moduleKeyBySectionLabel(string $sectionTitle): ?string
    {
        foreach (self::modulesCatalog() as $key => $module) {
            if (($module['label'] ?? '') === $sectionTitle) {
                return $key;
            }
        }

        return null;
    }
}
