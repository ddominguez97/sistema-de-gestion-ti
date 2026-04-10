<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';

session_start();
// Modo desarrollo: sesión temporal en localhost
if(!isset($_SESSION['nagsa_user']) && in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost:8080','localhost','127.0.0.1:8080','127.0.0.1'])){
    $_SESSION['nagsa_user'] = 'dev_local';
    $_SESSION['nagsa_name'] = 'Desarrollo Local';
}
if (!isset($_SESSION['nagsa_user'])) {
    http_response_code(401);
    die(json_encode(['error' => 'No autorizado']));
}

// ── Almacenamiento en archivo JSON (desarrollo) ────────────────────────────
// En producción se migra a SQL Server Express via getLocalDB()
define('DATA_FILE', ROOT_PATH . '/data/actas.json');

function loadData() {
    if (!file_exists(DATA_FILE)) {
        return ['next_id' => 1, 'actas' => []];
    }
    $raw = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    if (!$data || !isset($data['actas'])) {
        return ['next_id' => 1, 'actas' => []];
    }
    return $data;
}

function saveData($data) {
    file_put_contents(DATA_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Guardar acta nueva ──────────────────────────────────────────────────────
if ($action === 'guardar') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        die(json_encode(['error' => 'Datos inválidos']));
    }

    $data   = loadData();
    $id     = $data['next_id'];
    $numero = $input['numero'] ?? ('ACT-' . str_pad($id, 3, '0', STR_PAD_LEFT));
    $now    = date('Y-m-d H:i:s');

    $acta = [
        'id'                     => $id,
        'numero'                 => $numero,
        'tipo'                   => $input['tipo'] ?? 'entrega',
        'fecha'                  => $input['fecha'] ?? date('Y-m-d'),
        'lugar'                  => $input['lugar'] ?? null,
        'entregado_por'          => $input['entregado_por'] ?? null,
        'entregado_cargo'        => $input['entregado_cargo'] ?? null,
        'recibido_por'           => $input['recibido_por'] ?? null,
        'recibido_cargo'         => $input['recibido_cargo'] ?? null,
        'autorizado_por'         => $input['autorizado_por'] ?? null,
        'autorizado_cargo'       => $input['autorizado_cargo'] ?? null,
        'motivo'                 => $input['motivo'] ?? null,
        'destino'                => $input['destino'] ?? null,
        'retira_persona'         => $input['retira_persona'] ?? null,
        'retira_cargo'           => $input['retira_cargo'] ?? null,
        'observaciones'          => $input['observaciones'] ?? null,
        'equipos'                => $input['equipos'] ?? [],
        'total_equipos'          => count($input['equipos'] ?? []),
        'estado'                 => 'pendiente',
        'aceptada_por'           => null,
        'aceptada_fecha'         => null,
        'aceptada_observaciones' => null,
        'firma_digital'          => null,
        'created_by'             => $_SESSION['nagsa_user'],
        'created_at'             => $now,
        'updated_at'             => $now,
    ];

    $data['actas'][] = $acta;
    $data['next_id'] = $id + 1;
    saveData($data);

    echo json_encode(['ok' => true, 'id' => $id, 'numero' => $numero]);
    exit;
}

