<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
$branding = getBranding();
$empresa_nombre = strtoupper($branding['nombre']);

$zebra_ip   = $cfg['zebra_ip']   ?? '';
$zebra_port = (int)($cfg['zebra_port'] ?? 9100);

function buildZPL($item, $size, $fields) {
    $esc = function($s) {
        return str_replace(['\\', '^', '~', '{', '}', '|'], ['\\\\', '\^', '\~', '\{', '\}', '\|'],
            mb_substr((string)($s ?? '---'), 0, 35));
    };

    $field_map = [
        'nombre' => ['label' => 'NOMBRE',    'key' => 'nombre'],
        'fab'    => ['label' => 'FABRICANTE','key' => 'fabricante'],
        'mod'    => ['label' => 'MODELO',    'key' => 'modelo'],
        'serie'  => ['label' => 'S/N',       'key' => 'serie'],
        'tipo'   => ['label' => 'TIPO',      'key' => 'tipo'],
        'ip'     => ['label' => 'IP',        'key' => 'ip'],
        'user'   => ['label' => 'USUARIO',   'key' => 'usuario'],
    ];

    $active  = array_filter($field_map, fn($k) => !empty($fields[$k]), ARRAY_FILTER_USE_KEY);
    $nfields = count($active);
    $has_qr  = !empty($fields['qr']) && !empty($item['qr_url']);

    if($size === 58) {
        // ── 58x39mm = 463x312 dots @ 203dpi ─────────────────────────────────
        $w          = 463;
        $h          = 312;
        $header_h   = 36;   // header negro
        $body_start = 38;   // justo debajo del header
        $footer_y   = 285;  // donde empieza el footer
        $body_h     = $footer_y - $body_start; // 247 dots

        // QR: area de 200 dots a la derecha
        // mag=5 → QR ocupa ~180 dots → centrado vertical
        $qr_mag  = 5;
        $qr_size = 180;
        $qr_area = $has_qr ? 200 : 0;
        $sep_x   = $w - $qr_area;                              // 263
        $qr_x    = $sep_x + 10;                                // 273
        $qr_y    = $body_start + (int)(($body_h - $qr_size) / 2); // 72

        // Campos info
        $info_x       = 4;
        $field_h      = 41; // label(13)+gap(2)+valor(24)+sep(2) = 41
        $info_y_start = $body_start + 4;

        // Fuentes
        $font_lh  = 13; $font_lw = 8;   // label
        $font_vh  = 24; $font_vw = 15;  // valor — más ancho
        $max_chars = 40;

        // Header
        $hfh = 26; $hfw = 17; $hty = 5; // fuente ancha y legible
        $max_header = 47;

        // Footer
        $ffh = 12; $ffw = 7;

    } else {
        // ── 100x60mm = 800x480 dots @ 203dpi ────────────────────────────────
        $w          = 800;
        $h          = 480;
        $header_h   = 42;
        $body_start = 46;
        $footer_y   = 452;
        $body_h     = $footer_y - $body_start; // 406 dots

        $qr_mag  = 5;
        $qr_size = 180;
        $qr_area = $has_qr ? 220 : 0;
        $sep_x   = $w - $qr_area;
        $qr_x    = $sep_x + 12;
        $qr_y    = $body_start + (int)(($body_h - $qr_size) / 2);

        $info_x       = 8;
        $field_h      = (int)min(90, floor($body_h / max(1,$nfields)));
        $info_y_start = $body_start + 6;

        $font_lh  = 15; $font_lw = 9;
        $font_vh  = 30; $font_vw = 18;
        $max_chars = 28;

        $hfh = 30; $hfw = 18; $hty = 6;
        $max_header = 46;
        $ffh = 14; $ffw = 9;
    }

    $zpl  = "^XA\n";
    $zpl .= "^PW{$w}\n";
    $zpl .= "^LL{$h}\n";
    $zpl .= "^CI28\n";
    $zpl .= "^LT16\n"; // margen superior 2mm

    // ── Header negro con texto blanco centrado ───────────────────────────────
    $zpl .= "^FO0,0^GB{$w},{$header_h},{$header_h}^FS\n";
    $header_txt = mb_strimwidth($empresa_nombre . "  -  " . strtoupper($item['nombre'] ?? ''), 0, $max_header, '..');
    $zpl .= "^CF0,{$hfh},{$hfw}\n";
    $zpl .= "^FO0,{$hty}^FR^FB{$w},1,0,C,0^FD{$header_txt}^FS\n";
    $zpl .= "^CF0,30,20\n"; // reset fuente

    // ── Separador vertical ───────────────────────────────────────────────────
    if($has_qr) {
        $sep_h = $footer_y - $body_start;
        $zpl .= "^FO{$sep_x},{$body_start}^GB1,{$sep_h},1^FS\n";
    }

    // ── Campos info ──────────────────────────────────────────────────────────
    $y = $info_y_start;
    foreach($field_map as $fkey => $fdef) {
        if(empty($fields[$fkey])) continue;
        $raw = (string)($item[$fdef['key']] ?? '');
        $val = $esc(mb_strimwidth($raw ?: '---', 0, $max_chars, '..'));
        $zpl .= "^FO{$info_x},{$y}^A0N,{$font_lh},{$font_lw}^FD{$fdef['label']}^FS\n";
        $zpl .= "^FO{$info_x}," . ($y + $font_lh + 2) . "^A0N,{$font_vh},{$font_vw}^FD{$val}^FS\n";
        $y += $field_h;
    }

    // ── QR ───────────────────────────────────────────────────────────────────
    if($has_qr) {
        $zpl .= "^FO{$qr_x},{$qr_y}^BQN,2,{$qr_mag}^FDMA," . $item['qr_url'] . "^FS\n";
    }

    // ── Footer centrado ──────────────────────────────────────────────────────
    $zpl .= "^FO0,{$footer_y}^GB{$w},1,1^FS\n";
    $zpl .= "^FO0," . ($footer_y + 4) . "^A0N,{$ffh},{$ffw}^FB{$w},1,0,C,0^FD" . $empresa_nombre . "  *  Gestion de Activos TI  *  glpi.nagsa.com.ec^FS\n";

    $zpl .= "^XZ\n";
    return $zpl;
}

