<?php
class FlowEstado
{
    public static function trabajosVisibles(PDO $conn, $uid, $dispositivo)
    {
        $lista=FlowCola::ultimos($conn,100,$uid,$dispositivo);
        if($uid===null)return $lista;
        $sql="SELECT * FROM tbl_flow_trabajos WHERE creado_por=? AND estado IN ('pendiente','procesando')";
        $args=[(int)$uid];if($dispositivo){$sql.=' AND dispositivo=?';$args[]=$dispositivo;}
        $q=$conn->prepare($sql);$q->execute($args);
        $porId=[];foreach(array_merge($lista,$q->fetchAll(PDO::FETCH_ASSOC)) as $t)$porId[(int)$t['id']]=$t;
        krsort($porId);return array_values($porId);
    }

    // Global history is called only after checking the authenticated administrator role.
    public static function paginaAdmin(PDO $conn, $pagina)
    {
        $total=(int)$conn->query('SELECT COUNT(*) FROM tbl_flow_trabajos')->fetchColumn();
        $paginas=max(1,(int)ceil($total/100));$pagina=max(1,min($paginas,(int)$pagina));
        $lista=$conn->query('SELECT * FROM tbl_flow_trabajos ORDER BY id DESC LIMIT 100 OFFSET '.(($pagina-1)*100))->fetchAll(PDO::FETCH_ASSOC);
        return ['trabajos'=>$lista,'pagina'=>$pagina,'paginas'=>$paginas,'total'=>$total];
    }

    public static function cupo(PDO $conn, $uid, $dispositivo)
    {
        if(!$dispositivo)return ['usadas'=>0,'libres'=>3,'pedidos'=>[]];
        $q=$conn->prepare("SELECT id,tipo,cantidad,estado,proyecto,proyecto_nombre FROM tbl_flow_trabajos WHERE creado_por = ? AND dispositivo = ? AND estado IN ('pendiente','procesando') ORDER BY id");
        $q->execute([(int)$uid,$dispositivo]);$pedidos=$q->fetchAll(PDO::FETCH_ASSOC);
        $usadas=array_sum(array_map(function($p){return max(1,(int)$p['cantidad']);},$pedidos));
        return ['usadas'=>$usadas,'libres'=>max(0,3-$usadas),'pedidos'=>$pedidos];
    }

    public static function contexto(PDO $conn, array $trabajos)
    {
        $keys=[];foreach($trabajos as $t){
            $keys[]='ext_perfil_'.(int)$t['id'];
            $keys[]='ext_proy_pedido_'.(int)$t['id'];
            $keys[]='ext_pasos_'.(int)$t['id'];
            $keys[]='ext_pct_'.(int)$t['id'];
            $keys[]='ext_politica_'.(int)$t['id'];
        }
        $ajustes=[];if($keys){$q=$conn->prepare('SELECT clave,valor FROM tbl_flow_ajustes WHERE clave IN ('.implode(',',array_fill(0,count($keys),'?')).')');$q->execute($keys);$ajustes=$q->fetchAll(PDO::FETCH_KEY_PAIR);}
        $perfiles=$conn->query('SELECT id,token_hash,latido FROM tbl_flow_tokens')->fetchAll(PDO::FETCH_ASSOC);
        return ['ajustes'=>$ajustes,'perfiles'=>$perfiles,'db_ahora'=>strtotime((string)$conn->query('SELECT NOW()')->fetchColumn())];
    }

    public static function mensajeCupo(PDO $conn, $uid, $dispositivo)
    {
        $c=self::cupo($conn,$uid,$dispositivo);
        $ids=array_map(function($p){return '#'.(int)$p['id'].' ×'.max(1,(int)$p['cantidad']);},$c['pedidos']);
        return 'Máximo tres generaciones pendientes o en curso. '.$c['usadas'].'/3 ocupadas'.($ids?' por '.implode(', ',$ids):'').'. Los cancelados no cuentan.';
    }

    public static function mensajeCliente($mensaje)
    {
        if(preg_match('/actividad inusual|unusual activity|algo inusual/i',(string)$mensaje))return 'La generación no está disponible temporalmente. Inténtalo más tarde.';
        if(preg_match('/debugger|tab with id|chrome\.debugger/i',(string)$mensaje))return 'Motor SnaveCloud: se perdió la conexión con el proceso. Puedes reintentar.';
        return str_ireplace(['extensiones','extensión','extension'],['motores SnaveCloud','Motor SnaveCloud','Motor SnaveCloud'],(string)$mensaje);
    }

    public static function duracionTexto($segundos)
    {
        $s=max(0,(int)$segundos);$h=intdiv($s,3600);$m=intdiv($s%3600,60);
        return ($h?$h.' h ':'').($m||$h?$m.' min ':'').($s%60).' s';
    }

    public static function tiempoEntrega(array $t,$ahora)
    {
        $inicio=strtotime((string)($t['creado_en']??''));
        if($inicio===false || !$inicio)return null;
        $activo=in_array($t['estado']??'', ['pendiente','procesando'],true);
        $fin=$activo?(int)$ahora:strtotime((string)($t['terminado_en']??''));
        if(!$fin || $fin<$inicio)return null;
        $entregado=($t['estado']??'')==='hecho' && count(FlowCola::archivosDe($t))>=max(1,(int)($t['cantidad']??1));
        $segundos=$fin-$inicio;
        return ['segundos'=>$segundos,'activo'=>$activo,'etiqueta'=>$entregado?'Entregado en':'Tiempo transcurrido','texto'=>self::duracionTexto($segundos)];
    }

