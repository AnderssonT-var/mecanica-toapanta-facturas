<?php
// Registro de parches y antifallos: constancia de cada corrección (algo que falló) y cada antifallo (protección
// para que algo no falle) aplicado al programa y al portal. Los que vienen con cada versión se cargan solos al
// desplegar (por código, sin duplicar); los que escribas a mano en el panel quedan igual en la tabla.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(404); exit; }

const PAR_TIPOS = ['ANTIFALLO' => 'Antifallo', 'PARCHE' => 'Parche', 'MEJORA' => 'Mejora'];
const PAR_ESTADOS = ['PLANEADO' => 'Planeado', 'APLICADO' => 'Aplicado (sin publicar)', 'PUBLICADO' => 'Publicado'];
const PAR_RIESGOS = ['ALTO' => 'Alto', 'MEDIO' => 'Medio', 'BAJO' => 'Bajo'];
const PAR_AREAS = ['SRI' => 'SRI y comprobantes', 'NUMERACION' => 'Numeración', 'DINERO' => 'Pagos y cobros', 'LICENCIA' => 'Licencia y reloj', 'FIRMA' => 'Firma electrónica',
  'DATOS' => 'Base de datos y equipo', 'CORREO' => 'Correo', 'PANTALLA' => 'Pantallas', 'INSTALADOR' => 'Instalador', 'PORTAL' => 'Portal', 'MONITOR' => 'Monitor', 'NUBE' => 'Nube y sincronización', 'INVENTARIO' => 'Inventario', 'CONTABILIDAD' => 'Contabilidad', 'BANCOS' => 'Bancos y tesorería'];

