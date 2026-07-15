const express = require('express');
const { uploadFiles } = require('../../controllers/uploadImagesController');
const { processAndZip } = require('../../controllers/compressedImagesController');

const router = express.Router();

router.post("/", uploadFiles.any(), async (req, res) => {
    if (!req.files || req.files.length === 0) {
        return res.status(400).send({ error: 'Nenhum arquivo foi enviado.' });
    }

    try {
        // Aguarda a função processAndZip terminar antes de enviar a resposta
        await processAndZip();

        // Envia a resposta apenas depois que o processamento terminar
        res.status(200).send({
            message: 'Upload de múltiplos arquivos realizado com sucesso!',
            files: req.files.map(file => ({
                fileName: file.filename,
                path: file.path
            }))
        });
    } catch (error) {
        console.error('Erro durante o processamento:', error);
        res.status(500).send({ error: 'Erro ao processar os arquivos.' });
    }
});


module.exports = router;