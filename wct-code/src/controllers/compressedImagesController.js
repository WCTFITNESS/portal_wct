// Importação de módulos
const fs = require('fs-extra');
const path = require('path');
const tinify = require('tinify');
const jimp = require('jimp');
const archiver = require('archiver');

// Configuração da API TinyPNG
tinify.key = 'n4St3JvVLG5MNv2SCsScNpkbvlbT05GD';

// Diretórios
const LOCAL_DIR = 'uploads/redimensionamento';
const OUTPUT_DIR = 'uploads/processadas';
const ZIP_OUTPUT = 'uploads/comprimidas.zip';

async function getFileSize(filePath) {
  const stats = await fs.stat(filePath);
  return stats.size;
}

// Função para redimensionar e comprimir imagem
async function processImage(imagePath, outputPath) {
  try {
    // Redimensionar e converter para JPEG usando Jimp
    const image = await jimp.read(imagePath);
    await image
      .resize(2000, 2000) // Redimensiona a imagem para 2000x2000 pixels, preservando as proporções
      .quality(20) // Define a qualidade JPEG para 85%
      .writeAsync(outputPath); // Salva a imagem redimensionada

    // Comprimir com TinyPNG
    const source = tinify.fromFile(outputPath);
    await source.toFile(outputPath);

    console.log(`Imagem processada: ${outputPath}`);

    // Fecha o arquivo antes de tentar remover
    await fs.close(await fs.open(imagePath, 'r'));

    // Deletar a imagem original logo após o processamento
    await deleteFileWithRetry(imagePath);
    console.log(`Imagem original apagada: ${imagePath}`);
  } catch (err) {
    console.error(`Erro ao processar imagem ${imagePath}:`, err);
    throw err;  // Re-throw para garantir que o erro seja capturado pela função principal
  }
}

// Função para tentar apagar um arquivo várias vezes
async function deleteFileWithRetry(filePath, attempts = 3, delay = 2000) {
  for (let i = 0; i < attempts; i++) {
    try {
      // Verifica se o arquivo é acessível (leitura/escrita)
      await fs.access(filePath, fs.constants.F_OK | fs.constants.W_OK);

      // Altera as permissões para permitir exclusão
      await fs.chmod(filePath, 0o777);

      // Tenta apagar o arquivo
      await fs.remove(filePath);
      console.log(`Arquivo apagado: ${filePath}`);
      return;
    } catch (err) {
      if (err.code === 'EPERM') {
        console.log(`Erro ao apagar ${filePath}, tentando novamente em ${delay / 1000} segundos...`);
        await new Promise((resolve) => setTimeout(resolve, delay));
      } else {
        console.error(`Erro desconhecido ao apagar ${filePath}:`, err);
        throw err;
      }
    }
  }
  console.error(`Não foi possível apagar o arquivo: ${filePath}`);
  throw new Error(`Falha ao apagar o arquivo após ${attempts} tentativas`);
}

// Função para percorrer as subpastas e processar imagens
async function processImages() {
  // Garantindo que o diretório de saída exista
  await fs.ensureDir(OUTPUT_DIR);

  // Lendo o diretório de imagens
  const files = await fs.readdir(LOCAL_DIR, { withFileTypes: true });

  // Verifica se há arquivos no diretório
  if (files.length === 0) {
    console.log('Nenhuma imagem encontrada no diretório de entrada.');
    return;
  }

  for (const file of files) {
    if (file.isFile() && /\.(jpg|jpeg|png)$/i.test(file.name)) {
      const localImagePath = path.join(LOCAL_DIR, file.name);
      const processedImagePath = path.join(OUTPUT_DIR, `compressed_${file.name}`);

      console.log(`Processando: ${localImagePath}`);
      await processImage(localImagePath, processedImagePath);
    } else {
      console.log(`Arquivo ignorado (não é imagem): ${file.name}`);
    }
  }

  console.log('Todas as imagens foram processadas!');
}


// Função para criar o arquivo ZIP
async function createZip() {
  try {
    const output = fs.createWriteStream(ZIP_OUTPUT);
    const archive = archiver('zip', { zlib: { level: 9 } });

    output.on('close', async () => {
      console.log(`Arquivo ZIP criado com sucesso: ${ZIP_OUTPUT} (${archive.pointer()} bytes)`);

      // Aguarda um pequeno tempo para garantir o fechamento do arquivo ZIP
      await new Promise((res) => setTimeout(res, 1000));

      console.log('ZIP Finalizado!');
    });

    archive.on('error', (err) => {
      console.error('Erro ao criar ZIP:', err);
      throw err;
    });

    archive.pipe(output);
    archive.directory(OUTPUT_DIR, false);
    await archive.finalize();  // A função `finalize` é assíncrona, portanto deve ser aguardada.

  } catch (err) {
    console.error('Erro na função createZip:', err);
    throw err;
  }
}

// Função para limpar diretórios
async function cleanUp() {
  try {
    // Remover a pasta de saída
    await fs.remove(OUTPUT_DIR);

    // Aguarda um tempo para garantir que não haja processos abertos
    await new Promise((resolve) => setTimeout(resolve, 2000));

    // Verifica novamente se a pasta LOCAL_DIR está vazia
    const isLocalDirEmpty = (await fs.readdir(LOCAL_DIR)).length === 0;

    // Se estiver vazia, tenta apagar o diretório
    if (isLocalDirEmpty) {
      await fs.chmod(LOCAL_DIR, 0o777); // Ajusta permissões
      await fs.rmdir(LOCAL_DIR, { recursive: true, force: true }); // Força a remoção
      console.log('Pasta redimensionamento apagada!');
    } else {
      console.log('Pasta redimensionamento não estava vazia.');
    }
  } catch (err) {
    console.error('Erro ao limpar pastas:', err);
  }
}

// Função principal para processar, zipar e limpar
async function processAndZip() {
  try {
    await processImages();
    await createZip();
    await cleanUp();
  } catch (err) {
    console.error('Erro ao processar, zipar ou limpar:', err);
    throw err;  // Isso permite que o erro seja capturado no fluxo superior
  }
}

// Executa o script
module.exports = { processAndZip };
