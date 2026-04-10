<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../../config/config.php';
$pdo = getDB();

// ── Buscar impresoras por nombre o IP (para Sistema_admin.php) ─────────────
if(isset($_GET['action']) && $_GET['action'] === 'impresoras'){
    $q = trim($_GET['q'] ?? '');
    if(strlen($q) < 1){ die(json_encode([])); }
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.serial,
                m.name AS modelo,
                ip.name AS ip
         FROM glpi_printers p
         LEFT JOIN glpi_printermodels m ON m.id = p.printermodels_id
         LEFT JOIN glpi_networkports np ON np.items_id = p.id AND np.itemtype = 'Printer' AND np.is_deleted = 0
         LEFT JOIN glpi_networknames nn ON nn.items_id = np.id AND nn.itemtype = 'NetworkPort'
         LEFT JOIN glpi_ipaddresses ip ON ip.items_id = nn.id AND ip.itemtype = 'NetworkName'
         WHERE p.is_deleted = 0 AND p.is_template = 0
           AND (p.name LIKE :q1 OR ip.name LIKE :q2)
         GROUP BY p.id
         ORDER BY p.name
         LIMIT 10"
    );
    $stmt->execute([':q1' => '%'.$q.'%', ':q2' => '%'.$q.'%']);
    $rows = [];
    foreach($stmt->fetchAll() as $r){
        $rows[] = [
            'id'     => $r['id'],
            'nombre' => $r['name'],
            'ip'     => $r['ip'] ?: '---',
            'modelo' => $r['modelo'] ?: '---',
            'serie'  => $r['serial'] ?: '---',
        ];
    }
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
    exit;
}

$BASE = $cfg['base_url'] ?? '';
$ENT  = (int)($cfg['entity_id'] ?? 0);
$show = $cfg['show'] ?? [];
$ef   = $ENT > 0 ? 'AND entities_id='.$ENT : '';

function getIP($pdo,$type,$id){
    // GLPI 11: usa 'name' en glpi_ipaddresses (texto directo)
    // GLPI 10: usaba 'ip_number_2' (entero, requería long2ip)
    $s=$pdo->prepare("SELECT ip.name FROM glpi_networkports np JOIN glpi_networknames nn ON nn.items_id=np.id AND nn.itemtype='NetworkPort' JOIN glpi_ipaddresses ip ON ip.items_id=nn.id AND ip.itemtype='NetworkName' WHERE np.itemtype=:t AND np.items_id=:id AND np.is_deleted=0 LIMIT 1");
    $s->execute([':t'=>$type,':id'=>$id]);$r=$s->fetch();return $r?$r['name']:'---';
}
function getUsuario($pdo,$uid){if(!$uid)return'';$s=$pdo->prepare("SELECT CONCAT(firstname,' ',realname) n FROM glpi_users WHERE id=:id LIMIT 1");$s->execute([':id'=>$uid]);$r=$s->fetch();return $r?trim($r['n']):'';} 
function getFab($pdo,$mid){if(!$mid)return'';$s=$pdo->prepare("SELECT name FROM glpi_manufacturers WHERE id=:id LIMIT 1");$s->execute([':id'=>$mid]);$r=$s->fetch();return $r?$r['name']:'';}
function getLookup($pdo,$table,$id){if(!$id)return'---';$s=$pdo->prepare("SELECT name FROM {$table} WHERE id=:id LIMIT 1");$s->execute([':id'=>$id]);$r=$s->fetch();return $r?$r['name']:'---';}
function getEstado($pdo,$id){if(!$id)return'';$s=$pdo->prepare("SELECT name FROM glpi_states WHERE id=:id LIMIT 1");$s->execute([':id'=>$id]);$r=$s->fetch();return $r?$r['name']:'';}
function qrUrl($base,$type,$id){$map=['Computer'=>'computer.form.php','Monitor'=>'monitor.form.php','Printer'=>'printer.form.php','Peripheral'=>'peripheral.form.php','NetworkEquipment'=>'networkequipment.form.php','Enclosure'=>'enclosure.form.php','PassiveDCEquipment'=>'passivedcequipment.form.php','CartridgeItem'=>'cartridgeitem.form.php','ConsumableItem'=>'consumableitem.form.php','Phone'=>'phone.form.php'];$f=$map[$type]??'computer.form.php';return $base?$base.'/front/'.$f.'?id='.$id:$type.'?id='.$id;}

$out=[];

