<?php
/** Identidad de espacio por acceso y perfil. La IP no identifica a una persona. */
class FlowPrivado
{
    public static function codigoCliente($uid, $dispositivo)
    {
        return 'SC-'.strtoupper(substr(hash('sha256','cliente|'.(int)$uid.'|'.$dispositivo),0,12));
    }

    public static function codigoProyecto($uid, $dispositivo, $proyecto)
    {
        return substr(hash('sha256','proyecto|'.(int)$uid.'|'.$dispositivo.'|'.$proyecto),0,12);
    }

    public static function resolverProyecto($uid, $dispositivo, $codigo, array $proyectos)
    {
        if(!preg_match('/^[a-f0-9]{12}$/',(string)$codigo))return null;
        foreach($proyectos as $p) {
            if(hash_equals(self::codigoProyecto($uid,$dispositivo,$p['id']),$codigo))return $p['id'];
        }
        return null;
    }

    public static function proyectoInicial(PDO $conn, $uid, $dispositivo)
    {
        $clave='flow_priv_'.substr(hash('sha256',(int)$uid.'|'.$dispositivo),0,40);
        $q=$conn->prepare('SELECT GET_LOCK(?,5)');$q->execute([$clave]);
        if(!(int)$q->fetchColumn())return null;
        try {
            $p=FlowCola::proyectos($conn,$uid,$dispositivo);
            if($p)return $p[0]['flow_id'];
            return FlowCola::crearProyecto($conn,$uid,'Mi proyecto',$dispositivo);
        } finally { $q=$conn->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$clave]); }
    }

    public static function asegurarSpaces(PDO $conn)
    {
        try { $conn->query('SELECT dispositivo FROM tbl_flow_spaces LIMIT 0'); }
        catch(PDOException $e) {
            try { $conn->exec("ALTER TABLE tbl_flow_spaces ADD COLUMN dispositivo VARCHAR(32) NOT NULL DEFAULT ''"); }
            catch(PDOException $otro) { $conn->query('SELECT dispositivo FROM tbl_flow_spaces LIMIT 0'); }
        }
    }
}
