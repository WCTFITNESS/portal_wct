<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Captura tokens do Hub Lexos no navegador e mantém cache (localStorage + servidor).
 */
final class LexosHubBrowserCacheService
{
    public const LS_ACCESS = 'wct_lexos_hub_access';
    public const LS_REFRESH = 'wct_lexos_hub_refresh';
    public const LS_SYNCED_AT = 'wct_lexos_hub_synced_at';

    public function __construct(
        private LexosHubSessionService $lexosHubSessionService,
        private LexosCredentialsService $lexosCredentialsService,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, has_refresh: bool, has_access: bool}
     */
    public function saveFromBrowser(string $accessToken, string $refreshToken): array
    {
        $accessToken = trim($accessToken);
        $refreshToken = trim($refreshToken);

        if ($accessToken === '' && $refreshToken === '') {
            return [
                'ok' => false,
                'message' => 'Nenhum token recebido do navegador.',
                'has_refresh' => false,
                'has_access' => false,
            ];
        }

        $this->lexosHubSessionService->persistHubTokens($accessToken, $refreshToken);

        if ($accessToken === '' || $this->needsRefresh($accessToken)) {
            $this->lexosHubSessionService->refreshHubAccessToken();
        }

        $hasRefresh = $this->lexosCredentialsService->getHubRefreshToken() !== '';
        $hasAccess = $this->lexosCredentialsService->getHubAccessToken() !== '';

        if (!$hasAccess && !$hasRefresh) {
            return [
                'ok' => false,
                'message' => 'Tokens salvos, mas o refresh não gerou sessão válida para Produtos.',
                'has_refresh' => false,
                'has_access' => false,
            ];
        }

        return [
            'ok' => true,
            'message' => $hasAccess
                ? 'Token Hub salvo no servidor e pronto para a aba Produtos.'
                : 'Refresh salvo; aguardando renovação do access token.',
            'has_refresh' => $hasRefresh,
            'has_access' => $hasAccess,
        ];
    }

    public function buildBookmarklet(string $captureUrl): string
    {
        $captureUrl = json_encode($captureUrl, JSON_UNESCAPED_SLASHES);
        $lsAccess = self::LS_ACCESS;
        $lsRefresh = self::LS_REFRESH;
        $lsSynced = self::LS_SYNCED_AT;

        $js = <<<JS
javascript:(function(){
var t=localStorage.getItem('access_token')||'';
var r=localStorage.getItem('refresh_token')||localStorage.getItem('refreshToken')||'';
if(!t&&!r){alert('Faça login em app-hub.lexos.com.br primeiro.');return;}
try{
 sessionStorage.setItem('wct_lexos_hub_last_capture',String(Date.now()));
}catch(e){}
var f=document.createElement('form');
f.method='POST';
f.action={$captureUrl};
f.target='_blank';
var fields={lexos_hub_token:String(t).trim(),lexos_hub_refresh_token:String(r).trim()};
Object.keys(fields).forEach(function(k){
 var i=document.createElement('input');
 i.type='hidden';
 i.name=k;
 i.value=fields[k];
 f.appendChild(i);
});
document.body.appendChild(f);
f.submit();
})();
JS;

        return preg_replace('/\s+/', '', $js) ?? $js;
    }

    /**
     * @return array{access: string, refresh: string, synced_at: int}
     */
    public function buildLocalStorageBootstrapScript(string $accessToken, string $refreshToken): array
    {
        return [
            'access' => $accessToken,
            'refresh' => $refreshToken,
            'synced_at' => time(),
        ];
    }

    private function needsRefresh(string $accessToken): bool
    {
        $parts = explode('.', $accessToken);
        if (count($parts) < 2) {
            return false;
        }
        $payloadRaw = strtr($parts[1], '-_', '+/');
        $pad = strlen($payloadRaw) % 4;
        if ($pad > 0) {
            $payloadRaw .= str_repeat('=', 4 - $pad);
        }
        $payload = json_decode((string) base64_decode($payloadRaw), true);
        if (!is_array($payload) || !isset($payload['exp'])) {
            return false;
        }

        return time() >= ((int) $payload['exp'] - 300);
    }
}