if(!empty($show['computadoras'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,computermodels_id,computertypes_id,users_id,states_id FROM glpi_computers WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['computadoras'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_computermodels',$r['computermodels_id']),'serie'=>$r['serial']?:'---','ip'=>getIP($pdo,'Computer',$r['id']),'tipo'=>getLookup($pdo,'glpi_computertypes',$r['computertypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Computer',$r['id']),'activo'=>'Computadora'];}
if(!empty($show['monitores'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,monitormodels_id,monitortypes_id,users_id,states_id FROM glpi_monitors WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['monitores'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_monitormodels',$r['monitormodels_id']),'serie'=>$r['serial']?:'---','ip'=>'---','tipo'=>getLookup($pdo,'glpi_monitortypes',$r['monitortypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Monitor',$r['id']),'activo'=>'Monitor'];}
if(!empty($show['impresoras'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,printermodels_id,printertypes_id,users_id,states_id FROM glpi_printers WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['impresoras'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_printermodels',$r['printermodels_id']),'serie'=>$r['serial']?:'---','ip'=>getIP($pdo,'Printer',$r['id']),'tipo'=>getLookup($pdo,'glpi_printertypes',$r['printertypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Printer',$r['id']),'activo'=>'Impresora'];}
if(!empty($show['perifericos'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,peripheralmodels_id,peripheraltypes_id,users_id,states_id FROM glpi_peripherals WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['perifericos'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_peripheralmodels',$r['peripheralmodels_id']),'serie'=>$r['serial']?:'---','ip'=>'---','tipo'=>getLookup($pdo,'glpi_peripheraltypes',$r['peripheraltypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Peripheral',$r['id']),'activo'=>'Periferico'];}
if(!empty($show['redes'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,networkequipmentmodels_id,networkequipmenttypes_id,users_id,states_id FROM glpi_networkequipments WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['redes'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_networkequipmentmodels',$r['networkequipmentmodels_id']),'serie'=>$r['serial']?:'---','ip'=>getIP($pdo,'NetworkEquipment',$r['id']),'tipo'=>getLookup($pdo,'glpi_networkequipmenttypes',$r['networkequipmenttypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'NetworkEquipment',$r['id']),'activo'=>'Dispositivo de Red'];}
if(!empty($show['gabinetes'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,enclosuremodels_id,states_id FROM glpi_enclosures WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['gabinetes'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_enclosuremodels',$r['enclosuremodels_id']),'serie'=>$r['serial']?:'---','ip'=>'---','tipo'=>'Gabinete','usuario'=>'','estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Enclosure',$r['id']),'activo'=>'Gabinete'];}
if(!empty($show['telefonos'])){$rows=$pdo->query("SELECT id,name,serial,manufacturers_id,phonemodels_id,phonetypes_id,users_id,states_id FROM glpi_phones WHERE is_deleted=0 AND is_template=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['telefonos'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>getLookup($pdo,'glpi_phonemodels',$r['phonemodels_id']),'serie'=>$r['serial']?:'---','ip'=>getIP($pdo,'Phone',$r['id']),'tipo'=>getLookup($pdo,'glpi_phonetypes',$r['phonetypes_id']),'usuario'=>getUsuario($pdo,$r['users_id']),'estado'=>getEstado($pdo,$r['states_id']),'qr_url'=>qrUrl($BASE,'Phone',$r['id']),'activo'=>'Telefono'];}
// GLPI 11: cartridgeitems y consumableitems no tienen states_id
if(!empty($show['cartuchos'])){$rows=$pdo->query("SELECT id,name,manufacturers_id,cartridgeitemtypes_id FROM glpi_cartridgeitems WHERE is_deleted=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['cartuchos'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>'---','serie'=>'---','ip'=>'---','tipo'=>getLookup($pdo,'glpi_cartridgeitemtypes',$r['cartridgeitemtypes_id']),'usuario'=>'','estado'=>'','qr_url'=>qrUrl($BASE,'CartridgeItem',$r['id']),'activo'=>'Cartucho'];}
if(!empty($show['consumibles'])){$rows=$pdo->query("SELECT id,name,manufacturers_id,consumableitemtypes_id FROM glpi_consumableitems WHERE is_deleted=0 $ef ORDER BY name")->fetchAll();foreach($rows as $r)$out['consumibles'][]=['id'=>(string)$r['id'],'nombre'=>$r['name'],'fabricante'=>getFab($pdo,$r['manufacturers_id']),'modelo'=>'---','serie'=>'---','ip'=>'---','tipo'=>getLookup($pdo,'glpi_consumableitemtypes',$r['consumableitemtypes_id']),'usuario'=>'','estado'=>'','qr_url'=>qrUrl($BASE,'ConsumableItem',$r['id']),'activo'=>'Consumible'];}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