    public static function pedido(array $t,array $contexto,$correoCompleto=false)
    {
        $perfil=null;$cuenta=trim((string)($t['cuenta']??''));
        $marker=$contexto['ajustes']['ext_perfil_'.(int)$t['id']]??'';
        foreach($contexto['perfiles'] as $p){
            $l=json_decode((string)$p['latido'],true)?:[];
            if($marker==='perfil:'.$p['token_hash']){$perfil=$p;$perfil['latido']=$l;break;}
        }
        $confirmada=!empty($t['generacion_confirmada'])||trim((string)($t['operacion']??''))!=='';
        $l=$perfil['latido']??[];
        $enLinea=$perfil && time()-(int)($l['t']??0)<=90;
        $ultima=$t['tomado_en']?strtotime($t['tomado_en']):0;
        $edad=($contexto['db_ahora']??time())-$ultima;
        $seguimiento=$enLinea && !empty($l['activa']) && $ultima && $edad>=-30 && $edad<=90;
        $label=['hecho'=>'Listo','error'=>'Falló','rechazado'=>'Rechazado'][$t['estado']]??$t['estado'];$detalle=(string)($t['mensaje']??'');
        if($t['estado']==='pendiente'){$label='En cola';$detalle='Esperando una cuenta disponible.';}
        if($t['estado']==='cancelado'){$label='Cancelado';$detalle='No ocupa ninguna plaza de generación.';}
        if($t['estado']==='procesando'){
            if(trim((string)($t['operacion']??''))!=='' && !$marker){$label='Generando · Motor SnaveCloud';$detalle='Pedido aceptado por el generador del servidor.';}
            elseif(!$seguimiento){$label='Sin seguimiento reciente';$detalle=$confirmada?'Flow aceptó el pedido, pero falta seguimiento reciente de la extensión. Revisa su cuenta antes de repetirlo.':'No hay confirmación de generación ni seguimiento reciente de la extensión.';}
            elseif(!$confirmada){$label='Preparando · Motor SnaveCloud';$detalle='Asignado a una cuenta. Flow todavía no confirmó la generación.';}
            else{$label='Generando · Motor SnaveCloud';$detalle='Flow confirmó la generación; la extensión sigue esperando el resultado.';}
        }
        if(!$correoCompleto){
            if($t['estado']==='procesando'){
                if(!$seguimiento && !(trim((string)($t['operacion']??''))!=='' && !$marker))$detalle=$confirmada?'Motor SnaveCloud: generación aceptada, pero sin actualización reciente.':'Motor SnaveCloud: falta una actualización del proceso.';
                elseif(!$confirmada)$detalle='Motor SnaveCloud está preparando tu pedido.';
                else $detalle='Motor SnaveCloud está esperando el resultado de tu generación.';
            }
            $detalle=self::mensajeCliente($detalle);
        }
        $nombre=$correoCompleto?$cuenta:'';
        if($correoCompleto && strpos($cuenta,'ext:')===0)$nombre='Cuenta sin identificar';
        $flow=$contexto['ajustes']['ext_proy_pedido_'.(int)$t['id']]??'';
        if(!$flow){
            foreach(json_decode($contexto['ajustes']['ext_pasos_'.(int)$t['id']]??'[]',true)?:[] as $paso){
                if(($paso['t']??'')==='Proyecto abierto' && ($paso['cuenta']??'')===$cuenta && preg_match('/^[A-Za-z0-9-]{8,64}$/',(string)($paso['d']??'')))$flow=$paso['d'];
            }
        }
        if(!$flow && !$marker)$flow=$t['proyecto'];
        $url=$correoCompleto && preg_match('/^[A-Za-z0-9-]{8,64}$/',(string)$flow) && !FlowCola::esProyectoPanel($flow)?'https://flow.google.com/project/'.rawurlencode($flow):'';
        $porcentajeTexto='';
        $pct=json_decode($contexto['ajustes']['ext_pct_'.(int)$t['id']]??'',true);
        if($t['estado']==='procesando' && $confirmada && is_array($pct) && time()-(int)($pct['t']??0)>=0 && time()-(int)($pct['t']??0)<=90){
            $valores=$pct['valores']??[];
            if(is_array($valores) && $valores && count($valores)<=max(1,(int)$t['cantidad']) && count(array_filter($valores,function($v){return is_numeric($v)&&$v>=0&&$v<=100;}))===count($valores)){
                // 100% visual se reserva para la entrega: antes como máximo 99%.
                $porcentajeTexto=implode(' · ',array_map(function($v){return min(100,(int)floor($v)).'%';},$valores));
            }
        }
        if($t['estado']==='hecho' && count(FlowCola::archivosDe($t))>=max(1,(int)$t['cantidad']))$porcentajeTexto='100%';
        $politica=(string)($contexto['ajustes']['ext_politica_'.(int)$t['id']]??'');
        if($politica && $t['estado']==='rechazado')$label='Rechazado por política de Flow';
        foreach(FlowCola::archivosDe($t) as $archivo){
            if(!empty($archivo['temp']) && ($archivo['tipo']??'')==='video' && preg_match('#^https://flow\.google\.com/asb/#i',(string)($archivo['url']??''))){
                $label='Video generado';$detalle='Abre el enlace directo de Flow. La copia se guarda en segundo plano.';$porcentajeTexto='100%';break;
            }
        }
        return ['politica'=>$politica,'porcentajeTexto'=>$porcentajeTexto,'estado'=>$label,'detalle'=>$detalle,'cuenta'=>$nombre,'perfil'=>$correoCompleto && $perfil?(int)$perfil['id']:null,'enLinea'=>$correoCompleto && (bool)$enLinea,'seguimiento'=>(bool)$seguimiento,'confirmada'=>$confirmada,'proyectoUrl'=>$url];
    }
}
