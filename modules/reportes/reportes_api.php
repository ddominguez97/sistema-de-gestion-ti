<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';

session_start();
if (!isset($_SESSION['nagsa_user'])) {
    http_response_code(401);
    die(json_encode(['error' => 'No autorizado']));
}

$db     = getLocalDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Guardar acta nueva ──────────────────────────────────────────────────────
if ($action === 'guardar') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        die(json_encode(['error' => 'Datos inválidos']));
    }

    $numero = $input['numero'] ?? ('ACT-' . substr(time(), -6));
    $tipo   = $input['tipo']   ?? 'entrega';

    $sql = "INSERT INTO actas (
        numero, tipo, fecha, lugar,
        entregado_por, entregado_cargo, recibido_por, recibido_cargo,
        autorizado_por, autorizado_cargo, motivo, destino, retira_persona, retira_cargo,
        observaciones, equipos, total_equipos,
        estado, created_by
    ) VALUES (
        :numero, :tipo, :fecha, :lugar,
        :entregado_por, :entregado_cargo, :recibido_por, :recibido_cargo,
        :autorizado_por, :autorizado_cargo, :motivo, :destino, :retira_persona, :retira_cargo,
        :observaciones, :equipos, :total_equipos,
        'pendiente', :created_by
    )";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':numero'           => $numero,
        ':tipo'             => $tipo,
        ':fecha'            => $input['fecha'] ?? date('Y-m-d'),
        ':lugar'            => $input['lugar'] ?? null,
        ':entregado_por'    => $input['entregado_por'] ?? null,
        ':entregado_cargo'  => $input['entregado_cargo'] ?? null,
        ':recibido_por'     => $input['recibido_por'] ?? null,
        ':recibido_cargo'   => $input['recibido_cargo'] ?? null,
        ':autorizado_por'   => $input['autorizado_por'] ?? null,
        ':autorizado_cargo' => $input['autorizado_cargo'] ?? null,
        ':motivo'           => $input['motivo'] ?? null,
        ':destino'          => $input['destino'] ?? null,
        ':retira_persona'   => $input['retira_persona'] ?? null,
        ':retira_cargo'     => $input['retira_cargo'] ?? null,
        ':observaciones'    => $input['observaciones'] ?? null,
        ':equipos'          => json_encode($input['equipos'] ?? [], JSON_UNESCAPED_UNICODE),
        ':total_equipos'    => count($input['equipos'] ?? []),
        ':created_by'       => $_SESSION['nagsa_user'],
    ]);

    $id = $db->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $id, 'numero' => $numero]);
    exit;
}

// ── Listar actas con filtros ────────────────────────────────────────────────
if ($action === 'listar') {
    $where  = [];
    $params = [];

    if (!empty($_GET['tipo'])) {
        $where[]       = 'tipo = :tipo';
        $params[':tipo'] = $_GET['tipo'];
    }
    if (!empty($_GET['estado'])) {
        $where[]         = 'estado = :estado';
        $params[':estado'] = $_GET['estado'];
    }
    if (!empty($_GET['desde'])) {
        $where[]         = 'fecha >= :desde';
        $params[':desde'] = $_GET['desde'];
    }
    if (!empty($_GET['hasta'])) {
        $where[]         = 'fecha <= :hasta';
        $params[':hasta'] = $_GET['hasta'];
    }
    if (!empty($_GET['buscar'])) {
        $where[] = '(numero LIKE :q1 OR entregado_por LIKE :q2 OR recibido_por LIKE :q3 OR autorizado_por LIKE :q4 OR retira_persona LIKE :q5 OR created_by LIKE :q6)';
        $q = '%' . $_GET['buscar'] . '%';
        $params[':q1'] = $q;
        $params[':q2'] = $q;
        $params[':q3'] = $q;
        $params[':q4'] = $q;
        $params[':q5'] = $q;
        $params[':q6'] = $q;
    }

    $sql = "SELECT id, numero, tipo, fecha, lugar,
                   entregado_por, recibido_por, autorizado_por, retira_persona,
                   total_equipos, estado, created_by, created_at
            FROM actas";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Obtener detalle de un acta ──────────────────────────────────────────────
if ($action === 'detalle') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) {
        http_response_code(400);
        die(json_encode(['error' => 'ID requerido']));
    }
    $stmt = $db->prepare("SELECT * FROM actas WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $acta = $stmt->fetch();
    if (!$acta) {
        http_response_code(404);
        die(json_encode(['error' => 'Acta no encontrada']));
    }
    $acta['equipos'] = json_decode($acta['equipos'], true) ?: [];
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

    $sql = "UPDATE actas SET
                estado = :estado,
                aceptada_por = :aceptada_por,
                aceptada_fecha = GETDATE(),
                aceptada_observaciones = :obs,
                firma_digital = :firma,
                updated_at = GETDATE()
            WHERE id = :id AND estado = 'pendiente'";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':estado'      => $input['estado'],
        ':aceptada_por'=> $input['aceptada_por'] ?? $_SESSION['nagsa_user'],
        ':obs'         => $input['observaciones'] ?? null,
        ':firma'       => $input['firma'] ?? null,
        ':id'          => (int)$input['id'],
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(409);
        die(json_encode(['error' => 'El acta ya fue procesada o no existe']));
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ── Estadísticas ────────────────────────────────────────────────────────────
if ($action === 'estadisticas') {
    $stats = [];

    // Totales por tipo
    $stmt = $db->query("SELECT tipo, COUNT(*) as total FROM actas GROUP BY tipo");
    $stats['por_tipo'] = $stmt->fetchAll();

    // Totales por estado
    $stmt = $db->query("SELECT estado, COUNT(*) as total FROM actas GROUP BY estado");
    $stats['por_estado'] = $stmt->fetchAll();

    // Ultimos 6 meses
    $stmt = $db->query("
        SELECT FORMAT(fecha, 'yyyy-MM') as mes, tipo, COUNT(*) as total
        FROM actas
        WHERE fecha >= DATEADD(MONTH, -6, GETDATE())
        GROUP BY FORMAT(fecha, 'yyyy-MM'), tipo
        ORDER BY mes
    ");
    $stats['por_mes'] = $stmt->fetchAll();

    // Total general
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(total_equipos) as equipos FROM actas");
    $stats['general'] = $stmt->fetch();

    // Top creadores
    $stmt = $db->query("SELECT TOP 5 created_by, COUNT(*) as total FROM actas GROUP BY created_by ORDER BY total DESC");
    $stats['top_usuarios'] = $stmt->fetchAll();

    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Acción no válida']);
