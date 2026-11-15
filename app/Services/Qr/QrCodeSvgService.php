<?php

namespace App\Services\Qr;

class QrCodeSvgService
{
    /**
     * GF(256) exponent and log tables with primitive polynomial 0x11D (x^8 + x^4 + x^3 + x^2 + 1)
     */
    private static ?array $gfExp = null;
    private static ?array $gfLog = null;

    /**
     * QR Code Version Specifications for Error Correction Level M
     * Version => [total_codewords, ec_codewords_per_block, num_blocks_g1, data_per_block_g1, num_blocks_g2, data_per_block_g2]
     */
    private const VERSION_TABLE_M = [
        1 => [26, 10, 1, 16, 0, 0],
        2 => [44, 16, 1, 28, 0, 0],
        3 => [70, 26, 1, 44, 0, 0],
        4 => [100, 18, 2, 32, 0, 0],
        5 => [134, 24, 2, 43, 0, 0],
        6 => [172, 16, 4, 27, 0, 0],
        7 => [196, 18, 2, 31, 2, 32],
        8 => [242, 22, 2, 38, 2, 39],
        9 => [292, 22, 3, 36, 2, 37],
        10 => [346, 26, 4, 43, 1, 44],
    ];

    /**
     * Alignment pattern center coordinates for versions 1 to 10
     */
    private const ALIGNMENT_COORDS = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    public function __construct()
    {
        self::initGf();
    }

