<?php

/**
 * Generador de los iconos de los modulos (Modules/<Name>/Public/img/icon.png).
 *
 * Uso:  php tools/make-module-icons.php
 *
 * Por que existe: son imagenes que hay que poder retocar sin herramientas de diseño, y el PHP de
 * este entorno no tiene GD (ni ImageMagick hace falta). El script dibuja con distancias con signo
 * (SDF) y cobertura analitica por pixel, y escribe el PNG a mano con zlib (`gzcompress` + CRC32),
 * asi que las unicas dependencias son PHP y zlib.
 *
 * Los iconos miden 256x256 con las esquinas transparentes: la tarjeta de System -> Modules los pinta
 * a 128x128 con border-radius (public/css/style.css, .module-card img), asi que a 256 se ven nitidos
 * en pantallas HiDPI. El resultado es determinista: volver a ejecutarlo no cambia los ficheros.
 *
 * La clave "img" del module.json apunta a /modules/<alias>/<fichero>, que se sirve a traves del
 * enlace public/modules/<alias> -> Modules/<Name>/Public. Si ese enlace no existe, el navegador
 * recibe un 404 y la tarjeta muestra la imagen rota (el script avisa al terminar).
 */

const SIZE = 256;          // lado del PNG
const RADIUS = 56;         // radio de las esquinas del cuadrado de fondo

// --- Utilidades -------------------------------------------------------------

function hex2rgb($hex)
{
    return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
}

function clamp01($value)
{
    return $value < 0 ? 0.0 : ($value > 1 ? 1.0 : $value);
}

/**
 * Distancia con signo a un rectangulo redondeado (negativa dentro).
 */
function sdfRoundRect($px, $py, $x, $y, $w, $h, $r)
{
    $dx = abs($px - ($x + $w / 2)) - ($w / 2 - $r);
    $dy = abs($py - ($y + $h / 2)) - ($h / 2 - $r);
    $ox = max($dx, 0.0);
    $oy = max($dy, 0.0);

    return sqrt($ox * $ox + $oy * $oy) + min(max($dx, $dy), 0.0) - $r;
}

function distSegment($px, $py, $ax, $ay, $bx, $by)
{
    $vx = $bx - $ax;
    $vy = $by - $ay;
    $wx = $px - $ax;
    $wy = $py - $ay;
    $len2 = $vx * $vx + $vy * $vy;
    $t = $len2 > 0.0 ? ($wx * $vx + $wy * $vy) / $len2 : 0.0;
    $t = $t < 0.0 ? 0.0 : ($t > 1.0 ? 1.0 : $t);
    $dx = $wx - $t * $vx;
    $dy = $wy - $t * $vy;

    return sqrt($dx * $dx + $dy * $dy);
}

/**
 * Anade a $points los de un tramo: 'l' recto (a->b) o 'q' cuadratico (a->b con control c).
 */
function segmentPoints($points, $type, $a, $b, $c, $steps)
{
    for ($i = 0; $i <= $steps; $i++) {
        $t = $i / $steps;
        $u = 1.0 - $t;
        if ($type === 'q') {
            $points[] = [
                $u * $u * $a[0] + 2 * $u * $t * $b[0] + $t * $t * $c[0],
                $u * $u * $a[1] + 2 * $u * $t * $b[1] + $t * $t * $c[1],
            ];
        } else {
            $points[] = [$a[0] + $t * ($b[0] - $a[0]), $a[1] + $t * ($b[1] - $a[1])];
        }
    }

    return $points;
}

/**
 * Distancia minima de un punto a una polilinea.
 */
function sdfPolyline($px, $py, $points)
{
    $min = INF;
    for ($i = 0, $n = count($points) - 1; $i < $n; $i++) {
        $d = distSegment($px, $py, $points[$i][0], $points[$i][1], $points[$i + 1][0], $points[$i + 1][1]);
        if ($d < $min) {
            $min = $d;
        }
    }

    return $min;
}

/**
 * Refleja una polilinea respecto del eje vertical (x -> 256 - x).
 */
function mirrorX($points)
{
    $out = [];
    foreach ($points as $point) {
        $out[] = [256 - $point[0], $point[1]];
    }

    return $out;
}