// Historial: lo que ya se aplicó y lo que viene en esta versión. Cada despliegue agrega solo los códigos nuevos.
function par_semilla(): array {
  return [
    ['AF-001', 'ANTIFALLO', 'SRI', 'ALTO', '0.13.1', '', 'APLICADO', '2026-11-09', 'Bloqueo de emojis y caracteres que el SRI no acepta',
      'Un emoji o un carácter raro (por ejemplo «pag👆s») hacía inválido el XML y el SRI devolvía el comprobante.',
      'Las pantallas no dejan escribir esos caracteres y, antes de firmar, el XML se limpia: comillas tipográficas, guiones largos y letras con signos raros se cambian por su equivalente aceptado.'],
    ['AF-002', 'ANTIFALLO', 'SRI', 'ALTO', '0.13.1', '', 'APLICADO', '2026-11-09', 'Revisión previa antes de enviar al SRI',
      'Errores de datos (clave de acceso, RUC, número, fecha futura, total) se descubrían recién con el rechazo del SRI.',
      'Antes de enviar se revisa la clave de acceso (dígito verificador), tipo de documento, RUC, numeración, fecha y totales; si algo está mal se avisa en la pantalla con la explicación del código del SRI.'],
    ['AF-003', 'ANTIFALLO', 'SRI', 'MEDIO', '0.13.1', '1.6.0', 'APLICADO', '2026-11-09', 'Reparación automática de XML ya generados',
      'Comprobantes pendientes creados antes del antierror podían llevar caracteres inválidos.',
      'Al reintentar, el XML se repara (misma limpieza) antes de volver a firmarlo. La versión web aplica la misma regla.'],
    ['AF-014', 'ANTIFALLO', 'SRI', 'MEDIO', '', '', 'APLICADO', '2026-11-04', 'Recuperar la autorización ante «secuencial registrado»',
      'Si la respuesta del SRI no llegaba (internet caído justo al enviar), el reintento daba «secuencial registrado» aunque el comprobante sí estaba autorizado.',
      'Facmit consulta la autorización con la clave de acceso y comprueba que sea el mismo comprobante antes de darlo por autorizado.'],
    ['PA-001', 'PARCHE', 'INSTALADOR', 'BAJO', '0.13.2', '', 'APLICADO', '2026-11-10', 'Icono del programa y del instalador',
      'El instalador y el programa instalado aparecían sin el logo de Facmit.',
      'Se incluyó el icono en todos los tamaños (16 a 256) en el instalador, la ventana y la bandeja.'],
    ['AF-004', 'ANTIFALLO', 'DATOS', 'MEDIO', '0.14.0', '', 'APLICADO', '2026-11-10', 'Guardados dentro de guardados sin romper la base',
      'Registrar un pago a varios documentos a la vez abría una transacción dentro de otra y fallaba.',
      'Las transacciones ahora se pueden anidar (puntos de guardado): si algo falla, se deshace todo junto y no queda a medias.'],
    ['AF-005', 'ANTIFALLO', 'INSTALADOR', 'MEDIO', '', '', 'APLICADO', '2026-11-13', 'El instalador no corre en Windows 7, 8 ni 32 bits',
      'En Windows antiguos el programa se instalaba y no abría.',
      'El instalador revisa la versión de Windows y explica que se necesita Windows 10 u 11 de 64 bits. (Protección que ya existía; se registra para tener constancia.)'],
    ['AF-006', 'ANTIFALLO', 'NUMERACION', 'ALTO', '', '', 'APLICADO', '2026-11-13', 'No se puede bajar la numeración por debajo de lo emitido',
      'Poner un «último número» menor al ya emitido repetía facturas.',
      'Documentos y secuencias y los puntos de los socios rechazan un secuencial menor al último emitido en el programa. (Protección que ya existía; se registra para tener constancia.)'],
    ['AF-007', 'ANTIFALLO', 'MONITOR', 'ALTO', '0.15.0', '1.8.0', 'APLICADO', '2026-11-13', 'Monitor de usuarios y de errores',
      'Los problemas se conocían solo cuando el cliente llamaba.',
      'Cada equipo informa su estado cada 5 minutos y los errores técnicos (limpios, sin datos de clientes). El panel Monitor los agrupa y avisa los que reaparecen.'],
    ['PA-002', 'PARCHE', 'DINERO', 'ALTO', '0.15.1', '', 'APLICADO', '2026-11-13', 'Facturación masiva: montos con coma decimal',
      'Al pegar desde Excel «25,50» se leía 25, «1.234,56» se leía 1.234 y «1,234.56» se leía 1. Las facturas salían con montos equivocados sin ningún aviso.',
      'Se reconoce el formato ecuatoriano y el estadounidense; si el monto es ambiguo («1,234») la fila queda en error para que la corrijan, nunca se adivina. El campo acepta coma.'],
    ['PA-003', 'PARCHE', 'MONITOR', 'BAJO', '', '1.8.1', 'APLICADO', '2026-11-13', 'Monitor: equipos con diagnóstico apagado no salen como desconectados',
      'Si el cliente apagaba el diagnóstico, el panel avisaba «sin conectarse, a los 15 días se bloquea» aunque su licencia se verificaba bien.',
      'Se toma el contacto más reciente (monitor o verificación de licencia) y se indica «Diagnóstico apagado por el cliente».'],
    ['AF-008', 'ANTIFALLO', 'NUMERACION', 'ALTO', '0.15.1', '1.8.1', 'APLICADO', '2026-11-13', 'Aviso de misma serie en dos equipos',
      'Dos cajas del mismo RUC con el mismo punto de emisión sacan los mismos números; el SRI rechaza uno («secuencial registrado»), sobre todo si trabajan sin internet.',
      'Cada equipo informa qué puntos de emisión usa. Si otro equipo del mismo RUC usa el mismo, los dos ven un aviso rojo con el botón para cambiarlo, y en el Monitor aparece «Misma serie».'],
    ['AF-009', 'ANTIFALLO', 'NUMERACION', 'MEDIO', '0.15.1', '', 'APLICADO', '2026-11-13', 'La numeración avanza sola tras «secuencial registrado»',
      'Después de ese rechazo, la siguiente factura podía volver a chocar con el mismo número.',
      'Cuando el SRI dice que el número ya existe, la serie se adelanta a ese número para que la próxima no lo repita.'],
    ['AF-010', 'ANTIFALLO', 'LICENCIA', 'ALTO', '0.15.1', '', 'APLICADO', '2026-11-13', 'Reloj de Windows equivocado',
      'Si el reloj estuvo adelantado (por ejemplo, un año) y luego se corregía, el programa quedaba bloqueado como «fecha incorrecta» hasta llegar a esa fecha. Con la fecha mal, el SRI además rechaza.',
      'Al verificar la licencia se toma la fecha real del servidor: si la del equipo coincide, se desbloquea solo; si no, el mensaje dice qué fecha tiene el equipo, cuál es la real y cómo corregirla, con el botón «Verificar ahora» en el aviso.'],
    ['AF-011', 'ANTIFALLO', 'DINERO', 'MEDIO', '0.15.1', '', 'APLICADO', '2026-11-13', 'Pago o cobro registrado dos veces seguidas',
      'Un doble clic o el «no sé si se guardó» dejaba el mismo pago a proveedor o cobro a cliente registrado dos veces.',
      'Si el mismo valor, documento y referencia se registró hace menos de 3 minutos, se avisa en lugar de guardarlo otra vez.'],
    ['AF-012', 'ANTIFALLO', 'DATOS', 'MEDIO', '0.15.1', '', 'APLICADO', '2026-11-13', 'Carpeta de datos en OneDrive/Dropbox y disco casi lleno',
      'OneDrive y Dropbox bloquean la base mientras sincronizan; sin espacio en disco la base puede dañarse al guardar.',
      'El programa revisa dónde están sus datos y el espacio libre, y muestra un aviso con qué hacer.'],
    ['AF-013', 'ANTIFALLO', 'CORREO', 'BAJO', '0.15.1', '1.8.1', 'APLICADO', '2026-11-13', 'Correo mal configurado separado de los errores del programa',
      'Una contraseña de correo mal puesta llenaba el monitor de «errores» que no eran del programa.',
      'Los fallos de usuario o contraseña del correo ya no cuentan como error; en Usuarios aparece «correos sin enviar: revisar su configuración».'],
    ['AF-015', 'ANTIFALLO', 'LICENCIA', 'ALTO', '0.15.2', '', 'APLICADO', '2026-11-13', 'Fecha de Ecuador fija, tenga Windows la zona que tenga',
      'Con Windows en otra zona (por ejemplo UTC, común en equipos recién formateados), desde las 7 de la noche la factura salía con la fecha de mañana y el SRI la rechazaba.',
      'El programa trabaja siempre con la hora de Ecuador continental (o Galápagos, si se elige en Parámetros › Programa). Si Windows no deja aplicarla y su zona cambia la fecha, la emisión se detiene con la explicación.'],
    ['AF-016', 'ANTIFALLO', 'LICENCIA', 'ALTO', '0.15.2', '1.8.2', 'APLICADO', '2026-11-13', 'Revisión de la hora exacta contra el portal',
      'Solo se revisaba el día: un reloj desfasado varias horas pasaba sin aviso y podía cambiar la fecha de los comprobantes cerca de la medianoche.',
      'Cada respuesta del portal trae su hora exacta. Más de 10 minutos de diferencia: aviso con la hora real y cómo corregirla. Si la diferencia cambia la fecha: se detiene la emisión hasta corregir el reloj. Sin internet se sigue contando con un reloj interno que no cambia aunque muevan la hora de Windows. El Monitor muestra «Reloj desfasado».'],
    ['PA-004', 'PARCHE', 'PANTALLA', 'BAJO', '0.15.2', '', 'APLICADO', '2026-11-13', 'Fecha mostrada en la pantalla de factura después de las 7 p. m.',
      'La cabecera de la factura mostraba la fecha en hora universal: desde las 19:00 aparecía la fecha de mañana (el comprobante sí salía con la fecha correcta).',
      'Todas las fechas de las pantallas usan la hora de Ecuador.'],
    ['PA-005', 'PARCHE', 'SRI', 'BAJO', '0.15.2', '', 'APLICADO', '2026-11-13', 'ATS: mes sugerido equivocado a fin de mes',
      'Abriendo el ATS el 31 de marzo (o el 29–31 de otros meses), el mes sugerido podía ser el actual en vez del anterior.',
      'El mes anterior se calcula desde el día 1.'],
    ['AF-017', 'ANTIFALLO', 'DATOS', 'ALTO', '0.16.0', '1.9.0', 'APLICADO', '2026-11-13', 'Restaurar todo en una computadora nueva (nube o unidad externa)',
      'Si la PC se dañaba, había que crear el perfil y configurar todo de nuevo; no existía restaurar desde la nube y la firma del equipo anterior no servía.',
      'Al instalar en la PC nueva (bienvenida, asistente o pantalla de perfiles): «Restaurar desde la nube» o «desde unidad externa». Vuelve todo: emisor, logo, parámetros, clientes, productos, comprobantes, numeración, compras, cobros, usuarios y opciones. Lo emitido después del respaldo baja con la sincronización. La firma no se restaura (es personal): queda el aviso de cargarla, y también el de la contraseña del correo.'],
    ['AF-018', 'ANTIFALLO', 'DATOS', 'ALTO', '0.16.0', '', 'APLICADO', '2026-11-13', 'Copia automática del respaldo en unidad externa',
      'El respaldo diario quedaba en el mismo disco de la PC: si el disco se dañaba, se perdía junto con los datos.',
      'En Nube y respaldos se elige una memoria USB o disco externo: cada respaldo diario se copia ahí (sin la firma, se conservan 15 días). Si la unidad no se conecta en 3 días, aparece un aviso.'],
    ['AF-019', 'ANTIFALLO', 'NUMERACION', 'ALTO', '', '1.5.0', 'PUBLICADO', '2026-11-04', 'La app web tiene su propio punto de emisión',
      'Si la PC y la web facturaban al mismo tiempo con el mismo punto, podían sacar el mismo número.',
      'La web aparta su propio punto (por ejemplo 001-002) y ningún equipo puede usarlo; cada número web se toma con la numeración bloqueada, así dos ventas simultáneas nunca reciben el mismo. Los informes de la PC incluyen las ventas de todos los puntos. (Protección que ya existía; se registra para tener constancia.)'],
    ['AF-020', 'MEJORA', 'INSTALADOR', 'MEDIO', '0.16.1', '1.9.1', 'APLICADO', '2026-11-14', 'Actualizaciones silenciosas, sin el asistente del instalador',
      'Al actualizar aparecía el asistente del instalador como si se instalara de nuevo, y solo se instalaba al cerrar el programa (que muchos dejan abierto todo el día en la bandeja).',
      'La actualización se instala en silencio y el programa se vuelve a abrir solo. Si está abierto pero nadie lo usa (20 min de inactividad y nada enviándose al SRI), se instala sola y vuelve a la bandeja. Al abrir se muestra «Facmit se actualizó» con las novedades. Respaldo antes de cada actualización.'],
    ['AF-021', 'MEJORA', 'INSTALADOR', 'BAJO', '0.16.1', '1.9.1', 'APLICADO', '2026-11-14', 'Descargas diferenciales y programa más liviano',
      'Cada actualización podía bajar el instalador completo (unos 120 MB).',
      'Versiones conserva los .blockmap de las últimas 10 versiones: el programa baja solo los bloques que cambiaron (medido: 0,7 a 1,9 MB en vez de 110 MB). El instalador bajó de 122 a 110 MB (solo idiomas español e inglés, sin archivos de desarrollo).'],
    ['PA-006', 'PARCHE', 'FIRMA', 'ALTO', '0.16.2', '', 'APLICADO', '2026-11-14', 'Paso a producción: firma del titular marcada como ajena',
      'Algunas entidades certificadoras guardan en el certificado la cédula seguida de la fecha y hora de emisión (por ejemplo 1712252293 + 151026193934). Facmit comparaba el número completo y decía «la firma no pertenece al titular del RUC», bloqueando el paso a producción aunque la firma sí era del titular.',
      'Se reconoce la firma cuando su identificación empieza con la cédula del RUC (o con el RUC). Una cédula distinta sigue bloqueando.'],
    ['PA-007', 'PARCHE', 'SRI', 'ALTO', '0.16.3', '', 'APLICADO', '2026-11-14', 'Retenciones: tabla de porcentajes desactualizada',
      'La tabla de retención en la fuente de Facmit era la anterior a la Resolución NAC-DGERCGC26-00000009 (vigente desde el 1 de marzo de 2026): bienes 1,75 % (ahora 2 %), mano de obra 2 % (3 %), intelecto 8 % (10 %), publicidad 1,75 % (3 %), seguros 1,75 % (2 %), otras 2,75 % (3 %) y el 308 con un concepto que ya no corresponde. Las retenciones habrían salido con valores menores a los legales.',
      'Tabla actualizada con los códigos del ATS y los nuevos 303A (5 %), 312A (1 %), 343A (2 %) y 343B (2 %). Los perfiles con tabla guardada se actualizan solos sin tocar lo que el cliente personalizó. Pendiente: revisión del contador con la resolución oficial.'],
    ['ME-001', 'MEJORA', 'PORTAL', 'MEDIO', '0.16.4', '1.10.0', 'APLICADO', '2026-11-15', 'Nube de respaldo y portal web separados',
      'El portal web obligaba a tener la nube completa: quien solo quería el portal pagaba y guardaba en la nube toda su información, y quien solo quería respaldo no podía elegirlo por separado.',
      'Son dos funciones independientes y se suman al plan por separado. Solo nube: respaldo diario completo, restaurar en otra PC y varios equipos sincronizados. Solo portal: al portal viajan únicamente clientes, productos y catálogo, y el estado de las facturas hechas en el portal; nada más sale de la PC y no se guardan respaldos. Las dos: todo sincronizado. El portal sigue usando la PC encendida para firmar y autorizar.'],
    ['AF-022', 'ANTIFALLO', 'NUBE', 'ALTO', '0.17.0', '', 'APLICADO', '2026-11-16', 'Primera sincronización de una base grande',
      'En la prueba de saturación (30.000 facturas) la primera subida a la nube cargaba todo en memoria de una vez (más de 700 MB) y con cientos de equipos quietos el servidor recibía una consulta cada 20 segundos de cada uno.',
      'La subida va por tandas de 2.000 registros por tabla. La sincronización es adaptable: cada 20 s mientras hay movimiento y hasta 2 a 5 minutos cuando no hay nada nuevo; cualquier venta o cambio vuelve a la frecuencia rápida. Los puntos de emisión se confirman cada 10 minutos o al cambiar.'],
    ['AF-023', 'ANTIFALLO', 'SRI', 'ALTO', '0.17.0', '1.11.0', 'APLICADO', '2026-11-16', 'Datos absurdos o de tipo equivocado en una factura',
      'Con 3.000 facturas de prueba llenas de errores de usuario salieron 23 fallas: plazos con decimales (XML inválido), textos que llegaban como objeto («[object Object]» o «Array» en el XML) y cantidades o precios gigantes que superan los 14 dígitos del SRI (número de factura perdido).',
      'El plazo se redondea a días enteros, los textos solo aceptan texto o números, cantidades y precios tienen tope y el total no puede superar el máximo del SRI. Lo mismo en la app web (incluido «1e999» = infinito). Repetida la prueba: 0 fallas.'],
    ['AF-024', 'ANTIFALLO', 'SRI', 'MEDIO', '0.17.0', '', 'APLICADO', '2026-11-16', 'ATS de negocios con muchos clientes',
      'El validador del ATS tenía 32 MB de memoria y se quedaba sin memoria con anexos de unos 8 MB (miles de clientes en el mes).',
      'La memoria del validador crece con el tamaño del XML (probado con 60.000 clientes, 34 MB, en 1 segundo). Los informes con decenas de miles de filas muestran las primeras 3.000 en pantalla (el total incluye todas) para no congelar el programa.'],
    ['AF-025', 'ANTIFALLO', 'PORTAL', 'ALTO', '', '1.11.0', 'APLICADO', '2026-11-16', 'Base del hosting ocupada con muchos programas a la vez',
      'Con 5 a 10 peticiones simultáneas el hosting rechazaba conexiones nuevas a MySQL por un instante y el portal respondía «error interno» (500).',
      'El portal reintenta la conexión (pausas de 0,15 a 0,6 s, también por el socket local) y, si sigue ocupada, responde 503 «ocupado, reintenta»; el programa lo trata como falta de conexión y reintenta solo, sin registrarlo como error.'],
    ['PA-008', 'PARCHE', 'INVENTARIO', 'ALTO', '0.17.0', '', 'APLICADO', '2026-11-16', 'Factura anulada en el SRI no devolvía el stock',
      'Al anular una factura en el SRI sin nota de crédito, la venta desaparecía de los informes pero las unidades seguían descontadas del inventario y del kardex.',
      'La anulación sin nota de crédito devuelve el stock al momento; con nota de crédito, la devolución la sigue haciendo la nota.'],
    ['AF-026', 'ANTIFALLO', 'CONTABILIDAD', 'ALTO', '0.17.0', '', 'APLICADO', '2026-11-16', 'Cambios en periodos ya declarados',
      'Una compra, cobro, pago o asiento registrado con fecha de un mes ya declarado cambiaría los estados financieros y el ATS ya presentados.',
      'Cierre de periodo en Contabilidad: nada con fecha dentro del periodo cerrado se registra, cambia ni anula (facturas, compras, cobros, pagos, bancos, conciliaciones y asientos). Para cerrar no puede haber comprobantes sin autorizar ni asientos descuadrados. Reabrir exige motivo y queda registrado.'],
    ['AF-027', 'ANTIFALLO', 'BANCOS', 'MEDIO', '0.17.0', '', 'APLICADO', '2026-11-16', 'Cheques repetidos y partidas ya conciliadas',
      'Un mismo número de cheque podía registrarse dos veces y un movimiento ya cuadrado con el banco podía anularse después, descuadrando la conciliación.',
      'El número de cheque es único por cuenta (avanza solo; un cheque puede pagar varias facturas del mismo proveedor). Lo conciliado no se puede anular; solo se reabre la última conciliación, con motivo. Las salidas que dejarían la cuenta en negativo piden confirmación (sobregiro).'],
    ['ME-002', 'MEJORA', 'CONTABILIDAD', 'ALTO', '0.17.0', '1.11.0', 'APLICADO', '2026-11-16', 'Contabilidad completa',
      'Facmit no llevaba contabilidad: el contador tenía que volver a digitar ventas, compras, cobros y pagos en otro sistema.',
      'Plan de cuentas NIIF para PYMES (Supercias), asientos automáticos que nunca se descuadran con lo facturado (se arman desde los documentos), asientos manuales, libro diario, mayor, balance de comprobación, estado de resultados y de situación financiera, exportables a PDF y Excel. Probado: cartera, proveedores, bancos, IVA e inventario valorizado cuadran con su cuenta contable. Nueva función de licencia «Contabilidad y bancos».'],
    ['ME-003', 'MEJORA', 'BANCOS', 'ALTO', '0.17.0', '', 'APLICADO', '2026-11-16', 'Bancos y tesorería',
      'Los cobros y pagos por transferencia o cheque no decían a qué cuenta entraban o de cuál salían, y no había forma de cuadrar con el estado de cuenta del banco.',
      'Cuentas bancarias y caja chica, libro de bancos, cheques (posfechados y en tránsito), transferencias entre cuentas, conciliación bancaria y flujo de caja real y proyectado. Saldos iniciales de clientes y proveedores. Nuevos informes de cuentas por pagar, pagos, bancos y flujo.'],
    ['ME-004', 'MEJORA', 'SRI', 'MEDIO', '0.17.0', '', 'APLICADO', '2026-11-16', 'ATS según la norma de comprobantes electrónicos',
      'Sin el detalle de ventas el ATS salía también sin el total por establecimiento; no avisaba de retenciones emitidas fuera de los 5 días ni de retenciones sin su compra registrada (que no salían en el anexo).',
      'El total de ventas por establecimiento va siempre. Por defecto no se repite el detalle de facturas electrónicas (como pide el SRI), salvo los clientes que entregaron retenciones físicas. Opción para omitir las compras con retención electrónica. Avisos de plazo y de retenciones sin compra.'],
    ['AF-028', 'ANTIFALLO', 'INVENTARIO', 'ALTO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Costo promedio que se descuadraba',
      'El costo promedio se actualizaba en el momento de cada ingreso. Si una compra o liquidación se anulaba, se rechazaba en el SRI o llegaba tarde desde otro equipo, el costo quedaba mal para siempre (y con él el costo de ventas y la contabilidad).',
      'El costo se recalcula en orden de fecha desde el kardex cada vez que algo cambia: entradas con costo propio promedian, salidas y ajustes toman el promedio del momento. Se corrige también el costo guardado en cada movimiento (salvo en periodos contables cerrados). La cuenta contable de inventario es exactamente el kardex valorizado.'],
    ['AF-029', 'ANTIFALLO', 'INVENTARIO', 'ALTO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Movimientos duplicados entre equipos',
      'Si un comprobante llegaba a otro equipo antes que sus movimientos y ese equipo también lo procesaba, el stock se descontaba dos veces.',
      'Los movimientos automáticos tienen un identificador fijo calculado del documento y la línea: el mismo movimiento nunca se crea dos veces, lo procese el equipo que lo procese.'],
    ['AF-030', 'ANTIFALLO', 'INVENTARIO', 'ALTO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Compras editadas y devoluciones',
      'Editar las cantidades de una compra ya registrada no cambiaba el inventario. Las devoluciones de clientes entraban al costo de hoy, no al costo con que salieron, y si se cambiaba el código del producto la devolución no lo encontraba.',
      'Cada versión de la compra tiene sus propios movimientos: al editarla se retiran los anteriores y entran los nuevos. La devolución se enlaza con el movimiento exacto de la venta: vuelve al mismo producto, bodega, lote y costo.'],
    ['AF-031', 'ANTIFALLO', 'INVENTARIO', 'MEDIO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Protecciones del inventario',
      'Se podía eliminar un producto con stock, quitarle el control de stock o de lotes con mercadería, desactivar una bodega con productos, registrar ingresos con fecha futura o en periodos cerrados y escribir el costo a mano encima del promedio.',
      'Todo eso se bloquea con un mensaje que dice qué hacer (ajuste o toma física a cero, transferir). El costo de un producto con movimientos lo calcula el kardex. Opción «No vender sin stock».'],
    ['AF-032', 'ANTIFALLO', 'INVENTARIO', 'ALTO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Auditoría diaria del inventario',
      'Nada comprobaba que cada documento hubiera movido el inventario como correspondía.',
      'Al abrir el perfil (una vez al día) se compara cada factura, nota de crédito, liquidación y compra con sus movimientos: lo que no movió el stock, lo que lo movió de más, lo anulado que seguía contando y los movimientos de documentos inexistentes. Se repara solo y se recalculan los costos; stock negativo y lotes vencidos quedan como avisos.'],
    ['PA-009', 'PARCHE', 'INVENTARIO', 'MEDIO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Errores con datos raros en el inventario',
      'La prueba con 4.000 operaciones de inventario con datos de usuario equivocados encontró 145 fallas: fechas imposibles (2026-13-40), tipos de dato raros en filtros y lotes con texto «NaN».',
      'Se validan fechas, textos y filtros. Repetida la prueba: 0 fallas y ningún movimiento inválido en la base.'],
    ['ME-005', 'MEJORA', 'INVENTARIO', 'ALTO', '0.18.0', '', 'APLICADO', '2026-11-17', 'Lotes, toma física, compras por caja y reportes',
      'Faltaban funciones de un inventario profesional: lotes y caducidad, conteo físico masivo, compras por caja, valorizado a una fecha, rotación y reposición.',
      'Lotes con vencimiento y salida FEFO (lo que vence primero sale primero; los vencidos no se venden mientras haya otros). Toma física por bodega o línea con escáner. Factor caja → unidades en ingresos y compras (recordado por proveedor). Kardex valorizado, inventario a una fecha, rotación, sugerencia de reposición y lotes. Probado con 20.000 ventas y 2.000 productos: cada venta con inventario en unos 8 ms, auditar toda la base en 0,6 s.'],
    ['AF-033', 'ANTIFALLO', 'INVENTARIO', 'ALTO', '0.18.1', '1.12.0', 'APLICADO', '2026-11-17', 'Ventas desde el teléfono sin ver el stock',
      'El portal no mostraba existencias ni respetaba «No vender sin stock»: desde el teléfono se podía vender lo que no había y dejar el inventario en negativo.',
      'El programa sube un resumen ligero de existencias por producto y bodega (también para quien tiene portal sin nube). El portal muestra el stock al facturar y en el catálogo, descuenta lo vendido en la web que la PC aún no recoge y, con la opción activa, no deja emitir si no alcanza.'],
    ['AF-034', 'ANTIFALLO', 'INVENTARIO', 'MEDIO', '0.18.1', '', 'APLICADO', '2026-11-17', 'Líneas que no descuentan el inventario',
      'Una línea escrita a mano o con un código que no existe en el catálogo no descuenta el inventario, y nada lo advertía.',
      'El editor de facturas marca esas líneas («No descuenta inventario») y pide confirmación al emitir.'],
    ['ME-006', 'MEJORA', 'PORTAL', 'MEDIO', '0.18.1', '1.12.0', 'APLICADO', '2026-11-17', 'Notas de crédito desde el teléfono',
      'Las devoluciones solo se podían hacer desde la PC.',
      'Desde una factura autorizada en el portal: devolución por cantidades o anulación total, con lo ya devuelto descontado. El programa la emite con su firma y su serie, la envía al SRI y el stock vuelve a la bodega y lote de donde salió.'],
    ['ME-019', 'MEJORA', 'PORTAL', 'ALTO', '0.26.0', '1.20.0', 'APLICADO', '2026-11-19', 'Facturar sin internet en el celular y punto de emisión a elegir',
      'Sin señal la app no podía facturar, y la web y la app siempre usaban un punto de emisión propio del portal aunque facturara una sola persona.',
      'La app (1.2.0) reserva bloques de números por teléfono (nube_reservas) y guarda cifrados el catálogo, los clientes y los datos de facturación. Sin red arma la factura con número y clave de acceso y la envía sola al volver la señal (WorkManager); el portal la acepta una sola vez, solo con un número reservado por ese teléfono y con la clave verificada. Un administrador elige en el portal o en la app si la web y la app facturan con el punto propio del portal o con el de la computadora; en ese caso el programa salta los números que entregó la nube (secuenciasWeb al sincronizar) y, si chocan, renumera la factura web antes de firmarla.'],
    ['ME-018', 'MEJORA', 'INVENTARIO', 'ALTO', '0.25.0', '1.19.0', 'APLICADO', '2026-11-19', 'Inventario completo en el portal web y la app de Android',
      'El portal y la app solo mostraban el stock total de cada producto; los movimientos de bodega (compras, conteos, transferencias) solo se podían registrar en la PC.',
      'Página Inventario en el portal y vista Inventario en la app (Productos → Inventario): valor en bodega, bajo mínimo y agotados, stock por bodega, kardex por producto y formularios de ingreso, ajuste por conteo y transferencia. Se guardan como pedidos tipo INV que el programa aplica al sincronizar (exige la 0.25), se pueden cancelar mientras estén pendientes y respetan los permisos y el plan (función inventario). Las bodegas se sincronizan al portal. App Android 1.1.0: además, botón «Extraer IVA del precio» al facturar y al crear productos, barra inferior en una sola línea y sin campo de servidor en el inicio de sesión.'],
    ['PA-013', 'PARCHE', 'PORTAL', 'MEDIO', '0.25.0', '1.19.0', 'APLICADO', '2026-11-19', 'Stock de bodegas secundarias no llegaba al portal',
      'Las existencias de una bodega que no es la principal tienen un id de más de 64 caracteres (producto|bodega) y la nube las descartaba, así que el portal mostraba solo el stock de la bodega principal.',
      'La nube guarda esos ids largos con una clave derivada (h + sha1) y el programa vuelve a subir una sola vez las existencias afectadas.'],
    ['ME-017', 'MEJORA', 'PORTAL', 'MEDIO', '', '1.18.0', 'APLICADO', '2026-11-19', 'App nativa para Android',
      'Para facturar desde el celular solo existía la app web en el navegador.',
      'App nativa en Kotlin (Facmit para Android 1.0.0): apertura animada, inicio con ventas y avisos del día, facturar con clientes, más vendidos, stock e IVA, comprobantes con RIDE para imprimir o guardar en PDF, clientes, productos y cambio de empresa. Usa una API nueva del portal (app/api.php) con token por equipo y los mismos permisos y reglas de la app web; el guardado de clientes, productos y facturas se comparte entre ambas (app/lib/acciones.php). Descarga en facmit.com/licencias/descargar.php?app=android.'],
    ['ME-016', 'MEJORA', 'INTERFAZ', 'BAJO', '0.24.0', '', 'APLICADO', '2026-11-18', 'Pantalla de inicio con el logo',
      'Al abrir el programa no se veía nada hasta que cargaba la ventana completa, y en pantallas pequeñas la ventana se maximizaba antes de tiempo (maximize() también la muestra), incluso cuando el programa arrancaba oculto junto al reloj con Windows.',
      'Pantalla de inicio propia (sin bordes, 680×400): logo, nombre, versión, desarrollador y lo que va cargando, con barra de progreso; se muestra ~2 s y da paso a la ventana. No aparece al iniciar oculto con Windows. La ventana se maximiza recién al mostrarse.'],
    ['ME-015', 'MEJORA', 'SRI', 'ALTO', '0.23.0', '1.17.0', 'APLICADO', '2026-11-18', 'Transmisión el mismo día (Res. NAC-DGERCGC25-00000017)',
      'Desde el 1/1/2026 el SRI solo acepta comprobantes transmitidos el día de su emisión. Facmit reintentaba sin límite lo pendiente (aunque ya fuera de otro día, que el SRI rechaza) y las facturas del portal hechas con la PC apagada se autorizaban «al encenderla», aunque fuera al día siguiente.',
      'Avisos crecientes desde las 15:00 (programa y portal) con lo que falta autorizar hoy. Lo que no llegó al SRI el día de su emisión pasa a «Vencido»: el ciclo ya no lo envía y un clic lo vuelve a emitir con la fecha de hoy y el mismo número (clave nueva; stock, cartera, caja y abonos intactos; historial de la fecha anterior). Si pudo haber llegado al SRI, primero se consulta su autorización. Portal: aviso de PC apagada al vendedor y correo al dueño (máx. 2 al día). El SRI simulado ahora rechaza (65) lo extemporáneo, como el real.'],
    ['PA-012', 'PARCHE', 'DATOS', 'ALTO', '0.22.0', '', 'APLICADO', '2026-11-17', 'Restaurar un respaldo podía dañar la base',
      'Desde 0.19.2 los cálculos pesados usan una conexión propia en segundo plano. Al restaurar un respaldo el programa la detenía sin esperar: el archivo se reemplazaba con esa conexión abierta, la base nueva quedaba bloqueada («database is locked»), el perfil quedaba cerrado y, al cerrarse tarde la conexión, podía escribir páginas viejas encima («database disk image is malformed»). Hallado en la auditoría completa de funciones.',
      'El segundo plano abre la base solo durante cada cálculo y la cierra antes de responder. Restaurar, quitar o migrar un perfil espera a que se detenga y a que termine el ciclo del SRI. La espera ante una base ocupada se fija antes de pasar a WAL. Si la restauración falla, vuelve la base anterior y el perfil queda abierto. Prueba automática que reproduce el caso.'],
    ['ME-014', 'MEJORA', 'PORTAL', 'ALTO', '', '1.16.0', 'APLICADO', '2026-11-17', 'Precios: una sola fuente para el portal y la página',
      'Los precios de facmit.com estaban escritos a mano en la página y los del portal (Ajustes) eran otros: cambiar uno no cambiaba el otro, la página no tenía Contabilidad y el pago anual se calculaba distinto (12 meses en el portal, 10 en la página).',
      'Ajustes › Planes y precios es la fuente: la página lee esos precios en cada visita (planes, extras, armador, perfiles y la cifra de la portada), el precio sugerido de cada licencia usa la misma regla y api.php?a=precios los publica. Nuevo ajuste: meses que se cobran en el pago anual. Contabilidad aparece como extra en la página.'],
    ['ME-013', 'MEJORA', 'INTERFAZ', 'MEDIO', '0.21.1', '', 'APLICADO', '2026-11-17', 'Informes más compactos',
      'La pantalla de Reportes mostraba los 28 informes a la vez, en cinco grupos con títulos y una línea de explicación: demasiado texto antes del formulario.',
      'Una fila de temas (con cuántos informes tiene cada uno) y debajo solo los informes del tema elegido; al cambiar de tema se elige su primer informe. La explicación va junto al título del informe. Siguen disponibles los 28 y se ocultan los temas que el plan no incluye.'],
    ['ME-012', 'MEJORA', 'INTERFAZ', 'ALTO', '0.21.0', '1.15.0', 'APLICADO', '2026-11-17', 'Diseño más claro y adaptable',
      'Revisión de diseño de todas las pantallas: en ventanas chicas el menú ocupaba espacio fijo, el carrito del punto de venta quedaba fuera de la vista a 1024 px, las pestañas de Configuración se partían en dos líneas y varias listas vacías no explicaban nada.',
      'Menú que se pliega solo bajo 1200 px; carrito siempre visible y en dos filas; pestañas cortas en una línea; elegir la factura con un clic en devoluciones, notas de débito y anulación; informes agrupados por tema; listas vacías con explicación y botón; aviso de pruebas que lleva a producción y botón Ayuda; celular: datos del cliente al necesitarlos; portal: botones de archivo en español y plan resumido; página: menú en el celular.'],
    ['PA-011', 'PARCHE', 'PORTAL', 'BAJO', '', '1.15.0', 'APLICADO', '2026-11-17', '«IVA IVA 15%» en productos del celular',
      'La etiqueta de la tarifa repetía la palabra IVA.',
      'Se agrega «IVA» solo si la tarifa no lo trae.'],
    ['ME-011', 'MEJORA', 'LICENCIAS', 'ALTO', '0.20.0', '1.14.0', 'APLICADO', '2026-11-17', 'Funciones por categoría en la licencia',
      'Las funciones se activaban en bloques grandes (por ejemplo, guías, notas de crédito, de débito y reembolsos juntos).',
      'Catálogo único (portal y programa) con 18 funciones en sus categorías; el admin las apaga una por una con buscador y filtros. Van firmadas en la licencia; el programa las bloquea en el menú, en cada acción y en las notas de crédito del celular. Licencias anteriores sin cambios.'],
    ['AF-037', 'ANTIFALLO', 'DOCUMENTOS', 'BAJO', '0.20.0', '', 'APLICADO', '2026-11-17', 'Nota sin factura elegida',
      'Pedir una nota de crédito o débito sin factura daba un error técnico de la base de datos.',
      'Ahora dice «Selecciona una factura».'],
    ['PA-010', 'PARCHE', 'INTERFAZ', 'ALTO', '0.19.3', '', 'APLICADO', '2026-11-17', 'La tarjeta de carga no se ocultaba',
      'En 0.19.2, después del primer proceso lento la tarjeta «Procesando…» quedaba visible y tapaba la pantalla (el estilo de la tarjeta anulaba el atributo que la oculta).',
      'Regla de estilo que oculta la tarjeta y la barra al terminar; verificado en el programa.'],
    ['AF-036', 'ANTIFALLO', 'CLIENTES', 'MEDIO', '0.19.3', '', 'APLICADO', '2026-11-17', 'Nombre de cédulas sin RUC',
      'El servicio móvil del SRI que daba el nombre de una cédula sin RUC ahora rechaza las consultas de otros programas (firewall «Request Rejected»). Con RUC sigue funcionando.',
      'La cédula se consulta a la vez en el catastro (si tiene RUC) y en los servicios públicos que queden disponibles; si no, usa el nombre de tus documentos anteriores. Diagnóstico «¿Por qué no salió el nombre?» que muestra qué responde cada servicio (sin datos personales). Importación de clientes desde FactuSYS (DP05A110.DBF).'],
    ['ME-010', 'MEJORA', 'RENDIMIENTO', 'ALTO', '0.19.2', '', 'APLICADO', '2026-11-17', 'Sin congelarse en PC lentas',
      'Informes grandes, contabilidad, ATS, kardex y la auditoría del inventario se calculaban en el proceso principal: en una PC lenta la ventana quedaba trabada varios segundos (Windows la marcaba «No responde»).',
      'Esos cálculos se hacen en un proceso de segundo plano con su propia conexión a la base (mismo resultado, probado). La reparación del inventario trabaja por tandas y avisa el avance. Barra de carga arriba cuando algo tarda y tarjeta con el proceso, el avance y el tiempo en los procesos pesados.'],
    ['AF-035', 'ANTIFALLO', 'VENTAS', 'MEDIO', '0.19.1', '', 'APLICADO', '2026-11-17', 'Código de barras no encontrado al facturar',
      'En el editor de facturas, el escaneo solo buscaba el código propio del producto; si el lector leía el código de barras (código auxiliar), abría la búsqueda en vez de agregarlo. Si el cursor no estaba en el campo de escaneo, el código se perdía o caía en otro campo.',
      'Búsqueda por código propio o de barras en factura, carrito y punto de venta; el lector se reconoce por su velocidad aunque el cursor esté en otro lado (deshace lo escrito en otro campo), acepta Enter o Tab al final, pitido de confirmación y etiquetas de balanza.'],
    ['ME-009', 'MEJORA', 'TESORERIA', 'BAJO', '0.19.1', '', 'APLICADO', '2026-11-17', 'Bancos precargados',
      'Al crear una cuenta había que escribir el nombre del banco a mano.',
      '42 bancos, banca pública, cooperativas y mutualistas de Ecuador para elegir con un clic, con distintivo de color; logo propio opcional (imagen que sube el usuario). Las cuentas anteriores se reconocen por el nombre escrito.'],
    ['ME-007', 'MEJORA', 'VENTAS', 'MEDIO', '0.19.0', '1.13.0', 'APLICADO', '2026-11-17', 'Envío por WhatsApp',
      'Los comprobantes solo se enviaban por correo; muchos clientes prefieren recibirlos por WhatsApp.',
      'Botón WhatsApp en el detalle del comprobante, al emitir y en Cuentas por cobrar (recordatorio de pago). Abre el chat con el mensaje listo; con nube o portal lleva un enlace firmado (30 días) al PDF y al XML. Sin enlace, deja el PDF listo para adjuntar. Mensajes editables.'],
    ['ME-008', 'MEJORA', 'VENTAS', 'MEDIO', '0.19.0', '1.13.0', 'APLICADO', '2026-11-17', 'Pantalla del cliente',
      'El cliente no veía sus datos antes de pagar y los errores de cédula o correo se descubrían con la factura ya emitida.',
      'Segundo monitor con los datos del cliente, los productos y el total en vivo (factura y punto de venta), aviso para confirmar sus datos, vuelto y QR para descargar la factura. Ventana aislada que solo recibe lo que se muestra.'],
    ['PL-001', 'ANTIFALLO', 'FIRMA', 'MEDIO', '', '', 'PLANEADO', '2026-11-13', 'Firma vencida con comprobantes ya firmados en cola',
      'Si la firma vence o se cambia mientras hay comprobantes firmados sin enviar, el SRI podría rechazarlos por firma no vigente.',
      'Antes de reenviar un pendiente, revisar la vigencia del certificado con que se firmó y volver a firmarlo con la firma actual.'],
    ['PL-002', 'MEJORA', 'PANTALLA', 'BAJO', '', '', 'PLANEADO', '2026-11-13', 'Revisión en pantallas pequeñas (1366×768)',
      'Las pantallas nuevas (Masiva, Pagar, Reembolsos) no se han probado en laptops pequeñas.',
      'Revisar cada pantalla a 1366×768; las tablas ya se desplazan de lado, falta confirmar formularios y botones.'],
  ];
}

