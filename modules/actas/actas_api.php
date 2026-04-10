<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/config.php';
$pdo = getDB();

$action = $_GET['action'] ?? '';

// ── Buscar equipo por nombre ────────────────────────────────
if($action === 'buscar'){
    $q = trim($_GET['q'] ?? '');
    if(strlen($q) < 2){ die(json_encode([])); }
    $results = [];
    // Categorías con serial, usuario, estado, ubicación
    $tipos = [
        ['tabla'=>'glpi_computers',        'tipo'=>'Computadora',    'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_monitors',          'tipo'=>'Monitor',        'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_printers',          'tipo'=>'Impresora',      'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_peripherals',       'tipo'=>'Periférico',     'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_networkequipments', 'tipo'=>'Disp. de Red',   'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_phones',            'tipo'=>'Teléfono',       'has_serial'=>true,'has_user'=>true,'has_state'=>true],
        ['tabla'=>'glpi_enclosures',        'tipo'=>'Gabinete',       'has_serial'=>true,'has_user'=>false,'has_state'=>true],
    ];
    foreach($tipos as $t){
        $stmt = $pdo->prepare("SELECT id,name,serial,manufacturers_id,users_id,states_id,locations_id
            FROM {$t['tabla']} WHERE name LIKE :q AND is_deleted=0 AND is_template=0 LIMIT 10");
        $stmt->execute([':q'=>'%'.$q.'%']);
        foreach($stmt->fetchAll() as $r){
            $results[] = [
                'id'          => $r['id'],
                'nombre'      => $r['name'],
                'serie'       => $r['serial'] ?: '---',
                'fabricante'  => getFab($pdo,$r['manufacturers_id']),
                'modelo'      => getModelo($pdo,$t['tabla'],$r['id']),
                'usuario'     => $t['has_user'] ? getUsuario($pdo,$r['users_id']) : '---',
                'estado'      => getEstado($pdo,$r['states_id']),
                'estado_id'   => $r['states_id'],
                'ubicacion'   => getUbicacion($pdo,$r['locations_id']),
                'ubicacion_id'=> $r['locations_id'],
                'tipo'        => $t['tipo'],
                'tabla'       => $t['tabla'],
            ];
        }
    }

    // Consumibles y Cartuchos — incluye tipo, grupo a cargo y stock disponible
    $tiposSimples = [
        ['tabla'=>'glpi_consumableitems', 'tipo_label'=>'Consumible', 'type_col'=>'consumableitemtypes_id', 'type_table'=>'glpi_consumableitemtypes', 'stock_table'=>'glpi_consumables',   'stock_fk'=>'consumableitems_id'],
        ['tabla'=>'glpi_cartridgeitems',  'tipo_label'=>'Cartucho',   'type_col'=>'cartridgeitemtypes_id',  'type_table'=>'glpi_cartridgeitemtypes',  'stock_table'=>'glpi_cartridges',     'stock_fk'=>'cartridgeitems_id'],
    ];
    foreach($tiposSimples as $t){
        $stmt = $pdo->prepare("SELECT c.id, c.name, c.manufacturers_id, c.{$t['type_col']},
                g.name AS grupo_nombre,
                (SELECT COUNT(*) FROM {$t['stock_table']} s WHERE s.{$t['stock_fk']}=c.id AND s.date_out IS NULL) AS stock_disponible
            FROM {$t['tabla']} c
            LEFT JOIN glpi_groups_items gi ON gi.items_id=c.id AND gi.itemtype=:itemtype AND gi.type=1
            LEFT JOIN glpi_groups g ON g.id=gi.groups_id
            WHERE c.name LIKE :q AND c.is_deleted=0 LIMIT 10");
        $itemtype = $t['tabla']==='glpi_consumableitems' ? 'ConsumableItem' : 'CartridgeItem';
        $stmt->execute([':q'=>'%'.$q.'%', ':itemtype'=>$itemtype]);
        foreach($stmt->fetchAll() as $r){
            $tipo_nombre = getLookup($pdo, $t['type_table'], $r[$t['type_col']]);
            $results[] = [
                'id'          => $r['id'],
                'nombre'      => $r['name'],
                'serie'       => '---',
                'fabricante'  => getFab($pdo,$r['manufacturers_id']),
                'modelo'      => $tipo_nombre ?: '---',
                'usuario'     => $r['grupo_nombre'] ? 'Grupo: '.$r['grupo_nombre'] : '---',
                'estado'      => '---',
                'estado_id'   => '',
                'ubicacion'   => '---',
                'ubicacion_id'=> '',
                'tipo'        => $t['tipo_label'],
                'tabla'       => $t['tabla'],
                'stock'       => (int)$r['stock_disponible'],
            ];
        }
    }
    echo json_encode($results, JSON_UNESCAPED_UNICODE); exit;
}

// ── Obtener ubicaciones de GLPI ─────────────────────────────
if($action === 'ubicaciones'){
    $rows = $pdo->query("SELECT id, name, completename FROM glpi_locations ORDER BY completename")->fetchAll();
    echo json_encode($rows, JSON_UNESCAPED_UNICODE); exit;
}

// ── Obtener estados de GLPI ─────────────────────────────────
if($action === 'estados'){
    $rows = $pdo->query("SELECT id, name FROM glpi_states ORDER BY name")->fetchAll();
    echo json_encode($rows, JSON_UNESCAPED_UNICODE); exit;
}

// ── Buscar usuarios (local + dominio) ───────────────────────
if($action === 'usuarios'){
    $q = trim($_GET['q'] ?? '');
    if(strlen($q) < 2){ die(json_encode([])); }
    $stmt = $pdo->prepare("SELECT id, name, firstname, realname
        FROM glpi_users
        WHERE is_deleted=0
        AND (
            firstname LIKE :q1
            OR realname LIKE :q2
            OR name LIKE :q3
            OR CONCAT(firstname,' ',realname) LIKE :q4
            OR CONCAT(realname,' ',firstname) LIKE :q5
        )
        ORDER BY realname, firstname LIMIT 15");
    $stmt->execute([':q1'=>'%'.$q.'%',':q2'=>'%'.$q.'%',':q3'=>'%'.$q.'%',':q4'=>'%'.$q.'%',':q5'=>'%'.$q.'%']);
    $users = [];
    foreach($stmt->fetchAll() as $r){
        $nombre = trim($r['firstname'].' '.$r['realname']);
        if(!$nombre) $nombre = $r['name'];
        $users[] = [
            'id'     => $r['id'],
            'name'   => $r['name'],
            'nombre' => $nombre,
        ];
    }
    echo json_encode($users, JSON_UNESCAPED_UNICODE); exit;
}

// ── Helpers ─────────────────────────────────────────────────
function getLookup($pdo,$table,$id){if(!$id)return'---';$s=$pdo->prepare("SELECT name FROM {$table} WHERE id=:id LIMIT 1");$s->execute([':id'=>$id]);$r=$s->fetch();return $r?$r['name']:'---';}
function getFab($pdo,$id){
    if(!$id) return '---';
    $s=$pdo->prepare("SELECT name FROM glpi_manufacturers WHERE id=:id LIMIT 1");
    $s->execute([':id'=>$id]); $r=$s->fetch(); return $r?$r['name']:'---';
}
function getModelo($pdo,$tabla,$itemId){
    $map=[
        'glpi_computers'=>'glpi_computermodels',
        'glpi_monitors'=>'glpi_monitormodels',
        'glpi_printers'=>'glpi_printermodels',
        'glpi_peripherals'=>'glpi_peripheralmodels',
        'glpi_networkequipments'=>'glpi_networkequipmentmodels',
        'glpi_phones'=>'glpi_phonemodels',
    ];
    $colMap=[
        'glpi_computers'=>'computermodels_id',
        'glpi_monitors'=>'monitormodels_id',
        'glpi_printers'=>'printermodels_id',
        'glpi_peripherals'=>'peripheralmodels_id',
        'glpi_networkequipments'=>'networkequipmentmodels_id',
        'glpi_phones'=>'phonemodels_id',
    ];
    if(!isset($map[$tabla])) return '---';
    $col=$colMap[$tabla];
    $s=$pdo->prepare("SELECT t.name FROM {$tabla} i JOIN {$map[$tabla]} t ON t.id=i.{$col} WHERE i.id=:id LIMIT 1");
    $s->execute([':id'=>$itemId]); $r=$s->fetch(); return $r?$r['name']:'---';
}
function getUsuario($pdo,$id){
    if(!$id) return '---';
    $s=$pdo->prepare("SELECT CONCAT(firstname,' ',realname) n FROM glpi_users WHERE id=:id LIMIT 1");
    $s->execute([':id'=>$id]); $r=$s->fetch(); return $r?trim($r['n']):'---';
}
function getEstado($pdo,$id){
    if(!$id) return '---';
    $s=$pdo->prepare("SELECT name FROM glpi_states WHERE id=:id LIMIT 1");
    $s->execute([':id'=>$id]); $r=$s->fetch(); return $r?$r['name']:'---';
}
function getUbicacion($pdo,$id){
    if(!$id) return '---';
    $s=$pdo->prepare("SELECT name FROM glpi_locations WHERE id=:id LIMIT 1");
    $s->execute([':id'=>$id]); $r=$s->fetch(); return $r?$r['name']:'---';
}

// ── Proveedores ───────────────────────────────────────────────────────────────
if($action === 'proveedores'){
    $q = trim($_GET['q'] ?? '');
    if(strlen($q) < 2){ echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT id, name FROM glpi_suppliers WHERE name LIKE :q AND is_deleted=0 ORDER BY name LIMIT 15");
    $stmt->execute([':q'=>'%'.$q.'%']);
    $rows = $stmt->fetchAll();
    echo json_encode(array_map(fn($r)=>['id'=>$r['id'],'nombre'=>$r['name']], $rows));
    exit;
}

http_response_code(400);
echo json_encode(['error'=>'Acción no válida']);

?>