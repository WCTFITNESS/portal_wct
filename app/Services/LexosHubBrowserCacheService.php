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

        if ($refreshToken !== '') {
            $this->lexosHubSessionService->persistHubRefreshToken($refreshToken);
        }

        if ($accessToken !== '' && $this->lexosHubSessionService->isHubAccessValid($accessToken)) {
            $this->lexosHubSessionService->persistHubTokens($accessToken, $refreshToken);
        } elseif ($accessToken !== '') {
            $this->lexosHubSessionService->persistHubTokens($accessToken, $refreshToken);
        }

        $sessionOk = $this->lexosHubSessionService->maintainHubSession();
        $hasRefresh = $this->lexosCredentialsService->getHubRefreshToken() !== '';
        $hasAccess = $this->lexosCredentialsService->getHubAccessToken() !== '';

        if (!$sessionOk || !$hasAccess) {
            return [
                'ok' => false,
                'message' => $hasRefresh
                    ? 'Refresh Hub salvo, mas a renovação não gerou sessão válida para Produtos. Verifique se o refresh é de app-hub.lexos.com.br.'
                    : 'Tokens recebidos, mas nenhum refresh Hub foi salvo.',
                'has_refresh' => $hasRefresh,
                'has_access' => $hasAccess,
            ];
        }

        return [
            'ok' => true,
            'message' => 'Token Hub salvo no servidor e pronto para a aba Produtos.',
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
}
