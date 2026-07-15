const express = require('express');
const { upload } = require('../../controllers/uploadCampaignController');
const { excelRead } = require('../../controllers/processFileCampaignController');

const router = express.Router();

router.post("/", upload.single('file'), async (req, res) => {
    if (!req.file) {
        return res.status(400).send({ error: 'Nenhum arquivo foi enviado.' });
    }

    console.log("Arquivo recebido:", req.file);

    // ✅ Passar apenas o caminho do arquivo para a função
    await excelRead(req.file.path);

    console.log("Executando após 5 segundos!");
    res.status(200).send({
        message: 'Upload realizado com sucesso após 5 segundos!',
        fileName: req.file.filename,
        path: req.file.path
    });

});


module.exports = router;