// --- Motor de pintado -------------------------------------------------------

/**
 * Color y alfa de un pixel.
 *
 * Capas: cada una es ['sdf' => callable(px, py), 'alpha' => 0..1]. El 'sdf' devuelve la distancia
 * con signo al contorno de la figura; la cobertura se estima con una rampa de 1 px alrededor de 0,
 * que es lo que da el antialiasing. Las capas se pintan en orden sobre el degradado de fondo.
 *
 * @return array|null [r, g, b, a] o null si el pixel queda fuera del cuadrado.
 */
function paintPixel($x, $y, $c0, $c1, $layers)
{
    $px = $x + 0.5;
    $py = $y + 0.5;

    $bgAlpha = clamp01(0.5 - sdfRoundRect($px, $py, 0, 0, SIZE, SIZE, RADIUS));
    if ($bgAlpha <= 0.0) {
        return null;
    }

    // Degradado diagonal de la esquina superior izquierda a la inferior derecha.
    // Se cuantiza a 8 bits antes de componer: las capas se mezclan sobre el bufer, no sobre
    // precision infinita, que es lo que haria un compositor real.
    $t = clamp01(($px + $py) / (SIZE * 2));
    $r = (int) round($c0[0] + $t * ($c1[0] - $c0[0]));
    $g = (int) round($c0[1] + $t * ($c1[1] - $c0[1]));
    $b = (int) round($c0[2] + $t * ($c1[2] - $c0[2]));

    foreach ($layers as $layer) {
        $alpha = clamp01(0.5 - call_user_func($layer['sdf'], $px, $py)) * $layer['alpha'];
        if ($alpha <= 0.0) {
            continue;
        }
        $r = (int) round($r + $alpha * (255 - $r));
        $g = (int) round($g + $alpha * (255 - $g));
        $b = (int) round($b + $alpha * (255 - $b));
    }

    return [$r, $g, $b, (int) round($bgAlpha * 255)];
}

/**
 * Escribe un PNG RGBA de 8 bits (color type 6) sin librerias.
 */
function writePng($target, $pixels)
{
    $raw = '';
    for ($y = 0; $y < SIZE; $y++) {
        $raw .= "\x00"; // filtro 0 (None)
        for ($x = 0; $x < SIZE; $x++) {
            $pixel = $pixels[$y][$x];
            $raw .= $pixel === null
                ? "\x00\x00\x00\x00"                                     // fuera: transparente puro
                : chr($pixel[0]) . chr($pixel[1]) . chr($pixel[2]) . chr($pixel[3]);
        }
    }

    $chunk = function ($type, $data) {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    $png = "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', SIZE, SIZE, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))    // zlib, que es justo lo que espera IDAT
        . $chunk('IEND', '');

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }
    file_put_contents($target, $png);

    $info = @getimagesizefromstring($png);
    printf(
        "  %-46s %6d bytes  %s\n",
        str_replace(dirname(__DIR__) . DIRECTORY_SEPARATOR, '', $target),
        strlen($png),
        $info ? $info[0] . 'x' . $info[1] . ' ' . $info['mime'] : 'PNG INVALIDO'
    );
}

function render($c0hex, $c1hex, $layersBuilder)
{
    $c0 = hex2rgb($c0hex);
    $c1 = hex2rgb($c1hex);
    $layers = call_user_func($layersBuilder);

    $pixels = [];
    for ($y = 0; $y < SIZE; $y++) {
        $pixels[$y] = [];
        for ($x = 0; $x < SIZE; $x++) {
            $pixels[$y][$x] = paintPixel($x, $y, $c0, $c1, $layers);
        }
    }

    return $pixels;
}

// --- Icono de RestApi: llaves { } con tres puntos (API JSON) ----------------

/**
 * Las cinco figuras del glifo (las dos llaves y los tres puntos) se combinan con un unico trazo:
 * se usa una sola capa cuyo SDF es el minimo de todas, de modo que donde se solapen no se suman.
 */
