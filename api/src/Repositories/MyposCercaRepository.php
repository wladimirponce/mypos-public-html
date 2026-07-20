<?php

declare(strict_types=1);

namespace Mypos\Repositories;

use PDO;

final class MyposCercaRepository
{
    public function __construct(private readonly PDO $db) {}

    public function connection(): PDO { return $this->db; }

    public function profile(int $empresaId, int $sucursalId): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM presencia_digital_perfiles WHERE empresa_id=:empresa_id AND sucursal_id=:sucursal_id LIMIT 1');
        $stmt->execute(['empresa_id'=>$empresaId,'sucursal_id'=>$sucursalId]);
        $row=$stmt->fetch();
        if (!is_array($row)) return null;
        $row['horarios']=$this->hours((int)$row['id']);
        return $row;
    }

    public function profileBySlug(string $slug): ?array
    {
        $stmt=$this->db->prepare("SELECT p.* FROM presencia_digital_perfiles p INNER JOIN empresas e ON e.id=p.empresa_id AND e.activo=1 INNER JOIN sucursales s ON s.id=p.sucursal_id AND s.activo=1 WHERE p.slug=:slug AND p.publicado=1 LIMIT 1");
        $stmt->execute(['slug'=>$slug]);
        $row=$stmt->fetch();
        if (!is_array($row)) return null;
        $row['horarios']=$this->hours((int)$row['id']);
        return $row;
    }

    public function upsertProfile(array $d): int
    {
        $stmt=$this->db->prepare("INSERT INTO presencia_digital_perfiles
            (empresa_id,sucursal_id,slug,nombre_publico,descripcion,categoria,logo_url,portada_url,telefono_publico,whatsapp,direccion_publica,comuna,ciudad,latitud,longitud,permite_retiro,permite_despacho,permite_cotizaciones,permite_reservas,anticipo_tipo,anticipo_valor,permite_pago_superior,reserva_expira_minutos,publicado,cerrado_temporalmente,privacidad_json)
            VALUES (:empresa_id,:sucursal_id,:slug,:nombre_publico,:descripcion,:categoria,:logo_url,:portada_url,:telefono_publico,:whatsapp,:direccion_publica,:comuna,:ciudad,:latitud,:longitud,:permite_retiro,:permite_despacho,:permite_cotizaciones,:permite_reservas,:anticipo_tipo,:anticipo_valor,:permite_pago_superior,:reserva_expira_minutos,:publicado,:cerrado_temporalmente,:privacidad_json)
            ON DUPLICATE KEY UPDATE slug=VALUES(slug),nombre_publico=VALUES(nombre_publico),descripcion=VALUES(descripcion),categoria=VALUES(categoria),logo_url=VALUES(logo_url),portada_url=VALUES(portada_url),telefono_publico=VALUES(telefono_publico),whatsapp=VALUES(whatsapp),direccion_publica=VALUES(direccion_publica),comuna=VALUES(comuna),ciudad=VALUES(ciudad),latitud=VALUES(latitud),longitud=VALUES(longitud),permite_retiro=VALUES(permite_retiro),permite_despacho=VALUES(permite_despacho),permite_cotizaciones=VALUES(permite_cotizaciones),permite_reservas=VALUES(permite_reservas),anticipo_tipo=VALUES(anticipo_tipo),anticipo_valor=VALUES(anticipo_valor),permite_pago_superior=VALUES(permite_pago_superior),reserva_expira_minutos=VALUES(reserva_expira_minutos),publicado=VALUES(publicado),cerrado_temporalmente=VALUES(cerrado_temporalmente),privacidad_json=VALUES(privacidad_json),id=LAST_INSERT_ID(id)");
        $stmt->execute($d);
        return (int)$this->db->lastInsertId();
    }

    public function replaceHours(int $empresaId,int $profileId,array $hours): void
    {
        $del=$this->db->prepare('DELETE FROM presencia_digital_horarios WHERE empresa_id=:empresa_id AND perfil_id=:perfil_id');
        $del->execute(['empresa_id'=>$empresaId,'perfil_id'=>$profileId]);
        $ins=$this->db->prepare('INSERT INTO presencia_digital_horarios (empresa_id,perfil_id,dia_semana,apertura,cierre,cerrado) VALUES (:empresa_id,:perfil_id,:dia_semana,:apertura,:cierre,:cerrado)');
        foreach($hours as $h){$ins->execute(['empresa_id'=>$empresaId,'perfil_id'=>$profileId,'dia_semana'=>$h['dia_semana'],'apertura'=>$h['apertura'],'cierre'=>$h['cierre'],'cerrado'=>$h['cerrado']]);}
    }

    public function hours(int $profileId): array
    {
        $stmt=$this->db->prepare('SELECT dia_semana,apertura,cierre,cerrado FROM presencia_digital_horarios WHERE perfil_id=:perfil_id ORDER BY dia_semana');
        $stmt->execute(['perfil_id'=>$profileId]);
        return $stmt->fetchAll() ?: [];
    }

    public function products(int $empresaId,?int $sucursalId=null): array
    {
        $sql="SELECT p.id producto_id,p.nombre,p.precio_venta,p.activo,pdp.*,COALESCE(ss.cantidad,0) cantidad,COALESCE(ss.reservado,0) reservado FROM productos p LEFT JOIN presencia_digital_productos pdp ON pdp.producto_id=p.id AND pdp.empresa_id=p.empresa_id ";
        $params=['empresa_id'=>$empresaId];
        if($sucursalId!==null){$sql.='AND pdp.sucursal_id=:sucursal_join LEFT JOIN stock_sucursal ss ON ss.empresa_id=p.empresa_id AND ss.producto_id=p.id AND ss.sucursal_id=:sucursal_stock ';$params['sucursal_join']=$sucursalId;$params['sucursal_stock']=$sucursalId;}else{$sql.='LEFT JOIN stock_sucursal ss ON 1=0 ';}
        $sql.='WHERE p.empresa_id=:empresa_id AND p.activo=1 ORDER BY p.nombre LIMIT 1000';
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];
    }

    public function upsertProduct(array $d): int
    {
        $stmt=$this->db->prepare("INSERT INTO presencia_digital_productos (empresa_id,sucursal_id,producto_id,slug,nombre_publico,descripcion_publica,imagen_url,palabras_busqueda,categoria_publica,mostrar_precio,precio_online,stock_protegido,mostrar_agotado,permite_cotizacion,permite_reserva,exige_pago_total,regulacion,publicado)
            VALUES (:empresa_id,:sucursal_id,:producto_id,:slug,:nombre_publico,:descripcion_publica,:imagen_url,:palabras_busqueda,:categoria_publica,:mostrar_precio,:precio_online,:stock_protegido,:mostrar_agotado,:permite_cotizacion,:permite_reserva,:exige_pago_total,:regulacion,:publicado)
            ON DUPLICATE KEY UPDATE slug=VALUES(slug),nombre_publico=VALUES(nombre_publico),descripcion_publica=VALUES(descripcion_publica),imagen_url=VALUES(imagen_url),palabras_busqueda=VALUES(palabras_busqueda),categoria_publica=VALUES(categoria_publica),mostrar_precio=VALUES(mostrar_precio),precio_online=VALUES(precio_online),stock_protegido=VALUES(stock_protegido),mostrar_agotado=VALUES(mostrar_agotado),permite_cotizacion=VALUES(permite_cotizacion),permite_reserva=VALUES(permite_reserva),exige_pago_total=VALUES(exige_pago_total),regulacion=VALUES(regulacion),publicado=VALUES(publicado),id=LAST_INSERT_ID(id)");
        $stmt->execute($d);return (int)$this->db->lastInsertId();
    }

    public function publicProduct(string $slug): ?array
    {
        $stmt=$this->db->prepare("SELECT pdp.*,p.nombre nombre_interno,p.precio_venta,pp.slug local_slug,pp.nombre_publico local_nombre,pp.direccion_publica,pp.comuna,pp.ciudad,pp.latitud,pp.longitud,COALESCE(ss.cantidad,0) cantidad,COALESCE(ss.reservado,0) reservado FROM presencia_digital_productos pdp INNER JOIN productos p ON p.id=pdp.producto_id AND p.activo=1 INNER JOIN presencia_digital_perfiles pp ON pp.empresa_id=pdp.empresa_id AND pp.sucursal_id=pdp.sucursal_id AND pp.publicado=1 LEFT JOIN stock_sucursal ss ON ss.empresa_id=pdp.empresa_id AND ss.sucursal_id=pdp.sucursal_id AND ss.producto_id=pdp.producto_id WHERE pdp.slug=:slug AND pdp.publicado=1 LIMIT 1");
        $stmt->execute(['slug'=>$slug]);$row=$stmt->fetch();return is_array($row)?$row:null;
    }

    public function search(string $q,?float $lat,?float $lng,int $limit): array
    {
        $distance=$lat!==null&&$lng!==null?'(6371*ACOS(LEAST(1,COS(RADIANS(:lat_cos))*COS(RADIANS(pp.latitud))*COS(RADIANS(pp.longitud)-RADIANS(:lng_dist))+SIN(RADIANS(:lat_sin))*SIN(RADIANS(pp.latitud)))))':'NULL';
        $sql="SELECT pdp.empresa_id,pdp.sucursal_id,pdp.producto_id,pdp.slug producto_slug,COALESCE(NULLIF(pdp.nombre_publico,''),p.nombre) producto_nombre,pdp.imagen_url,pdp.permite_cotizacion,pdp.permite_reserva,pdp.exige_pago_total,pp.slug local_slug,pp.nombre_publico local_nombre,pp.direccion_publica,pp.comuna,pp.ciudad,pp.latitud,pp.longitud,pp.whatsapp,pp.cerrado_temporalmente,CASE WHEN pdp.mostrar_precio=1 THEN COALESCE(pdp.precio_online,p.precio_venta) ELSE NULL END precio,CASE WHEN COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido<=0 THEN 'AGOTADO' WHEN COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido<=GREATEST(1,ss.stock_minimo) THEN 'POCAS_UNIDADES' ELSE 'DISPONIBLE' END disponibilidad,{$distance} distancia_km,COALESCE(ss.updated_at,pdp.updated_at) stock_actualizado_at FROM presencia_digital_productos pdp INNER JOIN productos p ON p.id=pdp.producto_id AND p.activo=1 INNER JOIN presencia_digital_perfiles pp ON pp.empresa_id=pdp.empresa_id AND pp.sucursal_id=pdp.sucursal_id AND pp.publicado=1 LEFT JOIN stock_sucursal ss ON ss.empresa_id=pdp.empresa_id AND ss.sucursal_id=pdp.sucursal_id AND ss.producto_id=pdp.producto_id WHERE pdp.publicado=1 AND (pdp.mostrar_agotado=1 OR COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido>0) AND (COALESCE(NULLIF(pdp.nombre_publico,''),p.nombre) LIKE :q_name OR pdp.palabras_busqueda LIKE :q_words OR pdp.categoria_publica LIKE :q_category) ORDER BY (disponibilidad='DISPONIBLE') DESC";
        if($lat!==null&&$lng!==null)$sql.=',distancia_km ASC';$sql.=' LIMIT '.(int)$limit;
        $params=['q_name'=>'%'.$q.'%','q_words'=>'%'.$q.'%','q_category'=>'%'.$q.'%'];if($lat!==null&&$lng!==null){$params+=['lat_cos'=>$lat,'lng_dist'=>$lng,'lat_sin'=>$lat];}
        $stmt=$this->db->prepare($sql);$stmt->execute($params);return $stmt->fetchAll()?:[];
    }

    public function publishedProductsForStore(int $empresaId,int $sucursalId): array
    {
        $stmt=$this->db->prepare("SELECT pdp.slug,COALESCE(NULLIF(pdp.nombre_publico,''),p.nombre) nombre,pdp.imagen_url,pdp.permite_cotizacion,pdp.permite_reserva,CASE WHEN pdp.mostrar_precio=1 THEN COALESCE(pdp.precio_online,p.precio_venta) ELSE NULL END precio,CASE WHEN COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido<=0 THEN 'AGOTADO' WHEN COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido<=GREATEST(1,ss.stock_minimo) THEN 'POCAS_UNIDADES' ELSE 'DISPONIBLE' END disponibilidad FROM presencia_digital_productos pdp INNER JOIN productos p ON p.id=pdp.producto_id AND p.activo=1 LEFT JOIN stock_sucursal ss ON ss.empresa_id=pdp.empresa_id AND ss.sucursal_id=pdp.sucursal_id AND ss.producto_id=pdp.producto_id WHERE pdp.empresa_id=:empresa_id AND pdp.sucursal_id=:sucursal_id AND pdp.publicado=1 AND (pdp.mostrar_agotado=1 OR COALESCE(ss.cantidad,0)-COALESCE(ss.reservado,0)-pdp.stock_protegido>0) ORDER BY nombre LIMIT 500");
        $stmt->execute(['empresa_id'=>$empresaId,'sucursal_id'=>$sucursalId]);return $stmt->fetchAll()?:[];
    }

    public function insertQuote(array $d,array $items): int
    {
        $stmt=$this->db->prepare('INSERT INTO presencia_cotizaciones_publicas (empresa_id,sucursal_id,codigo,estado,nombre_cliente,email_cliente,telefono_cliente,mensaje,expires_at) VALUES (:empresa_id,:sucursal_id,:codigo,\'SOLICITADA\',:nombre_cliente,:email_cliente,:telefono_cliente,:mensaje,:expires_at)');$stmt->execute($d);$id=(int)$this->db->lastInsertId();
        $ins=$this->db->prepare('INSERT INTO presencia_cotizaciones_items (empresa_id,solicitud_id,producto_id,cantidad,nombre_snapshot,precio_snapshot) VALUES (:empresa_id,:solicitud_id,:producto_id,:cantidad,:nombre_snapshot,:precio_snapshot)');foreach($items as $item){$ins->execute($item+['solicitud_id'=>$id]);}
        $this->enqueueMail((int)$d['empresa_id'],(string)$d['email_cliente'],(string)$d['nombre_cliente'],'Cotización '.(string)$d['codigo'].' recibida','Recibimos tu solicitud. El local responderá de forma asíncrona a este correo.','cotizacion_recibida','Confirmar recepción al cliente');
        $this->enqueueBusinessMail((int)$d['empresa_id'],'Nueva cotización '.(string)$d['codigo'],'Hay una nueva solicitud de cotización que requiere respuesta.','nueva_cotizacion','Responder oportunamente al cliente');
        return $id;
    }

    public function publicItems(array $productIds,int $empresaId,int $sucursalId): array
    {
        if($productIds===[])return[];$ids=implode(',',array_map('intval',$productIds));
        $stmt=$this->db->prepare("SELECT pdp.producto_id,pdp.stock_protegido,pdp.permite_cotizacion,pdp.permite_reserva,pdp.exige_pago_total,COALESCE(NULLIF(pdp.nombre_publico,''),p.nombre) nombre,COALESCE(pdp.precio_online,p.precio_venta) precio FROM presencia_digital_productos pdp INNER JOIN productos p ON p.id=pdp.producto_id WHERE pdp.empresa_id=:empresa_id AND pdp.sucursal_id=:sucursal_id AND pdp.publicado=1 AND pdp.producto_id IN ({$ids})");
        $stmt->execute(['empresa_id'=>$empresaId,'sucursal_id'=>$sucursalId]);$rows=$stmt->fetchAll()?:[];$out=[];foreach($rows as $row)$out[(int)$row['producto_id']]=$row;return$out;
    }

    public function listQuotes(int $empresaId,int $limit=100): array { $stmt=$this->db->prepare('SELECT q.*,s.nombre sucursal_nombre FROM presencia_cotizaciones_publicas q INNER JOIN sucursales s ON s.id=q.sucursal_id WHERE q.empresa_id=:empresa_id ORDER BY q.id DESC LIMIT '.(int)$limit);$stmt->execute(['empresa_id'=>$empresaId]);return$stmt->fetchAll()?:[]; }
    public function listReservations(int $empresaId,int $limit=100): array { $stmt=$this->db->prepare('SELECT r.*,s.nombre sucursal_nombre FROM presencia_reservas r INNER JOIN sucursales s ON s.id=r.sucursal_id WHERE r.empresa_id=:empresa_id ORDER BY r.id DESC LIMIT '.(int)$limit);$stmt->execute(['empresa_id'=>$empresaId]);return$stmt->fetchAll()?:[]; }

    public function insertReservation(array $data,array $items): int
    {
        $stmt=$this->db->prepare("INSERT INTO presencia_reservas (empresa_id,sucursal_id,codigo,token_hash,idempotency_key,estado,nombre_cliente,email_cliente,telefono_cliente,subtotal,anticipo_minimo,pagado,saldo_pendiente,expires_at) VALUES (:empresa_id,:sucursal_id,:codigo,:token_hash,:idempotency_key,'PENDIENTE_PAGO',:nombre_cliente,:email_cliente,:telefono_cliente,:subtotal,:anticipo_minimo,0,:subtotal_saldo,:expires_at)");
        $stmt->execute($data);$id=(int)$this->db->lastInsertId();
        $ins=$this->db->prepare('INSERT INTO presencia_reserva_items (empresa_id,reserva_id,producto_id,ubicacion_id,cantidad,precio_unitario,nombre_snapshot) VALUES (:empresa_id,:reserva_id,:producto_id,:ubicacion_id,:cantidad,:precio_unitario,:nombre_snapshot)');
        foreach($items as $item)$ins->execute($item+['reserva_id'=>$id]);
        $this->reservationEvent($data['empresa_id'],$id,'CREADA',['expires_at'=>$data['expires_at']]);
        $this->enqueueMail((int)$data['empresa_id'],(string)$data['email_cliente'],(string)$data['nombre_cliente'],'Reserva '.(string)$data['codigo'].' creada','Tu reserva fue creada y está esperando el pago. Vence el '.(string)$data['expires_at'].'.','reserva_pago_pendiente','Confirmar pago de la reserva');
        $this->enqueueBusinessMail((int)$data['empresa_id'],'Nueva reserva '.(string)$data['codigo'],'Se creó una reserva por $'.number_format((int)$data['subtotal'],0,',','.').' y está esperando el pago.','nueva_reserva','Preparar atención de reserva');
        return$id;
    }

    public function reservationByTokenHash(string $hash,bool $lock=false): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM presencia_reservas WHERE token_hash=:token_hash LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute(['token_hash'=>$hash]);$row=$stmt->fetch();if(!is_array($row))return null;$row['items']=$this->reservationItems((int)$row['id']);return$row;
    }

    public function reservation(int $empresaId,int $id,bool $lock=false): ?array
    {
        $stmt=$this->db->prepare('SELECT * FROM presencia_reservas WHERE empresa_id=:empresa_id AND id=:id LIMIT 1'.($lock?' FOR UPDATE':''));$stmt->execute(['empresa_id'=>$empresaId,'id'=>$id]);$row=$stmt->fetch();if(!is_array($row))return null;$row['items']=$this->reservationItems($id);return$row;
    }

    public function reservationItems(int $id): array { $stmt=$this->db->prepare('SELECT * FROM presencia_reserva_items WHERE reserva_id=:reserva_id ORDER BY id');$stmt->execute(['reserva_id'=>$id]);return$stmt->fetchAll()?:[]; }
    public function reservationEvent(int $empresaId,int $id,string $type,?array $detail=null): void { $stmt=$this->db->prepare('INSERT INTO presencia_reserva_eventos (empresa_id,reserva_id,tipo,detalle_json) VALUES (:empresa_id,:reserva_id,:tipo,:detalle_json)');$stmt->execute(['empresa_id'=>$empresaId,'reserva_id'=>$id,'tipo'=>$type,'detalle_json'=>$detail?json_encode($detail,JSON_UNESCAPED_UNICODE):null]);if(in_array($type,['PAGO_APROBADO','ESTADO_CAMBIADO','VENCIDA','CONVERTIDA_VENTA'],true)){$r=$this->reservation($empresaId,$id);if($r!==null){$label=$type==='ESTADO_CAMBIADO'?(string)($detail['hasta']??$type):$type;$this->enqueueMail($empresaId,(string)$r['email_cliente'],(string)$r['nombre_cliente'],'Actualización de reserva '.(string)$r['codigo'],'El nuevo estado de tu reserva es '.$label.'.','estado_reserva','Mantener informado al cliente');$this->enqueueBusinessMail($empresaId,'Reserva '.(string)$r['codigo'].': '.$label,'La reserva cambió al estado '.$label.'.','operacion_reserva','Coordinar la operación del local');}} }
    public function updateReservationState(int $empresaId,int $id,string $state): void { $stmt=$this->db->prepare('UPDATE presencia_reservas SET estado=:estado WHERE empresa_id=:empresa_id AND id=:id');$stmt->execute(['estado'=>$state,'empresa_id'=>$empresaId,'id'=>$id]); }
    public function clearReservationPaid(int $empresaId,int $id):void{$s=$this->db->prepare('UPDATE presencia_reservas SET pagado=0,saldo_pendiente=subtotal WHERE empresa_id=:empresa_id AND id=:id');$s->execute(['empresa_id'=>$empresaId,'id'=>$id]);}
    public function linkReservationSale(int $empresaId,int $id,int $saleId):void{$stmt=$this->db->prepare("UPDATE presencia_reservas SET estado='RETIRADA',venta_id=:venta_id WHERE empresa_id=:empresa_id AND id=:id");$stmt->execute(['venta_id'=>$saleId,'empresa_id'=>$empresaId,'id'=>$id]);}
    public function reservationItemForProduct(int $empresaId,int $reservationId,int $productId):?array{$s=$this->db->prepare('SELECT * FROM presencia_reserva_items WHERE empresa_id=:empresa_id AND reserva_id=:reserva_id AND producto_id=:producto_id AND reserva_liberada=0 LIMIT 1 FOR UPDATE');$s->execute(['empresa_id'=>$empresaId,'reserva_id'=>$reservationId,'producto_id'=>$productId]);$r=$s->fetch();return is_array($r)?$r:null;}
    public function markReservationItemReleased(int $id): void { $stmt=$this->db->prepare('UPDATE presencia_reserva_items SET reserva_liberada=1 WHERE id=:id AND reserva_liberada=0');$stmt->execute(['id'=>$id]); }
    public function expiredReservations(int $limit): array { $stmt=$this->db->query("SELECT id,empresa_id FROM presencia_reservas WHERE estado='PENDIENTE_PAGO' AND expires_at<NOW() ORDER BY expires_at LIMIT ".(int)$limit);return$stmt->fetchAll()?:[]; }

    public function metrics(int $empresaId,int $days): array
    {
        $stmt=$this->db->prepare("SELECT evento,COUNT(*) cantidad FROM presencia_metricas WHERE empresa_id=:empresa_id AND created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY) GROUP BY evento");$stmt->execute(['empresa_id'=>$empresaId]);$events=[];foreach($stmt->fetchAll() as $r)$events[(string)$r['evento']]=(int)$r['cantidad'];
        $stmt=$this->db->prepare("SELECT COUNT(*) reservas,COALESCE(SUM(subtotal),0) valor_reservado,COALESCE(SUM(pagado),0) pagado FROM presencia_reservas WHERE empresa_id=:empresa_reservas AND created_at>=DATE_SUB(NOW(),INTERVAL {$days} DAY)");$stmt->execute(['empresa_reservas'=>$empresaId]);
        return ['eventos'=>$events,'reservas'=>$stmt->fetch()?:[]];
    }
    public function metric(int $empresaId,?int $sucursalId,?int $productoId,string $event,string $sessionHash,?array $metadata=null): void { $stmt=$this->db->prepare('INSERT INTO presencia_metricas (empresa_id,sucursal_id,producto_id,evento,session_hash,metadata_json) VALUES (:empresa_id,:sucursal_id,:producto_id,:evento,:session_hash,:metadata_json)');$stmt->execute(['empresa_id'=>$empresaId,'sucursal_id'=>$sucursalId,'producto_id'=>$productoId,'evento'=>$event,'session_hash'=>$sessionHash,'metadata_json'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE):null]); }

    private function enqueueBusinessMail(int $empresaId,string $subject,string $message,string $intent,string $reason):void
    {
        $s=$this->db->prepare('SELECT COALESCE(NULLIF(ec.email_contacto,\'\'),e.email) email,COALESCE(NULLIF(ec.nombre_fantasia,\'\'),ec.razon_social,\'Empresa\') nombre FROM empresas e LEFT JOIN empresa_configuracion ec ON ec.empresa_id=e.id WHERE e.id=:empresa_id LIMIT 1');$s->execute(['empresa_id'=>$empresaId]);$r=$s->fetch();if(is_array($r)&&filter_var($r['email'],FILTER_VALIDATE_EMAIL)!==false)$this->enqueueMail($empresaId,(string)$r['email'],(string)$r['nombre'],$subject,$message,$intent,$reason);
    }

    private function enqueueMail(int $empresaId,string $email,string $name,string $subject,string $message,string $intent,string $reason):void
    {
        if(filter_var($email,FILTER_VALIDATE_EMAIL)===false)return;$s=$this->db->prepare("INSERT INTO agente_correos_outbox (empresa_id,destinatario,razon_social,asunto,html,intencion,motivo,estado,proximo_intento_at) VALUES (:empresa_id,:destinatario,:razon_social,:asunto,:html,:intencion,:motivo,'pendiente',NOW())");$s->execute(['empresa_id'=>$empresaId,'destinatario'=>mb_substr($email,0,190),'razon_social'=>mb_substr($name,0,190),'asunto'=>mb_substr($subject,0,190),'html'=>'<p>'.htmlspecialchars($message,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>','intencion'=>mb_substr($intent,0,80),'motivo'=>mb_substr($reason,0,500)]);
    }
}