// ── Modo debug ───────────────────────────────────────────────────────────────
if(isset($_GET['debug'])) {
    $test_item = [
        'id'         => '999',
        'nombre'     => 'EQC-WW-0000105',
        'fabricante' => 'LENOVO',
        'modelo'     => 'ThinkPad E14 Gen 4',
        'serie'      => 'PF4C3GV9',
        'tipo'       => 'Laptop',
        'ip'         => '192.168.1.100',
        'usuario'    => 'Juan Perez',
        'qr_url'     => 'https://glpi.nagsa.com.ec/front/computer.form.php?id=999',
        'activo'     => 'Computadora',
    ];
    $test_fields = ['nombre'=>true,'fab'=>true,'mod'=>true,'serie'=>true,'qr'=>true];
    $test_size   = isset($_GET['size']) ? (int)$_GET['size'] : 58;
    $zpl_debug   = buildZPL($test_item, $test_size, $test_fields);
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== ZPL GENERADO (size={$test_size}mm) ===\n\n";
    echo $zpl_debug;
    echo "\n=== FIN ZPL ===\n";
    exit;
}

if(!$zebra_ip){ http_response_code(400); die(json_encode(['error'=>'IP de impresora Zebra no configurada. Configura desde Sistema_admin.php'])); }

$body = json_decode(file_get_contents('php://input'), true);
if(!$body){ http_response_code(400); die(json_encode(['error'=>'Datos invalidos'])); }

$items  = $body['items']  ?? [];
$size   = (int)($body['size']   ?? 58);
$fields = $body['fields'] ?? [];

if(empty($items)){ http_response_code(400); die(json_encode(['error'=>'No hay etiquetas para imprimir'])); }

// ── Enviar a la Zebra por socket TCP ─────────────────────────────────────────
$zpl_total = '';
foreach($items as $item) {
    $zpl_total .= buildZPL($item, $size, $fields);
}

$socket = @fsockopen($zebra_ip, $zebra_port, $errno, $errstr, 5);
if(!$socket) {
    http_response_code(500);
    die(json_encode(['error' => "No se pudo conectar a la impresora ({$zebra_ip}:{$zebra_port}) — {$errstr}"]));
}

fwrite($socket, $zpl_total);
fclose($socket);

echo json_encode(['ok' => true, 'enviadas' => count($items)]);
?>
