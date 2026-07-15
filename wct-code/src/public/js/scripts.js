function showTrigger(status) {
    // Selecionar o contêiner onde o trigger será inserido
    const triggerBox = document.getElementById('trigger_box');

    // Verificar se o trigger já existe
    let trigger = document.getElementById('trigger');
    if (!trigger) {
        // Criar o elemento div dinamicamente se ele ainda não existir
        trigger = document.createElement('div');
        trigger.id = 'trigger';
        trigger.classList.add('visible'); // Adiciona classe para estilos ou animações
        triggerBox.appendChild(trigger);
    }

    // Atualizar o conteúdo do trigger
    trigger.textContent = `${status}...`;
    trigger.classList.remove('hidden'); // Certifique-se de que ele está visível
    trigger.classList.add('visible');
}

function hideTrigger() {
    // Selecionar o trigger existente
    const trigger = document.getElementById('trigger');
    if (trigger) {
        trigger.classList.remove('visible'); // Remover a classe 'visible' para ocultar o trigger
        trigger.classList.add('hidden'); // Adicionar a classe 'hidden' para garantir que o trigger está oculto
    }
}