// ── Listar actas con filtros ────────────────────────────────────────────────
if ($action === 'listar') {
    $data  = loadData();
    $actas = $data['actas'];

    // Filtros
    if (!empty($_GET['tipo'])) {
        $tipo = $_GET['tipo'];
        $actas = array_filter($actas, fn($a) => $a['tipo'] === $tipo);
    }
    if (!empty($_GET['estado'])) {
        $estado = $_GET['estado'];
        $actas = array_filter($actas, fn($a) => $a['estado'] === $estado);
    }
    if (!empty($_GET['desde'])) {
        $desde = $_GET['desde'];
        $actas = array_filter($actas, fn($a) => $a['fecha'] >= $desde);
    }
    if (!empty($_GET['hasta'])) {
        $hasta = $_GET['hasta'];
        $actas = array_filter($actas, fn($a) => $a['fecha'] <= $hasta);
    }
    if (!empty($_GET['buscar'])) {
        $q = mb_strtolower($_GET['buscar']);
        $actas = array_filter($actas, fn($a) =>
            str_contains(mb_strtolower($a['numero'] ?? ''), $q) ||
            str_contains(mb_strtolower($a['entregado_por'] ?? ''), $q) ||
            str_contains(mb_strtolower($a['recibido_por'] ?? ''), $q) ||
            str_contains(mb_strtolower($a['autorizado_por'] ?? ''), $q) ||
            str_contains(mb_strtolower($a['retira_persona'] ?? ''), $q) ||
            str_contains(mb_strtolower($a['created_by'] ?? ''), $q)
        );
    }

    // Ordenar por created_at DESC
    usort($actas, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

    // Devolver sin el campo equipos (lista ligera)
    $result = array_map(function ($a) {
        return [
            'id'              => $a['id'],
            'numero'          => $a['numero'],
            'tipo'            => $a['tipo'],
            'fecha'           => $a['fecha'],
            'lugar'           => $a['lugar'],
            'entregado_por'   => $a['entregado_por'],
            'recibido_por'    => $a['recibido_por'],
            'autorizado_por'  => $a['autorizado_por'],
            'retira_persona'  => $a['retira_persona'],
            'total_equipos'   => $a['total_equipos'],
            'estado'          => $a['estado'],
            'created_by'      => $a['created_by'],
            'created_at'      => $a['created_at'],
        ];
    }, $actas);

    echo json_encode(array_values($result), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Obtener detalle de un acta ──────────────────────────────────────────────
if ($action === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        die(json_encode(['error' => 'ID requerido']));
    }

    $data = loadData();
    $acta = null;
    foreach ($data['actas'] as $a) {
        if ($a['id'] === $id) { $acta = $a; break; }
    }

    if (!$acta) {
        http_response_code(404);
        die(json_encode(['error' => 'Acta no encontrada']));
    }

    echo json_encode($acta, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Aceptar / Rechazar acta (resguardo) ─────────────────────────────────────
if ($action === 'resguardo') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($input['id']) || empty($input['estado'])) {
        http_response_code(400);
        die(json_encode(['error' => 'Datos incompletos']));
    }

    $estados_validos = ['aceptada', 'rechazada'];
    if (!in_array($input['estado'], $estados_validos)) {
        http_response_code(400);
        die(json_encode(['error' => 'Estado no válido']));
    }

    $data  = loadData();
    $found = false;
    $now   = date('Y-m-d H:i:s');

    foreach ($data['actas'] as &$acta) {
        if ($acta['id'] === (int)$input['id'] && $acta['estado'] === 'pendiente') {
            $acta['estado']                 = $input['estado'];
            $acta['aceptada_por']           = $input['aceptada_por'] ?? $_SESSION['nagsa_user'];
            $acta['aceptada_fecha']         = $now;
            $acta['aceptada_observaciones'] = $input['observaciones'] ?? null;
            $acta['firma_digital']          = $input['firma'] ?? null;
            $acta['updated_at']             = $now;
            $found = true;
            break;
        }
    }
    unset($acta);

    if (!$found) {
        http_response_code(409);
        die(json_encode(['error' => 'El acta ya fue procesada o no existe']));
    }

    saveData($data);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Estadísticas ────────────────────────────────────────────────────────────
if ($action === 'estadisticas') {
    $data  = loadData();
    $actas = $data['actas'];
    $stats = [];

    // Totales por tipo
    $porTipo = [];
    foreach ($actas as $a) {
        $t = $a['tipo'];
        $porTipo[$t] = ($porTipo[$t] ?? 0) + 1;
    }
    $stats['por_tipo'] = array_map(fn($tipo, $total) => ['tipo' => $tipo, 'total' => $total], array_keys($porTipo), array_values($porTipo));

    // Totales por estado
    $porEstado = [];
    foreach ($actas as $a) {
        $e = $a['estado'];
        $porEstado[$e] = ($porEstado[$e] ?? 0) + 1;
    }
    $stats['por_estado'] = array_map(fn($estado, $total) => ['estado' => $estado, 'total' => $total], array_keys($porEstado), array_values($porEstado));

    // Ultimos 6 meses
    $limite = date('Y-m', strtotime('-6 months'));
    $porMes = [];
    foreach ($actas as $a) {
        $mes = substr($a['fecha'], 0, 7); // yyyy-MM
        if ($mes < $limite) continue;
        $key = $mes . '|' . $a['tipo'];
        $porMes[$key] = ($porMes[$key] ?? 0) + 1;
    }
    ksort($porMes);
    $stats['por_mes'] = array_map(function ($key, $total) {
        [$mes, $tipo] = explode('|', $key);
        return ['mes' => $mes, 'tipo' => $tipo, 'total' => $total];
    }, array_keys($porMes), array_values($porMes));

    // Total general
    $totalEquipos = array_sum(array_column($actas, 'total_equipos'));
    $stats['general'] = ['total' => count($actas), 'equipos' => $totalEquipos];

    // Top creadores
    $porUsuario = [];
    foreach ($actas as $a) {
        $u = $a['created_by'];
        $porUsuario[$u] = ($porUsuario[$u] ?? 0) + 1;
    }
    arsort($porUsuario);
    $stats['top_usuarios'] = array_map(
        fn($user, $total) => ['created_by' => $user, 'total' => $total],
        array_keys(array_slice($porUsuario, 0, 5, true)),
        array_values(array_slice($porUsuario, 0, 5, true))
    );

    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