    private static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);

        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $val;
            self::$gfLog[$val] = $i;
            $val <<= 1;
            if ($val & 0x100) {
                $val ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$gfExp[$i] = self::$gfExp[$i - 255];
        }
    }

    private function gfMul(int $x, int $y): int
    {
        if ($x === 0 || $y === 0) {
            return 0;
        }
        return self::$gfExp[self::$gfLog[$x] + self::$gfLog[$y]];
    }

    /**
     * Compute Reed-Solomon generator polynomial of degree $degree
     */
    private function rsGeneratorPoly(int $degree): array
    {
        $poly = [1];
        for ($i = 0; $i < $degree; $i++) {
            $factor = [1, self::$gfExp[$i]];
            $next = array_fill(0, count($poly) + 1, 0);
            for ($j = 0; $j < count($poly); $j++) {
                $next[$j] ^= $this->gfMul($poly[$j], $factor[0]);
                $next[$j + 1] ^= $this->gfMul($poly[$j], $factor[1]);
            }
            $poly = $next;
        }
        return $poly;
    }

    /**
     * Calculate Reed-Solomon error correction codewords for a data block
     */
    private function rsEncode(array $data, int $ecCount): array
    {
        $gen = $this->rsGeneratorPoly($ecCount);
        $remainder = array_fill(0, $ecCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            if ($factor !== 0) {
                for ($i = 0; $i < $ecCount; $i++) {
                    $remainder[$i] ^= $this->gfMul($gen[$i + 1], $factor);
                }
            }
        }

        return $remainder;
    }

    /**
     * Generate an offline scannable QR Code SVG
     */
    public function generateSvg(string $text, int $size = 250, int $margin = 4): string
    {
        $textBytes = array_values(unpack('C*', $text));
        $dataLen = count($textBytes);

        // Select lowest QR Version that fits data in Byte mode
        $version = null;
        $totalDataCapacity = 0;
        foreach (self::VERSION_TABLE_M as $v => $spec) {
            $cap = ($spec[2] * $spec[3]) + ($spec[4] * $spec[5]);
            // Byte mode header: 4 bits mode + (v <= 9 ? 8 : 16) bits length
            $headerBits = 4 + ($v <= 9 ? 8 : 16);
            $requiredBits = $headerBits + ($dataLen * 8);
            $requiredBytes = (int) ceil($requiredBits / 8);

            if ($requiredBytes <= $cap) {
                $version = $v;
                $totalDataCapacity = $cap;
                break;
            }
        }

        if ($version === null) {
            throw new \InvalidArgumentException("Text too long for offline QR generator: {$dataLen} bytes.");
        }

        $spec = self::VERSION_TABLE_M[$version];
        $ecCodewordsPerBlock = $spec[1];
        $numBlocksG1 = $spec[2];
        $dataPerBlockG1 = $spec[3];
        $numBlocksG2 = $spec[4];
        $dataPerBlockG2 = $spec[5];

        // 1. Encode bitstream (Byte mode)
        $bits = '';
        $bits .= '0100'; // Byte mode indicator
        $charCountBits = ($version <= 9) ? 8 : 16;
        $bits .= str_pad(decbin($dataLen), $charCountBits, '0', STR_PAD_LEFT);
        foreach ($textBytes as $b) {
            $bits .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT);
        }

        // Terminator up to 4 bits
        $maxDataBits = $totalDataCapacity * 8;
        $terminatorLen = min(4, $maxDataBits - strlen($bits));
        if ($terminatorLen > 0) {
            $bits .= str_repeat('0', $terminatorLen);
        }

        // Pad to byte boundary
        while (strlen($bits) % 8 !== 0 && strlen($bits) < $maxDataBits) {
            $bits .= '0';
        }

        // Convert to data codewords
        $dataCodewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $dataCodewords[] = bindec(substr($bits, $i, 8));
        }

        // Pad bytes (0xEC, 0x11)
        $padToggle = false;
        while (count($dataCodewords) < $totalDataCapacity) {
            $dataCodewords[] = $padToggle ? 0x11 : 0xEC;
            $padToggle = !$padToggle;
        }

        // 2. Partition into blocks & calculate Reed-Solomon EC
        $blocks = [];
        $offset = 0;
        for ($b = 0; $b < $numBlocksG1; $b++) {
            $blockData = array_slice($dataCodewords, $offset, $dataPerBlockG1);
            $blocks[] = [
                'data' => $blockData,
                'ec' => $this->rsEncode($blockData, $ecCodewordsPerBlock),
            ];
            $offset += $dataPerBlockG1;
        }
        for ($b = 0; $b < $numBlocksG2; $b++) {
            $blockData = array_slice($dataCodewords, $offset, $dataPerBlockG2);
            $blocks[] = [
                'data' => $blockData,
                'ec' => $this->rsEncode($blockData, $ecCodewordsPerBlock),
            ];
            $offset += $dataPerBlockG2;
        }

        // 3. Interleave data codewords, then EC codewords
        $finalCodewords = [];
        $maxBlockDataLen = max($dataPerBlockG1, $dataPerBlockG2);
        for ($i = 0; $i < $maxBlockDataLen; $i++) {
            foreach ($blocks as $blk) {
                if ($i < count($blk['data'])) {
                    $finalCodewords[] = $blk['data'][$i];
                }
            }
        }
        for ($i = 0; $i < $ecCodewordsPerBlock; $i++) {
            foreach ($blocks as $blk) {
                $finalCodewords[] = $blk['ec'][$i];
            }
        }

        // 4. Build matrix
        $dimension = 17 + ($version * 4);
        $matrix = array_fill(0, $dimension, array_fill(0, $dimension, null));
        $reserved = array_fill(0, $dimension, array_fill(0, $dimension, false));

        // Helper to set module
        $setModule = function (int $r, int $c, int $val, bool $isReserved = true) use (&$matrix, &$reserved, $dimension) {
            if ($r >= 0 && $r < $dimension && $c >= 0 && $c < $dimension) {
                $matrix[$r][$c] = $val;
                if ($isReserved) {
                    $reserved[$r][$c] = true;
                }
            }
        };

        // Finder pattern helper (7x7)
        $drawFinder = function (int $top, int $left) use ($setModule) {
            for ($r = 0; $r < 7; $r++) {
                for ($c = 0; $c < 7; $c++) {
                    if ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)) {
                        $setModule($top + $r, $left + $c, 1);
                    } else {
                        $setModule($top + $r, $left + $c, 0);
                    }
                }
            }
        };

        // Draw 3 Finders + Separators
        $drawFinder(0, 0);
        $drawFinder(0, $dimension - 7);
        $drawFinder($dimension - 7, 0);

        // Separators around finders
        for ($i = 0; $i < 8; $i++) {
            // Top-left
            $setModule(7, $i, 0);
            $setModule($i, 7, 0);
            // Top-right
            $setModule(7, $dimension - 8 + $i, 0);
            $setModule($i, $dimension - 8, 0);
            // Bottom-left
            $setModule($dimension - 8, $i, 0);
            $setModule($dimension - 8 + $i, 7, 0);
        }

        // Timing patterns
        for ($i = 8; $i < $dimension - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if ($matrix[6][$i] === null) {
                $setModule(6, $i, $val);
            }
            if ($matrix[$i][6] === null) {
                $setModule($i, 6, $val);
            }
        }

        // Alignment patterns
        $coords = self::ALIGNMENT_COORDS[$version] ?? [];
        foreach ($coords as $rCenter) {
            foreach ($coords as $cCenter) {
                // Skip if overlaps with any finder pattern
                if (($rCenter <= 8 && $cCenter <= 8) ||
                    ($rCenter <= 8 && $cCenter >= $dimension - 9) ||
                    ($rCenter >= $dimension - 9 && $cCenter <= 8)) {
                    continue;
                }
                for ($dr = -2; $dr <= 2; $dr++) {
                    for ($dc = -2; $dc <= 2; $dc++) {
                        $val = (abs($dr) === 2 || abs($dc) === 2 || ($dr === 0 && $dc === 0)) ? 1 : 0;
                        $setModule($rCenter + $dr, $cCenter + $dc, $val);
                    }
                }
            }
        }

        // Dark module at (4*V + 9, 8)
        $setModule((4 * $version) + 9, 8, 1);

        // Reserve format info modules
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$dimension - 8 + $i] = true;
            $reserved[$dimension - 8 + $i][8] = true;
        }

        // 5. Place Data Bits in 2-column zig-zag right-to-left
        $bitStream = '';
        foreach ($finalCodewords as $cw) {
            $bitStream .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $bitIndex = 0;
        $totalStreamBits = strlen($bitStream);

        $col = $dimension - 1;
        $goingUp = true;
        while ($col > 0) {
            if ($col === 6) {
                $col--; // Skip vertical timing column
            }
            $rowRange = $goingUp ? range($dimension - 1, 0, -1) : range(0, $dimension - 1);
            foreach ($rowRange as $r) {
                for ($c = $col; $c >= $col - 1; $c--) {
                    if (!$reserved[$r][$c]) {
                        $rawBit = ($bitIndex < $totalStreamBits) ? (int) $bitStream[$bitIndex++] : 0;
                        // Apply Mask Pattern 0: (r + c) % 2 == 0
                        $mask = (($r + $c) % 2 === 0) ? 1 : 0;
                        $matrix[$r][$c] = $rawBit ^ $mask;
                    }
                }
            }
            $goingUp = !$goingUp;
            $col -= 2;
        }

        // 6. Write Format Info (Level M = 00, Mask 0 = 000 -> 0x0000 -> XOR 0x5412 = 0x5412)
        // 15 bits: 101010000010010
        $formatBits = '101010000010010';
        $formatTopLeft = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8], [5, 8], [4, 8], [3, 8],
            [2, 8], [1, 8], [0, 8]
        ];
        $formatSplit = [
            [$dimension - 1, 8], [$dimension - 2, 8], [$dimension - 3, 8],
            [$dimension - 4, 8], [$dimension - 5, 8], [$dimension - 6, 8],
            [$dimension - 7, 8],
            [8, $dimension - 8], [8, $dimension - 7], [8, $dimension - 6],
            [8, $dimension - 5], [8, $dimension - 4], [8, $dimension - 3],
            [8, $dimension - 2], [8, $dimension - 1]
        ];

        for ($i = 0; $i < 15; $i++) {
            $bit = (int) $formatBits[$i];
            [$r1, $c1] = $formatTopLeft[$i];
            $matrix[$r1][$c1] = $bit;

            [$r2, $c2] = $formatSplit[$i];
            $matrix[$r2][$c2] = $bit;
        }

        // 7. Render SVG with crispEdges
        $viewBoxSize = $dimension + ($margin * 2);
        $path = '';
        for ($r = 0; $r < $dimension; $r++) {
            for ($c = 0; $c < $dimension; $c++) {
                if ($matrix[$r][$c] === 1) {
                    $x = $c + $margin;
                    $y = $r + $margin;
                    $path .= "M{$x},{$y}h1v1h-1z ";
                }
            }
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" shape-rendering="crispEdges">' .
            '<rect width="100%%" height="100%%" fill="#ffffff"/>' .
            '<path d="%s" fill="#000000"/>' .
            '</svg>',
            $viewBoxSize,
            $viewBoxSize,
            $size,
            $size,
            trim($path)
        );
    }
}