function restApiLayers()
{
    $half = 6.4;

    $left = [];
    $left = segmentPoints($left, 'q', [118, 54],  [92, 54],  [92, 78],  20);
    $left = segmentPoints($left, 'l', [92, 78],  [92, 113], null, 3);
    $left = segmentPoints($left, 'q', [92, 113], [92, 128], [66, 128], 20);
    $left = segmentPoints($left, 'q', [66, 128], [92, 128], [92, 143], 20);
    $left = segmentPoints($left, 'l', [92, 143], [92, 178], null, 3);
    $left = segmentPoints($left, 'q', [92, 178], [92, 202], [118, 202], 20);

    $right = mirrorX($left);
    $dots = [[128, 96, 8.5], [128, 128, 8.5], [128, 160, 8.5]];

    return [[
        'alpha' => 1.0,
        'sdf'   => function ($px, $py) use ($left, $right, $dots, $half) {
            $min = min(sdfPolyline($px, $py, $left), sdfPolyline($px, $py, $right)) - $half;
            foreach ($dots as $dot) {
                $dx = $px - $dot[0];
                $dy = $py - $dot[1];
                $d = sqrt($dx * $dx + $dy * $dy) - $dot[2];
                if ($d < $min) {
                    $min = $d;
                }
            }

            return $min;
        },
    ]];
}

// --- Icono de Kanban: tablero con tres columnas y tarjetas 3/2/1 -----------

function kanbanLayers()
{
    $layers = [];

    // Columnas: marco translucido.
    foreach ([44, 104, 164] as $column) {
        $layers[] = [
            'alpha' => 0.30,
            'sdf'   => function ($px, $py) use ($column) {
                return sdfRoundRect($px, $py, $column, 76, 48, 104, 8);
            },
        ];
    }

    // Tarjetas: blanco solido, de mas a menos (el trabajo avanza hacia la derecha).
    $cards = [[44, 88], [44, 118], [44, 148], [104, 88], [104, 118], [164, 88]];
    foreach ($cards as $card) {
        $layers[] = [
            'alpha' => 1.0,
            'sdf'   => function ($px, $py) use ($card) {
                return sdfRoundRect($px, $py, $card[0] + 6, $card[1], 36, 20, 5);
            },
        ];
    }

    return $layers;
}

// --- Main -------------------------------------------------------------------

$root = dirname(__DIR__);

$icons = [
    // alias del modulo => [module.json 'img', color inicial, color final, figuras]
    'restapi' => ['Modules/RestApi/Public/img/icon.png', '#1565c0', '#00b8d4', 'restApiLayers'],
    'kanban'  => ['Modules/Kanban/Public/img/icon.png',  '#4527a0', '#7b1fa2', 'kanbanLayers'],
];

echo "Generando iconos de modulo (" . SIZE . "x" . SIZE . ", RGBA, esquinas transparentes)\n";

$missing_links = [];
foreach ($icons as $alias => $icon) {
    list($relative, $from, $to, $builder) = $icon;

    writePng($root . '/' . $relative, render($from, $to, $builder));

    // La tarjeta pide /modules/<alias>/<fichero>: sin el enlace public/modules/<alias> da 404.
    if (!file_exists($root . '/public/modules/' . $alias)) {
        $missing_links[] = $alias;
    }
}

echo "\n";
if ($missing_links) {
    echo "AVISO: falta el enlace public/modules/" . implode(" y public/modules/", $missing_links) . ".\n";
    echo "       Sin el, la tarjeta del modulo muestra la imagen rota (el modulo lo sirve\n";
    echo "       Modules/<Name>/Public, no public/). Solucion:\n";
    echo "  - Linux:  php artisan freescout:module-install <alias>\n";
    echo "            (si el enlace existe pero apunta mal: rm -f public/modules/<alias> y repetir)\n";
    echo "  - Windows: symlink() no funciona sin permisos; usar una junction, que no los necesita:\n";
    foreach ($missing_links as $alias) {
        $name = $alias === 'restapi' ? 'RestApi' : ucfirst($alias);
        echo "            cmd /c mklink /J \"public\\modules\\$alias\" \"Modules\\$name\\Public\"\n";
    }
} else {
    echo "Enlaces public/modules/* presentes: los iconos se serviran en /modules/<alias>/img/icon.png\n";
}