function par_esquema(): void {
  static $listo = false;
  if ($listo) return;
  $pdo = db();
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
  $fin = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS parches (
    id $ai, codigo VARCHAR(20) NOT NULL UNIQUE, tipo VARCHAR(12) NOT NULL, area VARCHAR(20) NOT NULL, riesgo VARCHAR(8) NOT NULL DEFAULT 'MEDIO',
    version_programa VARCHAR(20) NOT NULL DEFAULT '', version_portal VARCHAR(20) NOT NULL DEFAULT '', estado VARCHAR(12) NOT NULL DEFAULT 'PLANEADO',
    fecha DATE NOT NULL, titulo VARCHAR(200) NOT NULL, problema TEXT NOT NULL, solucion TEXT NOT NULL, autor VARCHAR(60) NOT NULL DEFAULT 'Sistema',
    creado DATETIME NOT NULL, actualizado DATETIME NOT NULL)$fin");
  $existe = $pdo->prepare('SELECT 1 FROM parches WHERE codigo = ?');
  $ins = $pdo->prepare('INSERT INTO parches (codigo, tipo, area, riesgo, version_programa, version_portal, estado, fecha, titulo, problema, solucion, autor, creado, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
  foreach (par_semilla() as $p) {
    $existe->execute([$p[0]]);
    if ($existe->fetchColumn()) continue;
    $ins->execute([...$p, 'Sistema', ahora(), ahora()]);
  }
  par_marcar_publicados();
  $listo = true;
}

// Lo aplicado pasa solo a «Publicado» cuando ya llegó a los clientes: el programa, al publicarse en Versiones una
// versión igual o mayor; lo que es solo del portal, en cuanto el portal desplegado tiene esa versión.
function par_marcar_publicados(): void {
  $f = __DIR__ . '/../actualizaciones/latest.yml';
  $pub = is_file($f) && preg_match('/^version:\s*[\'"]?([0-9]+\.[0-9]+\.[0-9]+)/m', (string)file_get_contents($f), $m) ? $m[1] : '';
  $upd = db()->prepare("UPDATE parches SET estado = 'PUBLICADO', actualizado = ? WHERE id = ?");
  foreach (db()->query("SELECT id, version_programa, version_portal FROM parches WHERE estado = 'APLICADO'")->fetchAll() as $p) {
    $vp = (string)$p['version_programa']; $vw = (string)$p['version_portal'];
    $listoProg = $vp === '' || ($pub !== '' && version_compare($vp, $pub, '<='));
    $listoPortal = $vw === '' || version_compare($vw, FX_VERSION, '<=');
    if (($vp !== '' || $vw !== '') && $listoProg && $listoPortal) $upd->execute([ahora(), $p['id']]);
  }
}

function par_lista(array $f = []): array {
  $w = []; $a = [];
  foreach (['tipo', 'estado', 'area', 'riesgo'] as $k) if (!empty($f[$k])) { $w[] = "$k = ?"; $a[] = $f[$k]; }
  if (!empty($f['q'])) { $w[] = '(titulo LIKE ? OR problema LIKE ? OR solucion LIKE ? OR codigo LIKE ?)'; $q = '%' . $f['q'] . '%'; array_push($a, $q, $q, $q, $q); }
  $sql = 'SELECT * FROM parches' . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
    . " ORDER BY CASE estado WHEN 'PLANEADO' THEN 0 WHEN 'APLICADO' THEN 1 ELSE 2 END, fecha DESC, codigo DESC";
  $r = db()->prepare($sql);
  $r->execute($a);
  return $r->fetchAll();
}

function par_obtener(int $id): ?array {
  $r = db()->prepare('SELECT * FROM parches WHERE id = ?');
  $r->execute([$id]);
  return $r->fetch() ?: null;
}

// Código siguiente para registros manuales: AF-014, PA-004, ME-001…
function par_codigo_nuevo(string $tipo): string {
  $pre = ['ANTIFALLO' => 'AF', 'PARCHE' => 'PA', 'MEJORA' => 'ME'][$tipo] ?? 'AF';
  $max = 0;
  foreach (db()->query("SELECT codigo FROM parches WHERE codigo LIKE '$pre-%'")->fetchAll(PDO::FETCH_COLUMN) as $c) $max = max($max, (int)substr((string)$c, 3));
  return sprintf('%s-%03d', $pre, $max + 1);
}

function par_guardar(array $d, string $autor): int {
  $tipo = array_key_exists($d['tipo'] ?? '', PAR_TIPOS) ? $d['tipo'] : 'ANTIFALLO';
  $area = array_key_exists($d['area'] ?? '', PAR_AREAS) ? $d['area'] : 'SRI';
  $riesgo = array_key_exists($d['riesgo'] ?? '', PAR_RIESGOS) ? $d['riesgo'] : 'MEDIO';
  $estado = array_key_exists($d['estado'] ?? '', PAR_ESTADOS) ? $d['estado'] : 'PLANEADO';
  $titulo = mb_substr(trim((string)($d['titulo'] ?? '')), 0, 200);
  $problema = mb_substr(trim((string)($d['problema'] ?? '')), 0, 4000);
  $solucion = mb_substr(trim((string)($d['solucion'] ?? '')), 0, 4000);
  if (mb_strlen($titulo) < 4) throw new RuntimeException('Escribe un título.');
  if (mb_strlen($problema) < 4) throw new RuntimeException('Describe qué falla o qué podría fallar.');
  if (mb_strlen($solucion) < 4) throw new RuntimeException('Describe la solución (o el plan, si está planeado).');
  $ver = fn($v) => preg_match('/^\d+\.\d+\.\d+$/', trim((string)$v)) ? trim((string)$v) : '';
  $fecha = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($d['fecha'] ?? '')) ? $d['fecha'] : date('Y-m-d');
  $vals = [$tipo, $area, $riesgo, $ver($d['version_programa'] ?? ''), $ver($d['version_portal'] ?? ''), $estado, $fecha, $titulo, $problema, $solucion];
  $id = (int)($d['id'] ?? 0);
  if ($id && par_obtener($id)) {
    db()->prepare('UPDATE parches SET tipo = ?, area = ?, riesgo = ?, version_programa = ?, version_portal = ?, estado = ?, fecha = ?, titulo = ?, problema = ?, solucion = ?, actualizado = ? WHERE id = ?')
      ->execute([...$vals, ahora(), $id]);
    return $id;
  }
  db()->prepare('INSERT INTO parches (codigo, tipo, area, riesgo, version_programa, version_portal, estado, fecha, titulo, problema, solucion, autor, creado, actualizado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
    ->execute([par_codigo_nuevo($tipo), ...$vals, mb_substr($autor, 0, 60), ahora(), ahora()]);
  return (int)db()->lastInsertId();
}

function par_cambiar_estado(int $id, string $estado): void {
  if (!array_key_exists($estado, PAR_ESTADOS)) throw new RuntimeException('Estado no válido.');
  db()->prepare('UPDATE parches SET estado = ?, actualizado = ? WHERE id = ?')->execute([$estado, ahora(), $id]);
}

function par_eliminar(int $id): void {
  db()->prepare("DELETE FROM parches WHERE id = ? AND autor <> 'Sistema'")->execute([$id]);
}
