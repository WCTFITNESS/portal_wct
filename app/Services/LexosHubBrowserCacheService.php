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
    public const LS_CONTEXT = 'wct_lexos_hub_context';
    public const LS_SYNCED_AT = 'wct_lexos_hub_synced_at';

    public function __construct(
        private LexosHubSessionService $lexosHubSessionService,
        private LexosCredentialsService $lexosCredentialsService,
        private LexosHubContextParser $contextParser,
    ) {
    }

    /**
     * @param array<string, mixed> $hubContext
     * @return array{ok: bool, message: string, has_refresh: bool, has_access: bool, storage_keys: list<string>}
     */
    public function saveFromBrowser(string $accessToken, string $refreshToken, array $hubContext = []): array
    {
        $parsed = $this->contextParser->merge($accessToken, $refreshToken, $hubContext);
        $accessToken = $parsed['access'];
        $refreshToken = $parsed['refresh'];
        $hubContext = $parsed['context'];
        $storageKeys = array_keys($hubContext['local_storage'] ?? []);

        if ($hubContext !== []) {
            $this->lexosHubSessionService->persistHubContext($hubContext);
        }

        if ($accessToken === '' && $refreshToken === '') {
            return [
                'ok' => false,
                'message' => $storageKeys !== []
                    ? 'Sessão Hub capturada, mas nenhum token encontrado nas chaves: ' . implode(', ', array_slice($storageKeys, 0, 8))
                    : 'Nenhum token recebido do navegador.',
                'has_refresh' => false,
                'has_access' => false,
                'storage_keys' => $storageKeys,
            ];
        }

        if ($refreshToken !== '') {
            $this->lexosHubSessionService->persistHubRefreshToken($refreshToken);
        }

        if ($accessToken !== '') {
            $this->lexosHubSessionService->persistHubTokens($accessToken, $refreshToken);
            if ($this->lexosHubSessionService->isHubAccessValid($accessToken)) {
                return [
                    'ok' => true,
                    'message' => 'Token Hub salvo (mesmo fluxo do plugin Faturamento).',
                    'has_refresh' => $this->lexosCredentialsService->getHubRefreshToken() !== '',
                    'has_access' => true,
                    'storage_keys' => $storageKeys,
                ];
            }
        }

        if ($this->lexosHubSessionService->maintainHubSession()) {
            return [
                'ok' => true,
                'message' => 'Sessão Hub renovada e pronta para Produtos.',
                'has_refresh' => $this->lexosCredentialsService->getHubRefreshToken() !== '',
                'has_access' => $this->lexosCredentialsService->getHubAccessToken() !== '',
                'storage_keys' => $storageKeys,
            ];
        }

        $hasAccess = $this->lexosCredentialsService->getHubAccessToken() !== '';
        $hasRefresh = $this->lexosCredentialsService->getHubRefreshToken() !== '';

        return [
            'ok' => $hasAccess,
            'message' => $hasAccess
                ? 'Access token Hub salvo; aguarde sincronização ou recapture logado em app-hub.lexos.com.br.'
                : ($hasRefresh
                    ? 'Refresh salvo, mas access inválido. Faça login no Hub e clique no favorito Capturar Hub → Portal.'
                    : 'Capture a sessão com o favorito estando logado em app-hub.lexos.com.br.'),
            'has_refresh' => $hasRefresh,
            'has_access' => $hasAccess,
            'storage_keys' => $storageKeys,
        ];
    }

    public function buildBookmarklet(string $captureUrl): string
    {
        $captureUrl = json_encode($captureUrl, JSON_UNESCAPED_SLASHES);

        $js = <<<'JS'
javascript:(function(){
function dumpStorage(store){
 var out={},i,k;
 try{
  for(i=0;i<store.length;i++){
   k=store.key(i);
   if(k){out[k]=String(store.getItem(k)||'');}
  }
 }catch(e){}
 return out;
}
var storage=dumpStorage(localStorage);
var session=dumpStorage(sessionStorage);
var t=String(storage.access_token||storage.accessToken||'').trim();
var r=String(storage.refresh_token||storage.refreshToken||storage.hub_refresh_token||'').trim();
if(!t&&!r){
 for(var key in storage){
  var val=String(storage[key]||'');
  if(val.indexOf('eyJ')===0&&val.split('.').length===3){t=val;break;}
 }
}
if(!t&&!r){alert('Faça login em app-hub.lexos.com.br primeiro.');return;}
try{sessionStorage.setItem('wct_lexos_hub_last_capture',String(Date.now()));}catch(e){}
var f=document.createElement('form');
f.method='POST';
f.action=__CAPTURE_URL__;
f.target='_blank';
var fields={
 lexos_hub_token:t,
 lexos_hub_refresh_token:r,
 lexos_hub_storage:JSON.stringify(storage),
 lexos_hub_session_storage:JSON.stringify(session),
 lexos_hub_cookies:String(document.cookie||'')
};
Object.keys(fields).forEach(function(name){
 var input=document.createElement('input');
 input.type='hidden';
 input.name=name;
 input.value=fields[name];
 f.appendChild(input);
});
document.body.appendChild(f);
f.submit();
})();
JS;

        $js = str_replace('__CAPTURE_URL__', $captureUrl, $js);

        return preg_replace('/\s+/', '', $js) ?? $js;
    }

    /**
     * @return array{access: string, refresh: string, context: array<string, mixed>, synced_at: int}
     */
    public function buildLocalStorageBootstrapScript(string $accessToken, string $refreshToken, array $hubContext = []): array
    {
        return [
            'access' => $accessToken,
            'refresh' => $refreshToken,
            'context' => $hubContext,
            'synced_at' => time(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function parseHubContextFromRequest(array $payload): array
    {
        $context = $payload['lexos_hub_context'] ?? null;
        if (is_array($context)) {
            return $context;
        }

        $storage = $payload['lexos_hub_storage'] ?? $payload['local_storage'] ?? null;
        $session = $payload['lexos_hub_session_storage'] ?? $payload['session_storage'] ?? null;
        if (is_string($storage)) {
            $storage = json_decode($storage, true);
        }
        if (is_string($session)) {
            $session = json_decode($session, true);
        }

        return [
            'local_storage' => is_array($storage) ? $storage : [],
            'session_storage' => is_array($session) ? $session : [],
            'cookies' => trim((string) ($payload['lexos_hub_cookies'] ?? $payload['cookies'] ?? '')),
            'captured_at' => time(),
        ];
    }
}
