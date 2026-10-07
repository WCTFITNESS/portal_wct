<?php

declare(strict_types=1);

namespace App\Lib;

/**
 * Lê arquivos de um ZIP em memória (stored/deflate) sem depender da extensão ZipArchive.
 */
class SimpleZipReader
{
    /** @return array<string, string> nome => conteúdo (pastas ignoradas) */
    public static function extract(string $zip): array
    {
        $eocd = strrpos($zip, "PK\x05\x06");
        if ($eocd === false || strlen($zip) < $eocd + 22) {
            return [];
        }
        $end = unpack('vdisk/vcdDisk/ventriesDisk/ventries/VcdSize/VcdOffset', substr($zip, $eocd + 4, 16));
        $pos = (int) $end['cdOffset'];
        $files = [];

        for ($i = 0; $i < (int) $end['entries']; $i++) {
            if (substr($zip, $pos, 4) !== "PK\x01\x02") {
                break;
            }
            $cd = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/VcompSize/Vsize/vnameLen/vextraLen/vcommentLen/vdisk/vintAttr/VextAttr/Voffset', substr($zip, $pos + 4, 42));
            $name = substr($zip, $pos + 46, (int) $cd['nameLen']);
            $pos += 46 + (int) $cd['nameLen'] + (int) $cd['extraLen'] + (int) $cd['commentLen'];

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            $local = (int) $cd['offset'];
            if (substr($zip, $local, 4) !== "PK\x03\x04") {
                continue;
            }
            $lh = unpack('vnameLen/vextraLen', substr($zip, $local + 26, 4));
            $data = substr($zip, $local + 30 + (int) $lh['nameLen'] + (int) $lh['extraLen'], (int) $cd['compSize']);

            if ((int) $cd['method'] === 0) {
                $files[$name] = $data;
            } elseif ((int) $cd['method'] === 8) {
                $inflated = @gzinflate($data);
                if ($inflated !== false) {
                    $files[$name] = $inflated;
                }
            }
        }

        return $files;
    }
}
