const multer = require('multer');
const path = require('path');
const fs = require('fs');

// Configuração do armazenamento do multer
const storage = multer.diskStorage({
    destination: (req, file, cb) => {
        const relativePath = file.originalname;
        console.log('Caminho relativo recebido no servidor:', relativePath);  // Verifique aqui

        const folderPath = path.join('uploads/redimensionamento', path.dirname(relativePath));  // Diretório onde o arquivo será salvo
        console.log('Pasta para salvar arquivo:', folderPath);  // Verifique se a pasta está sendo gerada corretamente

        // Cria a pasta somente se ela não existir
        fs.mkdir(folderPath, { recursive: true }, (err) => {
            if (err) {
                console.error("Erro ao criar diretório:", err);
                return cb(err);  // Retorna o erro para o callback do multer
            }
            cb(null, folderPath);  // Chama o callback do multer após o diretório ser criado
        });
    },
    filename: (req, file, cb) => {
        cb(null, path.basename(file.originalname));  // Usamos o nome do arquivo, sem o caminho relativo
    }
});

// Configuração do multer
const uploadFiles = multer({ 
    storage,
    fileFilter: (req, file, cb) => {
        // Aceita todos os tipos de arquivos para permitir pastas inteiras
        cb(null, true);
    }
});

module.exports = { uploadFiles };
