<?php
/* =====================================================================
   Liga Premier Chapingo — Portal de capitanes · CORTE DE CAJA PUBLICADO

   Al entrar, cada representante ve el aviso «Ya está disponible tu estado
   de cuenta para descargar y el saldo al corte de caja», con dos accesos:
     · Abrir estado de cuenta      → PDF de SU equipo con saldo a la fecha de corte
     · Abrir corte de caja de liga → el PDF firmado que está en datos/cortes/

   PARA PUBLICAR UN CORTE NUEVO:
     1. Sube el PDF firmado a  capitanes/datos/cortes/
        (esa carpeta no se puede abrir desde internet: el PDF sólo se entrega
        a quien ya inició sesión con su código).
     2. Cambia aquí abajo «fecha» y «archivo». Listo: el aviso vuelve a salir
        a todos, porque la fecha cambió.
   Para quitar el aviso y los accesos: 'activo' => false.
   ===================================================================== */
return [
    'activo'  => true,
    'fecha'   => '2026-10-04',                                  // fecha del corte (AAAA-MM-DD)
    'archivo' => 'CIERRE_DE_CAJA_PREMIER_04102026.pdf',         // dentro de capitanes/datos/cortes/
];
